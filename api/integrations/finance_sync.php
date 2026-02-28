<?php
/**
 * Finance Integration Sync API
 * Supports: JSON, CSV, XML
 */
require_once '../../config.php';

function smnsFinanceSendJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function smnsFinanceArrayIsList(array $value): bool
{
    if (function_exists('array_is_list')) {
        return array_is_list($value);
    }
    return array_keys($value) === range(0, count($value) - 1);
}

function smnsFinanceXmlKey(string $key): string
{
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9_]+/i', '_', $key);
    $key = trim((string)$key, '_');
    if ($key === '') {
        $key = 'item';
    }
    if (is_numeric(substr($key, 0, 1))) {
        $key = 'item_' . $key;
    }
    return $key;
}

function smnsFinanceAppendXml(DOMDocument $dom, DOMElement $parent, string $key, $value): void
{
    $safeKey = smnsFinanceXmlKey($key);

    if (is_array($value)) {
        if (smnsFinanceArrayIsList($value)) {
            $childName = substr($safeKey, -1) === 's' && strlen($safeKey) > 1 ? substr($safeKey, 0, -1) : 'item';
            foreach ($value as $row) {
                smnsFinanceAppendXml($dom, $parent, $childName, $row);
            }
            return;
        }

        $node = $dom->createElement($safeKey);
        $parent->appendChild($node);
        foreach ($value as $childKey => $childValue) {
            smnsFinanceAppendXml($dom, $node, (string)$childKey, $childValue);
        }
        return;
    }

    $node = $dom->createElement($safeKey);
    if ($value !== null) {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        $node->appendChild($dom->createTextNode((string)$value));
    }
    $parent->appendChild($node);
}

function smnsFinanceSendXml(array $payload): void
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $root = $dom->createElement('finance_sync');
    $dom->appendChild($root);
    foreach ($payload as $key => $value) {
        smnsFinanceAppendXml($dom, $root, (string)$key, $value);
    }

    header('Content-Type: application/xml; charset=utf-8');
    echo $dom->saveXML();
    exit;
}

