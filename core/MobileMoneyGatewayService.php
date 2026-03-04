<?php
/**
 * Mobile Money Gateway Service (Backend Skeleton)
 * - Initiate PRN payment requests
 * - Receive and process webhook callbacks
 * - Auto-post successful PRN payments to ledger/payments
 */
class MobileMoneyGatewayService {
    private $conn;
    private $gatewayMode;
    private $defaultProvider;
    private $webhookSecret;
    private $logger;

    public function __construct(PDO $conn, array $options = []) {
        $this->conn = $conn;
        $rawGatewayMode = (string)($options['gateway_mode'] ?? (defined('PAYMENT_GATEWAY_MODE') ? PAYMENT_GATEWAY_MODE : 'mock'));
        $this->gatewayMode = $this->normalizeGatewayMode($rawGatewayMode);
        $this->defaultProvider = (string)($options['provider'] ?? (defined('MOBILE_MONEY_DEFAULT_PROVIDER') ? MOBILE_MONEY_DEFAULT_PROVIDER : 'sandbox'));
        $this->webhookSecret = (string)($options['webhook_secret'] ?? (defined('PAYMENT_GATEWAY_WEBHOOK_SECRET') ? PAYMENT_GATEWAY_WEBHOOK_SECRET : ''));

        try {
            $this->logger = class_exists('Logger') ? new Logger() : null;
        } catch (Exception $e) {
            $this->logger = null;
        }
    }

    public function ensureSchema() {
        $this->ensureStudentPaymentReferenceSchema();
        $this->ensurePaymentWorkflowSchema();
        $this->ensurePaymentMethodSchema();
        $this->ensureMobileMoneyTransactionSchema();
        $this->ensureBankTransactionSchema();
        $this->ensureBalanceUpdateProcedure();
    }

