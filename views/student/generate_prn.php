<?php
require_once '../../config.php';

$session = new Session('student');
$auth = new Auth('student');

if (
    !isset($_SESSION['student_logged_in']) ||
    $_SESSION['student_logged_in'] !== true ||
    ($_SESSION['student_role'] ?? '') !== 'student'
) {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'] ?? [];
$currentUserId = (int)($currentUser['id'] ?? 0);
$studentDbId = (int)($studentProfile['id'] ?? 0);

$db = new Database();
$conn = $db->getConnection();
$paymentGatewayMode = strtolower(trim((string)(defined('PAYMENT_GATEWAY_MODE') ? PAYMENT_GATEWAY_MODE : 'mock')));
if (!in_array($paymentGatewayMode, ['live', 'sandbox', 'mock'], true)) {
    $paymentGatewayMode = 'mock';
}
$isMockGatewayMode = ($paymentGatewayMode === 'mock');
$isSandboxGatewayMode = ($paymentGatewayMode === 'sandbox');
$institutionBankAccountName = trim((string)(defined('BANK_ACCOUNT_NAME') ? BANK_ACCOUNT_NAME : ''));
$institutionBankAccountNumber = trim((string)(defined('BANK_ACCOUNT_NUMBER') ? BANK_ACCOUNT_NUMBER : ''));
$institutionBankBranch = trim((string)(defined('BANK_BRANCH') ? BANK_BRANCH : ''));
$institutionBankSwift = trim((string)(defined('BANK_SWIFT') ? BANK_SWIFT : ''));

$currentSemester = [
    'academic_year' => '-',
    'semester_name' => '-',
    'id' => 0
];

$studentSemesterContext = getStudentCurrentSemesterContext($conn, $studentDbId);
if (!empty($studentSemesterContext['id'])) {
    $currentSemester['semester_name'] = $studentSemesterContext['semester_name'] ?? '-';
    $currentSemester['id'] = (int)($studentSemesterContext['id'] ?? 0);
    $currentSemester['academic_year'] = $studentSemesterContext['academic_year'] ?? '-';
}

$approvedFeesAmount = 0.0;
$totalPaidAmount = 0.0;
$outstandingBalance = 0.0;
$balanceOnAccount = 0.0;
if ($studentDbId > 0 && $currentSemester['id'] > 0) {
    $financialSnapshot = getStudentFinancialSnapshot(
        $conn,
        $studentDbId,
        (int)$currentSemester['id'],
        (int)($studentProfile['program_id'] ?? 0),
        (int)($studentSemesterContext['academic_year_id'] ?? 0),
        (int)($studentProfile['level_year'] ?? ($studentProfile['year_of_study'] ?? 1))
    );
    $approvedFeesAmount = (float)($financialSnapshot['approved_total_fees'] ?? 0);
    $totalPaidAmount = (float)($financialSnapshot['total_paid'] ?? 0);
    $outstandingBalance = (float)($financialSnapshot['balance_due'] ?? 0);
    $balanceOnAccount = (float)($financialSnapshot['balance_on_account'] ?? $outstandingBalance);
}

$academicStatusMeta = getStudentAcademicStatusMeta(
    $conn,
    (int)$studentDbId,
    (int)($currentSemester['id'] ?? 0),
    (string)($studentProfile['academic_status'] ?? '')
);
$academicStatus = (string)($academicStatusMeta['label'] ?? 'Status Pending');
$academicStatusStyle = (string)($academicStatusMeta['style'] ?? getAcademicStatusChipStyle('neutral'));

$registeredProgramName = '-';
$studentCountry = '';
$studentNationality = '';
if ($studentDbId > 0) {
    try {
        $progStmt = $conn->prepare("
            SELECT p.program_name, s.country, s.nationality
            FROM students s
            LEFT JOIN programs p ON s.program_id = p.id
            WHERE s.id = :student_id
            LIMIT 1
        ");
        $progStmt->execute(['student_id' => $studentDbId]);
        $progRow = $progStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $studentCountry = trim((string)($progRow['country'] ?? ''));
        $studentNationality = trim((string)($progRow['nationality'] ?? ''));
        if (!empty($progRow['program_name'])) {
            $registeredProgramName = (string)$progRow['program_name'];
        } elseif (!empty($studentProfile['program_name'])) {
            $registeredProgramName = $studentProfile['program_name'];
        }
    } catch (Exception $e) {
    }
}

if ($studentCountry === '') {
    $studentCountry = trim((string)($studentProfile['country'] ?? ''));
}
if ($studentNationality === '') {
    $studentNationality = trim((string)($studentProfile['nationality'] ?? ''));
}

$normalizeGeo = function ($value) {
    $v = strtolower(trim((string)$value));
    $v = preg_replace('/[^a-z]/', '', $v);
    return $v;
};

$countryNorm = $normalizeGeo($studentCountry);
$nationalityNorm = $normalizeGeo($studentNationality);
$ugandaTokens = ['uganda', 'ugandan', 'ug'];
$isUgandanStudent = false;
if ($nationalityNorm !== '') {
    // Nationality takes priority for fee display currency.
    $isUgandanStudent = in_array($nationalityNorm, $ugandaTokens, true);
} elseif ($countryNorm !== '') {
    $isUgandanStudent = in_array($countryNorm, $ugandaTokens, true);
} else {
    // Default local currency when profile country/nationality is not filled.
    $isUgandanStudent = true;
}
$isInternationalStudent = !$isUgandanStudent;
$studentDisplayCurrency = $isInternationalStudent ? 'USD' : 'UGX';
$usdUgxRate = (float)Helper::getUsdUgxRate();
if ($usdUgxRate <= 0) {
    $usdUgxRate = 3700.0;
}

$convertAmountForDisplay = function ($amountUgx) use ($isInternationalStudent, $usdUgxRate) {
    $val = (float)$amountUgx;
    if ($isInternationalStudent) {
        return $val / $usdUgxRate;
    }
    return $val;
};

$formatCurrencyForDisplay = function ($amountUgx) use ($convertAmountForDisplay, $studentDisplayCurrency, $isInternationalStudent) {
    $val = $convertAmountForDisplay($amountUgx);
    return Helper::formatCurrency($val, $studentDisplayCurrency, $isInternationalStudent ? 2 : 0);
};
$balanceOnAccountLabel = ((float)$totalPaidAmount > (float)$approvedFeesAmount) ? 'ACCOUNT CREDIT' : 'BALANCE ON ACCOUNT';

$formatCurrencyByStudentInput = function ($amount) use ($studentDisplayCurrency, $isInternationalStudent) {
    return Helper::formatCurrency((float)$amount, $studentDisplayCurrency, $isInternationalStudent ? 2 : 0);
};

$convertStudentInputToUgx = function ($amountInput) use ($isInternationalStudent, $usdUgxRate) {
    $value = (float)$amountInput;
    if ($value <= 0) {
        return 0.0;
    }
    if ($isInternationalStudent) {
        return $value * $usdUgxRate;
    }
    return $value;
};

$unpaidInvoices = [];
$unpaidInvoicesTotal = 0.0;
$paymentRefs = [];
$activePaymentRefs = [];
$expiredPaymentRefs = [];
$paidPaymentRefs = [];
$invoiceDataError = '';
$paymentRefDataError = '';

if (!function_exists('studentEnsurePaymentReferenceSchema')) {
    function studentEnsurePaymentReferenceSchema(PDO $conn) {
        $conn->exec("
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
                KEY idx_paid_payment (paid_payment_id),
                CONSTRAINT fk_student_payment_refs_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
                CONSTRAINT fk_student_payment_refs_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
                CONSTRAINT fk_student_payment_refs_payment FOREIGN KEY (paid_payment_id) REFERENCES payments(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('studentGeneratePrnCode')) {
    function studentGeneratePrnCode(PDO $conn) {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $randInt = random_int(0, 1679615); // 36^4 - 1
            } catch (Exception $e) {
                $randInt = mt_rand(0, 1679615);
            }
            $suffix = strtoupper(str_pad(base_convert((string)$randInt, 10, 36), 4, '0', STR_PAD_LEFT));
            // Short PRN format: PRN + yymmdd + 4-char token (e.g., PRN260227A1B2)
            $candidate = 'PRN' . date('ymd') . $suffix;

            $refStmt = $conn->prepare("SELECT id FROM student_payment_references WHERE reference_number = :ref LIMIT 1");
            $refStmt->execute(['ref' => $candidate]);
            if ($refStmt->fetchColumn()) {
                continue;
            }

            $payStmt = $conn->prepare("SELECT id FROM payments WHERE reference_number = :ref LIMIT 1");
            $payStmt->execute(['ref' => $candidate]);
            if ($payStmt->fetchColumn()) {
                continue;
            }

            return $candidate;
        }

        return 'PRN' . date('ymd') . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 4));
    }
}

$prnSchemaReady = false;
try {
    studentEnsurePaymentReferenceSchema($conn);
    $prnSchemaReady = true;
} catch (Exception $e) {
    $paymentRefDataError = 'Payment reference service is temporarily unavailable.';
}

if ($studentDbId > 0) {
    try {
        $invStmt = $conn->prepare("
            SELECT
                i.id,
                i.invoice_number,
                i.total_amount,
                i.amount_paid,
                i.balance,
                i.due_date,
                i.status,
                s.semester_name,
                ay.year_name AS academic_year
            FROM invoices i
            LEFT JOIN semesters s ON i.semester_id = s.id
            LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
            WHERE i.student_id = :student_id
              AND i.balance > 0
              AND i.status IN ('pending', 'partial', 'overdue')
            ORDER BY i.due_date ASC, i.id DESC
        ");
        $invStmt->execute(['student_id' => $studentDbId]);
        $unpaidInvoices = $invStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($unpaidInvoices as $invoiceRow) {
            $unpaidInvoicesTotal += (float)($invoiceRow['balance'] ?? 0);
        }
    } catch (Exception $e) {
        $invoiceDataError = 'Unable to load invoice data right now.';
    }
}

$generatedPrn = '';
$generatedAmountUgx = 0.0;
$generatedRefType = '';
$generatedPrnExpiresAt = '';
$prnError = '';
$depositAmountInput = '';
$activePrnTab = $_POST['prn_tab'] ?? ($_GET['prn_tab'] ?? 'new_prn');
$validPrnTabs = ['new_prn', 'payment_refs', 'payment_methods'];
if (!in_array($activePrnTab, $validPrnTabs, true)) {
    $activePrnTab = 'new_prn';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $supportedActions = ['generate_all_pending_prn', 'generate_partial_prn', 'generate_deposit_prn'];
    if (in_array($action, $supportedActions, true)) {
        $activePrnTab = 'new_prn';

        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $prnError = 'Invalid request token.';
        } elseif ($studentDbId <= 0) {
            $prnError = 'Student profile is missing.';
        } elseif (!$prnSchemaReady) {
            $prnError = 'Payment reference service is temporarily unavailable.';
        } else {
            $amountUgx = 0.0;
            $invoiceId = null;
            $referenceType = 'deposit';
            $metaPayload = [];

            if ($action === 'generate_all_pending_prn') {
                if (empty($unpaidInvoices) || $unpaidInvoicesTotal <= 0) {
                    $prnError = 'No pending invoices available for PRN generation.';
                } else {
                    $referenceType = 'all_pending';
                    $amountUgx = (float)$unpaidInvoicesTotal;
                    $invoiceIds = [];
                    foreach ($unpaidInvoices as $invoiceRow) {
                        $invoiceIds[] = (int)($invoiceRow['id'] ?? 0);
                    }
                    $metaPayload = [
                        'invoice_ids' => array_values(array_filter($invoiceIds)),
                        'invoice_count' => count(array_filter($invoiceIds))
                    ];
                }
            } elseif ($action === 'generate_partial_prn') {
                $invoiceIdCandidate = (int)($_POST['invoice_id'] ?? 0);
                $partialAmountRaw = trim((string)($_POST['partial_amount'] ?? ''));
                $targetInvoice = null;
                foreach ($unpaidInvoices as $invoiceRow) {
                    if ((int)($invoiceRow['id'] ?? 0) === $invoiceIdCandidate) {
                        $targetInvoice = $invoiceRow;
                        break;
                    }
                }

                if (!$targetInvoice) {
                    $prnError = 'Selected invoice is not available for partial payment.';
                } elseif ($partialAmountRaw === '' || !is_numeric($partialAmountRaw) || (float)$partialAmountRaw <= 0) {
                    $prnError = 'Enter a valid partial amount.';
                } else {
                    $referenceType = 'partial_invoice';
                    $invoiceId = $invoiceIdCandidate;
                    $amountUgx = $convertStudentInputToUgx((float)$partialAmountRaw);
                    $invoiceBalanceUgx = (float)($targetInvoice['balance'] ?? 0);

                    if ($amountUgx <= 0) {
                        $prnError = 'Enter a valid partial amount.';
                    } elseif (($amountUgx - $invoiceBalanceUgx) > 0.01) {
                        $prnError = 'Partial amount cannot exceed invoice balance.';
                    } else {
                        $metaPayload = [
                            'invoice_number' => (string)($targetInvoice['invoice_number'] ?? ''),
                            'invoice_balance_ugx' => $invoiceBalanceUgx
                        ];
                    }
                }
            } else {
                $depositAmountInput = trim((string)($_POST['deposit_amount'] ?? ''));
                if ($depositAmountInput === '' || !is_numeric($depositAmountInput) || (float)$depositAmountInput <= 0) {
                    $prnError = 'Enter a valid deposit amount.';
                } else {
                    $referenceType = 'deposit';
                    $amountUgx = $convertStudentInputToUgx((float)$depositAmountInput);
                    if ($amountUgx <= 0) {
                        $prnError = 'Enter a valid deposit amount.';
                    } elseif ($unpaidInvoicesTotal > 0 && ($amountUgx - (float)$unpaidInvoicesTotal) > 0.01) {
                        $prnError = 'Deposit amount cannot be higher than your outstanding balance. Use "All pending invoices" or enter a partial amount.';
                    } else {
                        $metaPayload = ['source' => 'self_deposit'];
                    }
                }
            }

            if ($prnError === '') {
                try {
                    $generatedPrn = studentGeneratePrnCode($conn);
                    $generatedPrnExpiresAt = date('Y-m-d H:i:s', strtotime('+14 days'));
                    $metaJson = json_encode($metaPayload, JSON_UNESCAPED_UNICODE);
                    if ($metaJson === false) {
                        $metaJson = '{}';
                    }

                    $insertRefStmt = $conn->prepare("
                        INSERT INTO student_payment_references (
                            reference_number,
                            student_id,
                            invoice_id,
                            semester_id,
                            reference_type,
                            amount,
                            currency_code,
                            status,
                            expires_at,
                            generated_by,
                            meta_json,
                            created_at,
                            updated_at
                        ) VALUES (
                            :reference_number,
                            :student_id,
                            :invoice_id,
                            :semester_id,
                            :reference_type,
                            :amount,
                            'UGX',
                            'active',
                            :expires_at,
                            'student',
                            :meta_json,
                            NOW(),
                            NOW()
                        )
                    ");
                    $insertRefStmt->execute([
                        'reference_number' => $generatedPrn,
                        'student_id' => $studentDbId,
                        'invoice_id' => $invoiceId,
                        'semester_id' => (int)($currentSemester['id'] ?? 0) > 0 ? (int)$currentSemester['id'] : null,
                        'reference_type' => $referenceType,
                        'amount' => $amountUgx,
                        'expires_at' => $generatedPrnExpiresAt,
                        'meta_json' => $metaJson
                    ]);

                    $generatedAmountUgx = $amountUgx;
                    $generatedRefType = $referenceType;

                    try {
                        $session->setFlash(
                            'success',
                            'PRN generated successfully: ' . $generatedPrn .
                            '. Expires on ' . date('d M Y, h:i A', strtotime($generatedPrnExpiresAt))
                        );
                    } catch (Exception $e) {
                    }
                } catch (Exception $e) {
                    $prnError = 'Unable to generate PRN right now. Please retry.';
                }
            }
        }
    }
}

if ($studentDbId > 0 && $prnSchemaReady) {
    try {
        $syncRefStmt = $conn->prepare("
            UPDATE student_payment_references spr
            LEFT JOIN (
                SELECT p.reference_number, MAX(p.id) AS payment_row_id
                FROM payments p
                WHERE p.reference_number IS NOT NULL
                  AND p.reference_number <> ''
                GROUP BY p.reference_number
            ) paid ON paid.reference_number = spr.reference_number
            SET spr.status = CASE
                WHEN paid.payment_row_id IS NOT NULL THEN 'paid'
                WHEN spr.status = 'active' AND spr.expires_at IS NOT NULL AND spr.expires_at < NOW() THEN 'expired'
                ELSE spr.status
            END,
            spr.paid_payment_id = CASE
                WHEN paid.payment_row_id IS NOT NULL THEN paid.payment_row_id
                ELSE spr.paid_payment_id
            END
            WHERE spr.student_id = :student_id
        ");
        $syncRefStmt->execute(['student_id' => $studentDbId]);

        $refsStmt = $conn->prepare("
            SELECT
                spr.id,
                spr.reference_number,
                spr.invoice_id,
                spr.reference_type,
                spr.amount,
                spr.currency_code,
                spr.status,
                spr.expires_at,
                spr.meta_json,
                spr.created_at,
                spr.updated_at,
                i.invoice_number,
                p.payment_id,
                p.receipt_number,
                p.payment_date,
                p.payment_method,
                p.amount AS paid_amount
            FROM student_payment_references spr
            LEFT JOIN invoices i ON spr.invoice_id = i.id
            LEFT JOIN payments p ON p.id = spr.paid_payment_id
            WHERE spr.student_id = :student_id
            ORDER BY spr.created_at DESC, spr.id DESC
            LIMIT 150
        ");
        $refsStmt->execute(['student_id' => $studentDbId]);
        $paymentRefs = $refsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($paymentRefs as $refRow) {
            $status = strtolower(trim((string)($refRow['status'] ?? 'active')));
            if ($status === 'paid') {
                $paidPaymentRefs[] = $refRow;
            } elseif ($status === 'active') {
                $activePaymentRefs[] = $refRow;
            } else {
                $expiredPaymentRefs[] = $refRow;
            }
        }
    } catch (Exception $e) {
        $paymentRefDataError = 'Unable to load payment references right now.';
    }
} elseif ($studentDbId > 0 && !$prnSchemaReady && $paymentRefDataError === '') {
    $paymentRefDataError = 'Payment references are not available at the moment.';
}

$referenceTypeLabel = function ($type) {
    $key = strtolower(trim((string)$type));
    if ($key === 'all_pending') {
        return 'All Pending Invoices';
    }
    if ($key === 'partial_invoice') {
        return 'Partial Invoice Payment';
    }
    return 'Account Deposit';
};

$latestActivePaymentRef = null;
foreach ($activePaymentRefs as $refRow) {
    $refType = strtolower(trim((string)($refRow['reference_type'] ?? '')));
    if ($refType === 'all_pending' || $refType === 'partial_invoice') {
        $latestActivePaymentRef = $refRow;
        break;
    }
}
if ($latestActivePaymentRef === null) {
    if ($unpaidInvoicesTotal <= 0) {
        $latestActivePaymentRef = $activePaymentRefs[0] ?? null;
    }
}

$studentViewsPath = BASE_PATH . '/views/student/';
$linkDashboard = 'dashboard.php';
$linkResults = 'results.php';
$linkInvoices = file_exists($studentViewsPath . 'invoices.php') ? 'invoices.php' : 'payments.php?section=bills';
$linkFees = file_exists($studentViewsPath . 'fees.php') ? 'fees.php' : 'payments.php?section=fees';
$linkGeneratePrn = 'generate_prn.php';
$linkEnroll = 'course-registration.php';
$linkPayments = file_exists($studentViewsPath . 'payments.php') ? 'payments.php' : 'notifications.php';
$linkProgramme = 'my-courses.php';
$linkApplyServices = file_exists($studentViewsPath . 'services.php') ? 'services.php' : 'dashboard.php';
$linkServiceHistory = 'notifications.php';
$linkNewIdCards = file_exists($studentViewsPath . 'new-id-cards.php') ? 'new-id-cards.php' : 'dashboard.php';
$linkMailbox = 'notifications.php';
$linkAcademicCalendar = file_exists($studentViewsPath . 'academic-calendar.php') ? 'academic-calendar.php' : 'notifications.php';
$mailUnreadCount = !empty($currentUser['id']) ? getUnreadNotificationCountForUser((int)$currentUser['id']) : 0;

$pageTitle = 'Generate PRN - ' . APP_NAME;
include '../../includes/header.php';
?>

<style>
body { background: #f2f4f7; }
.student-sidebar {
    width: 230px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    min-height: 100vh;
    height: 100vh;
    overflow-y: auto;
    overflow-x: hidden;
    border-right: 1px solid #e5e7eb;
    position: fixed;
    left: 0;
    top: 0;
    z-index: 100;
    box-shadow: 2px 0 12px rgba(15, 23, 42, 0.04);
    transition: transform 0.25s ease;
}
.student-sidebar ul { list-style: none; padding: 10px 8px; margin: 0; }
.student-sidebar > ul { padding-bottom: 20px; }
.student-sidebar li {
    padding: 9px 12px;
    margin-bottom: 4px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-size: 0.82rem;
    letter-spacing: 0.02em;
    color: #334155;
    cursor: pointer;
    transition: all 0.2s ease;
}
.student-sidebar li a { color: inherit; text-decoration: none; display: block; }
.student-sidebar li.active { background: #eaf2ff; border-color: #bfdbfe; color: #1d4ed8; font-weight: 700; }
.student-sidebar li:hover { background: #f1f5f9; color: #0f172a; }
.student-sidebar.sidebar-collapsed { transform: translateX(-100%); }
.sidebar-user-card {
    margin: 0.45rem 0.45rem 0.2rem;
    background: #2b3c4f;
    border-radius: 8px;
    color: #fff;
    text-align: center;
    padding: 0.6rem 0.55rem 0.6rem;
}
.sidebar-user-card img {
    width: 62px;
    height: 72px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid rgba(255,255,255,0.35);
    margin-bottom: 0.3rem;
}
.sidebar-user-name { font-size: 0.82rem; line-height: 1.2; }
.sidebar-user-no { font-size: 0.9rem; font-weight: 700; }


.sidebar-portal-title { font-size: 0.66rem; letter-spacing: 0.08em; text-transform: uppercase; color: #cbd5e1; margin-bottom: 0.4rem; font-weight: 700; }
.main-content {
    margin-left: 230px;
    width: calc(100vw - 230px);
    max-width: calc(100vw - 230px);
    min-height: 100vh;
    transition: margin-left 0.25s ease, width 0.25s ease;
}
.main-content.full-width { margin-left: 0; width: 100vw; max-width: 100vw; }

.student-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    padding: 0.5rem 1.2rem;
    position: sticky;
    top: 0;
    z-index: 10;
}
.student-profile-pic { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb; }

.chip-row {
    padding: 0.45rem 1.2rem 0.2rem;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex-wrap: wrap;
    row-gap: 0.4rem;
}
.chip {
    border-radius: 6px;
    padding: 4px 8px;
    font-weight: 600;
    font-size: 0.78rem;
    line-height: 1;
    white-space: nowrap;
}
.chip.gray { background: #f1f5f9; color: #222; }
.chip.blue { background: #1f7aa8; color: #fff; }
.chip.green { background: #16a34a; color: #fff; }
.chip.red { background: #fee2e2; color: #991b1b; }
.chip.balance-chip {
    background: #0ea5e9;
    border: 1px solid #0284c7;
    color: #ffffff;
    font-weight: 800;
}

.prn-wrap { padding: 0.9rem 1.2rem 1.3rem; }
.prn-card {
    background: #f6f7f9;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 0.9rem;
}
.prn-tabs { display: flex; gap: 0.35rem; margin-bottom: 0.8rem; }
.prn-tab {
    border: 1px solid #d1d5db;
    background: #fff;
    color: #334155;
    border-radius: 8px 8px 0 0;
    padding: 9px 16px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
}
.prn-tab.active {
    color: #1f7aa8;
    border-bottom-color: #fff;
}
.notice {
    background: #fef3c7;
    color: #7c5f14;
    border: 1px solid #f3d88c;
    border-radius: 4px;
    padding: 12px 14px;
    margin-bottom: 0.7rem;
}
.acc-item { border: 1px solid #d1d5db; border-top: none; background: #fff; }
.acc-item:first-of-type { border-top: 1px solid #d1d5db; border-radius: 6px 6px 0 0; }
.acc-item:last-of-type { border-radius: 0 0 6px 6px; }
.acc-head {
    width: 100%;
    text-align: left;
    border: none;
    background: #fff;
    padding: 12px 14px;
    font-size: 1rem;
    font-weight: 700;
    color: #374151;
    cursor: pointer;
}
.acc-head.active { color: #b42318; }
.acc-body { display: none; padding: 14px; border-top: 1px solid #e5e7eb; }
.acc-body.active { display: block; }
.prn-input {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 10px;
    width: 180px;
    font-size: 1rem;
}
.prn-generate-btn {
    border: 1px solid #1f7aa8;
    background: #1f7aa8;
    color: #fff;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
}
.prn-generated {
    margin-top: 12px;
    background: #ecfdf3;
    border: 1px solid #86efac;
    color: #166534;
    border-radius: 8px;
    padding: 10px;
    font-weight: 700;
}
.prn-generated-actions {
    margin-top: 9px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.prn-list-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
}
.prn-list-table th,
.prn-list-table td {
    border: 1px solid #e5e7eb;
    padding: 7px 8px;
    font-size: 0.82rem;
}
.prn-list-table th {
    background: #f8fafc;
    font-weight: 700;
}
.status-pill {
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 0.75rem;
    font-weight: 700;
}
.status-pill.pending { background: #fee2e2; color: #991b1b; }
.status-pill.partial { background: #ffedd5; color: #9a3412; }
.status-pill.overdue { background: #fef2f2; color: #b91c1c; }
.status-pill.active-ref { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.status-pill.expired-ref { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.status-pill.paid-ref { background: #e0f2fe; color: #075985; border: 1px solid #7dd3fc; }
.refs-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px;
    margin-bottom: 8px;
    background: #f8fafc;
}
.refs-groups {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 9px;
    padding: 3px;
}
.refs-group-btn {
    border: 1px solid transparent;
    background: transparent;
    color: #475569;
    border-radius: 7px;
    padding: 7px 11px;
    font-size: 0.86rem;
    cursor: pointer;
}
.refs-group-btn.active {
    background: #fff;
    color: #0f172a;
    border-color: #e2e8f0;
    box-shadow: 0 1px 2px rgba(2, 6, 23, 0.08);
}
.refs-reload-btn {
    border: 1px dashed #f87171;
    background: #fff;
    color: #ef4444;
    border-radius: 8px;
    padding: 7px 12px;
    font-weight: 700;
    font-size: 0.82rem;
    cursor: pointer;
}
.ref-action-row {
    display: flex;
    align-items: center;
    gap: 8px;
    justify-content: flex-end;
    flex-wrap: wrap;
}
.ref-action-row .refs-reload-btn,
.ref-action-row .prn-generate-btn {
    padding: 6px 10px;
    font-size: 0.78rem;
}
.ref-line {
    border: 1px solid #d8dee6;
    background: #fff;
    border-radius: 3px;
    padding: 12px 14px;
    font-size: 0.95rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 7px;
}
.ref-line .red { color: #b42318; }
.methods-wrap {
    padding: 6px 0 2px;
}
.methods-tabs {
    display: flex;
    gap: 6px;
    margin: 0 auto 10px;
    width: fit-content;
    background: #f1f5f9;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 3px;
}
.method-btn {
    border: 1px solid transparent;
    background: transparent;
    color: #64748b;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
}
.method-btn.active {
    background: #fff;
    color: #1f7aa8;
    border-color: #e2e8f0;
    box-shadow: 0 1px 2px rgba(2, 6, 23, 0.08);
}
.method-panel {
    display: none;
    max-width: 900px;
    margin: 0 auto;
    border: 1px solid #e5e7eb;
    background: #fff;
    border-radius: 12px;
    padding: 14px 18px;
}
.method-panel.active { display: block; }
.method-list {
    margin: 0;
    padding-left: 20px;
    font-size: 0.95rem;
    color: #1f2937;
    line-height: 1.25;
}
.mobile-money-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}
.mobile-money-col {
    border-right: 1px solid #e5e7eb;
    padding-right: 12px;
}
.mobile-money-col:last-child {
    border-right: none;
    padding-right: 0;
}
.mobile-money-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1f7aa8;
    text-decoration: underline;
    margin-bottom: 3px;
}
.dial-code {
    color: #b42318;
    font-weight: 700;
}
.method-current-ref {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}
.method-current-prn {
    display: inline-block;
    background: #111827;
    color: #f9fafb;
    border-radius: 6px;
    padding: 2px 8px;
    font-size: 0.84rem;
    letter-spacing: 0.03em;
}
.gateway-mode-warning {
    margin-bottom: 10px;
    border: 1px solid #f5c2c7;
    background: #fff1f2;
    color: #9f1239;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.88rem;
    font-weight: 700;
}
.gateway-mode-warning.sandbox {
    border-color: #93c5fd;
    background: #eff6ff;
    color: #1d4ed8;
}
.mobile-pay-card {
    margin-top: 12px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    background: #f8fafc;
    padding: 12px;
}
.mobile-pay-title {
    font-size: 0.9rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 8px;
}
.mobile-pay-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.mobile-pay-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.mobile-pay-field label {
    font-size: 0.8rem;
    font-weight: 700;
    color: #334155;
}
.mobile-pay-field select,
.mobile-pay-field input {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 0.9rem;
    background: #fff;
    color: #0f172a;
}
.mobile-pay-actions {
    margin-top: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.mobile-pay-check-btn {
    border: 1px solid #94a3b8;
    background: #fff;
    color: #334155;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 0.86rem;
    font-weight: 700;
    cursor: pointer;
}
.mobile-pay-status {
    margin-top: 10px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #fff;
    padding: 9px 10px;
    font-size: 0.85rem;
    color: #334155;
}
.mobile-pay-status.pending {
    border-color: #fcd34d;
    background: #fffbeb;
    color: #92400e;
}
.mobile-pay-status.success {
    border-color: #86efac;
    background: #ecfdf3;
    color: #166534;
}
.mobile-pay-status.error {
    border-color: #fca5a5;
    background: #fef2f2;
    color: #991b1b;
}
.mobile-pay-meta {
    margin-top: 6px;
    font-size: 0.78rem;
    color: #64748b;
}
.bank-proof-card {
    margin-top: 12px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    background: #f8fafc;
    padding: 12px;
}
.bank-proof-title {
    font-size: 0.9rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 8px;
}
.bank-proof-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.bank-proof-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.bank-proof-field.full {
    grid-column: 1 / -1;
}
.bank-proof-field label {
    font-size: 0.8rem;
    font-weight: 700;
    color: #334155;
}
.bank-proof-field input,
.bank-proof-field select,
.bank-proof-field textarea {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 0.9rem;
    background: #fff;
    color: #0f172a;
}
.bank-proof-field textarea {
    min-height: 76px;
    resize: vertical;
}
.bank-proof-actions {
    margin-top: 10px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.bank-pay-status {
    margin-top: 10px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #fff;
    padding: 9px 10px;
    font-size: 0.85rem;
    color: #334155;
}
.bank-pay-status.pending {
    border-color: #fcd34d;
    background: #fffbeb;
    color: #92400e;
}
.bank-pay-status.success {
    border-color: #86efac;
    background: #ecfdf3;
    color: #166534;
}
.bank-pay-status.error {
    border-color: #fca5a5;
    background: #fef2f2;
    color: #991b1b;
}
.bank-pay-meta {
    margin-top: 6px;
    font-size: 0.78rem;
    color: #64748b;
}
.bank-account-card {
    margin-top: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    background: #ffffff;
    padding: 12px;
}
.bank-account-title {
    font-size: 0.86rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 8px;
}
.bank-account-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.bank-account-item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 10px;
    background: #f8fafc;
}
.bank-account-item.full {
    grid-column: 1 / -1;
}
.bank-account-label {
    font-size: 0.75rem;
    font-weight: 700;
    color: #475569;
    margin-bottom: 3px;
}
.bank-account-value {
    font-size: 0.9rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.bank-copy-btn {
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #1f2937;
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 0.72rem;
    font-weight: 700;
    cursor: pointer;
}
.bank-account-note {
    margin-top: 8px;
    font-size: 0.78rem;
    color: #64748b;
}

/* Dark mode overrides for generate PRN panels */
html[data-theme='dark'] .prn-card {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .prn-tab {
    background: var(--app-surface-2) !important;
    color: #cbd5e1 !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .prn-tab.active {
    background: var(--app-surface-1) !important;
    color: #f8fafc !important;
    border-bottom-color: var(--app-surface-1) !important;
}
html[data-theme='dark'] .acc-item {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .acc-head {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
}
html[data-theme='dark'] .acc-head.active {
    color: #fca5a5 !important;
}
html[data-theme='dark'] .acc-body {
    background: var(--app-surface-1) !important;
    color: #e5e7eb !important;
    border-top-color: var(--app-border) !important;
}
html[data-theme='dark'] .acc-body > div[style*='color:#334155'],
html[data-theme='dark'] .acc-body > div[style*='color: #334155'],
html[data-theme='dark'] .acc-body > div[style*='color:#64748b'],
html[data-theme='dark'] .acc-body > div[style*='color: #64748b'] {
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .prn-input {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .prn-generated {
    background: rgba(16, 185, 129, 0.12) !important;
    border-color: rgba(52, 211, 153, 0.5) !important;
    color: #34d399 !important;
}
html[data-theme='dark'] .status-pill.active-ref {
    background: rgba(16, 185, 129, 0.16) !important;
    color: #6ee7b7 !important;
    border-color: rgba(52, 211, 153, 0.5) !important;
}
html[data-theme='dark'] .status-pill.expired-ref {
    background: rgba(239, 68, 68, 0.16) !important;
    color: #fca5a5 !important;
    border-color: rgba(248, 113, 113, 0.5) !important;
}
html[data-theme='dark'] .status-pill.paid-ref {
    background: rgba(14, 165, 233, 0.16) !important;
    color: #7dd3fc !important;
    border-color: rgba(56, 189, 248, 0.5) !important;
}
html[data-theme='dark'] .notice {
    background: #3a2f14 !important;
    color: #fef3c7 !important;
    border-color: #7c5f14 !important;
}
html[data-theme='dark'] .notice[style*='background:#eef2ff'],
html[data-theme='dark'] .notice[style*='background: #eef2ff'],
html[data-theme='dark'] .notice[style*='background:#f8fafc'],
html[data-theme='dark'] .notice[style*='background: #f8fafc'],
html[data-theme='dark'] .notice[style*='background:#ecfdf3'],
html[data-theme='dark'] .notice[style*='background: #ecfdf3'] {
    background: var(--app-surface-2) !important;
    color: #cbd5e1 !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .status-pill.pending {
    background: rgba(239, 68, 68, 0.18) !important;
    color: #fca5a5 !important;
    border: 1px solid rgba(248, 113, 113, 0.5) !important;
}
html[data-theme='dark'] .status-pill.partial {
    background: rgba(245, 158, 11, 0.16) !important;
    color: #fcd34d !important;
    border: 1px solid rgba(251, 191, 36, 0.45) !important;
}
html[data-theme='dark'] .status-pill.overdue {
    background: rgba(220, 38, 38, 0.2) !important;
    color: #fca5a5 !important;
    border: 1px solid rgba(248, 113, 113, 0.55) !important;
}
html[data-theme='dark'] .refs-toolbar {
    background: var(--app-surface-2) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .refs-groups {
    background: #0f172a !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .refs-group-btn {
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .refs-group-btn.active {
    background: var(--app-surface-1) !important;
    color: #f8fafc !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .refs-reload-btn {
    background: var(--app-surface-2) !important;
    color: #fca5a5 !important;
    border-color: #ef4444 !important;
}
html[data-theme='dark'] .refs-reload-btn:hover {
    background: #3a1820 !important;
    color: #fecaca !important;
}
html[data-theme='dark'] .ref-line {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .ref-line .red {
    color: #fca5a5 !important;
}
html[data-theme='dark'] .methods-tabs {
    background: #0f172a !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .method-btn {
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .method-btn.active {
    background: var(--app-surface-1) !important;
    color: #93c5fd !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .method-panel {
    background: var(--app-surface-2) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .method-list {
    color: #e5e7eb !important;
}
html[data-theme='dark'] .mobile-money-col {
    border-right-color: var(--app-border) !important;
}
html[data-theme='dark'] .mobile-money-title {
    color: #93c5fd !important;
}
html[data-theme='dark'] .dial-code {
    color: #fca5a5 !important;
}
html[data-theme='dark'] .method-current-prn {
    background: #020617 !important;
    color: #e2e8f0 !important;
    border: 1px solid var(--app-border) !important;
}
html[data-theme='dark'] .gateway-mode-warning {
    background: rgba(190, 24, 93, 0.18) !important;
    color: #fbcfe8 !important;
    border-color: rgba(244, 114, 182, 0.6) !important;
}
html[data-theme='dark'] .gateway-mode-warning.sandbox {
    background: rgba(30, 64, 175, 0.25) !important;
    color: #bfdbfe !important;
    border-color: rgba(96, 165, 250, 0.65) !important;
}
html[data-theme='dark'] .mobile-pay-card {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .mobile-pay-title {
    color: #e2e8f0 !important;
}
html[data-theme='dark'] .mobile-pay-field label {
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .mobile-pay-field select,
html[data-theme='dark'] .mobile-pay-field input {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .mobile-pay-check-btn {
    background: var(--app-surface-2) !important;
    color: #e2e8f0 !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .mobile-pay-status {
    background: var(--app-surface-2) !important;
    color: #cbd5e1 !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .mobile-pay-status.pending {
    background: rgba(245, 158, 11, 0.16) !important;
    color: #fcd34d !important;
    border-color: rgba(251, 191, 36, 0.45) !important;
}
html[data-theme='dark'] .mobile-pay-status.success {
    background: rgba(16, 185, 129, 0.16) !important;
    color: #6ee7b7 !important;
    border-color: rgba(52, 211, 153, 0.5) !important;
}
html[data-theme='dark'] .mobile-pay-status.error {
    background: rgba(220, 38, 38, 0.2) !important;
    color: #fca5a5 !important;
    border-color: rgba(248, 113, 113, 0.55) !important;
}
html[data-theme='dark'] .mobile-pay-meta {
    color: #94a3b8 !important;
}
html[data-theme='dark'] .bank-proof-card {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .bank-proof-title {
    color: #e2e8f0 !important;
}
html[data-theme='dark'] .bank-proof-field label {
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .bank-proof-field input,
html[data-theme='dark'] .bank-proof-field select,
html[data-theme='dark'] .bank-proof-field textarea {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .bank-pay-status {
    background: var(--app-surface-2) !important;
    color: #cbd5e1 !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .bank-pay-status.pending {
    background: rgba(245, 158, 11, 0.16) !important;
    color: #fcd34d !important;
    border-color: rgba(251, 191, 36, 0.45) !important;
}
html[data-theme='dark'] .bank-pay-status.success {
    background: rgba(16, 185, 129, 0.16) !important;
    color: #6ee7b7 !important;
    border-color: rgba(52, 211, 153, 0.5) !important;
}
html[data-theme='dark'] .bank-pay-status.error {
    background: rgba(220, 38, 38, 0.2) !important;
    color: #fca5a5 !important;
    border-color: rgba(248, 113, 113, 0.55) !important;
}
html[data-theme='dark'] .bank-pay-meta {
    color: #94a3b8 !important;
}
html[data-theme='dark'] .bank-account-card {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .bank-account-title {
    color: #e2e8f0 !important;
}
html[data-theme='dark'] .bank-account-item {
    background: var(--app-surface-2) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .bank-account-label {
    color: #94a3b8 !important;
}
html[data-theme='dark'] .bank-account-value {
    color: #e5e7eb !important;
}
html[data-theme='dark'] .bank-copy-btn {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
    color: #e5e7eb !important;
}
html[data-theme='dark'] .bank-account-note {
    color: #94a3b8 !important;
}
html[data-theme='dark'] .chip.balance-chip {
    background: #082f49 !important;
    border-color: #0ea5e9 !important;
    color: #bae6fd !important;
}

@media (max-width: 1200px) {
    .chip-row {
        white-space: normal;
        flex-wrap: wrap;
    }
    .methods-tabs {
        width: 100%;
        flex-wrap: wrap;
    }
    .mobile-money-grid {
        grid-template-columns: 1fr;
    }
    .mobile-money-col {
        border-right: none;
        padding-right: 0;
        border-bottom: 1px solid #e5e7eb;
        padding-bottom: 10px;
    }
    .mobile-money-col:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }
    .mobile-pay-grid {
        grid-template-columns: 1fr;
    }
    .bank-proof-grid {
        grid-template-columns: 1fr;
    }
    .bank-account-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="student-sidebar">
    <div class="sidebar-user-card">
        <div class="sidebar-portal-title">SMNS-STUDENT PORTAL</div>
        <?php if (!empty($studentProfile['photo'])): ?>
            <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile">
        <?php else: ?>
            <img src="/assets/img/student_sample.jpg" alt="Profile">
        <?php endif; ?>
        <div class="sidebar-user-name">
            <?php echo e(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? ''))); ?>
        </div>
        <div class="sidebar-user-no">STUDENT NO.: <?php echo e($studentProfile['student_id'] ?? '-'); ?></div>
    </div>
    <ul>
        <li class="active"><a href="<?php echo e($linkGeneratePrn); ?>">GENERATE PRN</a></li>
        <li><a href="<?php echo e($linkEnroll); ?>">ENROLLMENT & REGISTRATION</a></li>
        <li><a href="<?php echo e($linkPayments); ?>">PAYMENTS</a></li>
        <li><a href="<?php echo e($linkProgramme); ?>">MY COURSES & RESULTS</a></li>
        <li><a href="services.php?tab=apply">SERVICES</a></li>
        <ul class="services-submenu">
            <li><a href="services.php?tab=apply">APPLY FOR SERVICES</a></li>
            <li><a href="services.php?tab=history">SERVICE HISTORY</a></li>
            <li><a href="services.php?tab=new_id">NEW ID CARDS</a></li>
        </ul>
        <li><a href="<?php echo e($linkDashboard); ?>">BIO DATA</a></li>
        <li><a href="<?php echo BASE_URL; ?>/views/student/transcript.php">VIEW TRANSCRIPT</a></li>
        <li><a href="<?php echo e($linkMailbox); ?>">MY MAILBOX</a></li>
        <li><a href="<?php echo e($linkAcademicCalendar); ?>">ACADEMIC CALENDAR</a></li>
    </ul>
</div>

<div class="main-content">
    <div class="student-topbar">
        <div style="display:flex; align-items:center; gap:0.7rem;">
            <button id="menuBtn" style="background:none; border:none; font-size:1.1rem; cursor:pointer;" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <button onclick="location.href='<?php echo e($linkDashboard); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW BIO DATA</button>
            <button onclick="location.href='<?php echo e($linkResults); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW RESULTS</button>
            <button onclick="location.href='<?php echo e($linkInvoices); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW INVOICES</button>
            <button onclick="location.href='<?php echo e($linkFees); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">VIEW FEES STRUCTURE</button>
            <button onclick="location.href='<?php echo e($linkGeneratePrn); ?>'" style="background:#1f7aa8; color:#fff; border:1px solid #1f7aa8; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:700;">Generate PRN</button>
        </div>
        <div style="display:flex; align-items:center; gap:0.5rem; position:relative;">
            <?php if (!empty($studentProfile['photo'])): ?>
                <img src="<?php echo BASE_URL . '/' . $studentProfile['photo']; ?>" alt="Profile" class="student-profile-pic">
            <?php else: ?>
                <img src="/assets/img/student_sample.jpg" alt="Profile" class="student-profile-pic">
            <?php endif; ?>
            <span style="font-size:0.98rem; color:#222; font-weight:600; white-space:nowrap;">
                <?php echo e(strtoupper(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? '')))); ?>
            </span>
            <a href="<?php echo e($linkMailbox); ?>" title="My Mailbox" style="position:relative; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px solid #dbe3ef; border-radius:50%; color:#1f7aa8; text-decoration:none; background:#fff;">
                <i class="far fa-envelope"></i>
                <?php if ($mailUnreadCount > 0): ?>
                    <span style="position:absolute; top:-6px; right:-6px; min-width:16px; height:16px; padding:0 4px; border-radius:999px; background:#ef4444; color:#fff; font-size:10px; font-weight:700; line-height:16px; text-align:center;"><?php echo $mailUnreadCount > 99 ? '99+' : $mailUnreadCount; ?></span>
                <?php endif; ?>
            </a>
            <div class="profile-dropdown" style="position:relative;">
                <button id="profileDropBtn" style="background:none; border:none; font-size:0.98rem; cursor:pointer; padding:0 6px;">
                    <i class="fas fa-chevron-down"></i>
                </button>
                <div id="profileDropMenu" style="display:none; position:absolute; top:120%; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.08); min-width:140px; z-index:100;">
                    <a href="dashboard.php" style="display:block; padding:8px 14px; color:#1f2937; text-decoration:none; font-weight:600; font-size:0.92rem; border-bottom:1px solid #f1f5f9;">Profile</a>
                    <a href="services.php?tab=apply" style="display:block; padding:8px 14px; color:#1f2937; text-decoration:none; font-weight:600; font-size:0.92rem; border-bottom:1px solid #f1f5f9;">Services</a>
                    <a href="logout.php" style="display:block; padding:8px 14px; color:#dc2626; text-decoration:none; font-weight:600; font-size:0.92rem;">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <div class="chip-row">
        <span style="font-size:1rem; color:#1f7aa8;">PROGRAMME:</span>
        <span style="font-size:1rem;"><?php echo e($registeredProgramName); ?></span>
        <span class="chip" style="background:#16a34a; color:#fff;">ACTIVE</span>
        <span style="margin-left:auto; font-size:1rem; color:#1f7aa8;">ACADEMIC STATUS:</span>
        <span class="chip red" style="<?php echo e($academicStatusStyle); ?>"><?php echo e($academicStatus); ?></span>
    </div>

    <div class="chip-row">
        <span class="chip gray">CURRENT YR. <span style="color:#2563eb;"><?php echo e($currentSemester['academic_year']); ?></span></span>
        <span class="chip gray">CURRENT SEM. <span style="color:#2563eb;"><?php echo e($currentSemester['semester_name']); ?></span></span>
        <span class="chip red" style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;'; ?>"><?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['enrollment_status'] ?? 'not_enrolled') === 'enrolled') ? 'ENROLLED' : 'NOT ENROLLED'; ?></span>
        <span class="chip red" style="<?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;'; ?>"><?php echo ((getStudentLifecycleStatus($conn, (int)($studentProfile['id'] ?? 0), (int)($currentSemester['id'] ?? 0))['registration_status'] ?? 'not_registered') === 'registered') ? 'REGISTERED' : 'NOT REGISTERED'; ?></span>
        <span id="approvedFeesChip" class="chip gray">APPROVED FEES AMOUNT: <?php echo $formatCurrencyForDisplay((float)$approvedFeesAmount); ?></span>
        <span id="totalPaidChip" class="chip green">TOTAL PAID: <?php echo $formatCurrencyForDisplay((float)$totalPaidAmount); ?></span>
        <span id="balanceOnAccountChip" class="chip blue balance-chip"><?php echo e($balanceOnAccountLabel); ?>: <?php echo $formatCurrencyForDisplay((float)$balanceOnAccount); ?></span>
    </div>

    <div class="prn-wrap">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success"><?php echo e($session->getFlash('success')); ?></div>
        <?php endif; ?>
        <?php if (!empty($prnError)): ?>
            <div class="alert alert-danger"><?php echo e($prnError); ?></div>
        <?php endif; ?>
        <?php if ($isMockGatewayMode || $isSandboxGatewayMode): ?>
            <div class="gateway-mode-warning <?php echo $isSandboxGatewayMode ? 'sandbox' : 'mock'; ?>">
                <?php if ($isSandboxGatewayMode): ?>
                    SANDBOX MODE ACTIVE: provider API calls are enabled for test endpoints. Use sandbox credentials and a reachable webhook URL; no live collections should be used here.
                <?php else: ?>
                    MOCK MODE ACTIVE: mobile money prompts and callbacks are simulated for demo/testing, including automatic PRN posting to ledger. Set <code>PAYMENT_GATEWAY_MODE</code> to <code>sandbox</code> or <code>live</code> for real provider processing.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="prn-card">
            <div class="prn-tabs">
                <button type="button" class="prn-tab <?php echo $activePrnTab === 'new_prn' ? 'active' : ''; ?>" data-prn-tab="new_prn">GENERATE NEW PRN</button>
                <button type="button" class="prn-tab <?php echo $activePrnTab === 'payment_refs' ? 'active' : ''; ?>" data-prn-tab="payment_refs">MY PAYMENT REFs</button>
                <button type="button" class="prn-tab <?php echo $activePrnTab === 'payment_methods' ? 'active' : ''; ?>" data-prn-tab="payment_methods">PAYMENT METHODS</button>
            </div>

            <div id="prnTab_new_prn" class="prn-tab-panel" style="<?php echo $activePrnTab === 'new_prn' ? '' : 'display:none;'; ?>">
                <?php if (!empty($invoiceDataError)): ?>
                    <div class="alert alert-warning mb-2"><?php echo e($invoiceDataError); ?></div>
                <?php elseif (!empty($unpaidInvoices)): ?>
                    <div class="notice" style="background:#ecfdf3; border-color:#86efac; color:#166534;">
                        You have <?php echo count($unpaidInvoices); ?> unpaid invoice(s). Total outstanding: <?php echo $formatCurrencyForDisplay((float)$unpaidInvoicesTotal); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($generatedPrn)): ?>
                    <div class="prn-generated">
                        <div>Generated PRN: <?php echo e($generatedPrn); ?></div>
                        <div>Type: <?php echo e($referenceTypeLabel($generatedRefType)); ?> | Amount: <?php echo $formatCurrencyForDisplay((float)$generatedAmountUgx); ?></div>
                        <?php if (!empty($generatedPrnExpiresAt)): ?>
                            <div>Expires: <?php echo e(date('d M Y, h:i A', strtotime($generatedPrnExpiresAt))); ?></div>
                        <?php endif; ?>
                        <div class="prn-generated-actions">
                            <button type="button" class="refs-reload-btn copy-prn-btn" data-prn="<?php echo e($generatedPrn); ?>">COPY PRN</button>
                            <button
                                type="button"
                                class="prn-generate-btn open-methods-btn"
                                data-prn="<?php echo e($generatedPrn); ?>"
                                data-amount="<?php echo e($formatCurrencyForDisplay((float)$generatedAmountUgx)); ?>"
                                data-type="<?php echo e($referenceTypeLabel($generatedRefType)); ?>"
                                data-expiry="<?php echo !empty($generatedPrnExpiresAt) ? e(date('d M Y, h:i A', strtotime($generatedPrnExpiresAt))) : '-'; ?>"
                            >PAY WITH THIS PRN</button>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="acc-item">
                    <button type="button" class="acc-head" data-acc="all_pending">GENERATE PRN TO PAY FOR ALL PENDING INVOICES</button>
                    <div class="acc-body" id="acc_all_pending">
                        <?php if (!empty($unpaidInvoices)): ?>
                            <div style="font-size:0.86rem; margin-bottom:8px; color:#334155;">
                                All pending invoices selected. Total amount: <strong><?php echo $formatCurrencyForDisplay((float)$unpaidInvoicesTotal); ?></strong>
                            </div>
                            <table class="prn-list-table">
                                <thead>
                                    <tr>
                                        <th>Invoice No.</th>
                                        <th>Semester</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th style="text-align:right;">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unpaidInvoices as $invoice): ?>
                                        <?php $invStatus = strtolower((string)($invoice['status'] ?? 'pending')); ?>
                                        <tr>
                                            <td><?php echo e($invoice['invoice_number'] ?? '-'); ?></td>
                                            <td><?php echo e(trim((string)($invoice['academic_year'] ?? '-') . ' ' . (string)($invoice['semester_name'] ?? ''))); ?></td>
                                            <td><?php echo !empty($invoice['due_date']) ? e(date('d M Y', strtotime($invoice['due_date']))) : '-'; ?></td>
                                            <td><span class="status-pill <?php echo e($invStatus); ?>"><?php echo e(strtoupper($invStatus)); ?></span></td>
                                            <td style="text-align:right;"><?php echo $formatCurrencyForDisplay((float)($invoice['balance'] ?? 0)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <form method="POST" style="margin-top:10px; display:flex; justify-content:flex-end;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="generate_all_pending_prn">
                                <input type="hidden" name="prn_tab" value="new_prn">
                                <button type="submit" class="prn-generate-btn">GENERATE PRN FOR TOTAL</button>
                            </form>
                        <?php else: ?>
                            No pending invoices found.
                        <?php endif; ?>
                    </div>
                </div>
                <div class="acc-item">
                    <button type="button" class="acc-head" data-acc="partial_pending">GENERATE PRN TO MAKE PARTIAL PAYMENT ON PENDING INVOICES</button>
                    <div class="acc-body" id="acc_partial_pending">
                        <?php if (!empty($unpaidInvoices)): ?>
                            <table class="prn-list-table">
                                <thead>
                                    <tr>
                                        <th>Invoice No.</th>
                                        <th style="text-align:right;">Balance</th>
                                        <th>Generate Partial PRN</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unpaidInvoices as $invoice): ?>
                                        <?php
                                            $invoiceBalanceUgx = (float)($invoice['balance'] ?? 0);
                                            $invoiceBalanceDisplay = $convertAmountForDisplay($invoiceBalanceUgx);
                                            $partialInputValue = number_format($invoiceBalanceDisplay, $isInternationalStudent ? 2 : 0, '.', '');
                                        ?>
                                        <tr>
                                            <td><?php echo e($invoice['invoice_number'] ?? '-'); ?></td>
                                            <td style="text-align:right;"><?php echo $formatCurrencyForDisplay($invoiceBalanceUgx); ?></td>
                                            <td>
                                                <form method="POST" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="generate_partial_prn">
                                                    <input type="hidden" name="invoice_id" value="<?php echo (int)($invoice['id'] ?? 0); ?>">
                                                    <input type="hidden" name="prn_tab" value="new_prn">
                                                    <input type="number" name="partial_amount" min="<?php echo $isInternationalStudent ? '0.01' : '1'; ?>" step="0.01" class="prn-input" style="width:150px;" value="<?php echo e($partialInputValue); ?>" required>
                                                    <button type="submit" class="prn-generate-btn" style="padding:6px 10px; font-size:0.8rem;">GENERATE</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div style="margin-top:8px; font-size:0.8rem; color:#64748b;">
                                Use "Deposit to my account" below if you want to pay any custom amount.
                            </div>
                        <?php else: ?>
                            No pending invoices available for partial payment.
                        <?php endif; ?>
                    </div>
                </div>
                <div class="acc-item">
                    <button type="button" class="acc-head active" data-acc="deposit_account">GENERATE PRN TO DEPOSIT TO MY ACCOUNT</button>
                    <div class="acc-body active" id="acc_deposit_account">
                        <form method="POST" style="display:flex; align-items:end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="generate_deposit_prn">
                            <input type="hidden" name="prn_tab" value="new_prn">
                            <div>
                                <label style="font-size:1rem; margin-bottom:6px; display:block;"><span style="color:#dc2626;">*</span> AMOUNT TO DEPOSIT (<?php echo e($studentDisplayCurrency); ?>):</label>
                                <input type="number" name="deposit_amount" min="<?php echo $isInternationalStudent ? '0.01' : '1'; ?>" step="0.01" class="prn-input" value="<?php echo e($depositAmountInput); ?>" required>
                            </div>
                            <button type="submit" class="prn-generate-btn">GENERATE PRN</button>
                        </form>
                    </div>
                </div>
            </div>

            <div id="prnTab_payment_refs" class="prn-tab-panel" style="<?php echo $activePrnTab === 'payment_refs' ? '' : 'display:none;'; ?>">
                <?php if (!empty($paymentRefDataError)): ?>
                    <div class="alert alert-warning mb-2"><?php echo e($paymentRefDataError); ?></div>
                <?php endif; ?>

                <div class="refs-toolbar">
                    <div class="refs-groups">
                        <button type="button" class="refs-group-btn active" data-ref-group="active_refs">Active References (<?php echo count($activePaymentRefs); ?>)</button>
                        <button type="button" class="refs-group-btn" data-ref-group="expired_refs">Expired References (<?php echo count($expiredPaymentRefs); ?>)</button>
                        <button type="button" class="refs-group-btn" data-ref-group="paid_refs">Paid/Used (<?php echo count($paidPaymentRefs); ?>)</button>
                    </div>
                    <button type="button" id="reloadPaymentRefsBtn" class="refs-reload-btn">RELOAD</button>
                </div>

                <div id="refGroup_active_refs">
                    <?php if (!empty($activePaymentRefs)): ?>
                        <table class="prn-list-table">
                            <thead>
                                <tr>
                                    <th>PRN</th>
                                    <th>Type</th>
                                    <th>Invoice</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th>Expires</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activePaymentRefs as $ref): ?>
                                    <?php
                                        $refNumber = (string)($ref['reference_number'] ?? '-');
                                        $invoiceLabel = !empty($ref['invoice_number']) ? (string)$ref['invoice_number'] : (($ref['reference_type'] ?? '') === 'all_pending' ? 'MULTIPLE' : '-');
                                        $expiresLabel = !empty($ref['expires_at']) ? date('d M Y, h:i A', strtotime((string)$ref['expires_at'])) : '-';
                                        $amountLabel = $formatCurrencyForDisplay((float)($ref['amount'] ?? 0));
                                    ?>
                                    <?php $refTypeLabel = $referenceTypeLabel($ref['reference_type'] ?? 'deposit'); ?>
                                    <tr>
                                        <td><?php echo e($refNumber); ?></td>
                                        <td><?php echo e($refTypeLabel); ?></td>
                                        <td><?php echo e($invoiceLabel); ?></td>
                                        <td style="text-align:right;"><?php echo $amountLabel; ?></td>
                                        <td><?php echo e($expiresLabel); ?></td>
                                        <td><span class="status-pill active-ref">ACTIVE</span></td>
                                        <td style="text-align:right;">
                                            <div class="ref-action-row">
                                                <button type="button" class="refs-reload-btn copy-prn-btn" data-prn="<?php echo e($refNumber); ?>">COPY</button>
                                                <button
                                                    type="button"
                                                    class="prn-generate-btn open-methods-btn"
                                                    data-prn="<?php echo e($refNumber); ?>"
                                                    data-amount="<?php echo e($amountLabel); ?>"
                                                    data-type="<?php echo e($refTypeLabel); ?>"
                                                    data-expiry="<?php echo e($expiresLabel); ?>"
                                                >PAY NOW</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="notice" style="background:#eef2ff; color:#334155; border-color:#cbd5e1;">No active references. Generate a new PRN to start payment.</div>
                    <?php endif; ?>
                </div>

                <div id="refGroup_expired_refs" style="display:none;">
                    <?php if (!empty($expiredPaymentRefs)): ?>
                        <table class="prn-list-table">
                            <thead>
                                <tr>
                                    <th>PRN</th>
                                    <th>Type</th>
                                    <th>Invoice</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th>Expired On</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expiredPaymentRefs as $ref): ?>
                                    <?php
                                        $refNumber = (string)($ref['reference_number'] ?? '-');
                                        $invoiceLabel = !empty($ref['invoice_number']) ? (string)$ref['invoice_number'] : (($ref['reference_type'] ?? '') === 'all_pending' ? 'MULTIPLE' : '-');
                                        $expiresLabel = !empty($ref['expires_at']) ? date('d M Y, h:i A', strtotime((string)$ref['expires_at'])) : '-';
                                    ?>
                                    <tr>
                                        <td><?php echo e($refNumber); ?></td>
                                        <td><?php echo e($referenceTypeLabel($ref['reference_type'] ?? 'deposit')); ?></td>
                                        <td><?php echo e($invoiceLabel); ?></td>
                                        <td style="text-align:right;"><?php echo $formatCurrencyForDisplay((float)($ref['amount'] ?? 0)); ?></td>
                                        <td><?php echo e($expiresLabel); ?></td>
                                        <td><span class="status-pill expired-ref">EXPIRED</span></td>
                                        <td style="text-align:right;">
                                            <button type="button" class="refs-reload-btn copy-prn-btn" data-prn="<?php echo e($refNumber); ?>">COPY</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="notice" style="background:#f8fafc; color:#64748b; border-color:#e2e8f0;">No expired references.</div>
                    <?php endif; ?>
                </div>

                <div id="refGroup_paid_refs" style="display:none;">
                    <?php if (!empty($paidPaymentRefs)): ?>
                        <table class="prn-list-table">
                            <thead>
                                <tr>
                                    <th>PRN</th>
                                    <th>Type</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th>Paid On</th>
                                    <th>Receipt</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paidPaymentRefs as $ref): ?>
                                    <?php
                                        $paidAmountUgx = (float)($ref['paid_amount'] ?? 0);
                                        if ($paidAmountUgx <= 0) {
                                            $paidAmountUgx = (float)($ref['amount'] ?? 0);
                                        }
                                        $methodLabel = strtoupper(str_replace('_', ' ', (string)($ref['payment_method'] ?? '-')));
                                    ?>
                                    <tr>
                                        <td><?php echo e($ref['reference_number'] ?? '-'); ?></td>
                                        <td><?php echo e($referenceTypeLabel($ref['reference_type'] ?? 'deposit')); ?></td>
                                        <td style="text-align:right;"><?php echo $formatCurrencyForDisplay($paidAmountUgx); ?></td>
                                        <td><?php echo !empty($ref['payment_date']) ? e(date('d M Y', strtotime((string)$ref['payment_date']))) : '-'; ?></td>
                                        <td><?php echo e($ref['receipt_number'] ?: ($ref['payment_id'] ?? '-')); ?></td>
                                        <td><?php echo e($methodLabel); ?></td>
                                        <td><span class="status-pill paid-ref">PAID</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="notice" style="background:#f8fafc; color:#64748b; border-color:#e2e8f0;">No paid references yet.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div id="prnTab_payment_methods" class="prn-tab-panel" style="<?php echo $activePrnTab === 'payment_methods' ? '' : 'display:none;'; ?>">
                <div class="methods-wrap">
                    <?php
                        $methodHasSeed = !empty($latestActivePaymentRef);
                        $methodSeedPrn = '';
                        $methodSeedAmount = '-';
                        $methodSeedExpiry = '-';
                        $methodSeedType = '-';
                        if ($methodHasSeed) {
                            $methodSeedPrn = (string)($latestActivePaymentRef['reference_number'] ?? '');
                            $methodSeedAmount = $formatCurrencyForDisplay((float)($latestActivePaymentRef['amount'] ?? 0));
                            $methodSeedExpiry = !empty($latestActivePaymentRef['expires_at']) ? date('d M Y, h:i A', strtotime((string)$latestActivePaymentRef['expires_at'])) : '-';
                            $methodSeedType = $referenceTypeLabel((string)($latestActivePaymentRef['reference_type'] ?? 'deposit'));
                        }
                    ?>
                    <div
                        id="methodCurrentRefBox"
                        class="notice method-current-ref"
                        style="background:#ecfdf3; border-color:#86efac; color:#166534; <?php echo $methodHasSeed ? '' : 'display:none;'; ?>"
                    >
                        <div>
                            Current PRN:
                            <span id="methodCurrentPrn" class="method-current-prn"><?php echo e($methodSeedPrn); ?></span>
                            | Type:
                            <strong id="methodCurrentType"><?php echo e($methodSeedType); ?></strong>
                            | Amount:
                            <strong id="methodCurrentAmount"><?php echo e($methodSeedAmount); ?></strong>
                            | Expires:
                            <strong id="methodCurrentExpiry"><?php echo e($methodSeedExpiry); ?></strong>
                        </div>
                        <button type="button" id="methodCurrentCopyBtn" class="refs-reload-btn copy-prn-btn" data-prn="<?php echo e($methodSeedPrn); ?>">COPY PRN</button>
                    </div>
                    <div
                        id="methodNoPrnNotice"
                        class="notice"
                        style="background:#fef3c7; border-color:#f3d88c; color:#7c5f14; <?php echo $methodHasSeed ? 'display:none;' : ''; ?>"
                    >
                        You do not have an active PRN yet.
                        <button type="button" class="prn-generate-btn" data-open-prn-tab="new_prn" style="margin-left:8px; padding:6px 10px; font-size:0.8rem;">GENERATE PRN</button>
                    </div>

                    <div class="methods-tabs">
                        <button type="button" class="method-btn active" data-method-tab="bank">HOW TO PAY AT BANK</button>
                        <button type="button" class="method-btn" data-method-tab="mobile">HOW TO PAY WITH MOBILE MONEY</button>
                        <button type="button" class="method-btn" data-method-tab="visa">HOW TO PAY WITH VISA</button>
                    </div>

                    <div id="methodPanel_bank" class="method-panel active">
                        <ol class="method-list">
                            <li>Visit your preferred bank branch or banking app.</li>
                            <li>Share your active Payment Reference Number (PRN) and payment amount.</li>
                            <li>Keep the bank receipt and then confirm status in CHECK PRN STATUS.</li>
                        </ol>
                        <div class="bank-account-card">
                            <div class="bank-account-title">INSTITUTION BANK DETAILS</div>
                            <div class="bank-account-grid">
                                <div class="bank-account-item full">
                                    <div class="bank-account-label">Account Name</div>
                                    <div class="bank-account-value"><?php echo e($institutionBankAccountName !== '' ? $institutionBankAccountName : 'NOT SET'); ?></div>
                                </div>
                                <div class="bank-account-item">
                                    <div class="bank-account-label">Account Number</div>
                                    <div class="bank-account-value">
                                        <span><?php echo e($institutionBankAccountNumber !== '' ? $institutionBankAccountNumber : 'NOT SET'); ?></span>
                                        <?php if ($institutionBankAccountNumber !== ''): ?>
                                            <button type="button" class="bank-copy-btn copy-bank-detail-btn" data-copy="<?php echo e($institutionBankAccountNumber); ?>">COPY</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="bank-account-item">
                                    <div class="bank-account-label">Branch</div>
                                    <div class="bank-account-value"><?php echo e($institutionBankBranch !== '' ? $institutionBankBranch : 'NOT SET'); ?></div>
                                </div>
                                <div class="bank-account-item">
                                    <div class="bank-account-label">SWIFT Code</div>
                                    <div class="bank-account-value">
                                        <span><?php echo e($institutionBankSwift !== '' ? $institutionBankSwift : 'NOT SET'); ?></span>
                                        <?php if ($institutionBankSwift !== ''): ?>
                                            <button type="button" class="bank-copy-btn copy-bank-detail-btn" data-copy="<?php echo e($institutionBankSwift); ?>">COPY</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="bank-account-note">Set values in <code>config.php</code>: <code>BANK_ACCOUNT_NAME</code>, <code>BANK_ACCOUNT_NUMBER</code>, <code>BANK_BRANCH</code>, <code>BANK_SWIFT</code>.</div>
                        </div>
                        <div class="bank-proof-card">
                            <div class="bank-proof-title">SUBMIT BANK TRANSFER PROOF / REFERENCE</div>
                            <div class="bank-proof-grid">
                                <div class="bank-proof-field">
                                    <label for="bankPaymentMethod">Payment Channel</label>
                                    <select id="bankPaymentMethod">
                                        <option value="bank_agent" selected>Bank Agent (All Agents)</option>
                                        <option value="cente_agent">CenteAgent</option>
                                        <option value="bank_transfer">Bank Branch / Bank App Transfer</option>
                                    </select>
                                </div>
                                <div class="bank-proof-field">
                                    <label for="bankTransferBankName">Bank Name</label>
                                    <input type="text" id="bankTransferBankName" placeholder="e.g. Stanbic Bank">
                                </div>
                                <div class="bank-proof-field">
                                    <label for="bankTransferRef">Transfer/Receipt Reference</label>
                                    <input type="text" id="bankTransferRef" placeholder="e.g. BTRX9482211">
                                </div>
                                <div class="bank-proof-field">
                                    <label for="bankTransferAmount">Amount Paid (UGX)</label>
                                    <input type="number" min="1" step="0.01" id="bankTransferAmount" placeholder="e.g. 700000">
                                </div>
                                <div class="bank-proof-field">
                                    <label for="bankDepositorName">Depositor Name (Optional)</label>
                                    <input type="text" id="bankDepositorName" placeholder="Name on bank slip">
                                </div>
                                <div class="bank-proof-field full">
                                    <label for="bankTransferNotes">Notes / Proof Details (Optional)</label>
                                    <textarea id="bankTransferNotes" placeholder="Branch, date/time, teller details, or any proof context"></textarea>
                                </div>
                            </div>
                            <div class="bank-proof-actions">
                                <button type="button" id="bankSubmitBtn" class="prn-generate-btn">SUBMIT FOR VERIFICATION</button>
                                <button type="button" id="bankCheckStatusBtn" class="mobile-pay-check-btn">CHECK STATUS NOW</button>
                            </div>
                            <div id="bankPayStatus" class="bank-pay-status">Submit your bank transfer proof so finance can verify and post it to your ledger.</div>
                            <div id="bankPayMeta" class="bank-pay-meta"></div>
                        </div>
                    </div>

                    <div id="methodPanel_mobile" class="method-panel">
                        <div class="mobile-money-grid">
                            <div class="mobile-money-col">
                                <div class="mobile-money-title">PAY WITH MTN MOBILE MONEY</div>
                                <ol class="method-list">
                                    <li>Dial <span class="dial-code">*165*18#</span></li>
                                    <li>Follow prompts and enter your Payment Reference Number (PRN)</li>
                                    <li>Confirm recipient and complete payment</li>
                                </ol>
                            </div>
                            <div class="mobile-money-col">
                                <div class="mobile-money-title">PAY WITH AIRTEL MONEY</div>
                                <ol class="method-list">
                                    <li>Dial <span class="dial-code">*165*4*7*1#</span></li>
                                    <li>Follow prompts and enter your Payment Reference Number (PRN)</li>
                                    <li>Confirm recipient and complete payment</li>
                                </ol>
                            </div>
                        </div>
                        <div class="mobile-pay-card">
                            <div class="mobile-pay-title">INSTANT MOBILE MONEY PAYMENT REQUEST</div>
                            <div class="mobile-pay-grid">
                                <div class="mobile-pay-field">
                                    <label for="mobilePayProvider">Provider</label>
                                    <select id="mobilePayProvider">
                                        <option value="mtn">MTN Mobile Money</option>
                                        <option value="airtel">Airtel Money</option>
                                    </select>
                                </div>
                                <div class="mobile-pay-field">
                                    <label for="mobilePayPhone">Phone Number (MSISDN)</label>
                                    <input type="tel" id="mobilePayPhone" placeholder="e.g. 0782123456 / 0772123456 or 256782123456">
                                </div>
                            </div>
                            <div class="mobile-pay-actions">
                                <button type="button" id="mobileInitiateBtn" class="prn-generate-btn">REQUEST PAYMENT PROMPT</button>
                                <button type="button" id="mobileCheckStatusBtn" class="mobile-pay-check-btn">CHECK STATUS NOW</button>
                            </div>
                            <div id="mobilePayStatus" class="mobile-pay-status">Select provider and phone number, then request payment for the current PRN.</div>
                            <div id="mobilePayMeta" class="mobile-pay-meta"></div>
                        </div>
                    </div>

                    <div id="methodPanel_visa" class="method-panel">
                        <ol class="method-list">
                            <li>Use an approved secure card payment channel.</li>
                            <li>Enter your card details and the Payment Reference Number (PRN).</li>
                            <li>Authorize the transaction (OTP/3D Secure).</li>
                            <li>After payment, confirm status from CHECK PRN STATUS.</li>
                        </ol>
                    </div>

                    <div class="notice" style="margin-top:10px; background:#eef2ff; color:#334155; border-color:#cbd5e1;">
                        After payment, finance verifies and posts to your ledger.
                        <a href="payments.php?section=transactions&tx_tab=check_prn" style="font-weight:700; color:#1d4ed8; text-decoration:underline;">CHECK PRN STATUS</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var mobilePayConfig = <?php echo json_encode([
    'initiate_url' => BASE_URL . '/api/payments/initiate.php',
    'bank_submit_url' => BASE_URL . '/api/payments/bank_submit.php',
    'status_url' => BASE_URL . '/api/payments/status.php',
    'csrf_token' => Security::generateCSRFToken()
], JSON_UNESCAPED_SLASHES); ?>;
var financialUiConfig = <?php echo json_encode([
    'display_currency' => $studentDisplayCurrency,
    'is_international' => $isInternationalStudent ? 1 : 0,
    'usd_ugx_rate' => $usdUgxRate > 0 ? $usdUgxRate : 3700
], JSON_UNESCAPED_SLASHES); ?>;
var approvedFeesChipEl = document.getElementById('approvedFeesChip');
var totalPaidChipEl = document.getElementById('totalPaidChip');
var balanceOnAccountChipEl = document.getElementById('balanceOnAccountChip');

document.getElementById('menuBtn').addEventListener('click', function() {
    var sidebar = document.querySelector('.student-sidebar');
    var main = document.querySelector('.main-content');
    sidebar.classList.toggle('sidebar-collapsed');
    if (main) main.classList.toggle('full-width');
});

document.getElementById('profileDropBtn').addEventListener('click', function(e) {
    e.stopPropagation();
    var menu = document.getElementById('profileDropMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', function() {
    var menu = document.getElementById('profileDropMenu');
    if (menu) menu.style.display = 'none';
});

function openPrnTab(target) {
    if (!target) return;
    document.querySelectorAll('.prn-tab').forEach(function(t) {
        if (t.getAttribute('data-prn-tab') === target) {
            t.classList.add('active');
        } else {
            t.classList.remove('active');
        }
    });
    document.querySelectorAll('.prn-tab-panel').forEach(function(p) {
        p.style.display = 'none';
    });
    var panel = document.getElementById('prnTab_' + target);
    if (panel) {
        panel.style.display = '';
    }
}

function activateMethodTab(key) {
    if (!key) return;
    document.querySelectorAll('.method-btn').forEach(function(b) { b.classList.remove('active'); });
    document.querySelectorAll('.method-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.method-btn').forEach(function(btn) {
        if (btn.getAttribute('data-method-tab') === key) {
            btn.classList.add('active');
        }
    });
    var panel = document.getElementById('methodPanel_' + key);
    if (panel) {
        panel.classList.add('active');
    }
}

document.querySelectorAll('.prn-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        openPrnTab(tab.getAttribute('data-prn-tab'));
    });
});

document.querySelectorAll('.acc-head').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var key = btn.getAttribute('data-acc');
        var body = document.getElementById('acc_' + key);
        if (!body) return;
        var isActive = body.classList.contains('active');
        document.querySelectorAll('.acc-head').forEach(function(h) { h.classList.remove('active'); });
        document.querySelectorAll('.acc-body').forEach(function(b) { b.classList.remove('active'); });
        if (!isActive) {
            btn.classList.add('active');
            body.classList.add('active');
        }
    });
});

var reloadPaymentRefsBtn = document.getElementById('reloadPaymentRefsBtn');
if (reloadPaymentRefsBtn) {
    reloadPaymentRefsBtn.addEventListener('click', function() {
        window.location.reload();
    });
}

document.querySelectorAll('.refs-group-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var key = btn.getAttribute('data-ref-group');
        document.querySelectorAll('.refs-group-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('[id^=\"refGroup_\"]').forEach(function(g) { g.style.display = 'none'; });
        btn.classList.add('active');
        var group = document.getElementById('refGroup_' + key);
        if (group) {
            group.style.display = '';
        }
    });
});

function setMethodCurrentReference(prn, amountLabel, typeLabel, expiryLabel) {
    var currentBox = document.getElementById('methodCurrentRefBox');
    var noPrnNotice = document.getElementById('methodNoPrnNotice');
    var prnEl = document.getElementById('methodCurrentPrn');
    var typeEl = document.getElementById('methodCurrentType');
    var amountEl = document.getElementById('methodCurrentAmount');
    var expiryEl = document.getElementById('methodCurrentExpiry');
    var copyBtn = document.getElementById('methodCurrentCopyBtn');
    if (prnEl && prn) {
        prnEl.textContent = prn;
    }
    if (typeEl && typeLabel) {
        typeEl.textContent = typeLabel;
    }
    if (amountEl && amountLabel) {
        amountEl.textContent = amountLabel;
    }
    if (expiryEl && expiryLabel) {
        expiryEl.textContent = expiryLabel;
    }
    if (copyBtn && prn) {
        copyBtn.setAttribute('data-prn', prn);
    }
    if (currentBox) {
        currentBox.style.display = '';
    }
    if (noPrnNotice) {
        noPrnNotice.style.display = 'none';
    }
    setMobilePayMeta('');
    setBankPayMeta('');
}

var mobilePollTimer = null;
var mobilePollAttempts = 0;
var mobilePollMaxAttempts = 15;
var mobileInitiateBtn = document.getElementById('mobileInitiateBtn');
var mobileCheckStatusBtn = document.getElementById('mobileCheckStatusBtn');
var mobileProviderInput = document.getElementById('mobilePayProvider');
var mobilePhoneInput = document.getElementById('mobilePayPhone');
var mobilePayStatusEl = document.getElementById('mobilePayStatus');
var mobilePayMetaEl = document.getElementById('mobilePayMeta');
var bankSubmitBtn = document.getElementById('bankSubmitBtn');
var bankCheckStatusBtn = document.getElementById('bankCheckStatusBtn');
var bankPaymentMethodInput = document.getElementById('bankPaymentMethod');
var bankBankNameInput = document.getElementById('bankTransferBankName');
var bankTransferRefInput = document.getElementById('bankTransferRef');
var bankTransferAmountInput = document.getElementById('bankTransferAmount');
var bankDepositorNameInput = document.getElementById('bankDepositorName');
var bankTransferNotesInput = document.getElementById('bankTransferNotes');
var bankPayStatusEl = document.getElementById('bankPayStatus');
var bankPayMetaEl = document.getElementById('bankPayMeta');

function updateMobilePhonePlaceholder() {
    if (!mobilePhoneInput) return;
    var provider = mobileProviderInput ? (mobileProviderInput.value || 'mtn').toLowerCase() : 'mtn';
    if (provider === 'airtel') {
        mobilePhoneInput.placeholder = 'e.g. 0752123456 / 0742123456 or 256752123456';
    } else {
        mobilePhoneInput.placeholder = 'e.g. 0782123456 / 0772123456 or 256782123456';
    }
}

function getCurrentPrnForPayment() {
    var prnEl = document.getElementById('methodCurrentPrn');
    if (!prnEl) return '';
    return (prnEl.textContent || '').trim();
}

function setMobilePayStatus(message, mode) {
    if (!mobilePayStatusEl) return;
    mobilePayStatusEl.classList.remove('pending', 'success', 'error');
    if (mode === 'pending' || mode === 'success' || mode === 'error') {
        mobilePayStatusEl.classList.add(mode);
    }
    mobilePayStatusEl.textContent = message || '';
}

function setMobilePayMeta(message) {
    if (!mobilePayMetaEl) return;
    mobilePayMetaEl.textContent = message || '';
}

function setBankPayStatus(message, mode) {
    if (!bankPayStatusEl) return;
    bankPayStatusEl.classList.remove('pending', 'success', 'error');
    if (mode === 'pending' || mode === 'success' || mode === 'error') {
        bankPayStatusEl.classList.add(mode);
    }
    bankPayStatusEl.textContent = message || '';
}

function setBankPayMeta(message) {
    if (!bankPayMetaEl) return;
    bankPayMetaEl.textContent = message || '';
}

function clearMobilePolling() {
    if (mobilePollTimer) {
        clearInterval(mobilePollTimer);
        mobilePollTimer = null;
    }
    mobilePollAttempts = 0;
}

function isTerminalTransactionStatus(statusValue) {
    var status = (statusValue || '').toString().toLowerCase();
    if (!status) return false;
    return ['posted', 'paid', 'successful', 'failed', 'cancelled', 'expired'].indexOf(status) !== -1;
}

function formatStatusLine(data) {
    if (!data || typeof data !== 'object') {
        return '';
    }
    var refStatus = (data.reference_status || '').toString().toUpperCase();
    var txStatus = (data.transaction_status || '').toString().toUpperCase();
    var providerStatus = (data.provider_status || '').toString().toUpperCase();
    var bankStatus = (data.bank_status || '').toString().toUpperCase();
    var bankPaymentMethod = (data.bank_payment_method || '').toString().toUpperCase();
    var channel = (data.transaction_channel || '').toString().toUpperCase();
    var transferReference = (data.transfer_reference || '').toString();
    var receipt = (data.receipt_number || data.payment_id || '').toString();
    var parts = [];
    if (refStatus) parts.push('PRN: ' + refStatus);
    if (channel) parts.push('Channel: ' + channel.replace('_', ' '));
    if (txStatus) parts.push('TX: ' + txStatus);
    if (bankPaymentMethod) parts.push('Bank Method: ' + bankPaymentMethod.replace('_', ' '));
    if (bankStatus && !txStatus) parts.push('Bank: ' + bankStatus);
    if (providerStatus) parts.push('Provider: ' + providerStatus);
    if (transferReference) parts.push('Bank Ref: ' + transferReference);
    if (receipt) parts.push('Receipt: ' + receipt);
    return parts.join(' | ');
}

function formatStudentMoneyFromUgx(valueUgx) {
    var amountUgx = Number(valueUgx || 0);
    if (!isFinite(amountUgx)) amountUgx = 0;
    var isInternational = Number(financialUiConfig.is_international || 0) === 1;
    var rate = Number(financialUiConfig.usd_ugx_rate || 3700);
    if (!isFinite(rate) || rate <= 0) {
        rate = 3700;
    }
    var displayAmount = isInternational ? (amountUgx / rate) : amountUgx;
    var decimals = isInternational ? 2 : 0;
    var formatter = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
    return (financialUiConfig.display_currency || 'UGX') + ' ' + formatter.format(displayAmount);
}

function applyFinancialSummary(summary) {
    if (!summary || typeof summary !== 'object') {
        return;
    }
    if (approvedFeesChipEl && summary.approved_fees_ugx != null) {
        approvedFeesChipEl.textContent = 'APPROVED FEES AMOUNT: ' + formatStudentMoneyFromUgx(summary.approved_fees_ugx);
    }
    if (totalPaidChipEl && summary.total_paid_ugx != null) {
        totalPaidChipEl.textContent = 'TOTAL PAID: ' + formatStudentMoneyFromUgx(summary.total_paid_ugx);
    }
    if (balanceOnAccountChipEl && summary.balance_on_account_ugx != null) {
        var creditUgx = Number(summary.account_credit_ugx || 0);
        var balanceLabel = creditUgx > 0 ? 'ACCOUNT CREDIT' : 'BALANCE ON ACCOUNT';
        balanceOnAccountChipEl.textContent = balanceLabel + ': ' + formatStudentMoneyFromUgx(summary.balance_on_account_ugx);
    }
}

function checkCurrentPrnStatus(options) {
    options = options || {};
    var silent = options.silent === true;
    var prn = getCurrentPrnForPayment();
    if (!prn) {
        if (!silent) {
            setMobilePayStatus('No active PRN selected. Choose a PRN first.', 'error');
        }
        return Promise.resolve(null);
    }

    var url = (mobilePayConfig.status_url || '') + '?reference_number=' + encodeURIComponent(prn);
    return fetch(url, {
        method: 'GET',
        credentials: 'same-origin'
    }).then(function(res) {
        return res.json().catch(function() {
            return { success: false, message: 'Invalid status response.' };
        });
    }).then(function(payload) {
        if (!payload || payload.success !== true) {
            if (!silent) {
                setMobilePayStatus((payload && payload.message) ? payload.message : 'Unable to check PRN status right now.', 'error');
            }
            return payload;
        }

        var row = payload.data || {};
        applyFinancialSummary(row.financial_summary || null);
        var txStatus = (row.transaction_status || '').toString().toLowerCase();
        var refStatus = (row.reference_status || '').toString().toLowerCase();
        var channel = (row.transaction_channel || '').toString().toLowerCase();
        var stageText = (row.status_stage || '').toString();
        var mainStatus = txStatus || refStatus;
        var statusLine = formatStatusLine(row);

        if (mainStatus === 'posted' || refStatus === 'paid' || mainStatus === 'paid') {
            setMobilePayStatus('Payment confirmed and posted to your ledger.', 'success');
            if (statusLine) setMobilePayMeta(statusLine);
            setBankPayStatus(stageText || 'Payment confirmed and posted to your ledger.', 'success');
            if (statusLine) setBankPayMeta(statusLine);
            clearMobilePolling();
            return payload;
        }

        if (channel === 'bank_transfer') {
            if (mainStatus === 'received') {
                setBankPayStatus(stageText || 'Bank transfer proof received. Awaiting finance verification.', 'pending');
                if (statusLine) setBankPayMeta(statusLine);
                if (!silent) {
                    setMobilePayStatus(stageText || 'Bank transfer proof received. Awaiting finance verification.', 'pending');
                    if (statusLine) setMobilePayMeta(statusLine);
                }
                clearMobilePolling();
                return payload;
            }
            if (mainStatus === 'verified') {
                setBankPayStatus(stageText || 'Bank transfer verified. Posting to ledger.', 'pending');
                if (statusLine) setBankPayMeta(statusLine);
                if (!silent) {
                    setMobilePayStatus(stageText || 'Bank transfer verified. Posting to ledger.', 'pending');
                    if (statusLine) setMobilePayMeta(statusLine);
                }
                clearMobilePolling();
                return payload;
            }
            if (mainStatus === 'failed') {
                setBankPayStatus(stageText || 'Bank transfer verification failed. Contact finance office.', 'error');
                if (statusLine) setBankPayMeta(statusLine);
                if (!silent) {
                    setMobilePayStatus(stageText || 'Bank transfer verification failed. Contact finance office.', 'error');
                    if (statusLine) setMobilePayMeta(statusLine);
                }
                clearMobilePolling();
                return payload;
            }
        }

        if (mainStatus === 'successful') {
            setMobilePayStatus('Payment is successful and pending ledger posting.', 'pending');
            if (statusLine) setMobilePayMeta(statusLine);
            return payload;
        }
        if (mainStatus === 'failed' || mainStatus === 'cancelled' || mainStatus === 'expired') {
            setMobilePayStatus('Payment request ended with status: ' + mainStatus.toUpperCase() + '.', 'error');
            if (statusLine) setMobilePayMeta(statusLine);
            clearMobilePolling();
            return payload;
        }

        if (!silent) {
            if (channel === 'bank_transfer') {
                setMobilePayStatus(stageText || ('Bank transfer status: ' + (mainStatus ? mainStatus.toUpperCase() : 'PENDING') + '.'), 'pending');
                setBankPayStatus(stageText || ('Bank transfer status: ' + (mainStatus ? mainStatus.toUpperCase() : 'PENDING') + '.'), 'pending');
            } else {
                setMobilePayStatus('Payment status: ' + (mainStatus ? mainStatus.toUpperCase() : 'PENDING') + '. Keep your phone on for the prompt.', 'pending');
            }
            if (statusLine) setMobilePayMeta(statusLine);
            if (statusLine) setBankPayMeta(statusLine);
        } else if (statusLine) {
            setMobilePayMeta(statusLine);
            setBankPayMeta(statusLine);
        }

        return payload;
    }).catch(function() {
        if (!silent) {
            setMobilePayStatus('Network issue while checking PRN status.', 'error');
        }
        return null;
    });
}

function startStatusPolling() {
    clearMobilePolling();
    mobilePollAttempts = 0;
    mobilePollTimer = setInterval(function() {
        mobilePollAttempts += 1;
        checkCurrentPrnStatus({ silent: true }).then(function(payload) {
            var row = (payload && payload.data) ? payload.data : {};
            var txStatus = (row.transaction_status || '').toString().toLowerCase();
            var refStatus = (row.reference_status || '').toString().toLowerCase();
            var channel = (row.transaction_channel || '').toString().toLowerCase();
            var mergedStatus = txStatus || refStatus;

            if (isTerminalTransactionStatus(mergedStatus) || refStatus === 'paid') {
                clearMobilePolling();
                return;
            }
            if (channel === 'bank_transfer' && ['received', 'verified', 'failed'].indexOf(mergedStatus) !== -1) {
                clearMobilePolling();
                return;
            }

            if (mobilePollAttempts >= mobilePollMaxAttempts) {
                clearMobilePolling();
                setMobilePayStatus('Still waiting for payment confirmation. You can check again in a few moments.', 'pending');
                return;
            }
        });
    }, 8000);
}

function copyPlainText(text, done) {
    if (!text) {
        if (typeof done === 'function') done(false);
        return;
    }

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(text).then(function() {
            if (typeof done === 'function') done(true);
        }).catch(function() {
            if (typeof done === 'function') done(false);
        });
        return;
    }

    try {
        var helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', '');
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        document.body.appendChild(helper);
        helper.select();
        var ok = document.execCommand('copy');
        document.body.removeChild(helper);
        if (typeof done === 'function') done(ok);
    } catch (e) {
        if (typeof done === 'function') done(false);
    }
}

document.querySelectorAll('.copy-prn-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var originalLabel = btn.textContent;
        var prn = btn.getAttribute('data-prn') || '';
        copyPlainText(prn, function(ok) {
            btn.textContent = ok ? 'COPIED' : 'COPY FAILED';
            setTimeout(function() {
                btn.textContent = originalLabel;
            }, 1200);
        });
    });
});

document.querySelectorAll('.copy-bank-detail-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var originalLabel = btn.textContent;
        var value = btn.getAttribute('data-copy') || '';
        copyPlainText(value, function(ok) {
            btn.textContent = ok ? 'COPIED' : 'FAILED';
            setTimeout(function() {
                btn.textContent = originalLabel;
            }, 1200);
        });
    });
});

document.querySelectorAll('.open-methods-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var prn = btn.getAttribute('data-prn') || '';
        var amount = btn.getAttribute('data-amount') || '';
        var type = btn.getAttribute('data-type') || '';
        var expiry = btn.getAttribute('data-expiry') || '';
        setMethodCurrentReference(prn, amount, type, expiry);
        openPrnTab('payment_methods');
        activateMethodTab('mobile');
        setMobilePayStatus('PRN selected. Enter phone number and request payment prompt.', 'pending');
        setMobilePayMeta('');
        setBankPayStatus('Submit your bank transfer proof so finance can verify and post it to your ledger.', 'pending');
        setBankPayMeta('');
    });
});

document.querySelectorAll('[data-open-prn-tab]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var tab = btn.getAttribute('data-open-prn-tab') || '';
        openPrnTab(tab);
    });
});

if (mobileInitiateBtn) {
    mobileInitiateBtn.addEventListener('click', function() {
        var prn = getCurrentPrnForPayment();
        var provider = mobileProviderInput ? (mobileProviderInput.value || 'mtn') : 'mtn';
        var phone = mobilePhoneInput ? (mobilePhoneInput.value || '').trim() : '';

        if (!prn) {
            setMobilePayStatus('No active PRN selected. Use PAY NOW on a reference first.', 'error');
            return;
        }
        if (!phone) {
            setMobilePayStatus('Enter a mobile money phone number first.', 'error');
            return;
        }

        mobileInitiateBtn.disabled = true;
        var originalLabel = mobileInitiateBtn.textContent;
        mobileInitiateBtn.textContent = 'SENDING REQUEST...';
        setMobilePayStatus('Submitting payment request to ' + provider.toUpperCase() + '...', 'pending');
        setMobilePayMeta('');

        fetch(mobilePayConfig.initiate_url || '', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': mobilePayConfig.csrf_token || ''
            },
            body: JSON.stringify({
                reference_number: prn,
                msisdn: phone,
                provider: provider,
                csrf_token: mobilePayConfig.csrf_token || ''
            })
        }).then(function(res) {
            return res.json().catch(function() {
                return { success: false, message: 'Invalid initiation response.' };
            });
        }).then(function(payload) {
            if (!payload || payload.success !== true) {
                setMobilePayStatus((payload && payload.message) ? payload.message : 'Payment request was not accepted.', 'error');
                return;
            }

            var txRef = (payload.transaction_ref || '').toString();
            var providerStatus = (payload.provider_status || '').toString().toUpperCase();
            setMobilePayStatus('Prompt sent. Approve the request on your phone.', 'pending');
            setMobilePayMeta((txRef ? 'TX: ' + txRef + ' | ' : '') + 'Provider: ' + (providerStatus || 'PENDING'));
            startStatusPolling();
            checkCurrentPrnStatus({ silent: false });
        }).catch(function() {
            setMobilePayStatus('Network issue while sending payment request.', 'error');
        }).finally(function() {
            mobileInitiateBtn.disabled = false;
            mobileInitiateBtn.textContent = originalLabel;
        });
    });
}

if (mobileCheckStatusBtn) {
    mobileCheckStatusBtn.addEventListener('click', function() {
        checkCurrentPrnStatus({ silent: false }).then(function(payload) {
            var row = (payload && payload.data) ? payload.data : {};
            var txStatus = (row.transaction_status || '').toString().toLowerCase();
            var refStatus = (row.reference_status || '').toString().toLowerCase();
            if (txStatus && !isTerminalTransactionStatus(txStatus) && refStatus !== 'paid') {
                startStatusPolling();
            }
        });
    });
}

if (bankSubmitBtn) {
    bankSubmitBtn.addEventListener('click', function() {
        var prn = getCurrentPrnForPayment();
        var paymentMethod = bankPaymentMethodInput ? (bankPaymentMethodInput.value || 'bank_agent') : 'bank_agent';
        var bankName = bankBankNameInput ? (bankBankNameInput.value || '').trim() : '';
        var transferReference = bankTransferRefInput ? (bankTransferRefInput.value || '').trim() : '';
        var amount = bankTransferAmountInput ? (bankTransferAmountInput.value || '').trim() : '';
        var depositorName = bankDepositorNameInput ? (bankDepositorNameInput.value || '').trim() : '';
        var notes = bankTransferNotesInput ? (bankTransferNotesInput.value || '').trim() : '';

        if (!prn) {
            setBankPayStatus('No active PRN selected. Use PAY NOW on a reference first.', 'error');
            return;
        }
        if (!transferReference) {
            setBankPayStatus('Enter the bank transfer/receipt reference.', 'error');
            return;
        }

        bankSubmitBtn.disabled = true;
        var originalLabel = bankSubmitBtn.textContent;
        bankSubmitBtn.textContent = 'SUBMITTING...';
        setBankPayStatus('Submitting bank transfer proof for verification...', 'pending');
        setBankPayMeta('');

        fetch(mobilePayConfig.bank_submit_url || '', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': mobilePayConfig.csrf_token || ''
            },
            body: JSON.stringify({
                reference_number: prn,
                payment_method: paymentMethod,
                bank_name: bankName,
                transfer_reference: transferReference,
                amount_submitted: amount,
                depositor_name: depositorName,
                notes: notes,
                csrf_token: mobilePayConfig.csrf_token || ''
            })
        }).then(function(res) {
            return res.json().catch(function() {
                return { success: false, message: 'Invalid submission response.' };
            });
        }).then(function(payload) {
            if (!payload || payload.success !== true) {
                setBankPayStatus((payload && payload.message) ? payload.message : 'Submission was not accepted.', 'error');
                return;
            }

            var txRef = (payload.transaction_ref || '').toString();
            setBankPayStatus((payload.message || 'Bank transfer proof received. Awaiting finance verification.'), 'pending');
            if (txRef) {
                setBankPayMeta('Bank TX: ' + txRef);
            }
            checkCurrentPrnStatus({ silent: false });
        }).catch(function() {
            setBankPayStatus('Network issue while submitting bank transfer proof.', 'error');
        }).finally(function() {
            bankSubmitBtn.disabled = false;
            bankSubmitBtn.textContent = originalLabel;
        });
    });
}

if (bankCheckStatusBtn) {
    bankCheckStatusBtn.addEventListener('click', function() {
        checkCurrentPrnStatus({ silent: false });
    });
}

if (mobileProviderInput) {
    mobileProviderInput.addEventListener('change', updateMobilePhonePlaceholder);
}
updateMobilePhonePlaceholder();
setBankPayStatus('Submit your bank transfer proof so finance can verify and post it to your ledger.', 'pending');

document.querySelectorAll('.method-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var key = btn.getAttribute('data-method-tab');
        activateMethodTab(key);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>