function smnsFinanceAuthorizeRequest(): array
{
    $expectedToken = defined('INTEGRATION_API_TOKEN') ? trim((string)INTEGRATION_API_TOKEN) : '';
    $providedToken = trim((string)($_SERVER['HTTP_X_INTEGRATION_TOKEN'] ?? ($_GET['token'] ?? '')));

    if (
        $providedToken !== '' &&
        $expectedToken !== '' &&
        stripos($expectedToken, 'replace-with-') !== 0 &&
        hash_equals($expectedToken, $providedToken)
    ) {
        return ['authorized' => true, 'actor' => 'integration_token', 'role' => 'integration'];
    }

    $modules = ['admin', 'finance'];
    foreach ($modules as $module) {
        $cookieName = 'SMNS_' . strtoupper($module) . '_SESSION';
        if (empty($_COOKIE[$cookieName])) {
            continue;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name($cookieName);
        session_start();

        $loggedInKey = $module . '_logged_in';
        $roleKey = $module . '_role';
        $userIdKey = $module . '_user_id';
        if (
            !empty($_SESSION[$loggedInKey]) &&
            $_SESSION[$loggedInKey] === true &&
            !empty($_SESSION[$userIdKey]) &&
            ($_SESSION[$roleKey] ?? '') === $module
        ) {
            return [
                'authorized' => true,
                'actor' => $module . '_session',
                'role' => $module,
                'user_id' => (int)$_SESSION[$userIdKey],
            ];
        }
    }

    return ['authorized' => false];
}

$auth = smnsFinanceAuthorizeRequest();
if (empty($auth['authorized'])) {
    smnsFinanceSendJson(401, ['success' => false, 'error' => 'Unauthorized']);
}

$format = strtolower(trim((string)($_GET['format'] ?? 'json')));
if (!in_array($format, ['json', 'csv', 'xml'], true)) {
    smnsFinanceSendJson(422, ['success' => false, 'error' => 'Invalid format. Use json, csv, or xml.']);
}

$semesterId = (int)($_GET['semester_id'] ?? 0);
$fromDate = trim((string)($_GET['from'] ?? ''));
$toDate = trim((string)($_GET['to'] ?? ''));
$limit = (int)($_GET['limit'] ?? 1000);
if ($limit <= 0) {
    $limit = 1000;
}
if ($limit > 10000) {
    $limit = 10000;
}

$validDate = static function (string $date): bool {
    if ($date === '') {
        return true;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
};

if (!$validDate($fromDate) || !$validDate($toDate)) {
    smnsFinanceSendJson(422, ['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD.']);
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $where = [];
    $params = [];
    if ($semesterId > 0) {
        $where[] = 'p.semester_id = :semester_id';
        $params['semester_id'] = $semesterId;
    }
    if ($fromDate !== '') {
        $where[] = 'p.payment_date >= :from_date';
        $params['from_date'] = $fromDate;
    }
    if ($toDate !== '') {
        $where[] = 'p.payment_date <= :to_date';
        $params['to_date'] = $toDate;
    }

    $sql = "
        SELECT
            p.id,
            p.payment_id,
            p.payment_date,
            p.amount,
            p.payment_method,
            p.reference_number,
            p.receipt_number,
            s.student_id AS student_number,
            CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, '')) AS student_name,
            i.invoice_number,
            COALESCE(i.total_amount, 0) AS invoice_total,
            COALESCE(i.amount_paid, 0) AS invoice_amount_paid,
            COALESCE(i.balance, 0) AS invoice_balance,
            sem.semester_name,
            sem.semester_number,
            ay.year_name AS academic_year
        FROM payments p
        INNER JOIN students s ON s.id = p.student_id
        LEFT JOIN invoices i ON i.id = p.invoice_id
        LEFT JOIN semesters sem ON sem.id = p.semester_id
        LEFT JOIN academic_years ay ON ay.id = sem.academic_year_id
    ";

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY p.payment_date DESC, p.id DESC LIMIT :limit_rows';
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totalAmount = 0.0;
    $methodSummary = [];
    foreach ($rows as $row) {
        $amount = (float)($row['amount'] ?? 0);
        $totalAmount += $amount;
        $method = strtolower((string)($row['payment_method'] ?? 'unknown'));
        if (!isset($methodSummary[$method])) {
            $methodSummary[$method] = ['count' => 0, 'amount' => 0.0];
        }
        $methodSummary[$method]['count']++;
        $methodSummary[$method]['amount'] += $amount;
    }

    $response = [
        'success' => true,
        'generated_at' => date('c'),
        'actor' => (string)($auth['actor'] ?? 'unknown'),
        'filters' => [
            'semester_id' => $semesterId > 0 ? $semesterId : null,
            'from' => $fromDate !== '' ? $fromDate : null,
            'to' => $toDate !== '' ? $toDate : null,
            'limit' => $limit,
        ],
        'summary' => [
            'payments_count' => count($rows),
            'total_collections' => round($totalAmount, 2),
            'by_method' => $methodSummary,
        ],
        'payments' => $rows,
    ];

    if ($format === 'json') {
        smnsFinanceSendJson(200, $response);
    }

    if ($format === 'xml') {
        smnsFinanceSendXml($response);
    }

    $filename = 'finance_sync_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
    fputcsv($out, ['Payments Count', (string)count($rows)]);
    fputcsv($out, ['Total Collections', number_format($totalAmount, 2, '.', '')]);
    fputcsv($out, []);
    fputcsv($out, [
        'Payment ID',
        'Date',
        'Amount',
        'Method',
        'Reference',
        'Receipt',
        'Student Number',
        'Student Name',
        'Invoice Number',
        'Invoice Total',
        'Invoice Paid',
        'Invoice Balance',
        'Semester',
        'Academic Year',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string)($row['payment_id'] ?? ''),
            (string)($row['payment_date'] ?? ''),
            (string)($row['amount'] ?? ''),
            (string)($row['payment_method'] ?? ''),
            (string)($row['reference_number'] ?? ''),
            (string)($row['receipt_number'] ?? ''),
            (string)($row['student_number'] ?? ''),
            trim((string)($row['student_name'] ?? '')),
            (string)($row['invoice_number'] ?? ''),
            (string)($row['invoice_total'] ?? ''),
            (string)($row['invoice_amount_paid'] ?? ''),
            (string)($row['invoice_balance'] ?? ''),
            (string)($row['semester_name'] ?? ''),
            (string)($row['academic_year'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
} catch (Exception $e) {
    smnsFinanceSendJson(500, [
        'success' => false,
        'error' => 'Unable to build finance sync feed.',
        'message' => APP_DEBUG ? $e->getMessage() : null,
    ]);
}

