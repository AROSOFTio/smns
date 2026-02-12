-- ============================================================================
-- SEMINARY RESULTS MANAGEMENT SYSTEM - SEED DATA
-- Version: 1.0
-- Database: MySQL 5.7+
-- Purpose: Populate database with sample data for testing and development
-- ============================================================================

USE smns;

-- Disable foreign key checks for smooth insertion
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- CLEAR EXISTING SEED DATA (Optional - Uncomment to clear before re-seeding)
-- ============================================================================

/*
-- Clear in reverse order of dependencies
DELETE FROM password_history WHERE user_id > 1;
DELETE FROM role_permissions WHERE role_id IN (1,2,3,4);
DELETE FROM activity_logs WHERE user_id > 1;
DELETE FROM notifications WHERE user_id > 1;
DELETE FROM announcements WHERE id > 0;
DELETE FROM course_units WHERE id > 0;
DELETE FROM student_gpas WHERE id > 0;
DELETE FROM results WHERE id > 0;
DELETE FROM course_registrations WHERE id > 0;
DELETE FROM course_assignments WHERE id > 0;
DELETE FROM invoice_items WHERE id > 0;
DELETE FROM payments WHERE id > 0;
DELETE FROM invoices WHERE id > 0;
DELETE FROM student_balances WHERE id > 0;
DELETE FROM fees_structure WHERE id > 0;
DELETE FROM students WHERE user_id > 1;
DELETE FROM lecturers WHERE user_id > 1;
DELETE FROM finance_staff WHERE user_id > 1;
DELETE FROM admins WHERE user_id > 1;
DELETE FROM courses WHERE id > 0;
DELETE FROM semesters WHERE id > 0;
DELETE FROM academic_years WHERE id > 0;
DELETE FROM programs WHERE id > 0;
DELETE FROM users WHERE id > 1;  -- Keep the default admin user
*/

-- ============================================================================
-- PROGRAMS
-- ============================================================================

INSERT INTO programs (program_code, program_name, department, duration_years, total_credits_required, description, status) VALUES
('BTH', 'Bachelor of Theology', 'Theology', 4, 120, 'Four-year undergraduate program in Biblical and Theological Studies', 'active'),
('MTH', 'Master of Theology', 'Theology', 2, 60, 'Two-year graduate program in advanced theological studies', 'active'),
('MDIV', 'Master of Divinity', 'Divinity', 3, 90, 'Three-year professional ministry preparation program', 'active'),
('DPT', 'Diploma in Pastoral Theology', 'Pastoral Studies', 2, 60, 'Two-year diploma program in pastoral ministry', 'active'),
('CMS', 'Certificate in Ministry Studies', 'Ministry', 1, 30, 'One-year certificate program in basic ministry skills', 'active'),
('PHD', 'Doctor of Philosophy in Theology', 'Theology', 4, 80, 'Four-year doctoral research program', 'active');

-- ============================================================================
-- ACADEMIC YEARS
-- ============================================================================

INSERT INTO academic_years (year_name, start_date, end_date, status) VALUES
('2022/2023', '2022-09-01', '2023-06-30', 'completed'),
('2023/2024', '2023-09-01', '2024-06-30', 'completed'),
('2024/2025', '2024-09-01', '2025-06-30', 'active'),
('2025/2026', '2025-09-01', '2026-06-30', 'inactive');

-- ============================================================================
-- SEMESTERS
-- ============================================================================

INSERT INTO semesters (academic_year_id, semester_name, semester_number, start_date, end_date, registration_start_date, registration_end_date, status) VALUES
-- 2022/2023
(1, 'Semester 1', 1, '2022-09-01', '2022-12-15', '2022-08-15', '2022-09-10', 'completed'),
(1, 'Semester 2', 2, '2023-01-15', '2023-06-30', '2023-01-05', '2023-01-20', 'completed'),
-- 2023/2024
(2, 'Semester 1', 1, '2023-09-01', '2023-12-15', '2023-08-15', '2023-09-10', 'completed'),
(2, 'Semester 2', 2, '2024-01-15', '2024-06-30', '2024-01-05', '2024-01-20', 'completed'),
-- 2024/2025
(3, 'Semester 1', 1, '2024-09-01', '2024-12-15', '2024-08-15', '2024-09-10', 'completed'),
(3, 'Semester 2', 2, '2025-01-15', '2025-06-30', '2025-01-05', '2025-01-20', 'active'),
-- 2025/2026
(4, 'Semester 1', 1, '2025-09-01', '2025-12-15', '2025-08-15', '2025-09-10', 'inactive'),
(4, 'Semester 2', 2, '2026-01-15', '2026-06-30', '2026-01-05', '2026-01-20', 'inactive');

-- ============================================================================
-- COURSES
-- ============================================================================

-- Bachelor of Theology Courses
INSERT INTO courses (course_code, course_name, credit_hours, program_id, level_year, semester_offered, prerequisites, description, status) VALUES
-- Year 1
('BTH101', 'Introduction to Biblical Studies', 3, 1, 1, 1, NULL, 'Overview of the Bible structure, themes, and interpretation methods', 'active'),
('BTH102', 'Old Testament Survey', 3, 1, 1, 1, NULL, 'Comprehensive study of the Old Testament books', 'active'),
('BTH103', 'New Testament Survey', 3, 1, 1, 2, NULL, 'Comprehensive study of the New Testament books', 'active'),
('BTH104', 'Christian Theology I', 3, 1, 1, 1, NULL, 'Introduction to systematic theology and doctrine', 'active'),
('BTH105', 'Church History I', 3, 1, 1, 2, NULL, 'Early church through medieval period', 'active'),
('BTH106', 'Hermeneutics', 3, 1, 1, 2, 'BTH101', 'Principles of biblical interpretation', 'active'),
('BTH107', 'Greek I', 3, 1, 1, 1, NULL, 'Introduction to Biblical Greek', 'active'),
('BTH108', 'Hebrew I', 3, 1, 1, 2, NULL, 'Introduction to Biblical Hebrew', 'active'),

