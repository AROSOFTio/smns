<?php
/**
 * Seed demo finance records for stakeholder walkthroughs.
 *
 * Purpose:
 * - Populate invoices, invoice items, payments, and student_balances
 * - Prioritize graduated/completed students
 * - Also fill students who have been added to the system but still show zero finance data
 *
 * Safety:
 * - Idempotent for generated demo records
 * - Skips semester terms that already have meaningful finance data
 */

require_once __DIR__ . '/../config.php';

$db = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function demoColumnExists(PDO $conn, string $table, string $column): bool
{
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM {$table} LIKE :column_name");
        $stmt->execute(['column_name' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function demoResolveFinanceStaffId(PDO $conn): int
{
    try {
        $stmt = $conn->query("
            SELECT fs.id
            FROM finance_staff fs
            LEFT JOIN users u ON u.id = fs.user_id
            ORDER BY
                CASE WHEN COALESCE(u.status, 'active') = 'active' THEN 0 ELSE 1 END,
                fs.id ASC
            LIMIT 1
        ");
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function demoEnsureStudentBalanceProcedure(PDO $conn): void
{
    try {
        $procStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()
              AND ROUTINE_TYPE = 'PROCEDURE'
              AND ROUTINE_NAME = 'sp_update_student_balance'
        ");
        $procStmt->execute();
        if ((int)$procStmt->fetchColumn() > 0) {
            return;
        }

        $conn->exec("
            CREATE PROCEDURE sp_update_student_balance(
                IN p_student_id INT,
                IN p_semester_id INT
            )
            BEGIN
                DECLARE v_total_fees DECIMAL(10,2);
                DECLARE v_total_paid DECIMAL(10,2);
                DECLARE v_balance DECIMAL(10,2);
                DECLARE v_last_payment_date DATE;

                SELECT IFNULL(SUM(total_amount), 0.00)
                INTO v_total_fees
                FROM invoices
                WHERE student_id = p_student_id
                  AND semester_id = p_semester_id;

                SELECT IFNULL(SUM(amount), 0.00), MAX(payment_date)
                INTO v_total_paid, v_last_payment_date
                FROM payments
                WHERE student_id = p_student_id
                  AND semester_id = p_semester_id;

                SET v_balance = v_total_fees - v_total_paid;

                INSERT INTO student_balances (
                    student_id,
                    semester_id,
                    total_fees,
                    total_paid,
                    balance,
                    last_payment_date
                ) VALUES (
                    p_student_id,
                    p_semester_id,
                    v_total_fees,
                    v_total_paid,
                    v_balance,
                    v_last_payment_date
                )
                ON DUPLICATE KEY UPDATE
                    total_fees = v_total_fees,
                    total_paid = v_total_paid,
                    balance = v_balance,
                    last_payment_date = v_last_payment_date;
            END
        ");
    } catch (Exception $e) {
        throw new RuntimeException('Unable to ensure sp_update_student_balance procedure: ' . $e->getMessage(), 0, $e);
    }
}

function demoNormalizeOutlierPayments(PDO $conn): int
{
    $updated = 0;

    try {
        $stmt = $conn->query("
            SELECT id, student_id, invoice_id, semester_id
            FROM payments
            WHERE amount = 99999999.99
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $paymentId = (int)($row['id'] ?? 0);
            $studentId = (int)($row['student_id'] ?? 0);
            $invoiceId = (int)($row['invoice_id'] ?? 0);
            $semesterId = (int)($row['semester_id'] ?? 0);
            if ($paymentId <= 0 || $studentId <= 0 || $semesterId <= 0) {
                continue;
            }

            $conn->beginTransaction();

            $updateStmt = $conn->prepare("
                UPDATE payments
                SET amount = 1000000.00,
                    notes = CONCAT(COALESCE(notes, ''), ' [Normalized stakeholder demo outlier from UGX 99,999,999.99 to UGX 1,000,000.00]')
                WHERE id = :id
            ");
            $updateStmt->execute(['id' => $paymentId]);

            if ($invoiceId > 0) {
                $invoiceStmt = $conn->prepare("
                    UPDATE invoices i
                    SET
                        amount_paid = (
                            SELECT COALESCE(SUM(p.amount), 0)
                            FROM payments p
                            WHERE p.invoice_id = i.id
                        ),
                        balance = GREATEST(
                            i.total_amount - (
                                SELECT COALESCE(SUM(p.amount), 0)
                                FROM payments p
                                WHERE p.invoice_id = i.id
                            ),
                            0
                        ),
                        status = CASE
                            WHEN (
                                i.total_amount - (
                                    SELECT COALESCE(SUM(p.amount), 0)
                                    FROM payments p
                                    WHERE p.invoice_id = i.id
                                )
                            ) <= 0 THEN 'paid'
                            WHEN (
                                SELECT COALESCE(SUM(p.amount), 0)
                                FROM payments p
                                WHERE p.invoice_id = i.id
                            ) > 0 THEN 'partial'
                            ELSE 'pending'
                        END
                    WHERE i.id = :invoice_id
                ");
                $invoiceStmt->execute(['invoice_id' => $invoiceId]);
            }

            $balanceStmt = $conn->prepare("
                INSERT INTO student_balances (
                    student_id,
                    semester_id,
                    total_fees,
                    total_paid,
                    balance,
                    last_payment_date
                )
                SELECT
                    :student_id,
                    :semester_id,
                    COALESCE((SELECT SUM(total_amount) FROM invoices WHERE student_id = :student_id2 AND semester_id = :semester_id2), 0),
                    COALESCE((SELECT SUM(amount) FROM payments WHERE student_id = :student_id3 AND semester_id = :semester_id3), 0),
                    GREATEST(
                        COALESCE((SELECT SUM(total_amount) FROM invoices WHERE student_id = :student_id4 AND semester_id = :semester_id4), 0)
                        - COALESCE((SELECT SUM(amount) FROM payments WHERE student_id = :student_id5 AND semester_id = :semester_id5), 0),
                        0
                    ),
                    (SELECT MAX(payment_date) FROM payments WHERE student_id = :student_id6 AND semester_id = :semester_id6)
                ON DUPLICATE KEY UPDATE
                    total_fees = VALUES(total_fees),
                    total_paid = VALUES(total_paid),
                    balance = VALUES(balance),
                    last_payment_date = VALUES(last_payment_date),
                    updated_at = NOW()
            ");
            $balanceStmt->execute([
                'student_id' => $studentId,
                'semester_id' => $semesterId,
                'student_id2' => $studentId,
                'semester_id2' => $semesterId,
                'student_id3' => $studentId,
                'semester_id3' => $semesterId,
                'student_id4' => $studentId,
                'semester_id4' => $semesterId,
                'student_id5' => $studentId,
                'semester_id5' => $semesterId,
                'student_id6' => $studentId,
                'semester_id6' => $semesterId,
            ]);

            $conn->commit();
            $updated++;
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw new RuntimeException('Unable to normalize outlier payments: ' . $e->getMessage(), 0, $e);
    }

    return $updated;
}

function demoFindExistingInvoice(PDO $conn, int $studentId, int $semesterId, string $invoiceNumber): array
{
    try {
        $stmt = $conn->prepare("
            SELECT id, invoice_number, total_amount, amount_paid, balance, status
            FROM invoices
            WHERE invoice_number = :invoice_number
               OR (student_id = :student_id AND semester_id = :semester_id)
            ORDER BY CASE WHEN invoice_number = :preferred_invoice_number THEN 0 ELSE 1 END, id ASC
            LIMIT 1
        ");
        $stmt->execute([
            'invoice_number' => $invoiceNumber,
            'preferred_invoice_number' => $invoiceNumber,
            'student_id' => $studentId,
            'semester_id' => $semesterId,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

function demoEnsureInvoiceItems(PDO $conn, int $invoiceId, float $feeTotal, string $createdAt): void
{
    if ($invoiceId <= 0 || $feeTotal <= 0) {
        return;
    }

    try {
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM invoice_items WHERE invoice_id = :invoice_id");
        $countStmt->execute(['invoice_id' => $invoiceId]);
        if ((int)$countStmt->fetchColumn() > 0) {
            return;
        }

        $itemStmt = $conn->prepare("
            INSERT INTO invoice_items (invoice_id, fee_structure_id, description, amount, created_at)
            VALUES (:invoice_id, NULL, :description, :amount, :created_at)
        ");
        foreach (demoResolveItemSplit($feeTotal) as $item) {
            if ((float)$item['amount'] <= 0) {
                continue;
            }
            $itemStmt->execute([
                'invoice_id' => $invoiceId,
                'description' => (string)$item['description'],
                'amount' => (float)$item['amount'],
                'created_at' => $createdAt,
            ]);
        }
    } catch (Exception $e) {
        throw new RuntimeException('Unable to ensure invoice items: ' . $e->getMessage(), 0, $e);
    }
}

function demoUpsertPayment(PDO $conn, string $sql, array $params): bool
{
    try {
        $existsStmt = $conn->prepare("SELECT id FROM payments WHERE payment_id = :payment_id LIMIT 1");
        $existsStmt->execute(['payment_id' => (string)$params['payment_id']]);
        if ($existsStmt->fetchColumn()) {
            return false;
        }

        $paymentStmt = $conn->prepare($sql);
        $paymentStmt->execute($params);
        return true;
    } catch (Exception $e) {
        throw new RuntimeException('Unable to seed demo payment ' . (string)($params['payment_id'] ?? '-') . ': ' . $e->getMessage(), 0, $e);
    }
}

function demoReconcileStudentSemesterFinance(PDO $conn, int $studentId, int $semesterId): void
{
    if ($studentId <= 0 || $semesterId <= 0) {
        return;
    }

    try {
        $invoiceRowsStmt = $conn->prepare("
            SELECT id, total_amount
            FROM invoices
            WHERE student_id = :student_id
              AND semester_id = :semester_id
        ");
        $invoiceRowsStmt->execute([
            'student_id' => $studentId,
            'semester_id' => $semesterId,
        ]);
        $invoiceRows = $invoiceRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $invoiceUpdateStmt = $conn->prepare("
            UPDATE invoices
            SET amount_paid = :amount_paid,
                balance = :balance,
                status = :status,
                updated_at = NOW()
            WHERE id = :invoice_id
        ");

        $totalFees = 0.0;
        foreach ($invoiceRows as $invoiceRow) {
            $invoiceId = (int)($invoiceRow['id'] ?? 0);
            $invoiceTotal = (float)($invoiceRow['total_amount'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }

            $paidStmt = $conn->prepare("
                SELECT COALESCE(SUM(amount), 0)
                FROM payments
                WHERE invoice_id = :invoice_id
            ");
            $paidStmt->execute(['invoice_id' => $invoiceId]);
            $amountPaid = (float)$paidStmt->fetchColumn();
            $balance = max($invoiceTotal - $amountPaid, 0.0);
            $status = $balance <= 0.009 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'pending');

            $invoiceUpdateStmt->execute([
                'amount_paid' => min($invoiceTotal, $amountPaid),
                'balance' => $balance,
                'status' => $status,
                'invoice_id' => $invoiceId,
            ]);

            $totalFees += $invoiceTotal;
        }

        $allPaymentsStmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0), MAX(payment_date)
            FROM payments
            WHERE student_id = :student_id
              AND semester_id = :semester_id
        ");
        $allPaymentsStmt->execute([
            'student_id' => $studentId,
            'semester_id' => $semesterId,
        ]);
        $paymentSummary = $allPaymentsStmt->fetch(PDO::FETCH_NUM) ?: [0, null];
        $totalPaid = (float)($paymentSummary[0] ?? 0);
        $lastPaymentDate = $paymentSummary[1] ?? null;

        $balanceStmt = $conn->prepare("
            INSERT INTO student_balances
                (student_id, semester_id, total_fees, total_paid, balance, last_payment_date, updated_at)
            VALUES
                (:student_id, :semester_id, :total_fees, :total_paid, :balance, :last_payment_date, NOW())
            ON DUPLICATE KEY UPDATE
                total_fees = VALUES(total_fees),
                total_paid = VALUES(total_paid),
                balance = VALUES(balance),
                last_payment_date = VALUES(last_payment_date),
                updated_at = NOW()
        ");
        $balanceStmt->execute([
            'student_id' => $studentId,
            'semester_id' => $semesterId,
            'total_fees' => $totalFees,
            'total_paid' => $totalPaid,
            'balance' => max($totalFees - $totalPaid, 0.0),
            'last_payment_date' => $lastPaymentDate,
        ]);
    } catch (Exception $e) {
        throw new RuntimeException(
            'Unable to reconcile finance summary for student ' . $studentId . ' semester ' . $semesterId . ': ' . $e->getMessage(),
            0,
            $e
        );
    }
}

function demoReconcileAllFinanceSummaries(PDO $conn): int
{
    $reconciled = 0;

    try {
        $stmt = $conn->query("
            SELECT student_id, semester_id
            FROM (
                SELECT student_id, semester_id FROM invoices
                UNION
                SELECT student_id, semester_id FROM payments
                UNION
                SELECT student_id, semester_id FROM student_balances
            ) finance_terms
            WHERE student_id IS NOT NULL
              AND semester_id IS NOT NULL
            ORDER BY student_id ASC, semester_id ASC
        ");
        $terms = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($terms as $term) {
            $studentId = (int)($term['student_id'] ?? 0);
            $semesterId = (int)($term['semester_id'] ?? 0);
            if ($studentId <= 0 || $semesterId <= 0) {
                continue;
            }

            demoReconcileStudentSemesterFinance($conn, $studentId, $semesterId);
            $reconciled++;
        }
    } catch (Exception $e) {
        throw new RuntimeException('Unable to reconcile finance summaries: ' . $e->getMessage(), 0, $e);
    }

    return $reconciled;
}

function demoGetStudentTimeline(PDO $conn, array $student): array
{
    $studentId = (int)($student['id'] ?? 0);
    if ($studentId <= 0) {
        return [];
    }

    $timeline = [];

    try {
        $stmt = $conn->prepare("
            SELECT
                sr.semester_id,
                COALESCE(sr.year_of_study, s.level_year, s.year_of_study, 1) AS year_of_study,
                sem.semester_number,
                sem.semester_name,
                sem.start_date AS semester_start_date,
                sem.end_date AS semester_end_date,
                sem.academic_year_id,
                ay.year_name AS academic_year,
                ay.start_date AS academic_year_start_date
            FROM semester_registrations sr
            INNER JOIN semesters sem ON sem.id = sr.semester_id
            INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
            INNER JOIN students s ON s.id = sr.student_id
            WHERE sr.student_id = :student_id
              AND COALESCE(LOWER(sr.status), '') <> 'rejected'
            ORDER BY ay.start_date ASC, sem.semester_number ASC, sr.id ASC
        ");
        $stmt->execute(['student_id' => $studentId]);
        $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $timeline = [];
    }

    if (!empty($timeline)) {
        return $timeline;
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                cr.semester_id,
                COALESCE(MIN(c.level_year), COALESCE(s.level_year, s.year_of_study, 1), 1) AS year_of_study,
                sem.semester_number,
                sem.semester_name,
                sem.start_date AS semester_start_date,
                sem.end_date AS semester_end_date,
                sem.academic_year_id,
                ay.year_name AS academic_year,
                ay.start_date AS academic_year_start_date
            FROM course_registrations cr
            INNER JOIN semesters sem ON sem.id = cr.semester_id
            INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
            INNER JOIN students s ON s.id = cr.student_id
            LEFT JOIN courses c ON c.id = cr.course_id
            WHERE cr.student_id = :student_id
              AND COALESCE(LOWER(cr.status), '') <> 'dropped'
            GROUP BY
                cr.semester_id,
                sem.semester_number,
                sem.semester_name,
                sem.start_date,
                sem.end_date,
                sem.academic_year_id,
                ay.year_name,
                ay.start_date,
                s.level_year,
                s.year_of_study
            ORDER BY ay.start_date ASC, sem.semester_number ASC, cr.semester_id ASC
        ");
        $stmt->execute(['student_id' => $studentId]);
        $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $timeline = [];
    }

    if (!empty($timeline)) {
        return $timeline;
    }

    $ctx = getStudentCurrentSemesterContext($conn, $studentId);
    if (!empty($ctx['id'])) {
        return [[
            'semester_id' => (int)($ctx['id'] ?? 0),
            'year_of_study' => max(1, (int)($student['level_year'] ?? ($student['year_of_study'] ?? 1))),
            'semester_number' => (int)($ctx['semester_number'] ?? 1),
            'semester_name' => (string)($ctx['semester_name'] ?? 'Semester'),
            'semester_start_date' => (string)($ctx['start_date'] ?? ''),
            'semester_end_date' => (string)($ctx['end_date'] ?? ''),
            'academic_year_id' => (int)($ctx['academic_year_id'] ?? 0),
            'academic_year' => (string)($ctx['academic_year'] ?? ''),
            'academic_year_start_date' => (string)($ctx['academic_year_start_date'] ?? ''),
        ]];
    }

    $activeSemester = Helper::getCurrentSemester();
    if (!empty($activeSemester['id'])) {
        return [[
            'semester_id' => (int)($activeSemester['id'] ?? 0),
            'year_of_study' => max(1, (int)($student['level_year'] ?? ($student['year_of_study'] ?? 1))),
            'semester_number' => (int)($activeSemester['semester_number'] ?? 1),
            'semester_name' => (string)($activeSemester['semester_name'] ?? 'Semester'),
            'semester_start_date' => (string)($activeSemester['start_date'] ?? ''),
            'semester_end_date' => (string)($activeSemester['end_date'] ?? ''),
            'academic_year_id' => (int)($activeSemester['academic_year_id'] ?? 0),
            'academic_year' => (string)($activeSemester['academic_year'] ?? ''),
            'academic_year_start_date' => (string)($activeSemester['academic_year_start_date'] ?? ''),
        ]];
    }

    return [];
}

function demoSemesterHasMeaningfulFinanceData(PDO $conn, int $studentId, int $semesterId): bool
{
    try {
        $stmt = $conn->prepare("
            SELECT
                (
                    SELECT COUNT(*)
                    FROM invoices
                    WHERE student_id = :student_id
                      AND semester_id = :semester_id
                      AND (total_amount > 0 OR amount_paid > 0 OR balance > 0)
                ) AS invoice_count,
                (
                    SELECT COUNT(*)
                    FROM payments
                    WHERE student_id = :student_id
                      AND semester_id = :semester_id
                      AND amount > 0
                ) AS payment_count,
                (
                    SELECT COUNT(*)
                    FROM student_balances
                    WHERE student_id = :student_id
                      AND semester_id = :semester_id
                      AND (total_fees > 0 OR total_paid > 0 OR balance > 0)
                ) AS balance_count
        ");
        $stmt->execute([
            'student_id' => $studentId,
            'semester_id' => $semesterId
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ((int)($row['invoice_count'] ?? 0) > 0)
            || ((int)($row['payment_count'] ?? 0) > 0)
            || ((int)($row['balance_count'] ?? 0) > 0);
    } catch (Exception $e) {
        return false;
    }
}

function demoStudentHasAnyFinanceData(PDO $conn, int $studentId): bool
{
    try {
        $stmt = $conn->prepare("
            SELECT
                (
                    SELECT COUNT(*)
                    FROM invoices
                    WHERE student_id = :student_id
                      AND (total_amount > 0 OR amount_paid > 0 OR balance > 0)
                ) AS invoice_count,
                (
                    SELECT COUNT(*)
                    FROM payments
                    WHERE student_id = :student_id
                      AND amount > 0
                ) AS payment_count,
                (
                    SELECT COUNT(*)
                    FROM student_balances
                    WHERE student_id = :student_id
                      AND (total_fees > 0 OR total_paid > 0 OR balance > 0)
                ) AS balance_count
        ");
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ((int)($row['invoice_count'] ?? 0) > 0)
            || ((int)($row['payment_count'] ?? 0) > 0)
            || ((int)($row['balance_count'] ?? 0) > 0);
    } catch (Exception $e) {
        return false;
    }
}

function demoStableRatio(int $seed, float $min, float $max): float
{
    if ($max <= $min) {
        return $min;
    }
    $normalized = abs($seed % 1000) / 1000;
    return $min + (($max - $min) * $normalized);
}

function demoRoundCurrency(float $amount): float
{
    return round($amount / 1000) * 1000;
}

function demoResolveSemesterFeeTotal(PDO $conn, array $student, array $term): float
{
    $studentId = (int)($student['id'] ?? 0);
    $semesterId = (int)($term['semester_id'] ?? 0);
    $programId = (int)($student['program_id'] ?? 0);
    $academicYearId = (int)($term['academic_year_id'] ?? 0);
    $yearOfStudy = max(1, (int)($term['year_of_study'] ?? ($student['level_year'] ?? 1)));

    $snapshot = getStudentFinancialSnapshot(
        $conn,
        $studentId,
        $semesterId,
        $programId,
        $academicYearId,
        $yearOfStudy,
        true
    );

    $resolved = (float)($snapshot['approved_total_fees'] ?? 0);
    if ($resolved > 0) {
        return demoRoundCurrency($resolved);
    }

    $base = 1350000
        + (($yearOfStudy - 1) * 225000)
        + (((int)($term['semester_number'] ?? 1) - 1) * 90000);

    $programBump = ((int)$programId % 4) * 55000;
    return demoRoundCurrency($base + $programBump);
}

function demoResolvePaidAmounts(array $student, array $term, float $feeTotal): array
{
    $studentId = (int)($student['id'] ?? 0);
    $semesterId = (int)($term['semester_id'] ?? 0);
    $yearOfStudy = max(1, (int)($term['year_of_study'] ?? 1));
    $isGraduated = !empty($student['graduation_date'])
        || !empty($student['graduation_award_title'])
        || strtolower((string)($student['status'] ?? '')) === 'graduated'
        || strtolower((string)($student['academic_status'] ?? '')) === 'graduated';

    $seed = ($studentId * 37) + ($semesterId * 13) + ($yearOfStudy * 17);

    if ($isGraduated) {
        $paidRatio = demoStableRatio($seed, 0.93, 1.00);
        $paidAmount = min($feeTotal, demoRoundCurrency($feeTotal * $paidRatio));
        $creditAmount = (((($seed % 7) === 0) && $yearOfStudy >= 3) ? demoRoundCurrency($feeTotal * 0.04) : 0.0);
    } else {
        $paidRatio = demoStableRatio($seed, 0.48, 0.86);
        $paidAmount = min($feeTotal, demoRoundCurrency($feeTotal * $paidRatio));
        $creditAmount = 0.0;
    }

    if ($paidAmount <= 0 && $feeTotal > 0) {
        $paidAmount = min($feeTotal, demoRoundCurrency($feeTotal * 0.5));
    }

    return [
        'paid_amount' => $paidAmount,
        'credit_amount' => $creditAmount
    ];
}

function demoResolveItemSplit(float $feeTotal): array
{
    $tuition = demoRoundCurrency($feeTotal * 0.72);
    $functional = demoRoundCurrency($feeTotal * 0.18);
    $other = max(0.0, $feeTotal - $tuition - $functional);

    return [
        ['description' => 'Tuition Fees', 'amount' => $tuition],
        ['description' => 'Functional Fees', 'amount' => $functional],
        ['description' => 'Library & Examination Fees', 'amount' => $other],
    ];
}

function demoResolveDate(string $preferred, string $fallback, string $default): string
{
    foreach ([$preferred, $fallback, $default] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && strtotime($candidate) !== false) {
            return date('Y-m-d', strtotime($candidate));
        }
    }
    return date('Y-m-d');
}

$financeStaffId = demoResolveFinanceStaffId($conn);
if ($financeStaffId <= 0) {
    fwrite(STDERR, "No finance staff profile found. Seed at least one finance user before running this script.\n");
    exit(1);
}
demoEnsureStudentBalanceProcedure($conn);
$normalizedOutlierPayments = demoNormalizeOutlierPayments($conn);

$hasPaymentVerificationStatus = demoColumnExists($conn, 'payments', 'verification_status');
$hasPaymentVerifiedBy = demoColumnExists($conn, 'payments', 'verified_by');
$hasPaymentVerifiedAt = demoColumnExists($conn, 'payments', 'verified_at');
$hasPaymentVerificationNotes = demoColumnExists($conn, 'payments', 'verification_notes');

$studentsStmt = $conn->query("
    SELECT
        s.id,
        s.student_id,
        s.program_id,
        COALESCE(s.level_year, s.year_of_study, 1) AS level_year,
        s.status,
        s.academic_status,
        s.entry_year,
        s.entry_semester_id,
        s.created_at,
        s.graduation_date,
        s.graduation_award_title
    FROM students s
    ORDER BY
        CASE
            WHEN LOWER(COALESCE(s.status, '')) = 'graduated'
              OR LOWER(COALESCE(s.academic_status, '')) = 'graduated'
              OR s.graduation_date IS NOT NULL
              OR COALESCE(s.graduation_award_title, '') <> ''
            THEN 0
            ELSE 1
        END ASC,
        s.created_at DESC,
        s.id ASC
");
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$summary = [
    'students_considered' => 0,
    'students_seeded' => 0,
    'semester_terms_seeded' => 0,
    'invoices_created' => 0,
    'payments_created' => 0,
    'balances_updated' => 0,
    'students_skipped_existing' => 0,
    'terms_reconciled' => 0,
];

foreach ($students as $student) {
    $studentId = (int)($student['id'] ?? 0);
    if ($studentId <= 0) {
        continue;
    }

    $summary['students_considered']++;

    $isGraduated = !empty($student['graduation_date'])
        || !empty($student['graduation_award_title'])
        || strtolower((string)($student['status'] ?? '')) === 'graduated'
        || strtolower((string)($student['academic_status'] ?? '')) === 'graduated';

    $hasAnyFinance = demoStudentHasAnyFinanceData($conn, $studentId);
    if (!$isGraduated && $hasAnyFinance) {
        $summary['students_skipped_existing']++;
        continue;
    }

    $timeline = demoGetStudentTimeline($conn, $student);
    if (empty($timeline)) {
        continue;
    }

    $studentSeeded = false;

    foreach ($timeline as $term) {
        $semesterId = (int)($term['semester_id'] ?? 0);
        if ($semesterId <= 0) {
            continue;
        }

        $invoiceNumber = sprintf('DEMO-INV-%04d-%04d', $studentId, $semesterId);
        $existingInvoice = demoFindExistingInvoice($conn, $studentId, $semesterId, $invoiceNumber);
        if (demoSemesterHasMeaningfulFinanceData($conn, $studentId, $semesterId) && empty($existingInvoice)) {
            continue;
        }

        $yearOfStudy = max(1, (int)($term['year_of_study'] ?? ($student['level_year'] ?? 1)));
        $feeTotal = demoResolveSemesterFeeTotal($conn, $student, $term);
        if ($feeTotal <= 0) {
            continue;
        }

        $paymentShape = demoResolvePaidAmounts($student, $term, $feeTotal);
        $paidAmount = (float)($paymentShape['paid_amount'] ?? 0);
        $creditAmount = (float)($paymentShape['credit_amount'] ?? 0);
        $balanceAmount = max($feeTotal - $paidAmount, 0.0);

        $invoiceCreatedDate = demoResolveDate(
            (string)($term['semester_start_date'] ?? ''),
            (string)($term['academic_year_start_date'] ?? ''),
            (string)($student['created_at'] ?? '')
        );
        $paymentDateOne = date('Y-m-d', strtotime($invoiceCreatedDate . ' +14 days'));
        $paymentDateTwo = date('Y-m-d', strtotime($invoiceCreatedDate . ' +36 days'));
        $dueDate = date('Y-m-d', strtotime($invoiceCreatedDate . ' +45 days'));

        $invoiceStatus = $balanceAmount <= 0.009 ? 'paid' : ($paidAmount > 0 ? 'partial' : 'pending');
        $localInvoicesCreated = 0;
        $localPaymentsCreated = 0;
        $localBalancesUpdated = 0;
        $localTermsReconciled = 0;

        $conn->beginTransaction();
        try {
            $invoiceId = (int)($existingInvoice['id'] ?? 0);
            if ($invoiceId > 0) {
                $invoiceSyncStmt = $conn->prepare("
                    UPDATE invoices
                    SET total_amount = :total_amount,
                        due_date = :due_date,
                        updated_at = NOW()
                    WHERE id = :invoice_id
                ");
                $invoiceSyncStmt->execute([
                    'total_amount' => $feeTotal,
                    'due_date' => $dueDate,
                    'invoice_id' => $invoiceId,
                ]);
            } else {
                $invoiceStmt = $conn->prepare("
                    INSERT INTO invoices
                        (invoice_number, student_id, semester_id, total_amount, amount_paid, balance, due_date, status, created_at, updated_at)
                    VALUES
                        (:invoice_number, :student_id, :semester_id, :total_amount, :amount_paid, :balance, :due_date, :status, :created_at, NOW())
                ");
                $invoiceStmt->execute([
                    'invoice_number' => $invoiceNumber,
                    'student_id' => $studentId,
                    'semester_id' => $semesterId,
                    'total_amount' => $feeTotal,
                    'amount_paid' => min($feeTotal, $paidAmount),
                    'balance' => $balanceAmount,
                    'due_date' => $dueDate,
                    'status' => $invoiceStatus,
                    'created_at' => $invoiceCreatedDate . ' 09:00:00',
                ]);
                $invoiceId = (int)$conn->lastInsertId();
                $localInvoicesCreated++;
            }
            demoEnsureInvoiceItems($conn, $invoiceId, $feeTotal, $invoiceCreatedDate . ' 09:05:00');

            if ($paidAmount > 0) {
                $payments = [];
                if ($paidAmount >= 200000) {
                    $firstPayment = demoRoundCurrency($paidAmount * 0.55);
                    $secondPayment = max(0.0, $paidAmount - $firstPayment);
                    $payments[] = ['amount' => $firstPayment, 'date' => $paymentDateOne, 'method' => 'bank_transfer'];
                    if ($secondPayment > 0) {
                        $payments[] = ['amount' => $secondPayment, 'date' => $paymentDateTwo, 'method' => 'mobile_money'];
                    }
                } else {
                    $payments[] = ['amount' => $paidAmount, 'date' => $paymentDateOne, 'method' => 'cash'];
                }

                $basePaymentSql = "
                    INSERT INTO payments
                        (payment_id, student_id, invoice_id, amount, payment_date, payment_method, reference_number, received_by, semester_id, notes, receipt_number, created_at, updated_at";
                $basePaymentValues = ")
                    VALUES
                        (:payment_id, :student_id, :invoice_id, :amount, :payment_date, :payment_method, :reference_number, :received_by, :semester_id, :notes, :receipt_number, :created_at, NOW()";

                if ($hasPaymentVerificationStatus) {
                    $basePaymentSql .= ", verification_status";
                    $basePaymentValues .= ", :verification_status";
                }
                if ($hasPaymentVerifiedBy) {
                    $basePaymentSql .= ", verified_by";
                    $basePaymentValues .= ", :verified_by";
                }
                if ($hasPaymentVerifiedAt) {
                    $basePaymentSql .= ", verified_at";
                    $basePaymentValues .= ", :verified_at";
                }
                if ($hasPaymentVerificationNotes) {
                    $basePaymentSql .= ", verification_notes";
                    $basePaymentValues .= ", :verification_notes";
                }

                $paymentSql = $basePaymentSql . $basePaymentValues . ")";

                foreach ($payments as $index => $paymentRow) {
                    $sequence = $index + 1;
                    $paymentId = sprintf('DEMO-PAY-%04d-%04d-%02d', $studentId, $semesterId, $sequence);
                    $receiptNumber = sprintf('DEMO-RCPT-%04d-%04d-%02d', $studentId, $semesterId, $sequence);
                    $referenceNumber = sprintf('DEMO-REF-%04d-%04d-%02d', $studentId, $semesterId, $sequence);

                    $params = [
                        'payment_id' => $paymentId,
                        'student_id' => $studentId,
                        'invoice_id' => $invoiceId,
                        'amount' => (float)$paymentRow['amount'],
                        'payment_date' => (string)$paymentRow['date'],
                        'payment_method' => (string)$paymentRow['method'],
                        'reference_number' => $referenceNumber,
                        'received_by' => $financeStaffId,
                        'semester_id' => $semesterId,
                        'notes' => 'Stakeholder demo seeded payment for walkthrough visibility.',
                        'receipt_number' => $receiptNumber,
                        'created_at' => (string)$paymentRow['date'] . ' 11:00:00',
                    ];
                    if ($hasPaymentVerificationStatus) {
                        $params['verification_status'] = 'verified';
                    }
                    if ($hasPaymentVerifiedBy) {
                        $params['verified_by'] = $financeStaffId;
                    }
                    if ($hasPaymentVerifiedAt) {
                        $params['verified_at'] = (string)$paymentRow['date'] . ' 11:05:00';
                    }
                    if ($hasPaymentVerificationNotes) {
                        $params['verification_notes'] = 'Auto-seeded for stakeholder demo.';
                    }

                    if (demoUpsertPayment($conn, $paymentSql, $params)) {
                        $localPaymentsCreated++;
                    }
                }
            }

            if ($creditAmount > 0) {
                $paymentId = sprintf('DEMO-PAY-%04d-%04d-99', $studentId, $semesterId);
                $receiptNumber = sprintf('DEMO-RCPT-%04d-%04d-99', $studentId, $semesterId);
                $referenceNumber = sprintf('DEMO-REF-%04d-%04d-99', $studentId, $semesterId);

                $basePaymentSql = "
                    INSERT INTO payments
                        (payment_id, student_id, invoice_id, amount, payment_date, payment_method, reference_number, received_by, semester_id, notes, receipt_number, created_at, updated_at";
                $basePaymentValues = ")
                    VALUES
                        (:payment_id, :student_id, NULL, :amount, :payment_date, :payment_method, :reference_number, :received_by, :semester_id, :notes, :receipt_number, :created_at, NOW()";

                if ($hasPaymentVerificationStatus) {
                    $basePaymentSql .= ", verification_status";
                    $basePaymentValues .= ", :verification_status";
                }
                if ($hasPaymentVerifiedBy) {
                    $basePaymentSql .= ", verified_by";
                    $basePaymentValues .= ", :verified_by";
                }
                if ($hasPaymentVerifiedAt) {
                    $basePaymentSql .= ", verified_at";
                    $basePaymentValues .= ", :verified_at";
                }
                if ($hasPaymentVerificationNotes) {
                    $basePaymentSql .= ", verification_notes";
                    $basePaymentValues .= ", :verification_notes";
                }

                $paymentSql = $basePaymentSql . $basePaymentValues . ")";
                $params = [
                    'payment_id' => $paymentId,
                    'student_id' => $studentId,
                    'amount' => $creditAmount,
                    'payment_date' => $paymentDateTwo,
                    'payment_method' => 'bank_transfer',
                    'reference_number' => $referenceNumber,
                    'received_by' => $financeStaffId,
                    'semester_id' => $semesterId,
                    'notes' => 'Stakeholder demo seeded extra credit/payment on account.',
                    'receipt_number' => $receiptNumber,
                    'created_at' => $paymentDateTwo . ' 14:10:00',
                ];
                if ($hasPaymentVerificationStatus) {
                    $params['verification_status'] = 'verified';
                }
                if ($hasPaymentVerifiedBy) {
                    $params['verified_by'] = $financeStaffId;
                }
                if ($hasPaymentVerifiedAt) {
                    $params['verified_at'] = $paymentDateTwo . ' 14:12:00';
                }
                if ($hasPaymentVerificationNotes) {
                    $params['verification_notes'] = 'Auto-seeded stakeholder demo credit.';
                }
                if (demoUpsertPayment($conn, $paymentSql, $params)) {
                    $localPaymentsCreated++;
                }
            }

            demoReconcileStudentSemesterFinance($conn, $studentId, $semesterId);
            $localBalancesUpdated++;
            $localTermsReconciled++;

            $conn->commit();
            $summary['semester_terms_seeded']++;
            $summary['invoices_created'] += $localInvoicesCreated;
            $summary['payments_created'] += $localPaymentsCreated;
            $summary['balances_updated'] += $localBalancesUpdated;
            $summary['terms_reconciled'] += $localTermsReconciled;
            $studentSeeded = true;
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            fwrite(STDERR, 'Failed seeding student ' . ($student['student_id'] ?? ('#' . $studentId)) . ' semester ' . $semesterId . ': ' . $e->getMessage() . PHP_EOL);
        }
    }

    if ($studentSeeded) {
        $summary['students_seeded']++;
    }
}

$summary['terms_reconciled'] += demoReconcileAllFinanceSummaries($conn);

echo "Demo finance showcase seeding complete.\n";
echo 'Students considered: ' . $summary['students_considered'] . "\n";
echo 'Students seeded: ' . $summary['students_seeded'] . "\n";
echo 'Semester terms seeded: ' . $summary['semester_terms_seeded'] . "\n";
echo 'Invoices created: ' . $summary['invoices_created'] . "\n";
echo 'Payments created: ' . $summary['payments_created'] . "\n";
echo 'Balances updated: ' . $summary['balances_updated'] . "\n";
echo 'Students skipped because they already had finance data: ' . $summary['students_skipped_existing'] . "\n";
echo 'Finance terms reconciled: ' . $summary['terms_reconciled'] . "\n";
echo 'Outlier payments normalized: ' . (int)$normalizedOutlierPayments . "\n";
