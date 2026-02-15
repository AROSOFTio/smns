<?php
require 'config.php';

try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Add missing columns to students table
    $sql = "
    ALTER TABLE students
    ADD COLUMN title VARCHAR(10) NULL AFTER last_name,
    ADD COLUMN national_id VARCHAR(20) NULL AFTER date_of_birth,
    ADD COLUMN specialization VARCHAR(200) NULL AFTER level_year,
    ADD COLUMN qualifications TEXT NULL AFTER specialization
    ";

    $pdo->exec($sql);
    echo "Students table updated successfully.\n";

    // Add missing columns to lecturers table
    $sql2 = "
    ALTER TABLE lecturers
    ADD COLUMN title VARCHAR(10) NULL AFTER last_name,
    ADD COLUMN date_of_birth DATE NULL AFTER last_name
    ";

    $pdo->exec($sql2);
    echo "Lecturers table updated successfully.\n";

    // Create semester_registrations table (migration)
    $sql3 = "CREATE TABLE IF NOT EXISTS semester_registrations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        semester_id INT NOT NULL,
        year_of_study TINYINT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        request_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        approved_by INT DEFAULT NULL,
        approval_date DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_student_semester (student_id, semester_id),
        KEY idx_student (student_id),
        KEY idx_semester (semester_id),
        KEY idx_year (year_of_study),
        KEY idx_status (status),
        KEY idx_approver (approved_by),
        CONSTRAINT fk_semester_reg_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        CONSTRAINT fk_semester_reg_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
        CONSTRAINT fk_semester_reg_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql3);
    echo "semester_registrations table created/verified successfully.\n";

    // Ensure year_of_study column exists (add for upgrades)
    try {
        $pdo->exec("ALTER TABLE semester_registrations ADD COLUMN IF NOT EXISTS year_of_study TINYINT NULL AFTER semester_id");
        echo "semester_registrations.year_of_study ensured.\n";
    } catch (Exception $e) {
        // some MySQL versions don't support IF NOT EXISTS on ADD COLUMN — fallback
        try { $pdo->exec("ALTER TABLE semester_registrations ADD COLUMN year_of_study TINYINT NULL AFTER semester_id"); echo "semester_registrations.year_of_study added.\n"; } catch (Exception $e) { /* ignore */ }
    }

    // Ensure student_requests has semester_id and year_of_study columns
    try {
        $pdo->exec("ALTER TABLE student_requests ADD COLUMN IF NOT EXISTS semester_id INT NULL AFTER request_type");
        echo "student_requests.semester_id ensured.\n";
    } catch (Exception $e) {
        try { $pdo->exec("ALTER TABLE student_requests ADD COLUMN semester_id INT NULL AFTER request_type"); echo "student_requests.semester_id added.\n"; } catch (Exception $e) { /* ignore */ }
    }
    try {
        $pdo->exec("ALTER TABLE student_requests ADD COLUMN IF NOT EXISTS year_of_study INT NULL AFTER semester_id");
        echo "student_requests.year_of_study ensured.\n";
    } catch (Exception $e) {
        try { $pdo->exec("ALTER TABLE student_requests ADD COLUMN year_of_study INT NULL AFTER semester_id"); echo "student_requests.year_of_study added.\n"; } catch (Exception $e) { /* ignore */ }
    }
    try {
        $pdo->exec("ALTER TABLE student_requests ADD INDEX IF NOT EXISTS idx_request_type (request_type)");
        echo "student_requests index on request_type ensured.\n";
    } catch (Exception $e) {
        try { $pdo->exec("ALTER TABLE student_requests ADD INDEX idx_request_type (request_type)"); echo "student_requests index added.\n"; } catch (Exception $e) { /* ignore */ }
    }
    $pdo->exec($sql3);
    echo "semester_registrations table created/verified successfully.\n"; 

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>