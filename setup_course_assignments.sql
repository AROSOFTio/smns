-- Course Assignments Table Setup
-- This table links courses to specific programs, years, and semesters

-- Create course_assignments table if it doesn't exist
CREATE TABLE IF NOT EXISTS course_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    course_id INT NOT NULL,
    semester_id INT NOT NULL,
    year_of_study INT NOT NULL COMMENT 'Which year of study (1, 2, 3, 4, etc.)',
    is_required TINYINT(1) DEFAULT 1 COMMENT '1 = required, 0 = elective',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    UNIQUE KEY unique_assignment (program_id, course_id, semester_id, year_of_study)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Assigns courses to programs, semesters, and years';

-- Add year_of_study column to students table if missing
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS year_of_study INT DEFAULT 1 COMMENT 'Current year of study' 
AFTER study_year;

-- Add tracking columns for semester reporting
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS last_reported_semester_id INT NULL AFTER year_of_study,
ADD COLUMN IF NOT EXISTS last_reported_academic_year_id INT NULL AFTER last_reported_semester_id,
ADD COLUMN IF NOT EXISTS last_reported_at DATETIME NULL AFTER last_reported_academic_year_id;

-- Add year_of_study to semester_registrations for historical tracking
ALTER TABLE semester_registrations
ADD COLUMN IF NOT EXISTS year_of_study INT NULL COMMENT 'Year of study at registration time'
AFTER semester_id;

-- Example: Insert sample course assignments
-- Uncomment and modify based on your actual program/course/semester IDs
/*
INSERT INTO course_assignments (program_id, course_id, semester_id, year_of_study, is_required, status)
SELECT 
    1 as program_id,  -- Replace with your actual program ID
    c.id as course_id,
    1 as semester_id,  -- Replace with your actual semester ID
    1 as year_of_study,
    1 as is_required,
    'active' as status
FROM courses c
WHERE c.course_code IN ('CS101', 'MATH101', 'ENG101')  -- Replace with actual course codes
ON DUPLICATE KEY UPDATE status = 'active';
*/

-- View to check course assignments easily
CREATE OR REPLACE VIEW view_course_assignments AS
SELECT 
    ca.id,
    p.program_name,
    c.course_code,
    c.course_name,
    c.credits,
    s.semester_number,
    ay.year_name,
    ca.year_of_study,
    CASE WHEN ca.is_required = 1 THEN 'Required' ELSE 'Elective' END as course_type,
    ca.status
FROM course_assignments ca
JOIN programs p ON ca.program_id = p.id
JOIN courses c ON ca.course_id = c.id
JOIN semesters s ON ca.semester_id = s.id
JOIN academic_years ay ON s.academic_year_id = ay.id
ORDER BY p.program_name, ca.year_of_study, s.semester_number, c.course_code;
