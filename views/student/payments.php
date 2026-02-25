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

$outstandingBalance = (float)($studentProfile['account_balance'] ?? 0);
if ($studentDbId > 0 && $currentSemester['id'] > 0) {
    try {
        $balStmt = $conn->prepare("
            SELECT COALESCE(SUM(balance), 0)
            FROM student_balances
            WHERE student_id = :student_id AND semester_id = :semester_id
        ");
        $balStmt->execute([
            'student_id' => $studentDbId,
            'semester_id' => (int)$currentSemester['id']
        ]);
        $outstandingBalance = (float)$balStmt->fetchColumn();
    } catch (Exception $e) {
    }
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
$registeredProgramDepartment = '';
$registeredProgramDuration = 4;
if ($studentDbId > 0) {
    try {
        $progStmt = $conn->prepare("
            SELECT p.program_name, p.department, p.duration_years
            FROM students s
            LEFT JOIN programs p ON s.program_id = p.id
            WHERE s.id = :student_id
            LIMIT 1
        ");
        $progStmt->execute(['student_id' => $studentDbId]);
        $progRow = $progStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($progRow['program_name'])) {
            $registeredProgramName = $progRow['program_name'];
            $registeredProgramDepartment = (string)($progRow['department'] ?? '');
            if (!empty($progRow['duration_years'])) {
                $registeredProgramDuration = max(1, (int)$progRow['duration_years']);
            }
        } elseif (!empty($studentProfile['program_name'])) {
            $registeredProgramName = $studentProfile['program_name'];
        }
    } catch (Exception $e) {
    }
}

$section = $_GET['section'] ?? 'bills';
$validSections = ['bills', 'transactions', 'migrated', 'ledger', 'fees'];
if (!in_array($section, $validSections, true)) {
    $section = 'bills';
}

$invoiceRows = [];
$invoiceGroups = [];
$invoiceGroupList = [];
$invoiceTotals = ['total' => 0.0, 'paid' => 0.0, 'due' => 0.0, 'pct' => 0.0];
$transactions = [];
$invoiceTransactions = [];
$depositTransactions = [];
$migratedTransactions = [];
$migratedInvoiceTransactions = [];
$migratedDepositTransactions = [];
$ledgerStatementRows = [];
$ledgerNetBalance = 0.0;
$txTab = $_GET['tx_tab'] ?? 'check_prn';
$validTxTabs = ['invoice_payments', 'fees_deposits', 'check_prn'];
if (!in_array($txTab, $validTxTabs, true)) {
    $txTab = 'check_prn';
}
$prnQuery = trim((string)($_GET['prn_ref'] ?? ''));
$prnStatusRow = null;
$prnStatusMessage = '';
$migratedTab = $_GET['mtx_tab'] ?? 'invoice_payments';
$validMigratedTabs = ['invoice_payments', 'fees_deposits'];
if (!in_array($migratedTab, $validMigratedTabs, true)) {
    $migratedTab = 'invoice_payments';
}
$ledgerRows = [];
$feesRows = [];
$feesByYear = [];
$activeFeeVersion = null;
$activeFeeVersionLabel = '';
$activeFeeVersionCreatedBy = 'Finance Office';
$activeFeeVersionApprovedBy = 'University Administration';

if ($studentDbId > 0) {
    try {
        $invStmt = $conn->prepare("
            SELECT
                i.id,
                i.invoice_number,
                i.total_amount,
                i.amount_paid,
                i.balance,
                i.status,
                i.created_at,
                s.semester_number,
                s.semester_name,
                ay.year_name AS academic_year,
                COALESCE(sr.year_of_study, 1) AS year_of_study,
                (
                    SELECT GROUP_CONCAT(ii.description SEPARATOR ', ')
                    FROM invoice_items ii
                    WHERE ii.invoice_id = i.id
                ) AS item_descriptions
            FROM invoices i
            LEFT JOIN semesters s ON i.semester_id = s.id
            LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
            LEFT JOIN semester_registrations sr
                ON sr.student_id = i.student_id
                AND sr.semester_id = i.semester_id
            WHERE i.student_id = :student_id
            ORDER BY i.created_at ASC, i.id ASC
        ");
        $invStmt->execute(['student_id' => $studentDbId]);
        $invoiceRows = $invStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($invoiceRows as $row) {
            $amount = (float)($row['total_amount'] ?? 0);
            $paid = (float)($row['amount_paid'] ?? 0);
            $due = (float)($row['balance'] ?? 0);

            $invoiceTotals['total'] += $amount;
            $invoiceTotals['paid'] += $paid;
            $invoiceTotals['due'] += $due;

            $yearStudy = (int)($row['year_of_study'] ?? 1);
            $semNum = (int)($row['semester_number'] ?? 1);
            $ay = (string)($row['academic_year'] ?? '-');
            $key = $yearStudy . '|' . $semNum . '|' . $ay;

            if (!isset($invoiceGroups[$key])) {
                $invoiceGroups[$key] = [
                    'year_of_study' => $yearStudy,
                    'semester_number' => $semNum,
                    'academic_year' => $ay,
                    'rows' => [],
                    'total' => 0.0,
                    'paid' => 0.0,
                    'due' => 0.0
                ];
            }
            $invoiceGroups[$key]['rows'][] = $row;
            $invoiceGroups[$key]['total'] += $amount;
            $invoiceGroups[$key]['paid'] += $paid;
            $invoiceGroups[$key]['due'] += $due;
        }
        if ($invoiceTotals['total'] > 0) {
            $invoiceTotals['pct'] = (($invoiceTotals['total'] - $invoiceTotals['due']) / $invoiceTotals['total']) * 100;
        }
    } catch (Exception $e) {
    }

    // Build full semester/year timeline so previous levels appear even with no invoices.
    $timelineRows = [];
    try {
        $timelineStmt = $conn->prepare("
            SELECT
                COALESCE(sr.year_of_study, 1) AS year_of_study,
                s.semester_number,
                s.semester_name,
                ay.year_name AS academic_year
            FROM semester_registrations sr
            INNER JOIN semesters s ON sr.semester_id = s.id
            INNER JOIN academic_years ay ON s.academic_year_id = ay.id
            WHERE sr.student_id = :student_id
            GROUP BY sr.year_of_study, s.id, s.semester_number, s.semester_name, ay.year_name
            ORDER BY sr.year_of_study ASC, s.semester_number ASC, s.id ASC
        ");
        $timelineStmt->execute(['student_id' => $studentDbId]);
        $timelineRows = $timelineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
    }

    // Fallback from registered courses when semester registration history is missing/incomplete.
    if (empty($timelineRows)) {
        try {
            $timelineCourseStmt = $conn->prepare("
                SELECT
                    COALESCE(MIN(c.level_year), 1) AS year_of_study,
                    s.semester_number,
                    s.semester_name,
                    ay.year_name AS academic_year
                FROM course_registrations cr
                INNER JOIN semesters s ON cr.semester_id = s.id
                INNER JOIN academic_years ay ON s.academic_year_id = ay.id
                LEFT JOIN courses c ON cr.course_id = c.id
                WHERE cr.student_id = :student_id
                GROUP BY s.id, s.semester_number, s.semester_name, ay.year_name
                ORDER BY year_of_study ASC, s.semester_number ASC, s.id ASC
            ");
            $timelineCourseStmt->execute(['student_id' => $studentDbId]);
            $timelineRows = $timelineCourseStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
        }
    }

    foreach ($timelineRows as $term) {
        $yearStudy = (int)($term['year_of_study'] ?? 1);
        $semNum = (int)($term['semester_number'] ?? 1);
        $ay = (string)($term['academic_year'] ?? '-');
        $key = $yearStudy . '|' . $semNum . '|' . $ay;

        if (!isset($invoiceGroups[$key])) {
            $invoiceGroups[$key] = [
                'year_of_study' => $yearStudy,
                'semester_number' => $semNum,
                'academic_year' => $ay,
                'rows' => [],
                'total' => 0.0,
                'paid' => 0.0,
                'due' => 0.0
            ];
        }
    }

    $invoiceGroupList = array_values($invoiceGroups);
    usort($invoiceGroupList, function ($a, $b) {
        if ((int)$a['year_of_study'] !== (int)$b['year_of_study']) {
            return (int)$a['year_of_study'] <=> (int)$b['year_of_study'];
        }
        if ((int)$a['semester_number'] !== (int)$b['semester_number']) {
            return (int)$a['semester_number'] <=> (int)$b['semester_number'];
        }
        return strcmp((string)$a['academic_year'], (string)$b['academic_year']);
    });

    try {
        $txStmt = $conn->prepare("
            SELECT p.payment_id, p.invoice_id, p.amount, p.payment_date, p.payment_method, p.reference_number, p.receipt_number, p.notes, p.created_at, i.invoice_number
            FROM payments p
            LEFT JOIN invoices i ON p.invoice_id = i.id
            WHERE p.student_id = :student_id
            ORDER BY p.payment_date DESC, p.id DESC
        ");
        $txStmt->execute(['student_id' => $studentDbId]);
        $transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($transactions as $txRow) {
            $searchBlob = strtolower(
                (string)($txRow['payment_id'] ?? '') . ' ' .
                (string)($txRow['reference_number'] ?? '') . ' ' .
                (string)($txRow['receipt_number'] ?? '') . ' ' .
                (string)($txRow['notes'] ?? '')
            );
            if (strpos($searchBlob, 'migrat') !== false || strpos($searchBlob, 'legacy') !== false) {
                $migratedTransactions[] = $txRow;
                if (!empty($txRow['invoice_id'])) {
                    $migratedInvoiceTransactions[] = $txRow;
                } else {
                    $migratedDepositTransactions[] = $txRow;
                }
                continue;
            }

            if (!empty($txRow['invoice_id'])) {
                $invoiceTransactions[] = $txRow;
            } else {
                $depositTransactions[] = $txRow;
            }
        }

        if ($prnQuery !== '') {
            foreach ($transactions as $txRow) {
                $ref = (string)($txRow['reference_number'] ?? '');
                $receipt = (string)($txRow['receipt_number'] ?? '');
                $payId = (string)($txRow['payment_id'] ?? '');
                if (
                    strcasecmp($prnQuery, $ref) === 0 ||
                    strcasecmp($prnQuery, $receipt) === 0 ||
                    strcasecmp($prnQuery, $payId) === 0
                ) {
                    $prnStatusRow = $txRow;
                    break;
                }
            }
            if ($prnStatusRow === null) {
                $prnStatusMessage = 'No transaction found for the entered payment reference number.';
            }
        }
    } catch (Exception $e) {
    }

    // Build detailed ledger statement rows (debits from invoice items, credits from payments).
    try {
        $invItemStmt = $conn->prepare("
            SELECT
                i.invoice_number,
                i.created_at AS invoice_created_at,
                COALESCE(sr.year_of_study, 1) AS year_of_study,
                s.semester_number,
                ii.description,
                ii.amount
            FROM invoices i
            INNER JOIN invoice_items ii ON ii.invoice_id = i.id
            LEFT JOIN semesters s ON i.semester_id = s.id
            LEFT JOIN semester_registrations sr ON sr.student_id = i.student_id AND sr.semester_id = i.semester_id
            WHERE i.student_id = :student_id
            ORDER BY i.created_at ASC, ii.id ASC
        ");
        $invItemStmt->execute(['student_id' => $studentDbId]);
        $invItemRows = $invItemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($invItemRows as $row) {
            $yearText = 'YEAR ' . (int)($row['year_of_study'] ?? 1);
            $semNum = (int)($row['semester_number'] ?? 1);
            $semText = 'SEM ' . ($semNum === 1 ? '1' : '2');
            $desc = trim((string)($row['description'] ?? 'Invoice Item'));
            $entry = $desc . ' #' . (string)($row['invoice_number'] ?? '-') . ' ' . $yearText . ' ' . $semText;
            $descLower = strtolower($desc);
            $narration = 'Invoice';
            if (strpos($descLower, 'tuition') !== false) {
                $narration = 'Tuition';
            } elseif (strpos($descLower, 'functional') !== false) {
                $narration = 'Functional';
            }
            $ledgerStatementRows[] = [
                'timestamp' => (string)($row['invoice_created_at'] ?? ''),
                'entry' => $entry,
                'narration' => $narration,
                'debit' => (float)($row['amount'] ?? 0),
                'credit' => 0.0
            ];
        }

        foreach ($transactions as $txRow) {
            $reference = (string)($txRow['reference_number'] ?: ($txRow['receipt_number'] ?: ($txRow['payment_id'] ?? '-')));
            $ledgerStatementRows[] = [
                'timestamp' => !empty($txRow['created_at']) ? (string)$txRow['created_at'] : ((string)($txRow['payment_date'] ?? '')),
                'entry' => 'URA #' . $reference,
                'narration' => 'Payment',
                'debit' => 0.0,
                'credit' => (float)($txRow['amount'] ?? 0)
            ];
        }

        usort($ledgerStatementRows, function ($a, $b) {
            $ta = strtotime((string)($a['timestamp'] ?? ''));
            $tb = strtotime((string)($b['timestamp'] ?? ''));
            if ($ta === $tb) {
                return 0;
            }
            return $ta <=> $tb;
        });

        $running = 0.0;
        foreach ($ledgerStatementRows as &$row) {
            $running += ((float)$row['credit'] - (float)$row['debit']);
            $row['balance'] = $running;
        }
        unset($row);
        $ledgerNetBalance = $running;
    } catch (Exception $e) {
    }

    try {
        $ledgerStmt = $conn->prepare("
            SELECT sb.total_fees, sb.total_paid, sb.balance, sb.updated_at, s.semester_name, ay.year_name
            FROM student_balances sb
            LEFT JOIN semesters s ON sb.semester_id = s.id
            LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
            WHERE sb.student_id = :student_id
            ORDER BY sb.updated_at DESC
        ");
        $ledgerStmt->execute(['student_id' => $studentDbId]);
        $ledgerRows = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
    }

    try {
        $programIdForFees = (int)($studentProfile['program_id'] ?? 0);
        $academicYearIdForFees = (int)($studentSemesterContext['academic_year_id'] ?? 0);
        FeeStructureGovernance::ensureSchema($conn);
        $activeFeeVersion = FeeStructureGovernance::findPreferredPublishedVersion($conn, $programIdForFees, $academicYearIdForFees);

        if ($activeFeeVersion) {
            $activeFeeVersionLabel = (string)($activeFeeVersion['version_name'] ?? '');

            $createdByStmt = $conn->prepare("
                SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), 'Finance Office')
                FROM finance_staff
                WHERE id = :id
                LIMIT 1
            ");
            $createdByStmt->execute(['id' => (int)($activeFeeVersion['created_by'] ?? 0)]);
            $activeFeeVersionCreatedBy = (string)($createdByStmt->fetchColumn() ?: 'Finance Office');

            if (!empty($activeFeeVersion['approved_by'])) {
                $approvedByStmt = $conn->prepare("
                    SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), ''), 'University Administration')
                    FROM users u
                    LEFT JOIN admins a ON a.user_id = u.id
                    WHERE u.id = :id
                    LIMIT 1
                ");
                $approvedByStmt->execute(['id' => (int)$activeFeeVersion['approved_by']]);
                $activeFeeVersionApprovedBy = (string)($approvedByStmt->fetchColumn() ?: 'University Administration');
            }

            $feesStmt = $conn->prepare("
                SELECT
                    fs.fee_name,
                    fs.fee_type,
                    fs.amount,
                    fs.level_year,
                    fs.due_date,
                    COALESCE(fs.fine_amount, 0) AS fine_amount,
                    COALESCE(fs.discount_amount, 0) AS discount_amount,
                    s.semester_name,
                    s.semester_number,
                    ay.year_name
                FROM fees_structure fs
                LEFT JOIN semesters s ON fs.semester_id = s.id
                LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
                WHERE fs.version_id = :version_id
                  AND fs.status = 'active'
                ORDER BY fs.level_year ASC, s.semester_number ASC, fs.fee_name ASC
            ");
            $feesStmt->execute(['version_id' => (int)$activeFeeVersion['id']]);
            $feesRows = $feesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $feesStmt = $conn->prepare("
                SELECT
                    fs.fee_name,
                    fs.fee_type,
                    fs.amount,
                    fs.level_year,
                    fs.due_date,
                    COALESCE(fs.fine_amount, 0) AS fine_amount,
                    COALESCE(fs.discount_amount, 0) AS discount_amount,
                    s.semester_name,
                    s.semester_number,
                    ay.year_name
                FROM fees_structure fs
                LEFT JOIN semesters s ON fs.semester_id = s.id
                LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
                WHERE fs.status = 'active'
                  AND (fs.program_id = :program_id OR fs.program_id IS NULL)
                ORDER BY fs.level_year ASC, s.semester_number ASC, fs.fee_name ASC
            ");
            $feesStmt->execute(['program_id' => $programIdForFees]);
            $feesRows = $feesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        for ($y = 1; $y <= $registeredProgramDuration; $y++) {
            $feesByYear[$y] = [
                1 => ['rows' => [], 'total' => 0.0],
                2 => ['rows' => [], 'total' => 0.0]
            ];
        }
        foreach ($feesRows as $fr) {
            $year = (int)($fr['level_year'] ?? 1);
            if ($year < 1) {
                $year = 1;
            }
            if (!isset($feesByYear[$year])) {
                $feesByYear[$year] = [
                    1 => ['rows' => [], 'total' => 0.0],
                    2 => ['rows' => [], 'total' => 0.0]
                ];
            }
            $sem = (int)($fr['semester_number'] ?? 0);
            if ($sem !== 1 && $sem !== 2) {
                $semName = strtolower((string)($fr['semester_name'] ?? ''));
                $sem = (strpos($semName, '2') !== false || strpos($semName, 'ii') !== false) ? 2 : 1;
            }
            $feesByYear[$year][$sem]['rows'][] = $fr;
            $base = (float)($fr['amount'] ?? 0);
            $discount = (float)($fr['discount_amount'] ?? 0);
            $fine = (float)($fr['fine_amount'] ?? 0);
            $feesByYear[$year][$sem]['total'] += max(0, $base - $discount + $fine);
        }
        ksort($feesByYear);
    } catch (Exception $e) {
    }
}

$studentViewsPath = BASE_PATH . '/views/student/';
$linkDashboard = 'dashboard.php';
$linkResults = 'results.php';
$linkInvoices = file_exists($studentViewsPath . 'invoices.php') ? 'invoices.php' : 'payments.php?section=bills';
$linkFees = file_exists($studentViewsPath . 'fees.php') ? 'fees.php' : 'payments.php?section=fees';
$linkGeneratePrn = file_exists($studentViewsPath . 'generate_prn.php') ? 'generate_prn.php' : 'course-registration.php';
$linkEnroll = 'course-registration.php';
$linkPayments = 'payments.php';
$linkProgramme = 'my-courses.php';
$linkApplyServices = file_exists($studentViewsPath . 'services.php') ? 'services.php' : 'dashboard.php';
$linkServiceHistory = 'notifications.php';
$linkNewIdCards = file_exists($studentViewsPath . 'new-id-cards.php') ? 'new-id-cards.php' : 'dashboard.php';
$linkMailbox = 'notifications.php';
$linkAcademicCalendar = file_exists($studentViewsPath . 'academic-calendar.php') ? 'academic-calendar.php' : 'notifications.php';
$mailUnreadCount = !empty($currentUser['id']) ? getUnreadNotificationCountForUser((int)$currentUser['id']) : 0;

if ($section === 'ledger' && isset($_GET['download']) && $_GET['download'] === 'csv') {
    $filename = 'student_ledger_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['S/N', 'Time stamp', 'Entry', 'Narration', 'Debit', 'Credit', 'Balance']);
    $n = 1;
    foreach ($ledgerStatementRows as $row) {
        fputcsv($out, [
            $n++,
            !empty($row['timestamp']) ? date('M j, Y g:i A', strtotime($row['timestamp'])) : '-',
            $row['entry'] ?? '',
            $row['narration'] ?? '',
            number_format((float)($row['debit'] ?? 0)),
            number_format((float)($row['credit'] ?? 0)),
            number_format((float)($row['balance'] ?? 0))
        ]);
    }
    fputcsv($out, ['', '', '', 'UGX NET STATEMENT BALANCE', '', '', number_format((float)$ledgerNetBalance)]);
    fclose($out);
    exit;
}

$pageTitle = 'Payments - ' . APP_NAME;
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
.payments-submenu { list-style: none; padding: 0 0 0 10px; margin: 0 0 6px 0; }
.payments-submenu li { font-size: 0.79rem; margin-bottom: 3px; }
.payments-submenu li.active { background: #dceaf3; color: #0e7490; border-color: #bfddeb; }
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

.chip-row { padding: 0.45rem 1.2rem 0.2rem; display: flex; align-items: center; gap: 0.35rem; white-space: nowrap; }
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
.chip.red { background: #fee2e2; color: #991b1b; }

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
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 10px;
}
.sum-card {
    border: 1px solid #d1d5db;
    background: #fff;
    text-align: center;
    padding: 12px;
}
.sum-label {
    font-size: 0.82rem;
    font-weight: 700;
    color: #1f2937;
}
.sum-value {
    font-size: 1.05rem;
    font-weight: 800;
    margin-top: 2px;
    color: #059669;
}
.sum-value.due { color: #dc2626; }
.inv-accordion-item {
    border: 1px solid #d1d5db;
    border-radius: 10px;
    margin-bottom: 10px;
    overflow: hidden;
    background: #fff;
}
.inv-head {
    width: 100%;
    border: none;
    background: #fff;
    text-align: left;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    font-size: 0.95rem;
    font-weight: 800;
    color: #b42318;
    cursor: pointer;
}
.inv-head.secondary { color: #1f2937; }
.inv-body {
    display: none;
    border-top: 1px solid #e5e7eb;
    padding: 10px;
}
.inv-body.active { display: block; }
.tbl { width: 100%; border-collapse: collapse; }
.tbl th, .tbl td { border: 1px solid #e5e7eb; padding: 7px 8px; font-size: 0.82rem; }
.tbl th { background: #f8fafc; font-weight: 700; }
.group-total {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
    margin-top: 8px;
}
.group-total div {
    background: #edf2f7;
    border: 1px solid #d6dbe1;
    font-size: 0.82rem;
    font-weight: 700;
    padding: 8px;
}
.badge-cleared { color: #059669; font-weight: 700; }
.badge-pending { color: #dc2626; font-weight: 700; }
.fees-year-card {
    border: 1px solid #d5e1f3;
    margin-bottom: 10px;
    background: #fff;
}
.fees-year-head {
    width: 100%;
    border: none;
    background: #b8cbea;
    color: #123b72;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 9px 12px;
    font-size: 0.98rem;
    font-weight: 700;
    cursor: pointer;
}
.fees-year-body { padding: 8px 10px; display: none; }
.fees-year-body.active { display: block; }
.fees-sem-title {
    display: inline-block;
    background: #1f7aa8;
    color: #fff;
    font-weight: 700;
    font-size: 0.9rem;
    padding: 4px 10px;
    margin-bottom: 4px;
}
.fees-total-row {
    background: #d1d5db;
    font-weight: 700;
}
.tx-shell { border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; overflow: hidden; }
.tx-tabs { display: flex; gap: 0; border-bottom: 1px solid #e5e7eb; background: #f8fafc; }
.tx-tab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 16px;
    border-right: 1px solid #e5e7eb;
    font-size: 0.95rem;
    font-weight: 700;
    color: #1f2937;
    text-decoration: none;
}
.tx-tab.active {
    background: #fff;
    color: #0e7490;
}
.tx-body { padding: 14px; }
.tx-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #4b5563;
    border: 1px solid #e5e7eb;
    border-radius: 8px 8px 0 0;
    border-bottom: none;
    padding: 10px 12px;
    background: #fafafa;
}
.tx-form-row {
    border: 1px solid #e5e7eb;
    border-radius: 0 0 8px 8px;
    padding: 14px 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.tx-form-row label { font-size: 0.95rem; font-weight: 700; color: #1f2937; margin: 0; }
.tx-form-row input {
    flex: 1;
    min-width: 260px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 8px 10px;
    font-size: 0.95rem;
}
.tx-check-btn {
    border: 1px solid #1f7aa8;
    background: #1f7aa8;
    color: #fff;
    border-radius: 7px;
    padding: 8px 12px;
    font-size: 0.9rem;
    font-weight: 700;
    cursor: pointer;
}
.ledger-card {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #fff;
    overflow: hidden;
}
.ledger-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #e5e7eb;
    padding: 10px 12px;
}
.ledger-title {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1f7aa8;
}
.ledger-actions { display: flex; gap: 8px; }
.ledger-info-wrap {
    display: grid;
    grid-template-columns: 1fr 180px;
    gap: 12px;
    padding: 12px;
}
.ledger-photo {
    width: 100%;
    max-width: 140px;
    justify-self: end;
    border: 1px solid #d1d5db;
    border-radius: 2px;
}
.ledger-meta {
    font-size: 0.95rem;
    color: #111827;
    line-height: 1.55;
}
.ledger-caption {
    font-size: 1.75rem;
    margin: 10px 0 8px;
    color: #1f2937;
}
.ledger-net {
    margin-top: 8px;
    border-top: 1px solid #e5e7eb;
    padding-top: 8px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    font-size: 1.05rem;
    font-weight: 800;
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

/* Dark mode overrides for invoices/bills block */
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
    color: #93c5fd !important;
    border-bottom-color: var(--app-surface-1) !important;
}
html[data-theme='dark'] .summary-grid .sum-card {
    background: var(--app-surface-2) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .sum-label {
    color: #cbd5e1 !important;
}
html[data-theme='dark'] .sum-value {
    color: #34d399 !important;
}
html[data-theme='dark'] .sum-value.due {
    color: #f87171 !important;
}
html[data-theme='dark'] .inv-accordion-item {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .inv-head {
    background: var(--app-surface-2) !important;
    color: #fca5a5 !important;
}
html[data-theme='dark'] .inv-head.secondary {
    color: #e2e8f0 !important;
}
html[data-theme='dark'] .inv-body {
    border-top-color: var(--app-border) !important;
}
html[data-theme='dark'] .tbl th {
    background: #1f2937 !important;
    color: #f8fafc !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .tbl td {
    background: transparent !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .group-total div {
    background: #1f2937 !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .group-total span[style*='color:#059669;'],
html[data-theme='dark'] .group-total span[style*='color: #059669;'] {
    color: #34d399 !important;
}
html[data-theme='dark'] .group-total span[style*='color:#dc2626;'],
html[data-theme='dark'] .group-total span[style*='color: #dc2626;'] {
    color: #f87171 !important;
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


/* Additional dark-mode contrast fixes for invoice details */
html[data-theme='dark'] .prn-tabs .prn-tab.active {
    background: var(--app-surface-1) !important;
    color: #f8fafc !important;
}
html[data-theme='dark'] .inv-head span,
html[data-theme='dark'] .inv-head i,
html[data-theme='dark'] .prn-tab i,
html[data-theme='dark'] .prn-tab span {
    color: inherit !important;
}
html[data-theme='dark'] .inv-body {
    background: var(--app-surface-1) !important;
}
html[data-theme='dark'] .tbl tbody tr:nth-child(odd) {
    background: rgba(148, 163, 184, 0.06) !important;
}
html[data-theme='dark'] .tbl tbody tr:hover {
    background: rgba(59, 130, 246, 0.10) !important;
}
html[data-theme='dark'] .tbl a,
html[data-theme='dark'] .tbl button {
    color: #93c5fd !important;
}
html[data-theme='dark'] .badge-cleared,
html[data-theme='dark'] .badge-cleared i {
    color: #34d399 !important;
}
html[data-theme='dark'] .badge-pending,
html[data-theme='dark'] .badge-pending i {
    color: #f87171 !important;
}

/* Dark-mode fixes for transactions tabs and check PRN form */
html[data-theme='dark'] .tx-shell {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .tx-tabs {
    background: var(--app-surface-2) !important;
    border-bottom-color: var(--app-border) !important;
}
html[data-theme='dark'] .tx-tab {
    color: #cbd5e1 !important;
    border-right-color: var(--app-border) !important;
}
html[data-theme='dark'] .tx-tab i {
    color: inherit !important;
}
html[data-theme='dark'] .tx-tab:hover {
    background: rgba(59, 130, 246, 0.10) !important;
    color: #e2e8f0 !important;
}
html[data-theme='dark'] .tx-tab.active {
    background: var(--app-surface-1) !important;
    color: #7dd3fc !important;
}
html[data-theme='dark'] .tx-body {
    background: var(--app-surface-1) !important;
}
html[data-theme='dark'] .tx-title {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .tx-form-row {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .tx-form-row label {
    color: #e5e7eb !important;
}
html[data-theme='dark'] .tx-form-row label span[style*='color:#dc2626;'],
html[data-theme='dark'] .tx-form-row label span[style*='color: #dc2626;'] {
    color: #f87171 !important;
}
html[data-theme='dark'] .tx-form-row input {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .tx-form-row input::placeholder {
    color: #94a3b8 !important;
}
html[data-theme='dark'] .tx-check-btn {
    background: #1f7aa8 !important;
    border-color: #1f7aa8 !important;
    color: #fff !important;
}

/* Dark-mode fixes for fees structure accordion */
html[data-theme='dark'] .fees-year-card {
    background: var(--app-surface-1) !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .fees-year-head {
    background: var(--app-surface-2) !important;
    color: #e5e7eb !important;
    border-bottom: 1px solid var(--app-border) !important;
}
html[data-theme='dark'] .fees-year-head span,
html[data-theme='dark'] .fees-year-head i {
    color: inherit !important;
}
html[data-theme='dark'] .fees-year-head:hover {
    background: #273449 !important;
}
html[data-theme='dark'] .fees-year-body {
    background: var(--app-surface-1) !important;
}
html[data-theme='dark'] .fees-sem-title {
    background: #1f3b57 !important;
    color: #e0f2fe !important;
}
html[data-theme='dark'] .fees-total-row td {
    background: #1f2937 !important;
    color: #f8fafc !important;
    border-color: var(--app-border) !important;
}
html[data-theme='dark'] .fees-year-body .tbl td[style*='color:#64748b;'],
html[data-theme='dark'] .fees-year-body .tbl td[style*='color: #64748b;'] {
    color: #cbd5e1 !important;
}
@media (max-width: 1200px) {
    .chip-row {
        white-space: normal;
        flex-wrap: wrap;
    }
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
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
    .group-total { grid-template-columns: 1fr; }
    .ledger-info-wrap { grid-template-columns: 1fr; }
    .ledger-photo { justify-self: start; }
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
        <li><a href="<?php echo e($linkGeneratePrn); ?>">GENERATE PRN</a></li>
        <li><a href="<?php echo e($linkEnroll); ?>">ENROLLMENT & REGISTRATION</a></li>
        <li class="active"><a href="<?php echo e($linkPayments); ?>">PAYMENTS</a></li>
        <ul class="payments-submenu">
            <li class="<?php echo $section === 'bills' ? 'active' : ''; ?>"><a href="payments.php?section=bills">MY BILLS/INVOICES</a></li>
            <li class="<?php echo $section === 'transactions' ? 'active' : ''; ?>"><a href="payments.php?section=transactions">MY TRANSACTIONS</a></li>
            <li class="<?php echo $section === 'migrated' ? 'active' : ''; ?>"><a href="payments.php?section=migrated">MIGRATED TRANSACTIONS</a></li>
            <li class="<?php echo $section === 'ledger' ? 'active' : ''; ?>"><a href="payments.php?section=ledger">MY STUDENT LEDGER</a></li>
            <li class="<?php echo $section === 'fees' ? 'active' : ''; ?>"><a href="payments.php?section=fees">MY FEES STRUCTURE</a></li>
        </ul>
        <li><a href="<?php echo e($linkProgramme); ?>">MY PROGRAMME</a></li>
        <li><a href="services.php?tab=apply">SERVICES</a></li>
        <ul class="services-submenu">
            <li><a href="services.php?tab=apply">APPLY FOR SERVICES</a></li>
            <li><a href="services.php?tab=history">SERVICE HISTORY</a></li>
            <li><a href="services.php?tab=new_id">NEW ID CARDS</a></li>
        </ul>
        <li><a href="<?php echo e($linkDashboard); ?>">BIO DATA</a></li>
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
            <button onclick="location.href='<?php echo e($linkPayments); ?>'" style="background:#1f7aa8; color:#fff; border:1px solid #1f7aa8; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:700;">PAYMENTS</button>
            <button onclick="location.href='<?php echo e($linkGeneratePrn); ?>'" style="background:#f1f5f9; color:#222; border:1px solid #e5e7eb; border-radius:5px; padding:5px 10px; font-size:0.92rem; font-weight:600;">Generate PRN</button>
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
        <span class="chip gray">TOTAL FEES BAL DUE: <?php echo Helper::formatCurrencyDual((float)$outstandingBalance, 'UGX'); ?></span>
        <span class="chip blue">BALANCE ON ACCOUNT: <?php echo Helper::formatCurrencyDual((float)($studentProfile['account_balance'] ?? 0), 'UGX'); ?></span>
    </div>

    <div class="prn-wrap">
        <div class="prn-card">
            <div class="prn-tabs" style="justify-content:space-between;">
                <div class="prn-tab active" style="cursor:default;">
                    <?php
                    $titleMap = [
                        'bills' => 'SEMESTER INVOICES/BILLS',
                        'transactions' => 'MY TRANSACTIONS',
                        'migrated' => 'MIGRATED TRANSACTIONS',
                        'ledger' => 'MY STUDENT LEDGER',
                        'fees' => 'MY FEES STRUCTURE'
                    ];
                    echo e($titleMap[$section] ?? 'PAYMENTS');
                    ?>
                </div>
                <button type="button" class="refs-reload-btn" onclick="window.location.reload();">RELOAD</button>
            </div>

            <?php if ($section === 'bills'): ?>
                <div class="summary-grid">
                    <div class="sum-card"><div class="sum-label">TOTAL INVOICE AMOUNT</div><div class="sum-value"><?php echo Helper::formatCurrencyDual((float)$invoiceTotals['total'], 'UGX'); ?></div></div>
                    <div class="sum-card"><div class="sum-label">TOTAL INVOICE AMOUNT PAID</div><div class="sum-value"><?php echo Helper::formatCurrencyDual((float)$invoiceTotals['paid'], 'UGX'); ?></div></div>
                    <div class="sum-card"><div class="sum-label">TOTAL INVOICE AMOUNT DUE</div><div class="sum-value due"><?php echo Helper::formatCurrencyDual((float)$invoiceTotals['due'], 'UGX'); ?></div></div>
                    <div class="sum-card"><div class="sum-label">PERCENTAGE COMPLETION</div><div class="sum-value"><?php echo number_format($invoiceTotals['pct'], 2); ?> %</div></div>
                </div>

                <?php if (empty($invoiceGroupList)): ?>
                    <div class="alert alert-info mb-0">No invoices found.</div>
                <?php else: ?>
                    <?php $idx = 0; foreach ($invoiceGroupList as $group): $idx++; ?>
                        <?php $pct = $group['total'] > 0 ? (($group['total'] - $group['due']) / $group['total']) * 100 : 0; ?>
                        <div class="inv-accordion-item">
                            <button type="button" class="inv-head <?php echo $idx > 1 ? 'secondary' : ''; ?>" data-inv-target="inv_<?php echo $idx; ?>">
                                <span><i class="fas fa-paperclip mr-2"></i>YEAR <?php echo (int)$group['year_of_study']; ?> - SEMESTER <?php echo (int)$group['semester_number'] === 1 ? 'I' : 'II'; ?> - <?php echo e($group['academic_year']); ?></span>
                                <i class="fas fa-chevron-<?php echo $idx === 1 ? 'down' : 'right'; ?>"></i>
                            </button>
                            <div class="inv-body <?php echo $idx === 1 ? 'active' : ''; ?>" id="inv_<?php echo $idx; ?>">
                                <table class="tbl">
                                    <thead>
                                        <tr>
                                            <th>S/N</th><th>Invoice No.</th><th>Category</th><th>INV. AMOUNT</th><th>PAID</th><th>AMOUNT DUE</th><th>CURR</th><th>NARRATION</th><th>ALLOCATION</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $sn = 1; foreach ($group['rows'] as $row): ?>
                                            <?php
                                            $category = trim((string)($row['item_descriptions'] ?? ''));
                                            if ($category === '') {
                                                $category = 'Tuition Invoice';
                                            } else {
                                                $parts = explode(',', $category);
                                                $category = trim($parts[0]);
                                            }
                                            $dueVal = (float)($row['balance'] ?? 0);
                                            ?>
                                            <tr>
                                                <td><?php echo $sn++; ?></td>
                                                <td><?php echo e($row['invoice_number'] ?? '-'); ?></td>
                                                <td><?php echo e($category); ?></td>
                                                <td style="text-align:right;"><?php echo Helper::formatCurrencyDual((float)($row['total_amount'] ?? 0), 'UGX'); ?></td>
                                                <td style="text-align:right;"><?php echo Helper::formatCurrencyDual((float)($row['amount_paid'] ?? 0), 'UGX'); ?></td>
                                                <td style="text-align:right;"><?php echo Helper::formatCurrencyDual((float)$dueVal, 'UGX'); ?></td>
                                                <td>USD/UGX</td>
                                                <td><?php echo e($category); ?></td>
                                                <td><?php echo $dueVal <= 0 ? '<span class="badge-cleared"><i class="fas fa-check-circle"></i> Cleared</span>' : '<span class="badge-pending"><i class="fas fa-clock"></i> Pending</span>'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div class="group-total">
                                    <div>TOTAL AMOUNT: <span style="color:#059669;"><?php echo Helper::formatCurrencyDual((float)$group['total'], 'UGX'); ?></span></div>
                                    <div>TOTAL AMOUNT PAID: <span style="color:#059669;"><?php echo Helper::formatCurrencyDual((float)$group['paid'], 'UGX'); ?></span></div>
                                    <div>TOTAL AMOUNT DUE: <span style="color:#dc2626;"><?php echo Helper::formatCurrencyDual((float)$group['due'], 'UGX'); ?></span></div>
                                    <div>COMPLETION: <span style="color:#059669;"><?php echo number_format($pct, 2); ?> %</span></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php elseif ($section === 'transactions'): ?>
                <div class="tx-shell">
                    <div class="tx-tabs">
                        <a class="tx-tab <?php echo $txTab === 'invoice_payments' ? 'active' : ''; ?>" href="payments.php?section=transactions&tx_tab=invoice_payments"><i class="fas fa-money-bill-wave"></i> INVOICE PAYMENTS</a>
                        <a class="tx-tab <?php echo $txTab === 'fees_deposits' ? 'active' : ''; ?>" href="payments.php?section=transactions&tx_tab=fees_deposits"><i class="fas fa-file-invoice-dollar"></i> FEES DEPOSITS</a>
                        <a class="tx-tab <?php echo $txTab === 'check_prn' ? 'active' : ''; ?>" href="payments.php?section=transactions&tx_tab=check_prn"><i class="far fa-check-circle"></i> CHECK PRN STATUS</a>
                    </div>
                    <div class="tx-body">
                        <?php if ($txTab === 'invoice_payments'): ?>
                            <?php if (empty($invoiceTransactions)): ?>
                                <div class="alert alert-info mb-0">No invoice payment transactions found.</div>
                            <?php else: ?>
                                <table class="tbl">
                                    <thead><tr><th>Payment ID</th><th>Invoice No.</th><th>Reference</th><th>Method</th><th>Date</th><th>Amount (USD/UGX)</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($invoiceTransactions as $tx): ?>
                                            <tr>
                                                <td><?php echo e($tx['payment_id'] ?? '-'); ?></td>
                                                <td><?php echo e($tx['invoice_number'] ?? '-'); ?></td>
                                                <td><?php echo e($tx['reference_number'] ?: ($tx['receipt_number'] ?: '-')); ?></td>
                                                <td><?php echo e(ucwords(str_replace('_', ' ', (string)($tx['payment_method'] ?? '-')))); ?></td>
                                                <td><?php echo !empty($tx['payment_date']) ? e(date('d M Y', strtotime($tx['payment_date']))) : '-'; ?></td>
                                                <td style="text-align:right;"><?php echo Helper::formatCurrencyDual((float)($tx['amount'] ?? 0), 'UGX'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        <?php elseif ($txTab === 'fees_deposits'): ?>
                            <?php if (empty($depositTransactions)): ?>
                                <div class="alert alert-info mb-0">No fee deposit transactions found.</div>
                            <?php else: ?>
                                <table class="tbl">
                                    <thead><tr><th>Payment ID</th><th>Reference</th><th>Method</th><th>Date</th><th>Narration</th><th>Amount (USD/UGX)</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($depositTransactions as $tx): ?>
                                            <tr>
                                                <td><?php echo e($tx['payment_id'] ?? '-'); ?></td>
                                                <td><?php echo e($tx['reference_number'] ?: ($tx['receipt_number'] ?: '-')); ?></td>
                                                <td><?php echo e(ucwords(str_replace('_', ' ', (string)($tx['payment_method'] ?? '-')))); ?></td>
                                                <td><?php echo !empty($tx['payment_date']) ? e(date('d M Y', strtotime($tx['payment_date']))) : '-'; ?></td>
                                                <td><?php echo e($tx['notes'] ?? '-'); ?></td>
                                                <td style="text-align:right;"><?php echo Helper::formatCurrencyDual((float)($tx['amount'] ?? 0), 'UGX'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="tx-title">CHECK PAYMENT REFERENCE NUMBER STATUS</div>
                            <form class="tx-form-row" method="GET">
                                <input type="hidden" name="section" value="transactions">
                                <input type="hidden" name="tx_tab" value="check_prn">
                                <label><span style="color:#dc2626;">*</span> PAYMENT REFERENCE NUMBER:</label>
                                <input type="text" name="prn_ref" value="<?php echo e($prnQuery); ?>" placeholder="Enter Payment Reference Number" required>
                                <button type="submit" class="tx-check-btn"><i class="fas fa-search"></i> CHECK STATUS</button>
                            </form>
                            <?php if ($prnQuery !== ''): ?>
                                <?php if ($prnStatusRow): ?>
                                    <div class="alert alert-success mt-2 mb-0">
                                        <strong>Status:</strong> Paid |
                                        <strong>Payment ID:</strong> <?php echo e($prnStatusRow['payment_id'] ?? '-'); ?> |
                                        <strong>Amount:</strong> <?php echo Helper::formatCurrencyDual((float)($prnStatusRow['amount'] ?? 0), 'UGX'); ?> |
                                        <strong>Date:</strong> <?php echo !empty($prnStatusRow['payment_date']) ? e(date('d M Y', strtotime($prnStatusRow['payment_date']))) : '-'; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning mt-2 mb-0"><?php echo e($prnStatusMessage); ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($section === 'migrated'): ?>
                <div class="tx-shell">
                    <div class="tx-tabs">
                        <a class="tx-tab <?php echo $migratedTab === 'invoice_payments' ? 'active' : ''; ?>" href="payments.php?section=migrated&mtx_tab=invoice_payments"><i class="fas fa-money-bill-wave"></i> INVOICE PAYMENTS</a>
                        <a class="tx-tab <?php echo $migratedTab === 'fees_deposits' ? 'active' : ''; ?>" href="payments.php?section=migrated&mtx_tab=fees_deposits"><i class="fas fa-file-invoice-dollar"></i> FEES DEPOSITS</a>
                    </div>
                    <div class="tx-body">
                        <?php if ($migratedTab === 'invoice_payments'): ?>
                            <?php if (empty($migratedInvoiceTransactions)): ?>
                                <div class="alert alert-info mb-0">No migrated invoice payments available.</div>
                            <?php else: ?>
                                <table class="tbl">
                                    <thead><tr><th>Payment ID</th><th>Invoice No.</th><th>Reference</th><th>Method</th><th>Date</th><th>Narration</th><th>Amount (USD/UGX)</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($migratedInvoiceTransactions as $tx): ?>
                                            <tr>
                                                <td><?php echo e($tx['payment_id'] ?? '-'); ?></td>
                                                <td><?php echo e($tx['invoice_number'] ?? '-'); ?></td>
                                                <td><?php echo e($tx['reference_number'] ?: ($tx['receipt_number'] ?: '-')); ?></td>
                                                <td><?php echo e(ucwords(str_replace('_', ' ', (string)($tx['payment_method'] ?? '-')))); ?></td>
                                                <td><?php echo !empty($tx['payment_date']) ? e(date('d M Y', strtotime($tx['payment_date']))) : '-'; ?></td>
                                                <td><?php echo e($tx['notes'] ?? '-'); ?></td>
                                                <td style="text-align:right;"><?php echo Helper::formatCurrencyDual((float)($tx['amount'] ?? 0), 'UGX'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if (empty($migratedDepositTransactions)): ?>
                                <div class="alert alert-info mb-0">No migrated fee deposits available.</div>
                            <?php else: ?>
                                <table class="tbl">
                                    <thead><tr><th>Payment ID</th><th>Reference</th><th>Method</th><th>Date</th><th>Narration</th><th>Amount (USD/UGX)</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($migratedDepositTransactions as $tx): ?>
                                            <tr>
                                                <td><?php echo e($tx['payment_id'] ?? '-'); ?></td>
                                                <td><?php echo e($tx['reference_number'] ?: ($tx['receipt_number'] ?: '-')); ?></td>
                                                <td><?php echo e(ucwords(str_replace('_', ' ', (string)($tx['payment_method'] ?? '-')))); ?></td>
                                                <td><?php echo !empty($tx['payment_date']) ? e(date('d M Y', strtotime($tx['payment_date']))) : '-'; ?></td>
                                                <td><?php echo e($tx['notes'] ?? '-'); ?></td>
                                                <td style="text-align:right;"><?php echo Helper::formatCurrencyDual((float)($tx['amount'] ?? 0), 'UGX'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($section === 'ledger'): ?>
                <?php if (empty($ledgerStatementRows)): ?>
                    <div class="alert alert-info mb-0">No student ledger entries found.</div>
                <?php else: ?>
                    <div class="ledger-card">
                        <div class="ledger-head">
                            <div class="ledger-title">MY LEDGER</div>
                            <div class="ledger-actions">
                                <button type="button" class="refs-reload-btn" onclick="window.location.reload();">RELOAD</button>
                                <a class="tx-check-btn" style="text-decoration:none;" href="payments.php?section=ledger&download=csv">DOWNLOAD</a>
                            </div>
                        </div>

                        <div class="ledger-info-wrap">
                            <div class="ledger-meta">
                                <div><strong>TO:</strong> <?php echo e(strtoupper(trim(($studentProfile['last_name'] ?? '') . ' ' . ($studentProfile['first_name'] ?? '')))); ?> (<?php echo e($studentProfile['student_id'] ?? '-'); ?>)</div>
                                <div><strong>AS OF:</strong> <?php echo e(date('D, M j, Y g:i A')); ?></div>
                                <br>
                                <div><strong><?php echo e(strtoupper($registeredProgramDepartment !== '' ? $registeredProgramDepartment : 'FACULTY')); ?></strong></div>
                                <div><?php echo e(strtoupper($registeredProgramName)); ?></div>
                            </div>
                            <img src="/assets/img/student_sample.jpg" class="ledger-photo" alt="Ledger Profile">
                        </div>

                        <div style="padding:0 12px 12px;">
                            <div class="ledger-caption">Statement</div>
                            <table class="tbl">
                                <thead>
                                    <tr>
                                        <th>~</th>
                                        <th>Time stamp</th>
                                        <th>Entry</th>
                                        <th>Narration</th>
                                        <th>Debit</th>
                                        <th>Credit</th>
                                        <th>Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $sn = 1; foreach ($ledgerStatementRows as $row): ?>
                                        <tr>
                                            <td><?php echo $sn++; ?></td>
                                            <td><?php echo !empty($row['timestamp']) ? e(date('M j, Y g:i A', strtotime($row['timestamp']))) : '-'; ?></td>
                                            <td><?php echo e($row['entry'] ?? '-'); ?></td>
                                            <td><?php echo e($row['narration'] ?? '-'); ?></td>
                                            <td><?php echo ((float)($row['debit'] ?? 0) > 0) ? number_format((float)$row['debit']) : '0'; ?></td>
                                            <td><?php echo ((float)($row['credit'] ?? 0) > 0) ? number_format((float)$row['credit']) : '0'; ?></td>
                                            <td><?php echo number_format((float)($row['balance'] ?? 0)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="ledger-net">
                                <span>UGX NET STATEMENT BALANCE</span>
                                <span><?php echo number_format((float)$ledgerNetBalance); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php if (empty($feesByYear)): ?>
                    <div class="alert alert-info mb-0">No fee structure found for your program.</div>
                <?php else: ?>
                    <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:8px;">
                        <button type="button" class="refs-reload-btn" onclick="window.location.reload();">RELOAD</button>
                        <button type="button" class="tx-check-btn" onclick="window.print();">PRINT</button>
                    </div>
                    <div class="alert alert-info" style="margin-bottom:10px;">
                        <strong>Mode:</strong> Read-only active fee structure
                        <?php if (!empty($activeFeeVersionLabel)): ?>
                            <span class="ml-2"><strong>Version:</strong> <?php echo e($activeFeeVersionLabel); ?></span>
                            <span class="ml-2"><strong>Created by:</strong> <?php echo e($activeFeeVersionCreatedBy); ?> (Finance Office)</span>
                            <span class="ml-2"><strong>Approved by:</strong> <?php echo e($activeFeeVersionApprovedBy); ?> (University Administration)</span>
                        <?php endif; ?>
                    </div>
                    <?php $yIndex = 0; foreach ($feesByYear as $yearNum => $semData): $yIndex++; ?>
                        <div class="fees-year-card">
                            <button type="button" class="fees-year-head" data-fees-year="fees_year_<?php echo (int)$yearNum; ?>">
                                <span>YEAR <?php echo (int)$yearNum; ?></span>
                                <i class="fas fa-chevron-<?php echo $yIndex === 1 ? 'up' : 'down'; ?>"></i>
                            </button>
                            <div id="fees_year_<?php echo (int)$yearNum; ?>" class="fees-year-body <?php echo $yIndex === 1 ? 'active' : ''; ?>">
                                <?php foreach ([1, 2] as $semNum): ?>
                                    <div class="fees-sem-title">SEMESTER <?php echo $semNum === 1 ? 'I' : 'II'; ?></div>
                                    <table class="tbl" style="margin-bottom:8px;">
                                        <thead>
                                            <tr>
                                                <th>#</th><th>ITEM</th><th>CATEGORY</th><th>BASE</th><th>DISCOUNT</th><th>FINE</th><th>TO PAY</th><th>DEADLINE</th><th>CURR</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($semData[$semNum]['rows'])): ?>
                                                <?php $sn = 1; foreach ($semData[$semNum]['rows'] as $row): ?>
                                                    <?php
                                                        $baseAmount = (float)($row['amount'] ?? 0);
                                                        $discountAmount = (float)($row['discount_amount'] ?? 0);
                                                        $fineAmount = (float)($row['fine_amount'] ?? 0);
                                                        $toPayAmount = max(0, $baseAmount - $discountAmount + $fineAmount);
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $sn++; ?></td>
                                                        <td><?php echo e(strtoupper((string)($row['fee_name'] ?? '-'))); ?></td>
                                                        <td><?php echo e(strtoupper((string)($row['fee_type'] ?? '-'))); ?></td>
                                                        <td><?php echo number_format($baseAmount); ?></td>
                                                        <td><?php echo number_format($discountAmount); ?></td>
                                                        <td><?php echo number_format($fineAmount); ?></td>
                                                        <td><strong><?php echo number_format($toPayAmount); ?></strong></td>
                                                        <td><?php echo !empty($row['due_date']) ? e((string)$row['due_date']) : '-'; ?></td>
                                                        <td>UGX</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="9" style="text-align:center; color:#64748b;">No fees configured for this semester.</td></tr>
                                            <?php endif; ?>
                                            <tr class="fees-total-row">
                                                <td colspan="4">TOTAL</td>
                                                <td colspan="5"><?php echo number_format((float)($semData[$semNum]['total'] ?? 0)); ?> UGX</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
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

document.querySelectorAll('.inv-head').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var target = btn.getAttribute('data-inv-target');
        var panel = document.getElementById(target);
        if (!panel) return;
        var icon = btn.querySelector('i.fas.fa-chevron-right, i.fas.fa-chevron-down');
        var opening = !panel.classList.contains('active');
        panel.classList.toggle('active');
        if (icon) {
            icon.classList.remove('fa-chevron-right', 'fa-chevron-down');
            icon.classList.add(opening ? 'fa-chevron-down' : 'fa-chevron-right');
        }
    });
});

document.querySelectorAll('.fees-year-head').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var target = btn.getAttribute('data-fees-year');
        var panel = document.getElementById(target);
        if (!panel) return;
        var icon = btn.querySelector('i.fas.fa-chevron-up, i.fas.fa-chevron-down');
        var isOpen = panel.classList.contains('active');
        panel.classList.toggle('active');
        if (icon) {
            icon.classList.remove('fa-chevron-up', 'fa-chevron-down');
            icon.classList.add(isOpen ? 'fa-chevron-down' : 'fa-chevron-up');
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>