-- Year 2
('BTH201', 'Christian Theology II', 3, 1, 2, 1, 'BTH104', 'Advanced systematic theology', 'active'),
('BTH202', 'Church History II', 3, 1, 2, 1, 'BTH105', 'Reformation to modern era', 'active'),
('BTH203', 'Pentateuch', 3, 1, 2, 1, 'BTH102', 'Detailed study of the first five books of the Bible', 'active'),
('BTH204', 'Gospels', 3, 1, 2, 2, 'BTH103', 'Synoptic Gospels and John', 'active'),
('BTH205', 'Greek II', 3, 1, 2, 1, 'BTH107', 'Intermediate Biblical Greek', 'active'),
('BTH206', 'Hebrew II', 3, 1, 2, 2, 'BTH108', 'Intermediate Biblical Hebrew', 'active'),
('BTH207', 'Christian Ethics', 3, 1, 2, 2, NULL, 'Moral theology and ethical decision making', 'active'),
('BTH208', 'Philosophy of Religion', 3, 1, 2, 1, NULL, 'Philosophical approaches to religious belief', 'active'),

-- Year 3
('BTH301', 'Pauline Epistles', 3, 1, 3, 1, 'BTH204', 'Study of Paul\'s letters', 'active'),
('BTH302', 'Prophetic Literature', 3, 1, 3, 1, 'BTH203', 'Major and minor prophets', 'active'),
('BTH303', 'Systematic Theology', 3, 1, 3, 2, 'BTH201', 'Advanced doctrinal studies', 'active'),
('BTH304', 'World Religions', 3, 1, 3, 1, NULL, 'Comparative study of major world religions', 'active'),
('BTH305', 'Homiletics', 3, 1, 3, 2, NULL, 'Principles of sermon preparation and delivery', 'active'),
('BTH306', 'Pastoral Care', 3, 1, 3, 2, NULL, 'Ministry of counseling and care', 'active'),
('BTH307', 'Missiology', 3, 1, 3, 1, NULL, 'Theology and practice of missions', 'active'),
('BTH308', 'Apologetics', 3, 1, 3, 2, 'BTH208', 'Defense of the Christian faith', 'active'),

-- Year 4
('BTH401', 'Advanced Hermeneutics', 3, 1, 4, 1, 'BTH106', 'Advanced interpretive methods', 'active'),
('BTH402', 'Contemporary Theology', 3, 1, 4, 1, 'BTH303', 'Modern theological movements', 'active'),
('BTH403', 'Research Methods', 3, 1, 4, 1, NULL, 'Academic research and writing', 'active'),
('BTH404', 'Senior Thesis', 6, 1, 4, 2, 'BTH403', 'Independent research project', 'active'),
('BTH405', 'Ecclesiology', 3, 1, 4, 1, NULL, 'Doctrine of the church', 'active'),
('BTH406', 'Eschatology', 3, 1, 4, 2, 'BTH303', 'Study of end times theology', 'active');

-- Master of Divinity Courses
INSERT INTO courses (course_code, course_name, credit_hours, program_id, level_year, semester_offered, prerequisites, description, status) VALUES
('MDIV501', 'Advanced Biblical Exegesis', 3, 3, 1, 1, NULL, 'In-depth biblical interpretation', 'active'),
('MDIV502', 'Pastoral Leadership', 3, 3, 1, 1, NULL, 'Church leadership and administration', 'active'),
('MDIV503', 'Advanced Homiletics', 3, 3, 1, 2, NULL, 'Advanced preaching techniques', 'active'),
('MDIV504', 'Spiritual Formation', 3, 3, 1, 2, NULL, 'Personal and congregational spiritual growth', 'active'),
('MDIV505', 'Church Planting', 3, 3, 2, 1, NULL, 'Principles of starting new churches', 'active'),
('MDIV506', 'Advanced Pastoral Care', 3, 3, 2, 1, NULL, 'Clinical pastoral counseling', 'active'),
('MDIV507', 'Liturgy and Worship', 3, 3, 2, 2, NULL, 'Theology and practice of worship', 'active'),
('MDIV508', 'Field Education', 6, 3, 2, 2, NULL, 'Supervised ministry practicum', 'active');

-- Diploma Courses
INSERT INTO courses (course_code, course_name, credit_hours, program_id, level_year, semester_offered, prerequisites, description, status) VALUES
('DPT101', 'Bible Survey', 3, 4, 1, 1, NULL, 'Overview of the entire Bible', 'active'),
('DPT102', 'Basic Theology', 3, 4, 1, 1, NULL, 'Foundational Christian doctrines', 'active'),
('DPT103', 'Ministry Skills', 3, 4, 1, 2, NULL, 'Practical ministry competencies', 'active'),
('DPT104', 'Pastoral Ministry', 3, 4, 1, 2, NULL, 'Introduction to pastoral work', 'active'),
('DPT201', 'Preaching Basics', 3, 4, 2, 1, NULL, 'Introduction to preaching', 'active'),
('DPT202', 'Church Administration', 3, 4, 2, 1, NULL, 'Managing church operations', 'active'),
('DPT203', 'Evangelism', 3, 4, 2, 2, NULL, 'Sharing the Gospel effectively', 'active'),
('DPT204', 'Ministry Practicum', 3, 4, 2, 2, NULL, 'Hands-on ministry experience', 'active');

-- ============================================================================
-- USERS (Password for all users: 'password')
-- ============================================================================

-- Hash: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi (password)
-- For production, use proper password hashing
-- Using INSERT IGNORE to skip if users already exist

INSERT IGNORE INTO users (username, email, password_hash, role, status) VALUES
-- Admin users (admin already exists from schema, so will be skipped)
('admin', 'admin@seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active'),
('admin2', 'admin2@seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active'),