    public function initiatePaymentByReference($studentId, $referenceNumber, $msisdn, $provider = '') {
        $studentId = (int)$studentId;
        $referenceNumber = strtoupper(trim((string)$referenceNumber));
        $msisdn = $this->normalizePhone($msisdn);
        $provider = trim((string)$provider) !== '' ? trim((string)$provider) : $this->defaultProvider;
        $provider = $this->normalizeProviderName($provider);

        if ($studentId <= 0) {
            return ['success' => false, 'message' => 'Invalid student profile.'];
        }
        if ($referenceNumber === '') {
            return ['success' => false, 'message' => 'PRN is required.'];
        }
        if ($msisdn === '') {
            return ['success' => false, 'message' => 'Phone number is required.'];
        }

        $this->ensureSchema();

        try {
            $this->conn->beginTransaction();

            $refStmt = $this->conn->prepare("
                SELECT id, student_id, invoice_id, semester_id, reference_type, amount, status, expires_at
                FROM student_payment_references
                WHERE reference_number = :reference_number
                  AND student_id = :student_id
                LIMIT 1
                FOR UPDATE
            ");
            $refStmt->execute([
                'reference_number' => $referenceNumber,
                'student_id' => $studentId
            ]);
            $reference = $refStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (empty($reference)) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'PRN not found for this student.'];
            }

            if (strtolower((string)($reference['status'] ?? '')) === 'paid') {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'This PRN has already been paid.'];
            }
            if (strtolower((string)($reference['status'] ?? '')) !== 'active') {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'This PRN is not active.'];
            }
            if (!empty($reference['expires_at']) && strtotime((string)$reference['expires_at']) < time()) {
                $expStmt = $this->conn->prepare("
                    UPDATE student_payment_references
                    SET status = 'expired', updated_at = NOW()
                    WHERE id = :id
                ");
                $expStmt->execute(['id' => (int)$reference['id']]);
                $this->conn->commit();
                return ['success' => false, 'message' => 'This PRN has expired.'];
            }

            $amountExpected = (float)($reference['amount'] ?? 0);
            if ($amountExpected <= 0) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'PRN amount is invalid.'];
            }

            $txStmt = $this->conn->prepare("
                SELECT id, transaction_ref, status
                    , provider_request_id, provider_tx_id, provider_status
                FROM mobile_money_transactions
                WHERE reference_number = :reference_number
                  AND student_id = :student_id
                  AND status IN ('initiated','pending')
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $txStmt->execute([
                'reference_number' => $referenceNumber,
                'student_id' => $studentId
            ]);
            $existingPending = $txStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($existingPending)) {
                $status = strtolower((string)($existingPending['status'] ?? 'pending'));
                $providerStatus = strtolower((string)($existingPending['provider_status'] ?? 'pending'));
                $providerRequestId = (string)($existingPending['provider_request_id'] ?? '');
                $providerTxId = (string)($existingPending['provider_tx_id'] ?? '');
                $mockAutoCallbackStatus = 'skipped';
                $message = 'Payment request is already pending.';
                $transactionRefExisting = (string)($existingPending['transaction_ref'] ?? '');

                $this->conn->commit();

                if ($this->gatewayMode === 'mock') {
                    $mockCallbackResult = $this->processMockAutoCallback(
                        $referenceNumber,
                        $transactionRefExisting,
                        $providerRequestId,
                        $providerTxId,
                        $amountExpected
                    );
                    $mockAutoCallbackStatus = !empty($mockCallbackResult['success']) ? 'ok' : 'failed';

                    $latestTx = $this->findTransactionByRef($transactionRefExisting);
                    if (!empty($latestTx)) {
                        $status = strtolower((string)($latestTx['status'] ?? $status));
                        $providerStatus = strtolower((string)($latestTx['provider_status'] ?? $providerStatus));
                        $providerTxId = trim((string)($latestTx['provider_tx_id'] ?? $providerTxId));
                        if (in_array($status, ['successful', 'posted'], true)) {
                            $mockAutoCallbackStatus = 'ok';
                        }
                    }

                    if ($status === 'posted') {
                        $message = 'Mock payment auto-confirmed and posted to ledger.';
                    } elseif ($status === 'successful') {
                        $message = 'Mock payment auto-confirmed. Ledger posting is in progress.';
                    } elseif ($mockAutoCallbackStatus === 'failed') {
                        $callbackError = trim((string)($mockCallbackResult['message'] ?? ''));
                        $message = $callbackError !== ''
                            ? 'Mock payment request exists, but auto-callback failed: ' . $callbackError
                            : 'Mock payment request exists, but auto-callback failed.';
                    }
                }

                return [
                    'success' => ($status !== 'failed'),
                    'message' => $message,
                    'transaction_ref' => $transactionRefExisting,
                    'provider_request_id' => $providerRequestId,
                    'provider_tx_id' => $providerTxId,
                    'status' => $status,
                    'provider_status' => $providerStatus,
                    'amount' => $amountExpected,
                    'currency' => 'UGX',
                    'reference_number' => $referenceNumber,
                    'mock_mode' => ($this->gatewayMode === 'mock'),
                    'mock_auto_callback' => $this->gatewayMode === 'mock' ? $mockAutoCallbackStatus : null
                ];
            }

            $transactionRef = $this->generateUniqueCode('MMT', 'mobile_money_transactions', 'transaction_ref');

            $requestPayload = [
                'transaction_ref' => $transactionRef,
                'reference_number' => $referenceNumber,
                'student_id' => $studentId,
                'amount' => $amountExpected,
                'currency' => 'UGX',
                'provider' => $provider,
                'msisdn' => $msisdn
            ];

            $providerResult = $this->sendProviderInitiationRequest($requestPayload);
            $providerStatus = (string)($providerResult['provider_status'] ?? 'pending');
            $internalStatus = strtolower((string)($providerResult['status'] ?? 'pending')) === 'failed' ? 'failed' : 'pending';
            $providerRequestId = (string)($providerResult['provider_request_id'] ?? '');
            $providerTxId = (string)($providerResult['provider_tx_id'] ?? '');

            $insertTxStmt = $this->conn->prepare("
                INSERT INTO mobile_money_transactions (
                    transaction_ref,
                    reference_number,
                    student_id,
                    invoice_id,
                    semester_id,
                    provider,
                    msisdn,
                    currency_code,
                    amount_expected,
                    amount_received,
                    provider_request_id,
                    provider_tx_id,
                    provider_status,
                    status,
                    request_payload,
                    provider_response,
                    attempts,
                    created_at,
                    updated_at
                ) VALUES (
                    :transaction_ref,
                    :reference_number,
                    :student_id,
                    :invoice_id,
                    :semester_id,
                    :provider,
                    :msisdn,
                    'UGX',
                    :amount_expected,
                    NULL,
                    :provider_request_id,
                    :provider_tx_id,
                    :provider_status,
                    :status,
                    :request_payload,
                    :provider_response,
                    1,
                    NOW(),
                    NOW()
                )
            ");
            $insertTxStmt->execute([
                'transaction_ref' => $transactionRef,
                'reference_number' => $referenceNumber,
                'student_id' => $studentId,
                'invoice_id' => !empty($reference['invoice_id']) ? (int)$reference['invoice_id'] : null,
                'semester_id' => !empty($reference['semester_id']) ? (int)$reference['semester_id'] : null,
                'provider' => $provider,
                'msisdn' => $msisdn,
                'amount_expected' => $amountExpected,
                'provider_request_id' => $providerRequestId !== '' ? $providerRequestId : null,
                'provider_tx_id' => $providerTxId !== '' ? $providerTxId : null,
                'provider_status' => $providerStatus,
                'status' => $internalStatus,
                'request_payload' => json_encode($requestPayload),
                'provider_response' => json_encode($providerResult)
            ]);

            $this->conn->commit();

            $mockCallbackResult = null;
            $mockAutoCallbackStatus = '';
            if ($this->gatewayMode === 'mock' && $internalStatus === 'pending') {
                $mockCallbackResult = $this->processMockAutoCallback(
                    $referenceNumber,
                    $transactionRef,
                    $providerRequestId,
                    $providerTxId,
                    $amountExpected
                );
                if (!empty($mockCallbackResult['success'])) {
                    $mockAutoCallbackStatus = 'ok';
                } else {
                    $mockAutoCallbackStatus = 'failed';
                }

                $latestTx = $this->findTransactionByRef($transactionRef);
                if (!empty($latestTx)) {
                    $internalStatus = strtolower((string)($latestTx['status'] ?? $internalStatus));
                    $providerStatus = strtolower((string)($latestTx['provider_status'] ?? $providerStatus));
                    $providerTxId = trim((string)($latestTx['provider_tx_id'] ?? $providerTxId));
                    if (in_array($internalStatus, ['successful', 'posted'], true)) {
                        $mockAutoCallbackStatus = 'ok';
                    }
                }
            }

            $initiationSucceeded = ($internalStatus !== 'failed');
            $this->safeLog(
                $initiationSucceeded ? 'initiate_mobile_money' : 'initiate_mobile_money_failed',
                'finance',
                ($initiationSucceeded ? 'Mobile money payment initiated.' : 'Mobile money payment initiation failed.') . ' PRN: ' . $referenceNumber . '. TX: ' . $transactionRef
            );

            $message = trim((string)($providerResult['message'] ?? ''));
            if ($this->gatewayMode === 'mock') {
                if ($internalStatus === 'posted') {
                    $message = 'Mock payment auto-confirmed and posted to ledger.';
                } elseif ($internalStatus === 'successful') {
                    $message = 'Mock payment auto-confirmed. Ledger posting is in progress.';
                } elseif ($mockAutoCallbackStatus === 'failed') {
                    $callbackError = trim((string)($mockCallbackResult['message'] ?? ''));
                    $message = $callbackError !== ''
                        ? 'Mock payment request accepted, but auto-callback failed: ' . $callbackError
                        : 'Mock payment request accepted, but auto-callback failed.';
                } else {
                    $message = 'Mock payment request accepted.';
                }
            } elseif ($message === '') {
                $message = $initiationSucceeded ? 'Payment request initiated.' : 'Payment initiation failed.';
            }

            return [
                'success' => $initiationSucceeded,
                'message' => $message,
                'transaction_ref' => $transactionRef,
                'provider_request_id' => $providerRequestId,
                'provider_tx_id' => $providerTxId,
                'status' => $internalStatus,
                'provider_status' => $providerStatus,
                'amount' => $amountExpected,
                'currency' => 'UGX',
                'reference_number' => $referenceNumber,
                'error' => (string)($providerResult['error'] ?? ''),
                'mock_mode' => ($this->gatewayMode === 'mock'),
                'mock_auto_callback' => $this->gatewayMode === 'mock' ? ($mockAutoCallbackStatus !== '' ? $mockAutoCallbackStatus : 'skipped') : null
            ];
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to initiate payment.', 'error' => $e->getMessage()];
        }
    }

    public function submitBankTransferProof($studentId, $referenceNumber, array $payload = []) {
        $studentId = (int)$studentId;
        $referenceNumber = strtoupper(trim((string)$referenceNumber));
        $bankName = trim((string)($payload['bank_name'] ?? ''));
        $transferReference = strtoupper(trim((string)($payload['transfer_reference'] ?? ($payload['bank_reference'] ?? ''))));
        $paymentMethodLabel = strtolower(trim((string)($payload['payment_method'] ?? ($payload['payment_method_label'] ?? 'bank_agent'))));
        $depositorName = trim((string)($payload['depositor_name'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ($payload['proof_note'] ?? '')));
        $amountSubmittedRaw = (string)($payload['amount_submitted'] ?? ($payload['amount'] ?? '0'));
        $amountSubmitted = (float)str_replace(',', '', $amountSubmittedRaw);

        if (!in_array($paymentMethodLabel, ['bank_transfer', 'bank_agent', 'cente_agent'], true)) {
            $bankNameNorm = strtolower($bankName);
            if ($bankNameNorm !== '' && (strpos($bankNameNorm, 'cent') !== false || strpos($bankNameNorm, 'cente') !== false)) {
                $paymentMethodLabel = 'cente_agent';
            } else {
                $paymentMethodLabel = 'bank_agent';
            }
        }

        if ($studentId <= 0) {
            return ['success' => false, 'message' => 'Invalid student profile.'];
        }
        if ($referenceNumber === '') {
            return ['success' => false, 'message' => 'PRN is required.'];
        }
        if ($transferReference === '') {
            return ['success' => false, 'message' => 'Bank transfer reference is required.'];
        }

        $this->ensureSchema();

        try {
            $this->conn->beginTransaction();

            $reference = $this->findReferenceByNumber($referenceNumber, true);
            if (empty($reference) || (int)($reference['student_id'] ?? 0) !== $studentId) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'PRN not found for this student.'];
            }

            $referenceStatus = strtolower((string)($reference['status'] ?? ''));
            if ($referenceStatus === 'paid') {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'This PRN has already been paid.'];
            }
            if ($referenceStatus !== 'active') {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'This PRN is not active.'];
            }
            if (!empty($reference['expires_at']) && strtotime((string)$reference['expires_at']) < time()) {
                $expStmt = $this->conn->prepare("
                    UPDATE student_payment_references
                    SET status = 'expired', updated_at = NOW()
                    WHERE id = :id
                ");
                $expStmt->execute(['id' => (int)$reference['id']]);
                $this->conn->commit();
                return ['success' => false, 'message' => 'This PRN has expired.'];
            }

            $existingStmt = $this->conn->prepare("
                SELECT id, transaction_ref, status
                FROM bank_transactions
                WHERE reference_number = :reference_number
                  AND student_id = :student_id
                  AND transfer_reference = :transfer_reference
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $existingStmt->execute([
                'reference_number' => $referenceNumber,
                'student_id' => $studentId,
                'transfer_reference' => $transferReference
            ]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($existing)) {
                $existingStatus = strtolower((string)($existing['status'] ?? 'received'));
                if (in_array($existingStatus, ['received', 'verified', 'posted'], true)) {
                    $this->conn->commit();
                    return [
                        'success' => true,
                        'message' => 'Bank transfer proof already submitted.',
                        'transaction_ref' => (string)($existing['transaction_ref'] ?? ''),
                        'status' => $existingStatus
                    ];
                }
            }

            $amountExpected = (float)($reference['amount'] ?? 0);
            if ($amountExpected <= 0) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'PRN amount is invalid.'];
            }
            if ($amountSubmitted <= 0) {
                $amountSubmitted = $amountExpected;
            }

            $transactionRef = $this->generateUniqueCode('BKT', 'bank_transactions', 'transaction_ref');
            $insertStmt = $this->conn->prepare("
                INSERT INTO bank_transactions (
                    transaction_ref,
                    reference_number,
                    student_id,
                    invoice_id,
                    semester_id,
                    payment_method_label,
                    bank_name,
                    depositor_name,
                    transfer_reference,
                    currency_code,
                    amount_expected,
                    amount_submitted,
                    status,
                    submitted_notes,
                    submission_payload,
                    created_at,
                    updated_at
                ) VALUES (
                    :transaction_ref,
                    :reference_number,
                    :student_id,
                    :invoice_id,
                    :semester_id,
                    :payment_method_label,
                    :bank_name,
                    :depositor_name,
                    :transfer_reference,
                    'UGX',
                    :amount_expected,
                    :amount_submitted,
                    'received',
                    :submitted_notes,
                    :submission_payload,
                    NOW(),
                    NOW()
                )
            ");
            $insertStmt->execute([
                'transaction_ref' => $transactionRef,
                'reference_number' => $referenceNumber,
                'student_id' => $studentId,
                'invoice_id' => !empty($reference['invoice_id']) ? (int)$reference['invoice_id'] : null,
                'semester_id' => !empty($reference['semester_id']) ? (int)$reference['semester_id'] : null,
                'payment_method_label' => $paymentMethodLabel,
                'bank_name' => $bankName !== '' ? $bankName : null,
                'depositor_name' => $depositorName !== '' ? $depositorName : null,
                'transfer_reference' => $transferReference,
                'amount_expected' => $amountExpected,
                'amount_submitted' => $amountSubmitted,
                'submitted_notes' => $notes !== '' ? $notes : null,
                'submission_payload' => json_encode($payload)
            ]);

            $this->conn->commit();
            $this->safeLog(
                'submit_bank_transfer_proof',
                'finance',
                'Bank transfer proof submitted. PRN: ' . $referenceNumber . '. TX: ' . $transactionRef
            );
            $this->notifyFinanceTeamBankProofSubmitted(
                $studentId,
                $referenceNumber,
                (float)$amountSubmitted,
                $paymentMethodLabel,
                $transactionRef,
                $transferReference
            );

            return [
                'success' => true,
                'message' => 'Bank transfer proof received. Awaiting finance verification.',
                'transaction_ref' => $transactionRef,
                'status' => 'received',
                'payment_method' => $paymentMethodLabel,
                'reference_number' => $referenceNumber
            ];
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to submit bank transfer proof.', 'error' => $e->getMessage()];
        }
    }

    public function verifyBankTransaction($bankTransactionId, $financeStaffId, $decision = 'verify', $notes = '', $verifiedAmount = null) {
        $bankTransactionId = (int)$bankTransactionId;
        $financeStaffId = (int)$financeStaffId;
        $decision = strtolower(trim((string)$decision)) === 'fail' ? 'fail' : 'verify';
        $notes = trim((string)$notes);
        $verifiedAmount = $verifiedAmount !== null ? (float)$verifiedAmount : null;

        if ($bankTransactionId <= 0) {
            return ['success' => false, 'message' => 'Invalid bank transaction ID.'];
        }
        if ($financeStaffId <= 0) {
            return ['success' => false, 'message' => 'Finance staff profile is missing.'];
        }

        $this->ensureSchema();

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("
                SELECT b.*, spr.status AS reference_status, spr.paid_payment_id
                FROM bank_transactions b
                INNER JOIN student_payment_references spr
                    ON spr.reference_number = b.reference_number
                WHERE b.id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(['id' => $bankTransactionId]);
            $bankTx = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (empty($bankTx)) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Bank transaction not found.'];
            }

            $currentStatus = strtolower((string)($bankTx['status'] ?? 'received'));
            if ($currentStatus === 'posted') {
                $this->conn->commit();
                return [
                    'success' => true,
                    'message' => 'Bank transaction already posted.',
                    'status' => 'posted',
                    'posted_payment_id' => (int)($bankTx['posted_payment_id'] ?? 0)
                ];
            }

            if ($decision === 'fail') {
                $failureReason = $notes !== '' ? $notes : 'Marked as failed by finance verification.';
                $failStmt = $this->conn->prepare("
                    UPDATE bank_transactions
                    SET status = 'failed',
                        verified_by = :verified_by,
                        verified_at = NOW(),
                        verification_notes = :verification_notes,
                        failure_reason = :failure_reason,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $failStmt->execute([
                    'verified_by' => $financeStaffId,
                    'verification_notes' => $notes !== '' ? $notes : null,
                    'failure_reason' => $failureReason,
                    'id' => $bankTransactionId
                ]);

                $studentUserId = $this->resolveStudentUserId((int)($bankTx['student_id'] ?? 0));
                if ($studentUserId > 0) {
                    $this->notifyUser(
                        $studentUserId,
                        'Bank Transfer Verification Failed',
                        'Your bank transfer for PRN ' . (string)$bankTx['reference_number'] . ' could not be verified. Please contact finance office.',
                        'warning',
                        BASE_URL . '/views/student/generate_prn.php'
                    );
                }

                $this->conn->commit();
                return [
                    'success' => true,
                    'message' => 'Bank transaction marked as failed.',
                    'status' => 'failed'
                ];
            }

            $referenceStatus = strtolower((string)($bankTx['reference_status'] ?? ''));
            if ($referenceStatus === 'paid') {
                $dupStmt = $this->conn->prepare("
                    UPDATE bank_transactions
                    SET status = 'failed',
                        verified_by = :verified_by,
                        verified_at = NOW(),
                        verification_notes = :verification_notes,
                        failure_reason = :failure_reason,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $dupStmt->execute([
                    'verified_by' => $financeStaffId,
                    'verification_notes' => $notes !== '' ? $notes : 'Duplicate bank transfer for already-paid PRN.',
                    'failure_reason' => 'PRN already paid.',
                    'id' => $bankTransactionId
                ]);

                $studentUserId = $this->resolveStudentUserId((int)($bankTx['student_id'] ?? 0));
                if ($studentUserId > 0) {
                    $this->notifyUser(
                        $studentUserId,
                        'Bank Transfer Verification Failed',
                        'Bank transfer for PRN ' . (string)$bankTx['reference_number'] . ' was marked failed because the PRN is already paid.',
                        'warning',
                        BASE_URL . '/views/student/payments.php?section=transactions&tx_tab=check_prn'
                    );
                }
                $this->conn->commit();
                return ['success' => false, 'message' => 'PRN is already paid. This bank transaction was marked failed.'];
            }

            $verifyStmt = $this->conn->prepare("
                UPDATE bank_transactions
                SET status = 'verified',
                    verified_by = :verified_by,
                    verified_at = NOW(),
                    verification_notes = :verification_notes,
                    failure_reason = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $verifyStmt->execute([
                'verified_by' => $financeStaffId,
                'verification_notes' => $notes !== '' ? $notes : null,
                'id' => $bankTransactionId
            ]);

            $amountForPosting = $verifiedAmount !== null && $verifiedAmount > 0
                ? $verifiedAmount
                : (float)($bankTx['amount_submitted'] ?? 0);
            if ($amountForPosting <= 0) {
                $amountForPosting = (float)($bankTx['amount_expected'] ?? 0);
            }

            $postResult = $this->autoPostPaymentFromTransaction([
                'id' => (int)$bankTx['id'],
                'reference_number' => (string)$bankTx['reference_number'],
                'transaction_ref' => (string)($bankTx['transaction_ref'] ?? ''),
                'provider_tx_id' => (string)($bankTx['transfer_reference'] ?? ''),
                'payment_method' => (string)($bankTx['payment_method_label'] ?? 'bank_agent'),
                'transaction_table' => 'bank_transactions'
            ], $amountForPosting);

            if (empty($postResult['success'])) {
                $failedStmt = $this->conn->prepare("
                    UPDATE bank_transactions
                    SET status = 'failed',
                        failure_reason = :failure_reason,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $failedStmt->execute([
                    'failure_reason' => (string)($postResult['message'] ?? 'Auto-post failed'),
                    'id' => $bankTransactionId
                ]);

                $studentUserId = $this->resolveStudentUserId((int)($bankTx['student_id'] ?? 0));
                if ($studentUserId > 0) {
                    $this->notifyUser(
                        $studentUserId,
                        'Bank Transfer Verification Failed',
                        'Your bank transfer for PRN ' . (string)$bankTx['reference_number'] . ' was verified but posting to ledger failed. Finance is reviewing it.',
                        'warning',
                        BASE_URL . '/views/student/payments.php?section=transactions&tx_tab=check_prn'
                    );
                }
                $this->conn->commit();
                return ['success' => false, 'message' => (string)($postResult['message'] ?? 'Failed to auto-post verified bank transaction.')];
            }

            $studentUserId = $this->resolveStudentUserId((int)($bankTx['student_id'] ?? 0));
            if ($studentUserId > 0) {
                $this->notifyUser(
                    $studentUserId,
                    'Bank Transfer Posted',
                    'Your bank transfer for PRN ' . (string)$bankTx['reference_number'] . ' has been verified and posted to your ledger.',
                    'success',
                    BASE_URL . '/views/student/payments.php?section=transactions'
                );
            }

            $this->conn->commit();
            return [
                'success' => true,
                'message' => 'Bank transaction verified and posted to ledger.',
                'status' => 'posted',
                'posted_payment_id' => (int)($postResult['posted_payment_id'] ?? 0)
            ];
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Unable to verify bank transaction.', 'error' => $e->getMessage()];
        }
    }

    public function handleWebhook(array $payload, array $headers = [], $rawBody = '') {
        $this->ensureSchema();

        $sigCheck = $this->verifyWebhookSignature($headers, $rawBody);
        if (!$sigCheck['ok']) {
            return ['success' => false, 'status_code' => 401, 'message' => $sigCheck['message']];
        }

        $fields = $this->extractWebhookFields($payload);
        if (empty($fields['reference_number']) && empty($fields['transaction_ref']) && empty($fields['provider_request_id']) && empty($fields['provider_tx_id'])) {
            return ['success' => false, 'status_code' => 400, 'message' => 'Webhook payload missing transaction identifiers.'];
        }

        try {
            $this->conn->beginTransaction();

            $txRow = $this->findTransactionForWebhook($fields);
            if (empty($txRow) && !empty($fields['reference_number'])) {
                $reference = $this->findReferenceByNumber($fields['reference_number'], true);
                if (!empty($reference)) {
                    $txRow = $this->createAdhocTransactionFromWebhook($reference, $fields, $payload);
                }
            }

            if (empty($txRow)) {
                $this->conn->rollBack();
                return ['success' => false, 'status_code' => 404, 'message' => 'No matching transaction/PRN for webhook.'];
            }

            $txId = (int)($txRow['id'] ?? 0);
            $mappedStatus = $this->mapProviderStatusToInternal($fields['provider_status']);
            $receivedAmount = $fields['amount_received'] > 0 ? (float)$fields['amount_received'] : null;
            $providerStatus = $fields['provider_status'] !== '' ? $fields['provider_status'] : ($txRow['provider_status'] ?? null);

            $updateTxStmt = $this->conn->prepare("
                UPDATE mobile_money_transactions
                SET provider_request_id = COALESCE(:provider_request_id, provider_request_id),
                    provider_tx_id = COALESCE(:provider_tx_id, provider_tx_id),
                    provider_status = :provider_status,
                    status = :status,
                    amount_received = COALESCE(:amount_received, amount_received),
                    callback_payload = :callback_payload,
                    webhook_received_at = NOW(),
                    paid_at = CASE WHEN :status_success = 1 THEN COALESCE(paid_at, NOW()) ELSE paid_at END,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $updateTxStmt->execute([
                'provider_request_id' => $fields['provider_request_id'] !== '' ? $fields['provider_request_id'] : null,
                'provider_tx_id' => $fields['provider_tx_id'] !== '' ? $fields['provider_tx_id'] : null,
                'provider_status' => $providerStatus,
                'status' => $mappedStatus,
                'amount_received' => $receivedAmount,
                'callback_payload' => json_encode($payload),
                'status_success' => $mappedStatus === 'successful' ? 1 : 0,
                'id' => $txId
            ]);

            $refreshTxStmt = $this->conn->prepare("SELECT * FROM mobile_money_transactions WHERE id = :id LIMIT 1 FOR UPDATE");
            $refreshTxStmt->execute(['id' => $txId]);
            $txRow = $refreshTxStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $alreadyPostedPaymentId = (int)($txRow['posted_payment_id'] ?? 0);
            $resultData = [
                'transaction_ref' => (string)($txRow['transaction_ref'] ?? ''),
                'reference_number' => (string)($txRow['reference_number'] ?? ''),
                'status' => (string)($txRow['status'] ?? $mappedStatus),
                'posted_payment_id' => $alreadyPostedPaymentId
            ];

            if ($mappedStatus === 'successful' && $alreadyPostedPaymentId <= 0) {
                $amountForPosting = (float)($txRow['amount_received'] ?? 0);
                if ($amountForPosting <= 0) {
                    $amountForPosting = (float)($txRow['amount_expected'] ?? 0);
                }

                $postResult = $this->autoPostPaymentFromTransaction($txRow, $amountForPosting);
                if (!$postResult['success']) {
                    $updateErrStmt = $this->conn->prepare("
                        UPDATE mobile_money_transactions
                        SET status = 'successful',
                            failure_reason = :failure_reason,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $updateErrStmt->execute([
                        'failure_reason' => (string)($postResult['message'] ?? 'Auto-post failed'),
                        'id' => $txId
                    ]);
                    $this->conn->commit();
                    return [
                        'success' => false,
                        'status_code' => 500,
                        'message' => 'Webhook received but auto-post failed.',
                        'data' => $resultData
                    ];
                }

                $resultData['posted_payment_id'] = (int)($postResult['posted_payment_id'] ?? 0);
                $resultData['posted_payment_count'] = (int)($postResult['posted_payment_count'] ?? 0);
            }

            $this->conn->commit();

            $this->safeLog(
                'mobile_money_webhook',
                'finance',
                'Webhook processed. PRN: ' . (string)($resultData['reference_number'] ?? '-') . '. TX: ' . (string)($resultData['transaction_ref'] ?? '-')
            );

            return ['success' => true, 'status_code' => 200, 'message' => 'Webhook processed.', 'data' => $resultData];
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'status_code' => 500, 'message' => 'Webhook processing failed.', 'error' => $e->getMessage()];
        }
    }

    public function getStudentReferenceStatus($studentId, $referenceNumber) {
        $studentId = (int)$studentId;
        $referenceNumber = strtoupper(trim((string)$referenceNumber));
        if ($studentId <= 0 || $referenceNumber === '') {
            return ['success' => false, 'message' => 'Invalid request.'];
        }

        $this->ensureSchema();

        try {
            $stmt = $this->conn->prepare("
                SELECT
                    spr.reference_number,
                    spr.reference_type,
                    spr.amount,
                    spr.semester_id AS reference_semester_id,
                    spr.status AS reference_status,
                    spr.expires_at,
                    spr.paid_payment_id,
                    mmt.transaction_ref AS mobile_transaction_ref,
                    mmt.provider,
                    mmt.status AS mobile_transaction_status,
                    mmt.provider_status AS mobile_provider_status,
                    mmt.amount_received AS mobile_amount_received,
                    mmt.updated_at AS mobile_updated_at,
                    bkt.transaction_ref AS bank_transaction_ref,
                    bkt.status AS bank_status,
                    bkt.payment_method_label AS bank_payment_method,
                    bkt.transfer_reference,
                    bkt.bank_name,
                    bkt.amount_submitted,
                    bkt.amount_expected,
                    bkt.verified_at AS bank_verified_at,
                    bkt.posted_at AS bank_posted_at,
                    bkt.failure_reason AS bank_failure_reason,
                    bkt.verification_notes AS bank_verification_notes,
                    bkt.updated_at AS bank_updated_at,
                    p.payment_id,
                    p.receipt_number,
                    p.payment_date
                FROM student_payment_references spr
                LEFT JOIN mobile_money_transactions mmt
                    ON mmt.id = (
                        SELECT m2.id
                        FROM mobile_money_transactions m2
                        WHERE m2.reference_number = spr.reference_number
                          AND m2.student_id = spr.student_id
                        ORDER BY m2.id DESC
                        LIMIT 1
                    )
                LEFT JOIN bank_transactions bkt
                    ON bkt.id = (
                        SELECT b2.id
                        FROM bank_transactions b2
                        WHERE b2.reference_number = spr.reference_number
                          AND b2.student_id = spr.student_id
                        ORDER BY b2.id DESC
                        LIMIT 1
                    )
                LEFT JOIN payments p
                    ON p.id = spr.paid_payment_id
                WHERE spr.student_id = :student_id
                  AND spr.reference_number = :reference_number
                LIMIT 1
            ");
            $stmt->execute([
                'student_id' => $studentId,
                'reference_number' => $referenceNumber
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (empty($row)) {
                return ['success' => false, 'message' => 'PRN not found.'];
            }

            $referenceStatus = strtolower((string)($row['reference_status'] ?? ''));
            $bankStatus = strtolower((string)($row['bank_status'] ?? ''));
            $mobileStatus = strtolower((string)($row['mobile_transaction_status'] ?? ''));
            $channel = 'none';
            $transactionStatus = '';
            $transactionRef = '';
            $providerStatus = '';
            $amountReceived = null;
            $transactionUpdatedAt = null;

            if ($bankStatus !== '') {
                $channel = 'bank_transfer';
                $transactionStatus = $bankStatus;
                $transactionRef = (string)($row['bank_transaction_ref'] ?? '');
                $amountReceived = $row['amount_submitted'] !== null ? (float)$row['amount_submitted'] : null;
                $transactionUpdatedAt = (string)($row['bank_updated_at'] ?? '');
            } elseif ($mobileStatus !== '') {
                $channel = 'mobile_money';
                $transactionStatus = $mobileStatus;
                $transactionRef = (string)($row['mobile_transaction_ref'] ?? '');
                $providerStatus = (string)($row['mobile_provider_status'] ?? '');
                $amountReceived = $row['mobile_amount_received'] !== null ? (float)$row['mobile_amount_received'] : null;
                $transactionUpdatedAt = (string)($row['mobile_updated_at'] ?? '');
            }

            if ($referenceStatus === 'paid') {
                if ($channel === 'none') {
                    if (!empty($row['bank_transaction_ref'])) {
                        $channel = 'bank_transfer';
                        $transactionRef = (string)$row['bank_transaction_ref'];
                    } elseif (!empty($row['mobile_transaction_ref'])) {
                        $channel = 'mobile_money';
                        $transactionRef = (string)$row['mobile_transaction_ref'];
                    }
                }
                $transactionStatus = 'posted';
            } elseif ($transactionStatus === '') {
                $transactionStatus = $referenceStatus !== '' ? $referenceStatus : 'active';
            }

            $row['transaction_channel'] = $channel;
            $row['transaction_ref'] = $transactionRef;
            $row['transaction_status'] = $transactionStatus;
            $row['provider_status'] = $providerStatus;
            $row['amount_received'] = $amountReceived;
            $row['transaction_updated_at'] = $transactionUpdatedAt;
            $row['status_stage'] = $this->buildReferenceStatusStage($referenceStatus, $transactionStatus, $channel);
            $row['financial_summary'] = $this->buildStudentFinancialSummaryPayload(
                $studentId,
                (int)($row['reference_semester_id'] ?? 0)
            );

            return ['success' => true, 'data' => $row];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Unable to fetch PRN status.', 'error' => $e->getMessage()];
        }
    }

    private function buildReferenceStatusStage($referenceStatus, $transactionStatus, $channel) {
        $referenceStatus = strtolower(trim((string)$referenceStatus));
        $transactionStatus = strtolower(trim((string)$transactionStatus));
        $channel = strtolower(trim((string)$channel));

        if ($referenceStatus === 'paid' || $transactionStatus === 'posted' || $transactionStatus === 'paid') {
            return 'Payment posted to ledger.';
        }

        if ($channel === 'bank_transfer') {
            if ($transactionStatus === 'received') {
                return 'Bank transfer proof received. Awaiting finance verification.';
            }
            if ($transactionStatus === 'verified') {
                return 'Bank transfer verified. Posting to ledger.';
            }
            if ($transactionStatus === 'failed') {
                return 'Bank transfer verification failed. Contact finance office.';
            }
            return 'Bank transfer is pending finance verification.';
        }

        if ($channel === 'mobile_money') {
            if ($transactionStatus === 'successful') {
                return 'Mobile money payment is successful and pending ledger posting.';
            }
            if (in_array($transactionStatus, ['failed', 'cancelled', 'expired'], true)) {
                return 'Mobile money request ended with status: ' . strtoupper($transactionStatus) . '.';
            }
            return 'Mobile money request is pending confirmation.';
        }

        if ($referenceStatus === 'expired') {
            return 'PRN has expired. Generate a new PRN.';
        }
        if ($referenceStatus === 'cancelled') {
            return 'PRN is cancelled.';
        }

        return 'PRN is active and awaiting payment.';
    }

    private function autoPostPaymentFromTransaction(array $transactionRow, $amountPaid) {
        $referenceNumber = strtoupper(trim((string)($transactionRow['reference_number'] ?? '')));
        if ($referenceNumber === '') {
            return ['success' => false, 'message' => 'Missing PRN on transaction row.'];
        }

        $paymentMethod = strtolower(trim((string)($transactionRow['payment_method'] ?? 'mobile_money')));
        if (!in_array($paymentMethod, ['mobile_money', 'bank_transfer', 'bank_agent', 'cente_agent', 'card', 'cash', 'cheque'], true)) {
            $paymentMethod = 'mobile_money';
        }
        $transactionTable = strtolower(trim((string)($transactionRow['transaction_table'] ?? 'mobile_money_transactions')));
        if (!in_array($transactionTable, ['mobile_money_transactions', 'bank_transactions'], true)) {
            $transactionTable = 'mobile_money_transactions';
        }
        $isBankMethod = in_array($paymentMethod, ['bank_transfer', 'bank_agent', 'cente_agent'], true);
        $sourceLabel = $isBankMethod ? 'bank transfer verification' : 'mobile money webhook';
        $sourceTxLabel = $isBankMethod ? 'Bank Ref' : 'Provider TX';

        $reference = $this->findReferenceByNumber($referenceNumber, true);
        if (empty($reference)) {
            return ['success' => false, 'message' => 'PRN record not found.'];
        }

        $studentId = (int)($reference['student_id'] ?? 0);
        $referenceType = strtolower((string)($reference['reference_type'] ?? 'deposit'));
        $referenceAmount = (float)($reference['amount'] ?? 0);
        $amountPaid = (float)$amountPaid;
        if ($amountPaid <= 0) {
            $amountPaid = $referenceAmount;
        }
        if ($studentId <= 0 || $amountPaid <= 0) {
            return ['success' => false, 'message' => 'Cannot auto-post payment with invalid student/amount.'];
        }

        $systemFinanceId = $this->resolveSystemFinanceStaffId();
        if ($systemFinanceId <= 0) {
            return ['success' => false, 'message' => 'No finance staff profile available for auto-posting.'];
        }

        $hasVerificationColumns = $this->hasPaymentVerificationColumns();
        $note = 'Auto-posted from ' . $sourceLabel . '. TX: ' . (string)($transactionRow['transaction_ref'] ?? '-');
        $providerTx = trim((string)($transactionRow['provider_tx_id'] ?? ''));
        if ($providerTx !== '') {
            $note .= '. ' . $sourceTxLabel . ': ' . $providerTx;
        }

        $createdPaymentIds = [];
        $remaining = $amountPaid;
        $paymentDate = date('Y-m-d');

        if ($referenceType === 'all_pending') {
            $invoiceStmt = $this->conn->prepare("
                SELECT id, semester_id, total_amount, amount_paid, balance
                FROM invoices
                WHERE student_id = :student_id
                  AND balance > 0
                  AND status IN ('pending','partial','overdue')
                ORDER BY due_date ASC, id ASC
                FOR UPDATE
            ");
            $invoiceStmt->execute(['student_id' => $studentId]);
            $invoiceRows = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($invoiceRows as $invoiceRow) {
                if ($remaining <= 0) {
                    break;
                }
                $invoiceBalance = (float)($invoiceRow['balance'] ?? 0);
                if ($invoiceBalance <= 0) {
                    continue;
                }
                $apply = min($remaining, $invoiceBalance);
                if ($apply <= 0) {
                    continue;
                }

                $paymentId = $this->insertPaymentRow(
                    $studentId,
                    (int)($invoiceRow['id'] ?? 0),
                    (int)($invoiceRow['semester_id'] ?? 0),
                    $apply,
                    $paymentDate,
                    $referenceNumber,
                    $systemFinanceId,
                    $note,
                    $hasVerificationColumns,
                    $paymentMethod,
                    $isBankMethod
                        ? 'Auto-verified from bank transfer confirmation.'
                        : 'Auto-verified from mobile money webhook.'
                );
                $createdPaymentIds[] = $paymentId;

                $newPaid = (float)($invoiceRow['amount_paid'] ?? 0) + $apply;
                $invoiceTotal = (float)($invoiceRow['total_amount'] ?? 0);
                $newBalance = max($invoiceTotal - $newPaid, 0);
                $newStatus = $newBalance <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'pending');

                $updInvStmt = $this->conn->prepare("
                    UPDATE invoices
                    SET amount_paid = :amount_paid,
                        balance = :balance,
                        status = :status,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $updInvStmt->execute([
                    'amount_paid' => $newPaid,
                    'balance' => $newBalance,
                    'status' => $newStatus,
                    'id' => (int)$invoiceRow['id']
                ]);

                $this->syncStudentBalance((int)$studentId, (int)($invoiceRow['semester_id'] ?? 0));
                $remaining -= $apply;
            }
        } elseif ($referenceType === 'partial_invoice') {
            $invoiceId = (int)($reference['invoice_id'] ?? 0);
            if ($invoiceId > 0) {
                $invoiceStmt = $this->conn->prepare("
                    SELECT id, semester_id, total_amount, amount_paid, balance
                    FROM invoices
                    WHERE id = :id
                      AND student_id = :student_id
                    LIMIT 1
                    FOR UPDATE
                ");
                $invoiceStmt->execute(['id' => $invoiceId, 'student_id' => $studentId]);
                $invoiceRow = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                if (!empty($invoiceRow) && (float)($invoiceRow['balance'] ?? 0) > 0 && $remaining > 0) {
                    $apply = min($remaining, (float)($invoiceRow['balance'] ?? 0));
                    $paymentId = $this->insertPaymentRow(
                        $studentId,
                        $invoiceId,
                        (int)($invoiceRow['semester_id'] ?? 0),
                        $apply,
                        $paymentDate,
                        $referenceNumber,
                        $systemFinanceId,
                        $note,
                        $hasVerificationColumns,
                        $paymentMethod,
                        $isBankMethod
                            ? 'Auto-verified from bank transfer confirmation.'
                            : 'Auto-verified from mobile money webhook.'
                    );
                    $createdPaymentIds[] = $paymentId;

                    $newPaid = (float)($invoiceRow['amount_paid'] ?? 0) + $apply;
                    $invoiceTotal = (float)($invoiceRow['total_amount'] ?? 0);
                    $newBalance = max($invoiceTotal - $newPaid, 0);
                    $newStatus = $newBalance <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'pending');

                    $updInvStmt = $this->conn->prepare("
                        UPDATE invoices
                        SET amount_paid = :amount_paid,
                            balance = :balance,
                            status = :status,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $updInvStmt->execute([
                        'amount_paid' => $newPaid,
                        'balance' => $newBalance,
                        'status' => $newStatus,
                        'id' => $invoiceId
                    ]);
                    $this->syncStudentBalance($studentId, (int)($invoiceRow['semester_id'] ?? 0));
                    $remaining -= $apply;
                }
            }
        }

        if ($remaining > 0 || $referenceType === 'deposit') {
            $semesterId = (int)($reference['semester_id'] ?? 0);
            if ($semesterId <= 0) {
                $currentSemester = Helper::getCurrentSemester();
                $semesterId = (int)($currentSemester['id'] ?? 0);
            }
            if ($semesterId <= 0) {
                return ['success' => false, 'message' => 'No semester context for deposit auto-post.'];
            }

            $depositAmount = $referenceType === 'deposit' ? $amountPaid : max($remaining, 0);
            if ($depositAmount > 0) {
                $paymentId = $this->insertPaymentRow(
                    $studentId,
                    null,
                    $semesterId,
                    $depositAmount,
                    $paymentDate,
                    $referenceNumber,
                    $systemFinanceId,
                    $note,
                    $hasVerificationColumns,
                    $paymentMethod,
                    $isBankMethod
                        ? 'Auto-verified from bank transfer confirmation.'
                        : 'Auto-verified from mobile money webhook.'
                );
                $createdPaymentIds[] = $paymentId;
                $this->syncStudentBalance($studentId, $semesterId);
            }
        }

        if (empty($createdPaymentIds)) {
            return ['success' => false, 'message' => 'No payment rows were created during auto-post.'];
        }

        $firstPaymentId = (int)$createdPaymentIds[0];
        $updateRefStmt = $this->conn->prepare("
            UPDATE student_payment_references
            SET status = 'paid',
                paid_payment_id = :paid_payment_id,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updateRefStmt->execute([
            'paid_payment_id' => $firstPaymentId,
            'id' => (int)($reference['id'] ?? 0)
        ]);

        if ($transactionTable === 'bank_transactions') {
            $updateTxStmt = $this->conn->prepare("
                UPDATE bank_transactions
                SET status = 'posted',
                    posted_payment_id = :posted_payment_id,
                    posted_at = NOW(),
                    failure_reason = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $updateTxStmt->execute([
                'posted_payment_id' => $firstPaymentId,
                'id' => (int)($transactionRow['id'] ?? 0)
            ]);
        } else {
            $updateTxStmt = $this->conn->prepare("
                UPDATE mobile_money_transactions
                SET status = 'posted',
                    posted_payment_id = :posted_payment_id,
                    posted_at = NOW(),
                    failure_reason = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $updateTxStmt->execute([
                'posted_payment_id' => $firstPaymentId,
                'id' => (int)($transactionRow['id'] ?? 0)
            ]);
        }

        $studentUserId = $this->resolveStudentUserId($studentId);
        if ($studentUserId > 0) {
                $this->notifyUser(
                    $studentUserId,
                $isBankMethod ? 'Bank Transfer Posted' : 'Mobile Money Payment Posted',
                    'Your payment for PRN ' . $referenceNumber . ' has been received and posted to your ledger.',
                    'success',
                    BASE_URL . '/views/student/payments.php?section=transactions'
                );
        }
        $this->notifyFinanceTeamPaymentPosted(
            $studentId,
            $referenceNumber,
            (float)$amountPaid,
            $paymentMethod,
            (string)($transactionRow['transaction_ref'] ?? '')
        );

        return [
            'success' => true,
            'posted_payment_id' => $firstPaymentId,
            'posted_payment_count' => count($createdPaymentIds)
        ];
    }

    private function buildStudentFinancialSummaryPayload($studentId, $semesterId = 0) {
        $studentId = (int)$studentId;
        $semesterId = (int)$semesterId;
        if ($studentId <= 0) {
            return [
                'approved_fees_ugx' => 0.0,
                'total_paid_ugx' => 0.0,
                'balance_on_account_ugx' => 0.0,
                'balance_due_ugx' => 0.0,
                'account_credit_ugx' => 0.0,
                'semester_id' => 0
            ];
        }

        try {
            $snapshot = getStudentFinancialSnapshot($this->conn, $studentId, $semesterId, 0, 0, 1, true);
            if (!is_array($snapshot)) {
                $snapshot = $this->buildFinancialSnapshotFallback($studentId, $semesterId);
                $snapshot['semester_id'] = $semesterId;
            }
        } catch (Exception $e) {
            $snapshot = $this->buildFinancialSnapshotFallback($studentId, $semesterId);
            $snapshot['semester_id'] = $semesterId;
        }

        return [
            'approved_fees_ugx' => (float)($snapshot['approved_total_fees'] ?? $snapshot['total_fees'] ?? 0.0),
            'total_paid_ugx' => (float)($snapshot['total_paid'] ?? 0.0),
            'balance_on_account_ugx' => (float)($snapshot['balance_on_account'] ?? $snapshot['balance_due'] ?? 0.0),
            'balance_due_ugx' => (float)($snapshot['balance_due'] ?? 0.0),
            'account_credit_ugx' => (float)($snapshot['account_credit'] ?? 0.0),
            'semester_id' => (int)($snapshot['semester_id'] ?? $semesterId),
            'source' => (string)($snapshot['source'] ?? '')
        ];
    }

    private function notifyFinanceTeamBankProofSubmitted($studentId, $referenceNumber, $amountSubmitted, $paymentMethod, $transactionRef = '', $transferReference = '') {
        $studentId = (int)$studentId;
        $referenceNumber = strtoupper(trim((string)$referenceNumber));
        $amountSubmitted = (float)$amountSubmitted;
        $paymentMethod = strtolower(trim((string)$paymentMethod));
        $transactionRef = strtoupper(trim((string)$transactionRef));
        $transferReference = strtoupper(trim((string)$transferReference));
        if ($studentId <= 0 || $referenceNumber === '') {
            return;
        }

        $financeUserIds = $this->resolveFinanceUserIds();
        if (empty($financeUserIds)) {
            return;
        }

        $studentLabel = $this->resolveStudentLabelForNotification($studentId);
        $amountLabel = $amountSubmitted > 0 ? Helper::formatCurrency($amountSubmitted, 'UGX', 0) : 'UGX -';
        $methodLabel = strtoupper(str_replace('_', ' ', $paymentMethod !== '' ? $paymentMethod : 'bank_transfer'));
        $txPiece = $transactionRef !== '' ? (' TX: ' . $transactionRef . '.') : '';
        $bankRefPiece = $transferReference !== '' ? (' Bank Ref: ' . $transferReference . '.') : '';
        $title = 'Bank Proof Submitted';
        $message = $studentLabel . ' submitted bank payment proof for PRN ' . $referenceNumber . ' (' . $amountLabel . ', ' . $methodLabel . ').' . $txPiece . $bankRefPiece;
        $link = BASE_URL . '/views/finance/dashboard.php?section=payments#bank-verification-section';

        foreach (array_keys($financeUserIds) as $financeUserId) {
            $this->notifyUser((int)$financeUserId, $title, $message, 'info', $link);
        }
    }

    private function resolveFinanceUserIds() {
        $financeUserIds = [];
        try {
            $stmt = $this->conn->query("
                SELECT DISTINCT fs.user_id
                FROM finance_staff fs
                INNER JOIN users u ON u.id = fs.user_id
                WHERE u.role = 'finance'
                  AND COALESCE(u.status, 'active') = 'active'
            ");
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($rows as $row) {
                $uid = (int)($row['user_id'] ?? 0);
                if ($uid > 0) {
                    $financeUserIds[$uid] = true;
                }
            }
        } catch (Exception $e) {
        }

        if (empty($financeUserIds)) {
            try {
                $stmt = $this->conn->query("
                    SELECT id
                    FROM users
                    WHERE role = 'finance'
                      AND COALESCE(status, 'active') = 'active'
                ");
                $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                foreach ($rows as $row) {
                    $uid = (int)($row['id'] ?? 0);
                    if ($uid > 0) {
                        $financeUserIds[$uid] = true;
                    }
                }
            } catch (Exception $e) {
                // Backward-compatible fallback when users.status column is absent.
                try {
                    $stmt = $this->conn->query("
                        SELECT id
                        FROM users
                        WHERE role = 'finance'
                    ");
                    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                    foreach ($rows as $row) {
                        $uid = (int)($row['id'] ?? 0);
                        if ($uid > 0) {
                            $financeUserIds[$uid] = true;
                        }
                    }
                } catch (Exception $ignored) {
                }
            }
        }

        return $financeUserIds;
    }

    private function resolveStudentLabelForNotification($studentId) {
        $studentId = (int)$studentId;
        $studentLabel = 'Student #' . $studentId;
        if ($studentId <= 0) {
            return $studentLabel;
        }

        try {
            $studentStmt = $this->conn->prepare("
                SELECT student_id, first_name, last_name
                FROM students
                WHERE id = :id
                LIMIT 1
            ");
            $studentStmt->execute(['id' => $studentId]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($student)) {
                $name = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
                $sid = trim((string)($student['student_id'] ?? ''));
                if ($name !== '' && $sid !== '') {
                    return $name . ' (' . $sid . ')';
                }
                if ($name !== '') {
                    return $name;
                }
                if ($sid !== '') {
                    return $sid;
                }
            }
        } catch (Exception $e) {
        }

        return $studentLabel;
    }

    private function notifyFinanceTeamPaymentPosted($studentId, $referenceNumber, $amountPaid, $paymentMethod, $transactionRef = '') {
        $studentId = (int)$studentId;
        $referenceNumber = strtoupper(trim((string)$referenceNumber));
        $amountPaid = (float)$amountPaid;
        $paymentMethod = strtolower(trim((string)$paymentMethod));
        $transactionRef = trim((string)$transactionRef);

        if ($studentId <= 0 || $referenceNumber === '' || $amountPaid <= 0) {
            return;
        }

        $financeUserIds = $this->resolveFinanceUserIds();
        if (empty($financeUserIds)) {
            return;
        }

        $studentLabel = $this->resolveStudentLabelForNotification($studentId);

        $amountLabel = Helper::formatCurrency($amountPaid, 'UGX', 0);
        $methodLabel = strtoupper(str_replace('_', ' ', $paymentMethod));
        $txLabel = $transactionRef !== '' ? ' TX: ' . $transactionRef . '.' : '';
        $title = 'PRN Payment Posted';
        $message = 'PRN ' . $referenceNumber . ' for ' . $studentLabel . ' amount ' . $amountLabel . ' via ' . $methodLabel . ' has been posted to ledger.' . $txLabel;
        $link = BASE_URL . '/views/finance/dashboard.php?section=payments#payments-section';

        foreach (array_keys($financeUserIds) as $financeUserId) {
            $this->notifyUser((int)$financeUserId, $title, $message, 'info', $link);
        }
    }

    private function insertPaymentRow($studentId, $invoiceId, $semesterId, $amount, $paymentDate, $referenceNumber, $financeStaffId, $note, $hasVerificationColumns, $paymentMethod = 'mobile_money', $verificationNotes = '') {
        $paymentId = $this->generateUniqueCode('PAY', 'payments', 'payment_id');
        $receiptNumber = $this->generateUniqueCode('RCP', 'payments', 'receipt_number');
        $paymentMethod = strtolower(trim((string)$paymentMethod));
        if ($paymentMethod === '') {
            $paymentMethod = 'mobile_money';
        }
        $verificationNotes = trim((string)$verificationNotes);
        if ($verificationNotes === '') {
            $verificationNotes = 'Auto-verified from mobile money webhook.';
        }

        if ($hasVerificationColumns) {
            $stmt = $this->conn->prepare("
                INSERT INTO payments (
                    payment_id, student_id, invoice_id, amount, payment_date, payment_method, reference_number,
                    received_by, semester_id, notes, verification_status, verified_by, verified_at, verification_notes,
                    receipt_number, created_at, updated_at
                )
                VALUES (
                    :payment_id, :student_id, :invoice_id, :amount, :payment_date, :payment_method, :reference_number,
                    :received_by, :semester_id, :notes, 'verified', :verified_by, NOW(), :verification_notes,
                    :receipt_number, NOW(), NOW()
                )
            ");
            $stmt->execute([
                'payment_id' => $paymentId,
                'student_id' => (int)$studentId,
                'invoice_id' => $invoiceId ? (int)$invoiceId : null,
                'amount' => (float)$amount,
                'payment_date' => $paymentDate,
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'received_by' => (int)$financeStaffId,
                'semester_id' => (int)$semesterId,
                'notes' => $note,
                'verified_by' => (int)$financeStaffId,
                'verification_notes' => $verificationNotes,
                'receipt_number' => $receiptNumber
            ]);
        } else {
            $stmt = $this->conn->prepare("
                INSERT INTO payments (
                    payment_id, student_id, invoice_id, amount, payment_date, payment_method, reference_number,
                    received_by, semester_id, notes, receipt_number, created_at, updated_at
                )
                VALUES (
                    :payment_id, :student_id, :invoice_id, :amount, :payment_date, :payment_method, :reference_number,
                    :received_by, :semester_id, :notes, :receipt_number, NOW(), NOW()
                )
            ");
            $stmt->execute([
                'payment_id' => $paymentId,
                'student_id' => (int)$studentId,
                'invoice_id' => $invoiceId ? (int)$invoiceId : null,
                'amount' => (float)$amount,
                'payment_date' => $paymentDate,
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'received_by' => (int)$financeStaffId,
                'semester_id' => (int)$semesterId,
                'notes' => $note,
                'receipt_number' => $receiptNumber
            ]);
        }

        return (int)$this->conn->lastInsertId();
    }

    private function extractWebhookFields(array $payload) {
        $fields = [
            'reference_number' => '',
            'transaction_ref' => '',
            'provider_request_id' => '',
            'provider_tx_id' => '',
            'provider_status' => '',
            'amount_received' => 0.0
        ];

        $fields['reference_number'] = strtoupper(trim((string)$this->payloadValue($payload, [
            'reference_number',
            'prn',
            'reference',
            'order_id',
            'external_id',
            'data.reference_number',
            'data.reference',
            'data.prn',
            'data.external_id',
            'data.order_id',
            'transaction.reference',
            'transaction.external_id',
            'metadata.reference_number',
            'metadata.prn'
        ])));
        $fields['transaction_ref'] = strtoupper(trim((string)$this->payloadValue($payload, [
            'transaction_ref',
            'internal_ref',
            'merchant_ref',
            'meta.transaction_ref',
            'metadata.transaction_ref',
            'data.transaction_ref',
            'data.internal_ref'
        ])));
        $fields['provider_request_id'] = trim((string)$this->payloadValue($payload, [
            'provider_request_id',
            'request_id',
            'conversation_id',
            'reference_id',
            'request.reference_id',
            'data.provider_request_id',
            'data.request_id',
            'data.conversation_id'
        ]));
        $fields['provider_tx_id'] = trim((string)$this->payloadValue($payload, [
            'provider_tx_id',
            'provider_transaction_id',
            'transaction_id',
            'financial_transaction_id',
            'external_transaction_id',
            'data.provider_tx_id',
            'data.provider_transaction_id',
            'data.transaction_id',
            'transaction.id'
        ]));
        $fields['provider_status'] = strtolower(trim((string)$this->payloadValue($payload, [
            'status',
            'provider_status',
            'transaction_status',
            'data.status',
            'data.provider_status',
            'transaction.status',
            'result_code'
        ])));
        $fields['amount_received'] = (float)str_replace(',', '', (string)$this->payloadValue($payload, [
            'amount',
            'amount_received',
            'data.amount',
            'transaction.amount',
            'transaction.value'
        ], 0));

        return $fields;
    }

    private function findTransactionForWebhook(array $fields) {
        $clauses = [];
        $params = [];
        if (!empty($fields['transaction_ref'])) {
            $clauses[] = "transaction_ref = :transaction_ref";
            $params['transaction_ref'] = $fields['transaction_ref'];
        }
        if (!empty($fields['provider_request_id'])) {
            $clauses[] = "provider_request_id = :provider_request_id";
            $params['provider_request_id'] = $fields['provider_request_id'];
        }
        if (!empty($fields['provider_tx_id'])) {
            $clauses[] = "provider_tx_id = :provider_tx_id";
            $params['provider_tx_id'] = $fields['provider_tx_id'];
        }
        if (!empty($fields['reference_number'])) {
            $clauses[] = "reference_number = :reference_number";
            $params['reference_number'] = $fields['reference_number'];
        }
        if (empty($clauses)) {
            return [];
        }

        $sql = "SELECT * FROM mobile_money_transactions WHERE (" . implode(' OR ', $clauses) . ") ORDER BY id DESC LIMIT 1 FOR UPDATE";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function createAdhocTransactionFromWebhook(array $reference, array $fields, array $payload) {
        $transactionRef = !empty($fields['transaction_ref']) ? $fields['transaction_ref'] : $this->generateUniqueCode('MMT', 'mobile_money_transactions', 'transaction_ref');
        $insertStmt = $this->conn->prepare("
            INSERT INTO mobile_money_transactions (
                transaction_ref,
                reference_number,
                student_id,
                invoice_id,
                semester_id,
                provider,
                msisdn,
                currency_code,
                amount_expected,
                amount_received,
                provider_request_id,
                provider_tx_id,
                provider_status,
                status,
                callback_payload,
                created_at,
                updated_at
            ) VALUES (
                :transaction_ref,
                :reference_number,
                :student_id,
                :invoice_id,
                :semester_id,
                :provider,
                NULL,
                'UGX',
                :amount_expected,
                :amount_received,
                :provider_request_id,
                :provider_tx_id,
                :provider_status,
                'pending',
                :callback_payload,
                NOW(),
                NOW()
            )
        ");
        $insertStmt->execute([
            'transaction_ref' => $transactionRef,
            'reference_number' => (string)$reference['reference_number'],
            'student_id' => (int)($reference['student_id'] ?? 0),
            'invoice_id' => !empty($reference['invoice_id']) ? (int)$reference['invoice_id'] : null,
            'semester_id' => !empty($reference['semester_id']) ? (int)$reference['semester_id'] : null,
            'provider' => $this->defaultProvider,
            'amount_expected' => (float)($reference['amount'] ?? 0),
            'amount_received' => $fields['amount_received'] > 0 ? (float)$fields['amount_received'] : null,
            'provider_request_id' => !empty($fields['provider_request_id']) ? (string)$fields['provider_request_id'] : null,
            'provider_tx_id' => !empty($fields['provider_tx_id']) ? (string)$fields['provider_tx_id'] : null,
            'provider_status' => !empty($fields['provider_status']) ? (string)$fields['provider_status'] : 'pending',
            'callback_payload' => json_encode($payload)
        ]);

        $id = (int)$this->conn->lastInsertId();
        $stmt = $this->conn->prepare("SELECT * FROM mobile_money_transactions WHERE id = :id LIMIT 1 FOR UPDATE");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function findReferenceByNumber($referenceNumber, $forUpdate = false) {
        $sql = "
            SELECT *
            FROM student_payment_references
            WHERE reference_number = :reference_number
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute(['reference_number' => strtoupper(trim((string)$referenceNumber))]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function mapProviderStatusToInternal($providerStatus) {
        $status = strtolower(trim((string)$providerStatus));
        if ($status === '') {
            return 'pending';
        }

        $successSet = ['success', 'successful', 'completed', 'complete', 'paid', 'ok'];
        $failedSet = ['failed', 'fail', 'error', 'declined', 'rejected'];
        $cancelSet = ['cancelled', 'canceled', 'aborted'];
        $expiredSet = ['expired', 'timeout', 'timed_out'];

        if (in_array($status, $successSet, true)) {
            return 'successful';
        }
        if (in_array($status, $failedSet, true)) {
            return 'failed';
        }
        if (in_array($status, $cancelSet, true)) {
            return 'cancelled';
        }
        if (in_array($status, $expiredSet, true)) {
            return 'expired';
        }
        return 'pending';
    }

    private function verifyWebhookSignature(array $headers, $rawBody) {
        $secret = trim((string)$this->webhookSecret);
        if ($secret === '' || $this->gatewayMode === 'mock') {
            return ['ok' => true, 'message' => 'Signature validation bypassed.'];
        }

        $normalized = [];
        foreach ($headers as $key => $value) {
            $k = strtolower(trim((string)$key));
            if ($k === '') {
                continue;
            }
            if (is_array($value)) {
                $normalized[$k] = (string)($value[0] ?? '');
            } else {
                $normalized[$k] = (string)$value;
            }
        }
        if (empty($normalized) && function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                foreach ($all as $key => $value) {
                    $normalized[strtolower(trim((string)$key))] = (string)$value;
                }
            }
        }

        $signature = '';
        foreach (['x-webhook-signature', 'x-signature', 'x-pay-signature', 'x-hub-signature-256'] as $headerName) {
            if (!empty($normalized[$headerName])) {
                $signature = trim((string)$normalized[$headerName]);
                break;
            }
        }
        if ($signature === '') {
            return ['ok' => false, 'message' => 'Missing webhook signature header.'];
        }

        if (stripos($signature, 'sha256=') === 0) {
            $signature = trim(substr($signature, 7));
        }

        $expected = hash_hmac('sha256', (string)$rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'message' => 'Invalid webhook signature.'];
        }

        return ['ok' => true, 'message' => 'Signature verified.'];
    }

    private function sendProviderInitiationRequest(array $requestPayload) {
        $provider = $this->normalizeProviderName((string)($requestPayload['provider'] ?? $this->defaultProvider));

        $seed = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 10));
        $providerRequestId = 'REQ' . date('ymdHis') . substr($seed, 0, 4);
        $providerTxId = 'PTX' . date('ymdHis') . substr($seed, 4, 4);

        if ($this->gatewayMode === 'mock') {
            return [
                'status' => 'pending',
                'provider_status' => 'pending',
                'provider_request_id' => $providerRequestId,
                'provider_tx_id' => $providerTxId,
                'provider' => $provider,
                'message' => 'Mock gateway request accepted.'
            ];
        }

        if ($provider === 'mtn') {
            return $this->sendMtnInitiationRequest($requestPayload);
        }
        if ($provider === 'airtel') {
            return $this->sendAirtelInitiationRequest($requestPayload);
        }

        return [
            'status' => 'failed',
            'provider_status' => 'unsupported_provider',
            'provider' => $provider,
            'message' => 'Unsupported mobile money provider: ' . $provider
        ];
    }

    private function sendMtnInitiationRequest(array $requestPayload) {
        $endpoint = defined('MOBILE_MONEY_MTN_INITIATE_URL') ? trim((string)MOBILE_MONEY_MTN_INITIATE_URL) : '';
        if (!$this->isValidHttpUrl($endpoint) || $this->isPlaceholderConfigValue($endpoint)) {
            return [
                'status' => 'failed',
                'provider_status' => 'config_error',
                'provider' => 'mtn',
                'message' => 'MTN initiation URL is invalid or still set to a placeholder. Update MOBILE_MONEY_MTN_INITIATE_URL.'
            ];
        }

        $callbackUrl = $this->resolveWebhookUrl();
        if (!$this->isValidPublicWebhookUrl($callbackUrl)) {
            return [
                'status' => 'failed',
                'provider_status' => 'config_error',
                'provider' => 'mtn',
                'message' => 'Webhook URL must be a public https/http URL (not localhost/placeholder). Update PAYMENT_GATEWAY_WEBHOOK_URL.'
            ];
        }

        $bearer = defined('MOBILE_MONEY_MTN_BEARER_TOKEN') ? trim((string)MOBILE_MONEY_MTN_BEARER_TOKEN) : '';
        $apiKey = defined('MOBILE_MONEY_MTN_API_KEY') ? trim((string)MOBILE_MONEY_MTN_API_KEY) : '';
        $apiSecret = defined('MOBILE_MONEY_MTN_API_SECRET') ? trim((string)MOBILE_MONEY_MTN_API_SECRET) : '';

        $missingFields = [];
        if ($this->isPlaceholderConfigValue($bearer)) {
            $missingFields[] = 'MOBILE_MONEY_MTN_BEARER_TOKEN';
        }
        if ($this->isPlaceholderConfigValue($apiKey)) {
            $missingFields[] = 'MOBILE_MONEY_MTN_API_KEY';
        }
        if ($this->isPlaceholderConfigValue($apiSecret)) {
            $missingFields[] = 'MOBILE_MONEY_MTN_API_SECRET';
        }
        if (!empty($missingFields)) {
            return [
                'status' => 'failed',
                'provider_status' => 'config_error',
                'provider' => 'mtn',
                'message' => 'MTN gateway credentials are missing/placeholder: ' . implode(', ', $missingFields) . '.'
            ];
        }

        $payload = [
            'external_id' => (string)($requestPayload['reference_number'] ?? ''),
            'amount' => number_format((float)($requestPayload['amount'] ?? 0), 2, '.', ''),
            'currency' => (string)($requestPayload['currency'] ?? 'UGX'),
            'payer' => [
                'party_id_type' => 'MSISDN',
                'party_id' => (string)($requestPayload['msisdn'] ?? '')
            ],
            'payer_message' => 'Tuition payment ' . (string)($requestPayload['reference_number'] ?? ''),
            'payee_note' => 'PRN ' . (string)($requestPayload['reference_number'] ?? ''),
            'metadata' => [
                'transaction_ref' => (string)($requestPayload['transaction_ref'] ?? ''),
                'reference_number' => (string)($requestPayload['reference_number'] ?? '')
            ],
            'callback_url' => $callbackUrl
        ];

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($bearer !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        if ($apiKey !== '') {
            $headers[] = 'X-API-Key: ' . $apiKey;
        }
        if ($apiSecret !== '') {
            $headers[] = 'X-API-Secret: ' . $apiSecret;
        }

        $httpResult = $this->httpPostJson($endpoint, $payload, $headers);
        return $this->normalizeInitiationResult('mtn', $payload, $httpResult);
    }

    private function sendAirtelInitiationRequest(array $requestPayload) {
        $endpoint = defined('MOBILE_MONEY_AIRTEL_INITIATE_URL') ? trim((string)MOBILE_MONEY_AIRTEL_INITIATE_URL) : '';
        if (!$this->isValidHttpUrl($endpoint) || $this->isPlaceholderConfigValue($endpoint)) {
            return [
                'status' => 'failed',
                'provider_status' => 'config_error',
                'provider' => 'airtel',
                'message' => 'Airtel initiation URL is invalid or still set to a placeholder. Update MOBILE_MONEY_AIRTEL_INITIATE_URL.'
            ];
        }

        $countryCode = defined('MOBILE_MONEY_AIRTEL_COUNTRY_CODE') ? trim((string)MOBILE_MONEY_AIRTEL_COUNTRY_CODE) : 'UG';
        if ($countryCode === '') {
            $countryCode = 'UG';
        }

        $callbackUrl = $this->resolveWebhookUrl();
        if (!$this->isValidPublicWebhookUrl($callbackUrl)) {
            return [
                'status' => 'failed',
                'provider_status' => 'config_error',
                'provider' => 'airtel',
                'message' => 'Webhook URL must be a public https/http URL (not localhost/placeholder). Update PAYMENT_GATEWAY_WEBHOOK_URL.'
            ];
        }

        $bearer = defined('MOBILE_MONEY_AIRTEL_BEARER_TOKEN') ? trim((string)MOBILE_MONEY_AIRTEL_BEARER_TOKEN) : '';
        $clientId = defined('MOBILE_MONEY_AIRTEL_CLIENT_ID') ? trim((string)MOBILE_MONEY_AIRTEL_CLIENT_ID) : '';
        $clientSecret = defined('MOBILE_MONEY_AIRTEL_CLIENT_SECRET') ? trim((string)MOBILE_MONEY_AIRTEL_CLIENT_SECRET) : '';

        $missingFields = [];
        if ($this->isPlaceholderConfigValue($bearer)) {
            $missingFields[] = 'MOBILE_MONEY_AIRTEL_BEARER_TOKEN';
        }
        if ($this->isPlaceholderConfigValue($clientId)) {
            $missingFields[] = 'MOBILE_MONEY_AIRTEL_CLIENT_ID';
        }
        if ($this->isPlaceholderConfigValue($clientSecret)) {
            $missingFields[] = 'MOBILE_MONEY_AIRTEL_CLIENT_SECRET';
        }
        if (!empty($missingFields)) {
            return [
                'status' => 'failed',
                'provider_status' => 'config_error',
                'provider' => 'airtel',
                'message' => 'Airtel gateway credentials are missing/placeholder: ' . implode(', ', $missingFields) . '.'
            ];
        }

        $payload = [
            'reference' => (string)($requestPayload['reference_number'] ?? ''),
            'transaction' => [
                'id' => (string)($requestPayload['transaction_ref'] ?? ''),
                'amount' => number_format((float)($requestPayload['amount'] ?? 0), 2, '.', ''),
                'currency' => (string)($requestPayload['currency'] ?? 'UGX')
            ],
            'subscriber' => [
                'msisdn' => (string)($requestPayload['msisdn'] ?? ''),
                'country' => strtoupper($countryCode)
            ],
            'narration' => 'PRN ' . (string)($requestPayload['reference_number'] ?? ''),
            'callback_url' => $callbackUrl,
            'metadata' => [
                'reference_number' => (string)($requestPayload['reference_number'] ?? ''),
                'transaction_ref' => (string)($requestPayload['transaction_ref'] ?? '')
            ]
        ];

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($bearer !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        if ($clientId !== '') {
            $headers[] = 'X-Client-Id: ' . $clientId;
        }
        if ($clientSecret !== '') {
            $headers[] = 'X-Client-Secret: ' . $clientSecret;
        }

        $httpResult = $this->httpPostJson($endpoint, $payload, $headers);
        return $this->normalizeInitiationResult('airtel', $payload, $httpResult);
    }

    private function normalizeInitiationResult($provider, array $requestPayload, array $httpResult) {
        $httpCode = (int)($httpResult['http_code'] ?? 0);
        $response = is_array($httpResult['response'] ?? null) ? $httpResult['response'] : [];
        $raw = (string)($httpResult['raw_response'] ?? '');
        $error = trim((string)($httpResult['error'] ?? ''));

        $providerRequestId = trim((string)$this->payloadValue($response, [
            'provider_request_id',
            'request_id',
            'conversation_id',
            'reference_id',
            'id',
            'data.request_id',
            'data.reference_id'
        ], ''));
        $providerTxId = trim((string)$this->payloadValue($response, [
            'provider_tx_id',
            'provider_transaction_id',
            'transaction_id',
            'financial_transaction_id',
            'external_transaction_id',
            'data.transaction_id',
            'data.provider_tx_id',
            'transaction.id'
        ], ''));
        $providerStatus = strtolower(trim((string)$this->payloadValue($response, [
            'provider_status',
            'status',
            'transaction_status',
            'result.status',
            'data.status',
            'data.provider_status',
            'message'
        ], 'pending')));

        if ($providerRequestId === '') {
            $providerRequestId = 'REQ' . date('ymdHis') . strtoupper(substr(md5((string)($requestPayload['transaction_ref'] ?? uniqid('', true))), 0, 4));
        }

        $acceptedStatuses = [
            'pending', 'queued', 'accepted', 'processing', 'initiated', 'request_accepted', 'success', 'successful'
        ];
        $mapped = in_array($providerStatus, $acceptedStatuses, true) ? 'pending' : 'failed';

        if ($httpCode >= 400 || $error !== '') {
            $mapped = 'failed';
            if ($providerStatus === '' || $providerStatus === 'pending') {
                $providerStatus = 'http_' . ($httpCode > 0 ? $httpCode : 'error');
            }
            if ($providerStatus === 'http_error') {
                if (stripos($error, 'could not resolve host') !== false || stripos($error, 'name or service not known') !== false) {
                    $providerStatus = 'config_error';
                } elseif (stripos($error, 'ssl') !== false || stripos($error, 'certificate') !== false) {
                    $providerStatus = 'config_error';
                }
            }
        }

        $message = trim((string)$this->payloadValue($response, ['message', 'status_message', 'description', 'data.message'], ''));
        if ($message === '') {
            $message = $mapped === 'pending'
                ? strtoupper($provider) . ' payment request submitted.'
                : strtoupper($provider) . ' payment initiation failed.';
        }
        if ($providerStatus === 'config_error' && $error !== '') {
            $message = strtoupper($provider) . ' gateway configuration/connectivity error: ' . $error;
        }

        return [
            'status' => $mapped,
            'provider_status' => $providerStatus !== '' ? $providerStatus : ($mapped === 'pending' ? 'pending' : 'failed'),
            'provider_request_id' => $providerRequestId,
            'provider_tx_id' => $providerTxId,
            'provider' => $provider,
            'http_code' => $httpCode,
            'message' => $message,
            'error' => $error,
            'response' => $response,
            'raw_response' => $raw
        ];
    }

    private function httpPostJson($url, array $payload, array $headers = []) {
        $result = [
            'http_code' => 0,
            'response' => [],
            'raw_response' => '',
            'error' => ''
        ];

        $json = json_encode($payload);
        if ($json === false) {
            $json = '{}';
        }

        $timeout = defined('PAYMENT_GATEWAY_HTTP_TIMEOUT') ? (int)PAYMENT_GATEWAY_HTTP_TIMEOUT : 30;
        if ($timeout <= 0) {
            $timeout = 30;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            $raw = curl_exec($ch);
            if ($raw === false) {
                $result['error'] = (string)curl_error($ch);
            } else {
                $result['raw_response'] = (string)$raw;
            }
            $result['http_code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'content' => $json,
                    'timeout' => $timeout,
                    'ignore_errors' => true
                ]
            ]);
            $raw = @file_get_contents($url, false, $context);
            if ($raw === false) {
                $result['error'] = 'HTTP request failed.';
            } else {
                $result['raw_response'] = (string)$raw;
            }

            if (!empty($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $line) {
                    if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/i', (string)$line, $m)) {
                        $result['http_code'] = (int)($m[1] ?? 0);
                        break;
                    }
                }
            }
        }

        if ($result['raw_response'] !== '') {
            $decoded = json_decode($result['raw_response'], true);
            if (is_array($decoded)) {
                $result['response'] = $decoded;
            }
        }

        return $result;
    }

    private function processMockAutoCallback($referenceNumber, $transactionRef, $providerRequestId, $providerTxId, $amountExpected) {
        $payload = [
            'reference_number' => strtoupper(trim((string)$referenceNumber)),
            'transaction_ref' => strtoupper(trim((string)$transactionRef)),
            'provider_request_id' => trim((string)$providerRequestId),
            'provider_tx_id' => trim((string)$providerTxId),
            'provider_status' => 'successful',
            'amount_received' => (float)$amountExpected,
            'amount' => (float)$amountExpected
        ];
        $rawBody = json_encode($payload);
        if ($rawBody === false) {
            $rawBody = '';
        }
        return $this->handleWebhook($payload, [], $rawBody);
    }

    private function findTransactionByRef($transactionRef) {
        $transactionRef = strtoupper(trim((string)$transactionRef));
        if ($transactionRef === '') {
            return [];
        }
        $stmt = $this->conn->prepare("
            SELECT id, transaction_ref, status, provider_status, provider_tx_id, posted_payment_id
            FROM mobile_money_transactions
            WHERE transaction_ref = :transaction_ref
            LIMIT 1
        ");
        $stmt->execute(['transaction_ref' => $transactionRef]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function normalizeGatewayMode($mode) {
        $key = strtolower(trim((string)$mode));
        if (in_array($key, ['live', 'sandbox', 'mock'], true)) {
            return $key;
        }
        return 'mock';
    }

    private function isValidHttpUrl($value) {
        $url = trim((string)$value);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
    }

    private function isValidPublicWebhookUrl($value) {
        if (!$this->isValidHttpUrl($value)) {
            return false;
        }
        $url = trim((string)$value);
        if ($this->isPlaceholderConfigValue($url)) {
            return false;
        }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return false;
        }
        return true;
    }

    private function isPlaceholderConfigValue($value) {
        $text = strtolower(trim((string)$value));
        if ($text === '') {
            return true;
        }

        $placeholderTokens = [
            'replace-with-',
            'your-',
            '.example.com',
            'example.com',
            'changeme',
            'dummy',
            'token-here',
            'key-here',
            'secret-here'
        ];
        foreach ($placeholderTokens as $token) {
            if (strpos($text, $token) !== false) {
                return true;
            }
        }
        return false;
    }

    private function normalizeProviderName($provider) {
        $key = strtolower(trim((string)$provider));
        $key = str_replace([' ', '-'], '_', $key);

        if (in_array($key, ['mtn', 'mtn_momo', 'mtn_mobile_money', 'momopay', 'momo'], true)) {
            return 'mtn';
        }
        if (in_array($key, ['airtel', 'airtel_money', 'airtelmoney'], true)) {
            return 'airtel';
        }
        if ($key === '' || $key === 'sandbox' || $key === 'mock') {
            return 'mtn';
        }

        return $key;
    }

    private function resolveWebhookUrl() {
        $configured = defined('PAYMENT_GATEWAY_WEBHOOK_URL') ? trim((string)PAYMENT_GATEWAY_WEBHOOK_URL) : '';
        if ($configured !== '') {
            return $configured;
        }
        return rtrim((string)BASE_URL, '/') . '/api/payments/webhook.php';
    }

    private function payloadValue(array $payload, array $candidates, $default = '') {
        foreach ($candidates as $candidate) {
            $value = $this->payloadValueByPath($payload, (string)$candidate);
            if (is_array($value)) {
                continue;
            }
            if ($value !== null && trim((string)$value) !== '') {
                return $value;
            }
        }
        return $default;
    }

    private function payloadValueByPath(array $payload, $path) {
        $path = trim((string)$path);
        if ($path === '') {
            return null;
        }

        if (array_key_exists($path, $payload)) {
            return $payload[$path];
        }

        if (strpos($path, '.') === false) {
            return null;
        }

        $parts = explode('.', $path);
        $value = $payload;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function ensureStudentPaymentReferenceSchema() {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS student_payment_references (
                id INT(11) NOT NULL AUTO_INCREMENT,
                reference_number VARCHAR(64) NOT NULL,
                student_id INT(11) NOT NULL,
                invoice_id INT(11) DEFAULT NULL,
                semester_id INT(11) DEFAULT NULL,
                reference_type ENUM('all_pending','partial_invoice','deposit') NOT NULL DEFAULT 'deposit',
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                currency_code VARCHAR(3) NOT NULL DEFAULT 'UGX',
                status ENUM('active','paid','expired','cancelled') NOT NULL DEFAULT 'active',
                expires_at DATETIME DEFAULT NULL,
                generated_by ENUM('student','finance','system') NOT NULL DEFAULT 'student',
                meta_json TEXT DEFAULT NULL,
                paid_payment_id INT(11) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_reference_number (reference_number),
                KEY idx_student_status (student_id, status),
                KEY idx_student_created (student_id, created_at),
                KEY idx_invoice (invoice_id),
                KEY idx_paid_payment (paid_payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $columns = [];
        $colStmt = $this->conn->query("SHOW COLUMNS FROM student_payment_references");
        if ($colStmt) {
            foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columns[strtolower((string)($row['Field'] ?? ''))] = true;
            }
        }

        if (!isset($columns['semester_id'])) {
            $this->conn->exec("ALTER TABLE student_payment_references ADD COLUMN semester_id INT(11) DEFAULT NULL AFTER invoice_id");
        }
        if (!isset($columns['reference_type'])) {
            $this->conn->exec("ALTER TABLE student_payment_references ADD COLUMN reference_type ENUM('all_pending','partial_invoice','deposit') NOT NULL DEFAULT 'deposit' AFTER semester_id");
        }
        if (!isset($columns['currency_code'])) {
            $this->conn->exec("ALTER TABLE student_payment_references ADD COLUMN currency_code VARCHAR(3) NOT NULL DEFAULT 'UGX' AFTER amount");
        }
        if (!isset($columns['generated_by'])) {
            $this->conn->exec("ALTER TABLE student_payment_references ADD COLUMN generated_by ENUM('student','finance','system') NOT NULL DEFAULT 'student' AFTER expires_at");
        }
        if (!isset($columns['meta_json'])) {
            $this->conn->exec("ALTER TABLE student_payment_references ADD COLUMN meta_json TEXT DEFAULT NULL AFTER generated_by");
        }
        if (!isset($columns['paid_payment_id'])) {
            $this->conn->exec("ALTER TABLE student_payment_references ADD COLUMN paid_payment_id INT(11) DEFAULT NULL AFTER meta_json");
        }

        $indexes = [];
        $idxStmt = $this->conn->query("SHOW INDEX FROM student_payment_references");
        if ($idxStmt) {
            foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $idxRow) {
                $indexes[(string)($idxRow['Key_name'] ?? '')] = true;
            }
        }
        if (!isset($indexes['idx_student_status'])) {
            $this->conn->exec("ALTER TABLE student_payment_references ADD INDEX idx_student_status (student_id, status)");
        }
        if (!isset($indexes['idx_student_created'])) {
            $this->conn->exec("ALTER TABLE student_payment_references ADD INDEX idx_student_created (student_id, created_at)");
        }
    }

    private function ensureMobileMoneyTransactionSchema() {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS mobile_money_transactions (
                id INT(11) NOT NULL AUTO_INCREMENT,
                transaction_ref VARCHAR(64) NOT NULL,
                reference_number VARCHAR(64) NOT NULL,
                student_id INT(11) NOT NULL,
                invoice_id INT(11) DEFAULT NULL,
                semester_id INT(11) DEFAULT NULL,
                provider VARCHAR(32) NOT NULL DEFAULT 'sandbox',
                msisdn VARCHAR(24) DEFAULT NULL,
                currency_code VARCHAR(3) NOT NULL DEFAULT 'UGX',
                amount_expected DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                amount_received DECIMAL(12,2) DEFAULT NULL,
                provider_request_id VARCHAR(100) DEFAULT NULL,
                provider_tx_id VARCHAR(100) DEFAULT NULL,
                provider_status VARCHAR(50) DEFAULT NULL,
                status ENUM('initiated','pending','successful','posted','failed','cancelled','expired') NOT NULL DEFAULT 'initiated',
                request_payload LONGTEXT DEFAULT NULL,
                provider_response LONGTEXT DEFAULT NULL,
                callback_payload LONGTEXT DEFAULT NULL,
                attempts INT(11) NOT NULL DEFAULT 0,
                webhook_received_at DATETIME DEFAULT NULL,
                paid_at DATETIME DEFAULT NULL,
                posted_payment_id INT(11) DEFAULT NULL,
                posted_at DATETIME DEFAULT NULL,
                failure_reason TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_mmt_transaction_ref (transaction_ref),
                KEY idx_mmt_reference (reference_number),
                KEY idx_mmt_student_status (student_id, status),
                KEY idx_mmt_provider_req (provider_request_id),
                KEY idx_mmt_provider_tx (provider_tx_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $columns = [];
        $colStmt = $this->conn->query("SHOW COLUMNS FROM mobile_money_transactions");
        if ($colStmt) {
            foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columns[strtolower((string)($row['Field'] ?? ''))] = true;
            }
        }

        if (!isset($columns['semester_id'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN semester_id INT(11) DEFAULT NULL AFTER invoice_id");
        }
        if (!isset($columns['amount_received'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN amount_received DECIMAL(12,2) DEFAULT NULL AFTER amount_expected");
        }
        if (!isset($columns['provider_request_id'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN provider_request_id VARCHAR(100) DEFAULT NULL AFTER amount_received");
        }
        if (!isset($columns['provider_tx_id'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN provider_tx_id VARCHAR(100) DEFAULT NULL AFTER provider_request_id");
        }
        if (!isset($columns['provider_status'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN provider_status VARCHAR(50) DEFAULT NULL AFTER provider_tx_id");
        }
        if (!isset($columns['request_payload'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN request_payload LONGTEXT DEFAULT NULL AFTER status");
        }
        if (!isset($columns['provider_response'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN provider_response LONGTEXT DEFAULT NULL AFTER request_payload");
        }
        if (!isset($columns['callback_payload'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN callback_payload LONGTEXT DEFAULT NULL AFTER provider_response");
        }
        if (!isset($columns['attempts'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN attempts INT(11) NOT NULL DEFAULT 0 AFTER callback_payload");
        }
        if (!isset($columns['webhook_received_at'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN webhook_received_at DATETIME DEFAULT NULL AFTER attempts");
        }
        if (!isset($columns['paid_at'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN paid_at DATETIME DEFAULT NULL AFTER webhook_received_at");
        }
        if (!isset($columns['posted_payment_id'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN posted_payment_id INT(11) DEFAULT NULL AFTER paid_at");
        }
        if (!isset($columns['posted_at'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN posted_at DATETIME DEFAULT NULL AFTER posted_payment_id");
        }
        if (!isset($columns['failure_reason'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD COLUMN failure_reason TEXT DEFAULT NULL AFTER posted_at");
        }

        $indexes = [];
        $idxStmt = $this->conn->query("SHOW INDEX FROM mobile_money_transactions");
        if ($idxStmt) {
            foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $idxRow) {
                $indexes[(string)($idxRow['Key_name'] ?? '')] = true;
            }
        }
        if (!isset($indexes['uq_mmt_transaction_ref'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD UNIQUE KEY uq_mmt_transaction_ref (transaction_ref)");
        }
        if (!isset($indexes['idx_mmt_reference'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD INDEX idx_mmt_reference (reference_number)");
        }
        if (!isset($indexes['idx_mmt_student_status'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD INDEX idx_mmt_student_status (student_id, status)");
        }
        if (!isset($indexes['idx_mmt_provider_req'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD INDEX idx_mmt_provider_req (provider_request_id)");
        }
        if (!isset($indexes['idx_mmt_provider_tx'])) {
            $this->conn->exec("ALTER TABLE mobile_money_transactions ADD INDEX idx_mmt_provider_tx (provider_tx_id)");
        }
    }

    private function ensureBankTransactionSchema() {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS bank_transactions (
                id INT(11) NOT NULL AUTO_INCREMENT,
                transaction_ref VARCHAR(64) NOT NULL,
                reference_number VARCHAR(64) NOT NULL,
                student_id INT(11) NOT NULL,
                invoice_id INT(11) DEFAULT NULL,
                semester_id INT(11) DEFAULT NULL,
                payment_method_label ENUM('bank_transfer','bank_agent','cente_agent') NOT NULL DEFAULT 'bank_agent',
                bank_name VARCHAR(120) DEFAULT NULL,
                depositor_name VARCHAR(120) DEFAULT NULL,
                transfer_reference VARCHAR(100) NOT NULL,
                currency_code VARCHAR(3) NOT NULL DEFAULT 'UGX',
                amount_expected DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                amount_submitted DECIMAL(12,2) DEFAULT NULL,
                status ENUM('received','verified','posted','failed') NOT NULL DEFAULT 'received',
                submitted_notes TEXT DEFAULT NULL,
                submission_payload LONGTEXT DEFAULT NULL,
                verified_by INT(11) DEFAULT NULL,
                verified_at DATETIME DEFAULT NULL,
                verification_notes TEXT DEFAULT NULL,
                posted_payment_id INT(11) DEFAULT NULL,
                posted_at DATETIME DEFAULT NULL,
                failure_reason TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bkt_transaction_ref (transaction_ref),
                KEY idx_bkt_reference (reference_number),
                KEY idx_bkt_student_status (student_id, status),
                KEY idx_bkt_transfer_reference (transfer_reference),
                KEY idx_bkt_posted_payment (posted_payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $columns = [];
        $columnTypes = [];
        $colStmt = $this->conn->query("SHOW COLUMNS FROM bank_transactions");
        if ($colStmt) {
            foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $field = strtolower((string)($row['Field'] ?? ''));
                $columns[$field] = true;
                $columnTypes[$field] = strtolower((string)($row['Type'] ?? ''));
            }
        }

        if (!isset($columns['invoice_id'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN invoice_id INT(11) DEFAULT NULL AFTER student_id");
        }
        if (!isset($columns['semester_id'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN semester_id INT(11) DEFAULT NULL AFTER invoice_id");
        }
        if (!isset($columns['payment_method_label'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN payment_method_label ENUM('bank_transfer','bank_agent','cente_agent') NOT NULL DEFAULT 'bank_agent' AFTER semester_id");
        } else {
            $type = (string)($columnTypes['payment_method_label'] ?? '');
            if (strpos($type, "'bank_agent'") === false || strpos($type, "'cente_agent'") === false || strpos($type, "'bank_transfer'") === false) {
                $this->conn->exec("ALTER TABLE bank_transactions MODIFY COLUMN payment_method_label ENUM('bank_transfer','bank_agent','cente_agent') NOT NULL DEFAULT 'bank_agent'");
            }
        }
        if (!isset($columns['bank_name'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN bank_name VARCHAR(120) DEFAULT NULL AFTER payment_method_label");
        }
        if (!isset($columns['depositor_name'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN depositor_name VARCHAR(120) DEFAULT NULL AFTER bank_name");
        }
        if (!isset($columns['transfer_reference'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN transfer_reference VARCHAR(100) NOT NULL DEFAULT '' AFTER depositor_name");
        }
        if (!isset($columns['currency_code'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN currency_code VARCHAR(3) NOT NULL DEFAULT 'UGX' AFTER transfer_reference");
        }
        if (!isset($columns['amount_expected'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN amount_expected DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER currency_code");
        }
        if (!isset($columns['amount_submitted'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN amount_submitted DECIMAL(12,2) DEFAULT NULL AFTER amount_expected");
        }
        if (!isset($columns['submitted_notes'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN submitted_notes TEXT DEFAULT NULL AFTER status");
        }
        if (!isset($columns['submission_payload'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN submission_payload LONGTEXT DEFAULT NULL AFTER submitted_notes");
        }
        if (!isset($columns['verified_by'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN verified_by INT(11) DEFAULT NULL AFTER submission_payload");
        }
        if (!isset($columns['verified_at'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN verified_at DATETIME DEFAULT NULL AFTER verified_by");
        }
        if (!isset($columns['verification_notes'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN verification_notes TEXT DEFAULT NULL AFTER verified_at");
        }
        if (!isset($columns['posted_payment_id'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN posted_payment_id INT(11) DEFAULT NULL AFTER verification_notes");
        }
        if (!isset($columns['posted_at'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN posted_at DATETIME DEFAULT NULL AFTER posted_payment_id");
        }
        if (!isset($columns['failure_reason'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD COLUMN failure_reason TEXT DEFAULT NULL AFTER posted_at");
        }

        $indexes = [];
        $idxStmt = $this->conn->query("SHOW INDEX FROM bank_transactions");
        if ($idxStmt) {
            foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $idxRow) {
                $indexes[(string)($idxRow['Key_name'] ?? '')] = true;
            }
        }
        if (!isset($indexes['uq_bkt_transaction_ref'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD UNIQUE KEY uq_bkt_transaction_ref (transaction_ref)");
        }
        if (!isset($indexes['idx_bkt_reference'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD INDEX idx_bkt_reference (reference_number)");
        }
        if (!isset($indexes['idx_bkt_student_status'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD INDEX idx_bkt_student_status (student_id, status)");
        }
        if (!isset($indexes['idx_bkt_transfer_reference'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD INDEX idx_bkt_transfer_reference (transfer_reference)");
        }
        if (!isset($indexes['idx_bkt_posted_payment'])) {
            $this->conn->exec("ALTER TABLE bank_transactions ADD INDEX idx_bkt_posted_payment (posted_payment_id)");
        }
    }

    private function ensurePaymentWorkflowSchema() {
        try {
            $colStmt = $this->conn->query("SHOW COLUMNS FROM payments LIKE 'verification_status'");
            $hasVerificationStatus = (bool)($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC));
            if (!$hasVerificationStatus) {
                $this->conn->exec("
                    ALTER TABLE payments
                    ADD COLUMN verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'verified' AFTER notes,
                    ADD COLUMN verified_by INT NULL AFTER verification_status,
                    ADD COLUMN verified_at DATETIME NULL AFTER verified_by,
                    ADD COLUMN verification_notes TEXT NULL AFTER verified_at
                ");
            } else {
                $colStmt = $this->conn->query("SHOW COLUMNS FROM payments LIKE 'verified_by'");
                if (!($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC))) {
                    $this->conn->exec("ALTER TABLE payments ADD COLUMN verified_by INT NULL AFTER verification_status");
                }

                $colStmt = $this->conn->query("SHOW COLUMNS FROM payments LIKE 'verified_at'");
                if (!($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC))) {
                    $this->conn->exec("ALTER TABLE payments ADD COLUMN verified_at DATETIME NULL AFTER verified_by");
                }

                $colStmt = $this->conn->query("SHOW COLUMNS FROM payments LIKE 'verification_notes'");
                if (!($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC))) {
                    $this->conn->exec("ALTER TABLE payments ADD COLUMN verification_notes TEXT NULL AFTER verified_at");
                }
            }

            $indexes = [];
            $idxStmt = $this->conn->query("SHOW INDEX FROM payments");
            if ($idxStmt) {
                foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $idxRow) {
                    $indexes[(string)($idxRow['Key_name'] ?? '')] = true;
                }
            }
            if (!isset($indexes['idx_payment_verification_status'])) {
                $this->conn->exec("ALTER TABLE payments ADD INDEX idx_payment_verification_status (verification_status)");
            }
            if (!isset($indexes['idx_payment_verified_by'])) {
                $this->conn->exec("ALTER TABLE payments ADD INDEX idx_payment_verified_by (verified_by)");
            }
        } catch (Exception $e) {
            // Keep flow running even if migration fails on older environments.
        }
    }

    private function ensurePaymentMethodSchema() {
        try {
            $colStmt = $this->conn->query("SHOW COLUMNS FROM payments LIKE 'payment_method'");
            $row = $colStmt ? ($colStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
            if (empty($row)) {
                return;
            }
            $type = strtolower((string)($row['Type'] ?? ''));
            $required = ['cash', 'bank_transfer', 'bank_agent', 'cente_agent', 'mobile_money', 'cheque', 'card'];
            $allPresent = true;
            foreach ($required as $token) {
                if (strpos($type, "'" . $token . "'") === false) {
                    $allPresent = false;
                    break;
                }
            }
            if ($allPresent) {
                return;
            }

            $this->conn->exec("
                ALTER TABLE payments
                MODIFY COLUMN payment_method ENUM('cash','bank_transfer','bank_agent','cente_agent','mobile_money','cheque','card') NOT NULL
            ");
        } catch (Exception $e) {
            // Keep flows running even if schema migration is blocked.
        }
    }

    private function ensureBalanceUpdateProcedure() {
        try {
            $checkStmt = $this->conn->prepare("
                SELECT ROUTINE_NAME
                FROM INFORMATION_SCHEMA.ROUTINES
                WHERE ROUTINE_SCHEMA = DATABASE()
                  AND ROUTINE_TYPE = 'PROCEDURE'
                  AND ROUTINE_NAME = 'sp_update_student_balance'
                LIMIT 1
            ");
            $checkStmt->execute();
            if ($checkStmt->fetchColumn()) {
                return;
            }

            $this->conn->exec("
                CREATE PROCEDURE sp_update_student_balance(IN p_student_id INT, IN p_semester_id INT)
                BEGIN
                    DECLARE v_dummy INT DEFAULT 0;
                    SET v_dummy = 0;
                END
            ");
        } catch (Exception $e) {
            // If procedure creation is blocked, payment posting continues with fallback where possible.
        }
    }

    private function hasPaymentVerificationColumns() {
        try {
            $colStmt = $this->conn->query("SHOW COLUMNS FROM payments LIKE 'verification_status'");
            return (bool)($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            return false;
        }
    }

    private function syncStudentBalance($studentId, $semesterId) {
        $studentId = (int)$studentId;
        $semesterId = (int)$semesterId;
        if ($studentId <= 0 || $semesterId <= 0) {
            return;
        }

        try {
            $snapshot = getStudentFinancialSnapshot($this->conn, $studentId, $semesterId, 0, 0, 1, true);
            if (!is_array($snapshot)) {
                $snapshot = $this->buildFinancialSnapshotFallback($studentId, $semesterId);
            }
        } catch (Exception $e) {
            // Some environments miss helper procedures used by financial snapshot logic.
            // Fall back to direct totals so payment auto-posting still completes.
            $snapshot = $this->buildFinancialSnapshotFallback($studentId, $semesterId);
        }
        $totalFees = (float)($snapshot['approved_total_fees'] ?? $snapshot['total_fees'] ?? 0);
        $totalPaid = (float)($snapshot['total_paid'] ?? 0);
        $balanceDue = (float)($snapshot['balance_due'] ?? max($totalFees - $totalPaid, 0));

        $lastPaymentDate = null;
        try {
            if ($this->hasPaymentVerificationColumns()) {
                $lastStmt = $this->conn->prepare("
                    SELECT MAX(payment_date)
                    FROM payments
                    WHERE student_id = :student_id
                      AND semester_id = :semester_id
                      AND COALESCE(verification_status, 'verified') = 'verified'
                ");
            } else {
                $lastStmt = $this->conn->prepare("
                    SELECT MAX(payment_date)
                    FROM payments
                    WHERE student_id = :student_id
                      AND semester_id = :semester_id
                ");
            }
            $lastStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
            $lastPaymentDate = $lastStmt->fetchColumn();
            if ($lastPaymentDate === false || $lastPaymentDate === '') {
                $lastPaymentDate = null;
            }
        } catch (Exception $e) {
            $lastPaymentDate = null;
        }

        $existsStmt = $this->conn->prepare("SELECT id FROM student_balances WHERE student_id = :student_id AND semester_id = :semester_id LIMIT 1");
        $existsStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
        $balanceId = (int)$existsStmt->fetchColumn();

        if ($balanceId > 0) {
            $updateStmt = $this->conn->prepare("
                UPDATE student_balances
                SET total_fees = :total_fees,
                    total_paid = :total_paid,
                    balance = :balance,
                    last_payment_date = :last_payment_date,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute([
                'total_fees' => $totalFees,
                'total_paid' => $totalPaid,
                'balance' => $balanceDue,
                'last_payment_date' => $lastPaymentDate,
                'id' => $balanceId
            ]);
            return;
        }

        $insertStmt = $this->conn->prepare("
            INSERT INTO student_balances (student_id, semester_id, total_fees, total_paid, balance, last_payment_date, updated_at)
            VALUES (:student_id, :semester_id, :total_fees, :total_paid, :balance, :last_payment_date, NOW())
        ");
        $insertStmt->execute([
            'student_id' => $studentId,
            'semester_id' => $semesterId,
            'total_fees' => $totalFees,
            'total_paid' => $totalPaid,
            'balance' => $balanceDue,
            'last_payment_date' => $lastPaymentDate
        ]);
    }

    private function buildFinancialSnapshotFallback($studentId, $semesterId) {
        $studentId = (int)$studentId;
        $semesterId = (int)$semesterId;
        if ($studentId <= 0 || $semesterId <= 0) {
            return [
                'approved_total_fees' => 0.0,
                'total_paid' => 0.0,
                'balance_due' => 0.0,
                'balance_on_account' => 0.0,
                'account_credit' => 0.0
            ];
        }

        $totalFees = 0.0;
        $totalPaid = 0.0;

        try {
            $feesStmt = $this->conn->prepare("
                SELECT COALESCE(SUM(total_amount), 0)
                FROM invoices
                WHERE student_id = :student_id
                  AND semester_id = :semester_id
            ");
            $feesStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
            $totalFees = (float)$feesStmt->fetchColumn();
        } catch (Exception $e) {
            $totalFees = 0.0;
        }

        try {
            if ($this->hasPaymentVerificationColumns()) {
                $paidStmt = $this->conn->prepare("
                    SELECT COALESCE(SUM(amount), 0)
                    FROM payments
                    WHERE student_id = :student_id
                      AND semester_id = :semester_id
                      AND COALESCE(verification_status, 'verified') = 'verified'
                ");
            } else {
                $paidStmt = $this->conn->prepare("
                    SELECT COALESCE(SUM(amount), 0)
                    FROM payments
                    WHERE student_id = :student_id
                      AND semester_id = :semester_id
                ");
            }
            $paidStmt->execute(['student_id' => $studentId, 'semester_id' => $semesterId]);
            $totalPaid = (float)$paidStmt->fetchColumn();
        } catch (Exception $e) {
            $totalPaid = 0.0;
        }

        return [
            'approved_total_fees' => $totalFees,
            'total_paid' => $totalPaid,
            'balance_due' => max($totalFees - $totalPaid, 0),
            'balance_on_account' => ($totalPaid > $totalFees) ? ($totalPaid - $totalFees) : max($totalFees - $totalPaid, 0),
            'account_credit' => max($totalPaid - $totalFees, 0)
        ];
    }

    private function resolveSystemFinanceStaffId() {
        try {
            $stmt = $this->conn->query("SELECT id FROM finance_staff ORDER BY id ASC LIMIT 1");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function resolveStudentUserId($studentId) {
        $studentId = (int)$studentId;
        if ($studentId <= 0) {
            return 0;
        }
        try {
            $stmt = $this->conn->prepare("SELECT user_id FROM students WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $studentId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function notifyUser($userId, $title, $message, $type = 'info', $link = null) {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }

        try {
            $stmt = $this->conn->prepare("
                INSERT INTO notifications (user_id, title, message, type, read_status, link, created_at)
                VALUES (:user_id, :title, :message, :type, 'unread', :link, NOW())
            ");
            $stmt->execute([
                'user_id' => $userId,
                'title' => (string)$title,
                'message' => (string)$message,
                'type' => (string)$type,
                'link' => $link
            ]);
        } catch (Exception $e) {
            try {
                $fallback = $this->conn->prepare("
                    INSERT INTO notifications (user_id, title, message, type, link, created_at)
                    VALUES (:user_id, :title, :message, :type, :link, NOW())
                ");
                $fallback->execute([
                    'user_id' => $userId,
                    'title' => (string)$title,
                    'message' => (string)$message,
                    'type' => (string)$type,
                    'link' => $link
                ]);
            } catch (Exception $e2) {
            }
        }
    }

    private function normalizePhone($msisdn) {
        $value = trim((string)$msisdn);
        if ($value === '') {
            return '';
        }

        $value = str_replace([' ', '-', '(', ')'], '', $value);
        $value = preg_replace('/[^0-9\+]/', '', $value);
        if ($value === '') {
            return '';
        }

        if (strpos($value, '+') === 0) {
            $digits = preg_replace('/\D/', '', $value);
            return $digits;
        }

        $digits = preg_replace('/\D/', '', $value);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 10 && strpos($digits, '0') === 0) {
            return '256' . substr($digits, 1);
        }
        if (strlen($digits) === 9 && strpos($digits, '7') === 0) {
            return '256' . $digits;
        }

        return $digits;
    }

    private function generateUniqueCode($prefix, $table, $column) {
        $prefix = strtoupper(trim((string)$prefix));
        if ($prefix === '') {
            $prefix = 'REF';
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$column)) {
            throw new Exception('Invalid unique code target.');
        }

        for ($attempt = 0; $attempt < 30; $attempt++) {
            try {
                $tokenInt = random_int(0, 1679615);
            } catch (Exception $e) {
                $tokenInt = mt_rand(0, 1679615);
            }
            $token = strtoupper(str_pad(base_convert((string)$tokenInt, 10, 36), 4, '0', STR_PAD_LEFT));
            $candidate = $prefix . '-' . date('YmdHis') . '-' . $token;

            $sql = "SELECT id FROM `" . $table . "` WHERE `" . $column . "` = :code LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute(['code' => $candidate]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                return $candidate;
            }
        }

        return $prefix . '-' . date('YmdHis') . '-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 4));
    }

    private function safeLog($action, $module, $description, array $additionalData = []) {
        try {
            if (!($this->logger instanceof Logger) || !method_exists($this->logger, 'log')) {
                return;
            }

            $userId = 0;
            foreach (['finance_user_id', 'admin_user_id', 'student_user_id', 'lecturer_user_id', 'user_id'] as $key) {
                if (!empty($_SESSION[$key])) {
                    $userId = (int)$_SESSION[$key];
                    if ($userId > 0) {
                        break;
                    }
                }
            }

            $this->logger->log(
                $userId > 0 ? $userId : null,
                (string)$action,
                (string)$module,
                (string)$description,
                $additionalData
            );
        } catch (Exception $e) {
        }
    }
}
