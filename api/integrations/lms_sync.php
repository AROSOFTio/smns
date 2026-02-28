<?php
/**
 * LMS Integration Sync API
 * Supports: JSON, CSV, XML
 */
require_once '../../config.php';

function smnsLmsSendJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function smnsLmsArrayIsList(array $value): bool
{
    if (function_exists('array_is_list')) {
        return array_is_list($value);
    }
    return array_keys($value) === range(0, count($value) - 1);
}

function smnsLmsXmlKey(string $key): string
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

function smnsLmsAppendXml(DOMDocument $dom, DOMElement $parent, string $key, $value): void
{
    $safeKey = smnsLmsXmlKey($key);

    if (is_array($value)) {
        if (smnsLmsArrayIsList($value)) {
            $childName = substr($safeKey, -1) === 's' && strlen($safeKey) > 1 ? substr($safeKey, 0, -1) : 'item';
            foreach ($value as $row) {
                smnsLmsAppendXml($dom, $parent, $childName, $row);
            }
            return;
        }

        $node = $dom->createElement($safeKey);
        $parent->appendChild($node);
        foreach ($value as $childKey => $childValue) {
            smnsLmsAppendXml($dom, $node, (string)$childKey, $childValue);
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

function smnsLmsSendXml(array $payload): void
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $root = $dom->createElement('lms_sync');
    $dom->appendChild($root);
    foreach ($payload as $key => $value) {
        smnsLmsAppendXml($dom, $root, (string)$key, $value);
    }

    header('Content-Type: application/xml; charset=utf-8');
    echo $dom->saveXML();
    exit;
}

function smnsLmsAuthorizeRequest(): array
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

$auth = smnsLmsAuthorizeRequest();
if (empty($auth['authorized'])) {
    smnsLmsSendJson(401, ['success' => false, 'error' => 'Unauthorized']);
}

$format = strtolower(trim((string)($_GET['format'] ?? 'json')));
if (!in_array($format, ['json', 'csv', 'xml'], true)) {
    smnsLmsSendJson(422, ['success' => false, 'error' => 'Invalid format. Use json, csv, or xml.']);
}

$semesterId = (int)($_GET['semester_id'] ?? 0);
$programId = (int)($_GET['program_id'] ?? 0);
$includePending = filter_var($_GET['include_pending'] ?? '0', FILTER_VALIDATE_BOOLEAN);
$limit = (int)($_GET['limit'] ?? 10000);
if ($limit <= 0) {
    $limit = 10000;
}
if ($limit > 20000) {
    $limit = 20000;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $statusList = $includePending ? ['approved', 'pending'] : ['approved'];
    $where = [];
    $params = [];

    if ($semesterId > 0) {
        $where[] = 'cr.semester_id = :semester_id';
        $params['semester_id'] = $semesterId;
    }
    if ($programId > 0) {
        $where[] = 'c.program_id = :program_id';
        $params['program_id'] = $programId;
    }

    $statusMarks = [];
    foreach ($statusList as $idx => $statusValue) {
        $key = 'status_' . $idx;
        $statusMarks[] = ':' . $key;
        $params[$key] = $statusValue;
    }
    $where[] = 'cr.status IN (' . implode(', ', $statusMarks) . ')';

    $sql = "
        SELECT
            c.id AS course_id,
            c.course_code,
            c.course_name,
            c.credit_hours,
            c.level_year,
            c.semester_offered,
            sem.id AS semester_id,
            sem.semester_name,
            sem.semester_number,
            ay.year_name AS academic_year,
            s.id AS student_db_id,
            s.student_id AS student_number,
            CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, '')) AS student_name,
            s.email AS student_email,
            cr.registration_date,
            cr.status AS registration_status
        FROM course_registrations cr
        INNER JOIN courses c ON c.id = cr.course_id
        INNER JOIN students s ON s.id = cr.student_id
        INNER JOIN semesters sem ON sem.id = cr.semester_id
        INNER JOIN academic_years ay ON ay.id = sem.academic_year_id
    ";
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.course_code ASC, s.student_id ASC LIMIT :limit_rows';

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $courses = [];
    $uniqueStudents = [];
    foreach ($rows as $row) {
        $courseKey = (int)$row['course_id'] . '|' . (int)$row['semester_id'];
        if (!isset($courses[$courseKey])) {
            $courses[$courseKey] = [
                'course_id' => (int)$row['course_id'],
                'course_code' => (string)$row['course_code'],
                'course_name' => (string)$row['course_name'],
                'credit_hours' => (int)$row['credit_hours'],
                'level_year' => (int)$row['level_year'],
                'semester_id' => (int)$row['semester_id'],
                'semester_name' => (string)$row['semester_name'],
                'semester_number' => (int)$row['semester_number'],
                'academic_year' => (string)$row['academic_year'],
                'enrollments' => [],
            ];
        }

        $studentNumber = (string)($row['student_number'] ?? '');
        if ($studentNumber !== '') {
            $uniqueStudents[$studentNumber] = true;
        }

        $courses[$courseKey]['enrollments'][] = [
            'student_db_id' => (int)$row['student_db_id'],
            'student_number' => $studentNumber,
            'student_name' => trim((string)($row['student_name'] ?? '')),
            'student_email' => (string)($row['student_email'] ?? ''),
            'registration_date' => (string)($row['registration_date'] ?? ''),
            'registration_status' => (string)($row['registration_status'] ?? ''),
        ];
    }

    $response = [
        'success' => true,
        'generated_at' => date('c'),
        'actor' => (string)($auth['actor'] ?? 'unknown'),
        'lms_provider' => defined('LMS_INTEGRATION_PROVIDER') ? (string)LMS_INTEGRATION_PROVIDER : 'moodle',
        'lms_base_url' => defined('LMS_INTEGRATION_BASE_URL') ? (string)LMS_INTEGRATION_BASE_URL : '',
        'filters' => [
            'semester_id' => $semesterId > 0 ? $semesterId : null,
            'program_id' => $programId > 0 ? $programId : null,
            'include_pending' => $includePending,
            'limit' => $limit,
        ],
        'summary' => [
            'courses_count' => count($courses),
            'enrollments_count' => count($rows),
            'unique_students' => count($uniqueStudents),
        ],
        'courses' => array_values($courses),
    ];

    if ($format === 'json') {
        smnsLmsSendJson(200, $response);
    }

    if ($format === 'xml') {
        smnsLmsSendXml($response);
    }

    $filename = 'lms_sync_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
    fputcsv($out, ['Courses Count', (string)count($courses)]);
    fputcsv($out, ['Enrollments Count', (string)count($rows)]);
    fputcsv($out, ['Unique Students', (string)count($uniqueStudents)]);
    fputcsv($out, []);
    fputcsv($out, [
        'Course Code',
        'Course Name',
        'Semester',
        'Academic Year',
        'Credit Hours',
        'Level Year',
        'Student Number',
        'Student Name',
        'Student Email',
        'Registration Date',
        'Registration Status',
    ]);

    foreach ($rows as $row) {
        fputcsv($out, [
            (string)($row['course_code'] ?? ''),
            (string)($row['course_name'] ?? ''),
            (string)($row['semester_name'] ?? ''),
            (string)($row['academic_year'] ?? ''),
            (string)($row['credit_hours'] ?? ''),
            (string)($row['level_year'] ?? ''),
            (string)($row['student_number'] ?? ''),
            trim((string)($row['student_name'] ?? '')),
            (string)($row['student_email'] ?? ''),
            (string)($row['registration_date'] ?? ''),
            (string)($row['registration_status'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
} catch (Exception $e) {
    smnsLmsSendJson(500, [
        'success' => false,
        'error' => 'Unable to build LMS sync feed.',
        'message' => APP_DEBUG ? $e->getMessage() : null,
    ]);
}