-- Lecturer users
('prof.johnson', 'johnson@seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'lecturer', 'active'),
('prof.williams', 'williams@seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'lecturer', 'active'),
('prof.brown', 'brown@seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'lecturer', 'active'),
('prof.davis', 'davis@seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'lecturer', 'active'),
('prof.miller', 'miller@seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'lecturer', 'active'),
('prof.wilson', 'wilson@seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'lecturer', 'active'),

-- Finance users
('finance1', 'finance1@seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'finance', 'active'),
('finance2', 'finance2@seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'finance', 'active'),

-- Student users
('std001', 'john.doe@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std002', 'jane.smith@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std003', 'michael.johnson@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std004', 'sarah.williams@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std005', 'david.brown@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std006', 'emily.davis@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std007', 'james.miller@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std008', 'mary.wilson@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std009', 'robert.moore@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std010', 'jennifer.taylor@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std011', 'william.anderson@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std012', 'linda.thomas@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std013', 'richard.jackson@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std014', 'patricia.white@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std015', 'charles.harris@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std016', 'barbara.martin@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std017', 'joseph.thompson@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std018', 'susan.garcia@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std019', 'thomas.martinez@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('std020', 'jessica.robinson@student.seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active');

-- ============================================================================
-- ADMINS
-- ============================================================================

-- The first admin (user_id 1) already exists from schema
-- Add the second admin only
INSERT IGNORE INTO admins (user_id, first_name, last_name, phone, email) VALUES
(1, 'System', 'Administrator', '+256700000001', 'admin@seminary.edu'),
(2, 'Peter', 'Anderson', '+256700000002', 'admin2@seminary.edu');

-- ============================================================================
-- LECTURERS
-- ============================================================================

INSERT INTO lecturers (user_id, lecturer_id, first_name, middle_name, last_name, phone, email, department, qualifications, specialization, office_location, status) VALUES
(3, 'LEC001', 'Robert', 'James', 'Johnson', '+256700000101', 'johnson@seminary.edu', 'Theology', 'PhD in Biblical Studies', 'Old Testament', 'Office 101', 'active'),
(4, 'LEC002', 'Margaret', 'Anne', 'Williams', '+256700000102', 'williams@seminary.edu', 'Theology', 'PhD in Systematic Theology', 'Systematic Theology', 'Office 102', 'active'),
(5, 'LEC003', 'Daniel', 'Paul', 'Brown', '+256700000103', 'brown@seminary.edu', 'Pastoral Studies', 'MDiv, PhD in Pastoral Care', 'Pastoral Counseling', 'Office 103', 'active'),
(6, 'LEC004', 'Elizabeth', 'Grace', 'Davis', '+256700000104', 'davis@seminary.edu', 'Theology', 'PhD in Church History', 'Church History', 'Office 104', 'active'),
(7, 'LEC005', 'Matthew', 'John', 'Miller', '+256700000105', 'miller@seminary.edu', 'Ministry', 'PhD in Missiology', 'Missions and Evangelism', 'Office 105', 'active'),
(8, 'LEC006', 'Rebecca', 'Faith', 'Wilson', '+256700000106', 'wilson@seminary.edu', 'Theology', 'PhD in New Testament', 'New Testament Studies', 'Office 106', 'active');

-- ============================================================================
-- FINANCE STAFF
-- ============================================================================

INSERT INTO finance_staff (user_id, first_name, last_name, phone, email) VALUES
(9, 'Grace', 'Nakato', '+256700000201', 'finance1@seminary.edu'),
(10, 'Samuel', 'Musoke', '+256700000202', 'finance2@seminary.edu');

-- ============================================================================
-- STUDENTS
-- ============================================================================

INSERT INTO students (user_id, student_id, first_name, middle_name, last_name, date_of_birth, gender, phone, email, address, city, country, emergency_contact_name, emergency_contact_phone, emergency_contact_relationship, program_id, level_year, entry_year, entry_semester_id, status) VALUES
-- Bachelor of Theology Students
(11, 'STD2024001', 'John', 'Paul', 'Doe', '2001-03-15', 'Male', '+256700100001', 'john.doe@student.seminary.edu', '123 Main Street', 'Kampala', 'Uganda', 'Mary Doe', '+256700100002', 'Mother', 1, 2, 2023, 3, 'active'),
(12, 'STD2024002', 'Jane', 'Marie', 'Smith', '2002-05-20', 'Female', '+256700100003', 'jane.smith@student.seminary.edu', '456 Oak Avenue', 'Kampala', 'Uganda', 'Robert Smith', '+256700100004', 'Father', 1, 2, 2023, 3, 'active'),
(13, 'STD2024003', 'Michael', 'David', 'Johnson', '2001-07-10', 'Male', '+256700100005', 'michael.johnson@student.seminary.edu', '789 Pine Road', 'Entebbe', 'Uganda', 'Susan Johnson', '+256700100006', 'Mother', 1, 2, 2023, 3, 'active'),
(14, 'STD2024004', 'Sarah', 'Grace', 'Williams', '2002-09-25', 'Female', '+256700100007', 'sarah.williams@student.seminary.edu', '321 Elm Street', 'Jinja', 'Uganda', 'James Williams', '+256700100008', 'Father', 1, 1, 2024, 5, 'active'),
(15, 'STD2024005', 'David', 'Samuel', 'Brown', '2001-11-30', 'Male', '+256700100009', 'david.brown@student.seminary.edu', '654 Maple Drive', 'Kampala', 'Uganda', 'Ruth Brown', '+256700100010', 'Mother', 1, 1, 2024, 5, 'active'),
(16, 'STD2024006', 'Emily', 'Faith', 'Davis', '2002-02-14', 'Female', '+256700100011', 'emily.davis@student.seminary.edu', '987 Cedar Lane', 'Mbarara', 'Uganda', 'Peter Davis', '+256700100012', 'Father', 1, 3, 2022, 1, 'active'),
(17, 'STD2024007', 'James', 'Matthew', 'Miller', '2001-04-18', 'Male', '+256700100013', 'james.miller@student.seminary.edu', '147 Birch Court', 'Kampala', 'Uganda', 'Anna Miller', '+256700100014', 'Mother', 1, 3, 2022, 1, 'active'),
(18, 'STD2024008', 'Mary', 'Elizabeth', 'Wilson', '2002-06-22', 'Female', '+256700100015', 'mary.wilson@student.seminary.edu', '258 Walnut Place', 'Gulu', 'Uganda', 'Thomas Wilson', '+256700100016', 'Father', 1, 4, 2021, 1, 'active'),
(19, 'STD2024009', 'Robert', 'Joseph', 'Moore', '2001-08-05', 'Male', '+256700100017', 'robert.moore@student.seminary.edu', '369 Spruce Way', 'Kampala', 'Uganda', 'Linda Moore', '+256700100018', 'Mother', 1, 4, 2021, 1, 'active'),
(20, 'STD2024010', 'Jennifer', 'Anne', 'Taylor', '2002-10-12', 'Female', '+256700100019', 'jennifer.taylor@student.seminary.edu', '741 Ash Boulevard', 'Fort Portal', 'Uganda', 'Michael Taylor', '+256700100020', 'Father', 1, 1, 2024, 5, 'active'),

-- Master of Divinity Students
(21, 'STD2024011', 'William', 'Peter', 'Anderson', '1998-01-15', 'Male', '+256700100021', 'william.anderson@student.seminary.edu', '852 Hickory Street', 'Kampala', 'Uganda', 'Helen Anderson', '+256700100022', 'Spouse', 3, 1, 2024, 5, 'active'),
(22, 'STD2024012', 'Linda', 'Rose', 'Thomas', '1999-03-20', 'Female', '+256700100023', 'linda.thomas@student.seminary.edu', '963 Willow Road', 'Kampala', 'Uganda', 'George Thomas', '+256700100024', 'Spouse', 3, 1, 2024, 5, 'active'),
(23, 'STD2024013', 'Richard', 'Andrew', 'Jackson', '1997-05-25', 'Male', '+256700100025', 'richard.jackson@student.seminary.edu', '159 Cherry Lane', 'Mbale', 'Uganda', 'Dorothy Jackson', '+256700100026', 'Mother', 3, 2, 2023, 3, 'active'),
(24, 'STD2024014', 'Patricia', 'Lynn', 'White', '1998-07-30', 'Female', '+256700100027', 'patricia.white@student.seminary.edu', '357 Poplar Avenue', 'Kampala', 'Uganda', 'Christopher White', '+256700100028', 'Spouse', 3, 2, 2023, 3, 'active'),

-- Diploma Students
(25, 'STD2024015', 'Charles', 'Edward', 'Harris', '2000-02-10', 'Male', '+256700100029', 'charles.harris@student.seminary.edu', '468 Beech Drive', 'Kampala', 'Uganda', 'Nancy Harris', '+256700100030', 'Mother', 4, 1, 2024, 5, 'active'),
(26, 'STD2024016', 'Barbara', 'Jean', 'Martin', '2001-04-15', 'Female', '+256700100031', 'barbara.martin@student.seminary.edu', '579 Sycamore Court', 'Masaka', 'Uganda', 'Donald Martin', '+256700100032', 'Father', 4, 1, 2024, 5, 'active'),
(27, 'STD2024017', 'Joseph', 'Daniel', 'Thompson', '2000-06-20', 'Male', '+256700100033', 'joseph.thompson@student.seminary.edu', '680 Redwood Place', 'Kampala', 'Uganda', 'Betty Thompson', '+256700100034', 'Mother', 4, 2, 2023, 3, 'active'),
(28, 'STD2024018', 'Susan', 'Michelle', 'Garcia', '2001-08-25', 'Female', '+256700100035', 'susan.garcia@student.seminary.edu', '791 Magnolia Way', 'Arua', 'Uganda', 'Paul Garcia', '+256700100036', 'Father', 4, 2, 2023, 3, 'active'),

-- Additional BTH Students
(29, 'STD2024019', 'Thomas', 'Charles', 'Martinez', '2001-10-30', 'Male', '+256700100037', 'thomas.martinez@student.seminary.edu', '802 Dogwood Street', 'Kampala', 'Uganda', 'Carol Martinez', '+256700100038', 'Mother', 1, 2, 2023, 3, 'active'),
(30, 'STD2024020', 'Jessica', 'Nicole', 'Robinson', '2002-12-05', 'Female', '+256700100039', 'jessica.robinson@student.seminary.edu', '913 Cypress Road', 'Soroti', 'Uganda', 'Steven Robinson', '+256700100040', 'Father', 1, 2, 2023, 3, 'active');

-- ============================================================================
-- COURSE ASSIGNMENTS (Lecturers to Courses)
-- ============================================================================

INSERT INTO course_assignments (lecturer_id, course_id, semester_id, assigned_date, status) VALUES
-- Semester 6 (Current - 2024/2025 Semester 2)
(1, 3, 6, '2025-01-10', 'active'),  -- Prof. Johnson - BTH103 New Testament Survey
(1, 6, 6, '2025-01-10', 'active'),  -- Prof. Johnson - BTH106 Hermeneutics
(2, 1, 6, '2025-01-10', 'active'),  -- Prof. Williams - BTH101 Intro to Biblical Studies
(2, 14, 6, '2025-01-10', 'active'), -- Prof. Williams - BTH204 Gospels
(3, 16, 6, '2025-01-10', 'active'), -- Prof. Brown - BTH207 Christian Ethics
(3, 22, 6, '2025-01-10', 'active'), -- Prof. Brown - BTH305 Homiletics
(4, 5, 6, '2025-01-10', 'active'),  -- Prof. Davis - BTH105 Church History I
(4, 12, 6, '2025-01-10', 'active'), -- Prof. Davis - BTH202 Church History II
(5, 23, 6, '2025-01-10', 'active'), -- Prof. Miller - BTH306 Pastoral Care
(6, 8, 6, '2025-01-10', 'active'),  -- Prof. Wilson - BTH108 Hebrew I

-- Semester 5 (Previous - 2024/2025 Semester 1)
(1, 2, 5, '2024-09-01', 'completed'), -- Prof. Johnson - BTH102 Old Testament Survey
(2, 4, 5, '2024-09-01', 'completed'), -- Prof. Williams - BTH104 Christian Theology I
(3, 7, 5, '2024-09-01', 'completed'), -- Prof. Brown - BTH107 Greek I
(4, 10, 5, '2024-09-01', 'completed'), -- Prof. Davis - BTH201 Christian Theology II
(5, 19, 5, '2024-09-01', 'completed'), -- Prof. Miller - BTH301 Pauline Epistles
(6, 20, 5, '2024-09-01', 'completed'); -- Prof. Wilson - BTH302 Prophetic Literature

-- ============================================================================
-- COURSE REGISTRATIONS
-- ============================================================================

-- Year 2 Students - Semester 6 (Current Semester)
INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date) VALUES
-- John Doe (Year 2)
(1, 3, 6, '2025-01-08', 'approved', 1, '2025-01-09 10:00:00'),
(1, 6, 6, '2025-01-08', 'approved', 1, '2025-01-09 10:00:00'),
(1, 14, 6, '2025-01-08', 'approved', 1, '2025-01-09 10:00:00'),
(1, 16, 6, '2025-01-08', 'approved', 1, '2025-01-09 10:00:00'),

-- Jane Smith (Year 2)
(2, 3, 6, '2025-01-08', 'approved', 1, '2025-01-09 10:30:00'),
(2, 6, 6, '2025-01-08', 'approved', 1, '2025-01-09 10:30:00'),
(2, 14, 6, '2025-01-08', 'approved', 1, '2025-01-09 10:30:00'),
(2, 16, 6, '2025-01-08', 'approved', 1, '2025-01-09 10:30:00'),

-- Michael Johnson (Year 2)
(3, 3, 6, '2025-01-08', 'approved', 1, '2025-01-09 11:00:00'),
(3, 6, 6, '2025-01-08', 'approved', 1, '2025-01-09 11:00:00'),
(3, 14, 6, '2025-01-08', 'approved', 1, '2025-01-09 11:00:00'),
(3, 16, 6, '2025-01-08', 'approved', 1, '2025-01-09 11:00:00'),

-- Year 2 Students - Semester 5 (Previous Semester - Completed)
-- John Doe
(1, 2, 5, '2024-08-20', 'approved', 1, '2024-08-21 10:00:00'),
(1, 4, 5, '2024-08-20', 'approved', 1, '2024-08-21 10:00:00'),
(1, 7, 5, '2024-08-20', 'approved', 1, '2024-08-21 10:00:00'),
(1, 10, 5, '2024-08-20', 'approved', 1, '2024-08-21 10:00:00'),

-- Jane Smith
(2, 2, 5, '2024-08-20', 'approved', 1, '2024-08-21 10:30:00'),
(2, 4, 5, '2024-08-20', 'approved', 1, '2024-08-21 10:30:00'),
(2, 7, 5, '2024-08-20', 'approved', 1, '2024-08-21 10:30:00'),
(2, 10, 5, '2024-08-20', 'approved', 1, '2024-08-21 10:30:00'),

-- Michael Johnson
(3, 2, 5, '2024-08-20', 'approved', 1, '2024-08-21 11:00:00'),
(3, 4, 5, '2024-08-20', 'approved', 1, '2024-08-21 11:00:00'),
(3, 7, 5, '2024-08-20', 'approved', 1, '2024-08-21 11:00:00'),
(3, 10, 5, '2024-08-20', 'approved', 1, '2024-08-21 11:00:00');

-- ============================================================================
-- RESULTS (Previous Semester - Published)
-- ============================================================================

-- John Doe - Semester 5 Results (All Published)
INSERT INTO results (student_id, course_id, semester_id, assignment_marks, test_marks, midterm_marks, final_exam_marks, participation_marks, status, entered_by, approved_by, submitted_date, approved_date, published_date) VALUES
(1, 2, 5, 18, 15, 22, 68, 5, 'published', 1, 1, '2024-12-10 14:00:00', '2024-12-12 10:00:00', '2024-12-15 09:00:00'),
(1, 4, 5, 20, 18, 24, 72, 5, 'published', 2, 1, '2024-12-10 15:00:00', '2024-12-12 10:30:00', '2024-12-15 09:00:00'),
(1, 7, 5, 16, 14, 20, 62, 4, 'published', 3, 1, '2024-12-11 14:00:00', '2024-12-12 11:00:00', '2024-12-15 09:00:00'),
(1, 10, 5, 19, 17, 23, 70, 5, 'published', 4, 1, '2024-12-11 15:00:00', '2024-12-12 11:30:00', '2024-12-15 09:00:00');

-- Jane Smith - Semester 5 Results (All Published)
INSERT INTO results (student_id, course_id, semester_id, assignment_marks, test_marks, midterm_marks, final_exam_marks, participation_marks, status, entered_by, approved_by, submitted_date, approved_date, published_date) VALUES
(2, 2, 5, 19, 18, 24, 75, 5, 'published', 1, 1, '2024-12-10 14:00:00', '2024-12-12 10:00:00', '2024-12-15 09:00:00'),
(2, 4, 5, 20, 19, 25, 78, 5, 'published', 2, 1, '2024-12-10 15:00:00', '2024-12-12 10:30:00', '2024-12-15 09:00:00'),
(2, 7, 5, 17, 16, 22, 68, 5, 'published', 3, 1, '2024-12-11 14:00:00', '2024-12-12 11:00:00', '2024-12-15 09:00:00'),
(2, 10, 5, 20, 18, 24, 74, 5, 'published', 4, 1, '2024-12-11 15:00:00', '2024-12-12 11:30:00', '2024-12-15 09:00:00');

-- Michael Johnson - Semester 5 Results (All Published)
INSERT INTO results (student_id, course_id, semester_id, assignment_marks, test_marks, midterm_marks, final_exam_marks, participation_marks, status, entered_by, approved_by, submitted_date, approved_date, published_date) VALUES
(3, 2, 5, 17, 16, 21, 64, 4, 'published', 1, 1, '2024-12-10 14:00:00', '2024-12-12 10:00:00', '2024-12-15 09:00:00'),
(3, 4, 5, 18, 17, 23, 70, 5, 'published', 2, 1, '2024-12-10 15:00:00', '2024-12-12 10:30:00', '2024-12-15 09:00:00'),
(3, 7, 5, 15, 14, 19, 58, 4, 'published', 3, 1, '2024-12-11 14:00:00', '2024-12-12 11:00:00', '2024-12-15 09:00:00'),
(3, 10, 5, 18, 16, 22, 67, 4, 'published', 4, 1, '2024-12-11 15:00:00', '2024-12-12 11:30:00', '2024-12-15 09:00:00');

-- ============================================================================
-- FEES STRUCTURE
-- ============================================================================

INSERT INTO fees_structure (fee_name, fee_type, amount, program_id, level_year, semester_id, mandatory, due_date, description, status) VALUES
-- Bachelor of Theology Fees
('Tuition Fee - BTH Year 1', 'Tuition', 1500000.00, 1, 1, 6, 'yes', '2025-02-15', 'Semester tuition for Bachelor of Theology Year 1', 'active'),
('Tuition Fee - BTH Year 2', 'Tuition', 1500000.00, 1, 2, 6, 'yes', '2025-02-15', 'Semester tuition for Bachelor of Theology Year 2', 'active'),
('Tuition Fee - BTH Year 3', 'Tuition', 1500000.00, 1, 3, 6, 'yes', '2025-02-15', 'Semester tuition for Bachelor of Theology Year 3', 'active'),
('Tuition Fee - BTH Year 4', 'Tuition', 1500000.00, 1, 4, 6, 'yes', '2025-02-15', 'Semester tuition for Bachelor of Theology Year 4', 'active'),

-- Master of Divinity Fees
('Tuition Fee - MDIV Year 1', 'Tuition', 2000000.00, 3, 1, 6, 'yes', '2025-02-15', 'Semester tuition for Master of Divinity Year 1', 'active'),
('Tuition Fee - MDIV Year 2', 'Tuition', 2000000.00, 3, 2, 6, 'yes', '2025-02-15', 'Semester tuition for Master of Divinity Year 2', 'active'),

-- Diploma Fees
('Tuition Fee - DPT Year 1', 'Tuition', 1000000.00, 4, 1, 6, 'yes', '2025-02-15', 'Semester tuition for Diploma Year 1', 'active'),
('Tuition Fee - DPT Year 2', 'Tuition', 1000000.00, 4, 2, 6, 'yes', '2025-02-15', 'Semester tuition for Diploma Year 2', 'active'),

-- General Fees (All Programs)
('Library Fee', 'Library', 50000.00, NULL, NULL, 6, 'yes', '2025-02-15', 'Library access and services', 'active'),
('Medical Fee', 'Medical', 100000.00, NULL, NULL, 6, 'yes', '2025-02-15', 'Medical services and health insurance', 'active'),
('IT Fee', 'Technology', 75000.00, NULL, NULL, 6, 'yes', '2025-02-15', 'Computer lab and internet access', 'active'),
('Registration Fee', 'Administrative', 150000.00, NULL, NULL, 6, 'yes', '2025-01-31', 'Semester registration fee', 'active'),
('Student Activities Fee', 'Activities', 50000.00, NULL, NULL, 6, 'no', '2025-02-28', 'Student clubs and activities', 'active'),
('Exam Fee', 'Examination', 100000.00, NULL, NULL, 6, 'yes', '2025-05-15', 'Examination administration', 'active');

-- ============================================================================
-- INVOICES
-- ============================================================================

-- Generate invoices for current semester students
INSERT INTO invoices (invoice_number, student_id, semester_id, total_amount, amount_paid, balance, due_date, status) VALUES
-- Year 2 BTH Students
('INV-2025-001', 1, 6, 2025000.00, 1500000.00, 525000.00, '2025-02-15', 'partial'),
('INV-2025-002', 2, 6, 2025000.00, 2025000.00, 0.00, '2025-02-15', 'paid'),
('INV-2025-003', 3, 6, 2025000.00, 1000000.00, 1025000.00, '2025-02-15', 'partial'),
('INV-2025-004', 29, 6, 2025000.00, 0.00, 2025000.00, '2025-02-15', 'pending'),
('INV-2025-005', 30, 6, 2025000.00, 500000.00, 1525000.00, '2025-02-15', 'partial'),

-- Year 1 BTH Students
('INV-2025-006', 4, 6, 2025000.00, 2025000.00, 0.00, '2025-02-15', 'paid'),
('INV-2025-007', 5, 6, 2025000.00, 1000000.00, 1025000.00, '2025-02-15', 'partial'),
('INV-2025-008', 10, 6, 2025000.00, 0.00, 2025000.00, '2025-02-15', 'pending'),

-- Year 3 BTH Students
('INV-2025-009', 6, 6, 2025000.00, 2025000.00, 0.00, '2025-02-15', 'paid'),
('INV-2025-010', 7, 6, 2025000.00, 1500000.00, 525000.00, '2025-02-15', 'partial'),

-- Year 4 BTH Students
('INV-2025-011', 8, 6, 2025000.00, 2025000.00, 0.00, '2025-02-15', 'paid'),
('INV-2025-012', 9, 6, 2025000.00, 1200000.00, 825000.00, '2025-02-15', 'partial'),

-- MDIV Students
('INV-2025-013', 21, 6, 2475000.00, 2000000.00, 475000.00, '2025-02-15', 'partial'),
('INV-2025-014', 22, 6, 2475000.00, 2475000.00, 0.00, '2025-02-15', 'paid'),
('INV-2025-015', 23, 6, 2475000.00, 1500000.00, 975000.00, '2025-02-15', 'partial'),
('INV-2025-016', 24, 6, 2475000.00, 0.00, 2475000.00, '2025-02-15', 'pending'),

-- Diploma Students
('INV-2025-017', 25, 6, 1525000.00, 1525000.00, 0.00, '2025-02-15', 'paid'),
('INV-2025-018', 26, 6, 1525000.00, 800000.00, 725000.00, '2025-02-15', 'partial'),
('INV-2025-019', 27, 6, 1525000.00, 1525000.00, 0.00, '2025-02-15', 'paid'),
('INV-2025-020', 28, 6, 1525000.00, 500000.00, 1025000.00, '2025-02-15', 'partial');

-- ============================================================================
-- INVOICE ITEMS
-- ============================================================================

-- Invoice items for BTH Year 2 students (Student 1)
INSERT INTO invoice_items (invoice_id, fee_structure_id, description, amount) VALUES
(1, 2, 'Tuition Fee - BTH Year 2', 1500000.00),
(1, 9, 'Library Fee', 50000.00),
(1, 10, 'Medical Fee', 100000.00),
(1, 11, 'IT Fee', 75000.00),
(1, 12, 'Registration Fee', 150000.00),
(1, 14, 'Exam Fee', 100000.00);

-- Similar for other students (abbreviated for brevity)
-- You would repeat this pattern for each invoice

-- ============================================================================
-- PAYMENTS
-- ============================================================================

INSERT INTO payments (payment_id, student_id, invoice_id, amount, payment_date, payment_method, reference_number, received_by, semester_id, notes, receipt_number) VALUES
-- Student 1 - John Doe
('PAY-2025-001', 1, 1, 1000000.00, '2025-01-20', 'bank_transfer', 'BNK123456', 1, 6, 'First installment', 'RCP-2025-001'),
('PAY-2025-002', 1, 1, 500000.00, '2025-02-05', 'mobile_money', 'MM789012', 1, 6, 'Second installment', 'RCP-2025-002'),

-- Student 2 - Jane Smith (Fully paid)
('PAY-2025-003', 2, 2, 2025000.00, '2025-01-18', 'bank_transfer', 'BNK234567', 1, 6, 'Full payment', 'RCP-2025-003'),

-- Student 3 - Michael Johnson
('PAY-2025-004', 3, 3, 1000000.00, '2025-01-25', 'cash', NULL, 1, 6, 'Partial payment', 'RCP-2025-004'),

-- Student 5 - David Brown
('PAY-2025-005', 30, 5, 500000.00, '2025-02-01', 'mobile_money', 'MM345678', 2, 6, 'Initial payment', 'RCP-2025-005'),

-- Student 6 - Sarah Williams (Fully paid)
('PAY-2025-006', 4, 6, 2025000.00, '2025-01-15', 'bank_transfer', 'BNK345678', 1, 6, 'Full payment', 'RCP-2025-006'),

-- Student 7 - Emily Davis
('PAY-2025-007', 5, 7, 1000000.00, '2025-01-22', 'bank_transfer', 'BNK456789', 2, 6, 'Partial payment', 'RCP-2025-007'),

-- Student 9 - Emily Davis (Fully paid)
('PAY-2025-008', 6, 9, 2025000.00, '2025-01-17', 'bank_transfer', 'BNK567890', 1, 6, 'Full payment', 'RCP-2025-008'),

-- Student 10 - James Miller
('PAY-2025-009', 7, 10, 1500000.00, '2025-01-28', 'mobile_money', 'MM456789', 2, 6, 'Partial payment', 'RCP-2025-009'),

-- Student 11 - Mary Wilson (Fully paid)
('PAY-2025-010', 8, 11, 2025000.00, '2025-01-16', 'bank_transfer', 'BNK678901', 1, 6, 'Full payment', 'RCP-2025-010'),

-- Student 12 - Robert Moore
('PAY-2025-011', 9, 12, 1200000.00, '2025-01-30', 'cash', NULL, 2, 6, 'Partial payment', 'RCP-2025-011'),

-- MDIV Student 1
('PAY-2025-012', 21, 13, 2000000.00, '2025-01-19', 'bank_transfer', 'BNK789012', 1, 6, 'Partial payment', 'RCP-2025-012'),

-- MDIV Student 2 (Fully paid)
('PAY-2025-013', 22, 14, 2475000.00, '2025-01-14', 'bank_transfer', 'BNK890123', 1, 6, 'Full payment', 'RCP-2025-013'),

-- MDIV Student 3
('PAY-2025-014', 23, 15, 1500000.00, '2025-01-26', 'mobile_money', 'MM567890', 2, 6, 'Partial payment', 'RCP-2025-014'),

-- Diploma Student 1 (Fully paid)
('PAY-2025-015', 25, 17, 1525000.00, '2025-01-21', 'bank_transfer', 'BNK901234', 1, 6, 'Full payment', 'RCP-2025-015'),

-- Diploma Student 2
('PAY-2025-016', 26, 18, 800000.00, '2025-01-29', 'cash', NULL, 2, 6, 'Partial payment', 'RCP-2025-016'),

-- Diploma Student 3 (Fully paid)
('PAY-2025-017', 27, 19, 1525000.00, '2025-01-23', 'bank_transfer', 'BNK012345', 1, 6, 'Full payment', 'RCP-2025-017'),

-- Diploma Student 4
('PAY-2025-018', 28, 20, 500000.00, '2025-02-02', 'mobile_money', 'MM678901', 2, 6, 'Partial payment', 'RCP-2025-018');

-- ============================================================================
-- CALCULATE GPAs FOR STUDENTS WITH RESULTS
-- ============================================================================

CALL sp_calculate_student_gpa(1, 5);
CALL sp_calculate_student_gpa(2, 5);
CALL sp_calculate_student_gpa(3, 5);

-- ============================================================================
-- UPDATE STUDENT BALANCES
-- ============================================================================

CALL sp_update_student_balance(1, 6);
CALL sp_update_student_balance(2, 6);
CALL sp_update_student_balance(3, 6);
CALL sp_update_student_balance(4, 6);
CALL sp_update_student_balance(5, 6);
CALL sp_update_student_balance(6, 6);
CALL sp_update_student_balance(7, 6);
CALL sp_update_student_balance(8, 6);
CALL sp_update_student_balance(9, 6);
CALL sp_update_student_balance(10, 6);
CALL sp_update_student_balance(21, 6);
CALL sp_update_student_balance(22, 6);
CALL sp_update_student_balance(23, 6);
CALL sp_update_student_balance(24, 6);
CALL sp_update_student_balance(25, 6);
CALL sp_update_student_balance(26, 6);
CALL sp_update_student_balance(27, 6);
CALL sp_update_student_balance(28, 6);
CALL sp_update_student_balance(29, 6);
CALL sp_update_student_balance(30, 6);

-- ============================================================================
-- PERMISSIONS
-- ============================================================================

INSERT INTO permissions (permission_name, module, action, description) VALUES
-- Student permissions
('view_own_profile', 'student', 'read', 'View own student profile'),
('edit_own_profile', 'student', 'update', 'Edit own contact information'),
('view_own_results', 'results', 'read', 'View own academic results'),
('view_own_fees', 'finance', 'read', 'View own fees and payments'),
('register_courses', 'courses', 'create', 'Register for courses'),

-- Lecturer permissions
('view_assigned_courses', 'courses', 'read', 'View assigned courses'),
('view_class_list', 'students', 'read', 'View students in assigned courses'),
('enter_results', 'results', 'create', 'Enter student results'),
('submit_results', 'results', 'update', 'Submit results for approval'),
('view_submitted_results', 'results', 'read', 'View submitted results'),

-- Finance permissions
('view_all_payments', 'finance', 'read', 'View all payment records'),
('record_payment', 'finance', 'create', 'Record student payments'),
('generate_invoices', 'finance', 'create', 'Generate student invoices'),
('view_financial_reports', 'reports', 'read', 'View financial reports'),

-- Admin permissions
('manage_students', 'students', 'all', 'Full student management'),
('manage_lecturers', 'lecturers', 'all', 'Full lecturer management'),
('manage_courses', 'courses', 'all', 'Full course management'),
('manage_results', 'results', 'all', 'Full results management'),
('manage_users', 'users', 'all', 'Full user management'),
('manage_settings', 'settings', 'all', 'Manage system settings'),
('view_all_reports', 'reports', 'read', 'View all system reports'),
('approve_results', 'results', 'approve', 'Approve submitted results'),
('publish_results', 'results', 'publish', 'Publish approved results');

-- ============================================================================
-- ROLE PERMISSIONS
-- ============================================================================

-- Admin role (role_id = 1)
INSERT INTO role_permissions (role_id, permission_id) 
SELECT 1, id FROM permissions WHERE permission_name LIKE 'manage_%' OR permission_name LIKE 'approve_%' OR permission_name LIKE 'publish_%';

-- Lecturer role (role_id = 2)
INSERT INTO role_permissions (role_id, permission_id) 
SELECT 2, id FROM permissions WHERE permission_name IN (
    'view_assigned_courses', 'view_class_list', 'enter_results', 'submit_results', 'view_submitted_results'
);

-- Student role (role_id = 3)
INSERT INTO role_permissions (role_id, permission_id) 
SELECT 3, id FROM permissions WHERE permission_name LIKE 'view_own_%' OR permission_name = 'register_courses';

-- Finance role (role_id = 4)
INSERT INTO role_permissions (role_id, permission_id) 
SELECT 4, id FROM permissions WHERE permission_name LIKE '%payment%' OR permission_name LIKE '%invoice%' OR permission_name = 'view_financial_reports';

-- ============================================================================
-- ANNOUNCEMENTS
-- ============================================================================

INSERT INTO announcements (title, content, target_audience, priority, start_date, end_date, status, created_by) VALUES
('Welcome to Semester 2, 2024/2025', 'Welcome back to the new semester! We wish you success in your studies. Please ensure all fees are paid by the deadline.', 'all', 'high', '2025-01-15', '2025-01-31', 'active', 1),
('Library Hours Extended', 'The library will now be open until 10 PM on weekdays to support your studies during the semester.', 'students', 'normal', '2025-01-20', '2025-06-30', 'active', 1),
('Course Registration Deadline', 'Reminder: Course registration closes on January 20, 2025. Please complete your registration.', 'students', 'urgent', '2025-01-10', '2025-01-20', 'active', 1),
('Results Submission Reminder', 'All lecturers are reminded to submit results by December 10, 2025 for timely processing.', 'lecturers', 'high', '2024-11-01', '2024-12-10', 'inactive', 1),
('Fee Payment Deadline', 'Tuition fees are due by February 15, 2025. Please make arrangements to clear your balance.', 'students', 'urgent', '2025-02-01', '2025-02-15', 'active', 1);

-- ============================================================================
-- ACTIVITY LOGS (Sample)
-- ============================================================================

INSERT INTO activity_logs (user_id, action, module, description, ip_address, user_agent) VALUES
(1, 'login', 'authentication', 'Admin logged in successfully', '192.168.1.100', 'Mozilla/5.0'),
(11, 'login', 'authentication', 'Student logged in successfully', '192.168.1.101', 'Mozilla/5.0'),
(3, 'login', 'authentication', 'Lecturer logged in successfully', '192.168.1.102', 'Mozilla/5.0'),
(1, 'create', 'students', 'Created new student: STD2024001', '192.168.1.100', 'Mozilla/5.0'),
(9, 'create', 'payments', 'Recorded payment PAY-2025-001', '192.168.1.103', 'Mozilla/5.0'),
(3, 'submit', 'results', 'Submitted results for BTH102', '192.168.1.102', 'Mozilla/5.0'),
(1, 'approve', 'results', 'Approved results for BTH102', '192.168.1.100', 'Mozilla/5.0'),
(1, 'publish', 'results', 'Published results for Semester 1', '192.168.1.100', 'Mozilla/5.0');

-- ============================================================================
-- NOTIFICATIONS
-- ============================================================================

INSERT INTO notifications (user_id, title, message, type, read_status, link) VALUES
(11, 'Results Published', 'Your results for Semester 1, 2024/2025 have been published. Login to view.', 'success', 'unread', '/student/results.php'),
(11, 'Payment Received', 'Your payment of UGX 1,000,000 has been received. Receipt: RCP-2025-001', 'success', 'read', '/student/fees.php'),
(12, 'Results Published', 'Your results for Semester 1, 2024/2025 have been published. Login to view.', 'success', 'read', '/student/results.php'),
(3, 'Results Approved', 'Your submitted results for BTH102 have been approved.', 'success', 'read', '/lecturer/results.php'),
(1, 'Results Submitted', 'Prof. Johnson has submitted results for BTH102 for approval.', 'info', 'read', '/admin/results.php'),
(11, 'Fee Reminder', 'You have an outstanding balance of UGX 525,000. Please make payment by February 15, 2025.', 'warning', 'unread', '/student/fees.php');

-- ============================================================================
-- COURSE UNITS (Sample for a few courses)
-- ============================================================================

INSERT INTO course_units (course_id, unit_number, unit_title, unit_description, learning_outcomes) VALUES
-- BTH101 Units
(1, 1, 'Introduction to the Bible', 'Overview of biblical structure and composition', 'Understand the organization and themes of Scripture'),
(1, 2, 'Methods of Biblical Interpretation', 'Various approaches to reading and understanding the Bible', 'Apply basic hermeneutical principles'),
(1, 3, 'The Biblical Canon', 'Formation and authority of the biblical books', 'Explain the development of the biblical canon'),

-- BTH102 Units
(2, 1, 'Pentateuch Overview', 'Introduction to the first five books of the Bible', 'Identify key themes in the Pentateuch'),
(2, 2, 'Historical Books', 'Survey of Joshua through Esther', 'Trace Israel\'s history through the biblical narrative'),
(2, 3, 'Wisdom Literature', 'Study of Job, Psalms, Proverbs, Ecclesiastes, Song of Songs', 'Appreciate Hebrew wisdom traditions'),
(2, 4, 'The Prophets', 'Overview of major and minor prophets', 'Understand prophetic messages and their contexts');

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Uncomment to verify data insertion:
/*
SELECT 'Users' AS table_name, COUNT(*) AS count FROM users
UNION ALL
SELECT 'Students', COUNT(*) FROM students
UNION ALL
SELECT 'Lecturers', COUNT(*) FROM lecturers
UNION ALL
SELECT 'Admins', COUNT(*) FROM admins
UNION ALL
SELECT 'Finance Staff', COUNT(*) FROM finance_staff
UNION ALL
SELECT 'Programs', COUNT(*) FROM programs
UNION ALL
SELECT 'Courses', COUNT(*) FROM courses
UNION ALL
SELECT 'Semesters', COUNT(*) FROM semesters
UNION ALL
SELECT 'Course Registrations', COUNT(*) FROM course_registrations
UNION ALL
SELECT 'Results', COUNT(*) FROM results
UNION ALL
SELECT 'Invoices', COUNT(*) FROM invoices
UNION ALL
SELECT 'Payments', COUNT(*) FROM payments
UNION ALL
SELECT 'Announcements', COUNT(*) FROM announcements
UNION ALL
SELECT 'Notifications', COUNT(*) FROM notifications;
*/

-- ============================================================================
-- COMPLETION MESSAGE
-- ============================================================================

SELECT 'Database seeding completed successfully!' AS message;
SELECT '30 users created (2 admins, 6 lecturers, 2 finance, 20 students)' AS info;
SELECT '6 programs, 50+ courses, 8 semesters created' AS info;
SELECT 'Course registrations and results for 3 students' AS info;
SELECT '20 invoices and 18 payments created' AS info;
SELECT 'Default password for all users: password' AS warning;
SELECT 'PLEASE CHANGE ALL PASSWORDS IN PRODUCTION!' AS critical;

-- ============================================================================
-- END OF SEED DATA
-- ============================================================================