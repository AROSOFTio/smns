<?php
// scripts/fetch_admin_courses.php
// Simple JSON endpoint to fetch courses using the same filters as admin list
require_once __DIR__ . '/../config.php';

// Allow being called from browser or CLI for quick checks
try {
    $db = new Database();
    $conn = $db->getConnection();

    $search = $_GET['search'] ?? '';
    $level = $_GET['level'] ?? '';

    $sql = "SELECT c.* FROM courses c WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (c.course_code LIKE :search_code OR c.course_name LIKE :search_name)";
        $params['search_code'] = "%$search%";
        $params['search_name'] = "%$search%";
    }

    if (!empty($level)) {
        $sql .= " AND c.level_year = :level";
        $params['level'] = $level;
    }

    $sql .= " ORDER BY c.level_year ASC, c.semester_offered ASC, c.course_code ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'count' => count($courses), 'courses' => $courses]);
    exit;
} catch (Exception $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
