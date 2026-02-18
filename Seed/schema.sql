-- ============================================================================
-- SEMINARY RESULTS MANAGEMENT SYSTEM - DATABASE SCHEMA
-- Version: 1.0
-- Database: MySQL 5.7+
-- ============================================================================

-- Create Database
CREATE DATABASE IF NOT EXISTS smns 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE smns;

-- ============================================================================
-- CORE USER TABLES
-- ============================================================================

-- Users Table (Main authentication table)
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student', 'lecturer', 'finance', 'admin') NOT NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    last_login DATETIME NULL,
    failed_login_attempts INT DEFAULT 0,
    account_locked_until DATETIME NULL,
    password_reset_token VARCHAR(100) NULL,
    password_reset_expires DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password History Table
CREATE TABLE password_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ACADEMIC STRUCTURE TABLES
-- ============================================================================

-- Programs Table
CREATE TABLE programs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    program_code VARCHAR(20) UNIQUE NOT NULL,
    program_name VARCHAR(200) NOT NULL,
    department VARCHAR(100) NOT NULL,
    duration_years INT NOT NULL DEFAULT 3,
    total_credits_required INT NOT NULL DEFAULT 120,
    description TEXT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_program_code (program_code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Academic Years Table
CREATE TABLE academic_years (
    id INT PRIMARY KEY AUTO_INCREMENT,
    year_name VARCHAR(20) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active', 'inactive', 'completed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_year_name (year_name),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Semesters Table
CREATE TABLE semesters (
    id INT PRIMARY KEY AUTO_INCREMENT,
    academic_year_id INT NOT NULL,
    semester_name VARCHAR(50) NOT NULL,
    semester_number TINYINT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    registration_start_date DATE NULL,
    registration_end_date DATE NULL,
    status ENUM('active', 'inactive', 'completed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    INDEX idx_academic_year (academic_year_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Courses Table
CREATE TABLE courses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_code VARCHAR(20) UNIQUE NOT NULL,
    course_name VARCHAR(200) NOT NULL,
    credit_hours INT NOT NULL DEFAULT 3,
    lecture_hours INT NOT NULL DEFAULT 0 COMMENT 'LH - Lecture Hours',
    tutorial_hours INT NOT NULL DEFAULT 0 COMMENT 'TH - Tutorial Hours',
    practical_hours INT NOT NULL DEFAULT 0 COMMENT 'PH - Practical Hours',
    contact_hours INT GENERATED ALWAYS AS (lecture_hours + tutorial_hours + practical_hours) STORED COMMENT 'CH - Contact Hours (LH + TH + PH)',
    program_id INT NOT NULL,
    level_year INT NOT NULL,
    semester_offered TINYINT NOT NULL COMMENT '1=Semester 1, 2=Semester 2, 3=Both',
    prerequisites TEXT NULL,
    description TEXT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    INDEX idx_course_code (course_code),
    INDEX idx_program (program_id),
    INDEX idx_level (level_year),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Course Units/Topics Table
CREATE TABLE course_units (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    unit_number INT NOT NULL,
    unit_title VARCHAR(200) NOT NULL,
    unit_description TEXT NULL,
    learning_outcomes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course_id (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grading System Table
CREATE TABLE grades (
    id INT PRIMARY KEY AUTO_INCREMENT,
    grade_letter VARCHAR(5) NOT NULL,
    min_mark DECIMAL(5,2) NOT NULL,
    max_mark DECIMAL(5,2) NOT NULL,
    grade_point DECIMAL(3,2) NOT NULL,
    description VARCHAR(100) NULL,
    pass_status ENUM('pass', 'fail') NOT NULL DEFAULT 'pass',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_grade_letter (grade_letter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default grading system
INSERT INTO grades (grade_letter, min_mark, max_mark, grade_point, description, pass_status) VALUES
('A', 80.00, 100.00, 4.00, 'Excellent', 'pass'),
('B+', 75.00, 79.99, 3.50, 'Very Good', 'pass'),
('B', 70.00, 74.99, 3.00, 'Good', 'pass'),
('C+', 65.00, 69.99, 2.50, 'Above Average', 'pass'),
('C', 60.00, 64.99, 2.00, 'Average', 'pass'),
('D+', 55.00, 59.99, 1.50, 'Below Average', 'pass'),
('D', 50.00, 54.99, 2.00, 'Pass', 'pass'),
('F', 0.00, 49.99, 0.00, 'Fail', 'fail');

-- ============================================================================
-- STUDENT TABLES
-- ============================================================================

-- Students Table
CREATE TABLE students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    student_id VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    date_of_birth DATE NOT NULL,
    gender ENUM('Male', 'Female') NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL,
    address TEXT NULL,
    city VARCHAR(100) NULL,
    country VARCHAR(100) NULL,
    emergency_contact_name VARCHAR(200) NULL,
    emergency_contact_phone VARCHAR(20) NULL,
    emergency_contact_relationship VARCHAR(50) NULL,
    program_id INT NOT NULL,
    level_year INT NOT NULL DEFAULT 1,
    entry_year INT NOT NULL,
    entry_semester_id INT NULL,
    photo VARCHAR(255) NULL,
    status ENUM('active', 'graduated', 'withdrawn', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT,
    FOREIGN KEY (entry_semester_id) REFERENCES semesters(id) ON DELETE SET NULL,
    INDEX idx_student_id (student_id),
    INDEX idx_user_id (user_id),
    INDEX idx_program (program_id),
    INDEX idx_status (status),
    INDEX idx_level (level_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Semester registrations: students request semester registration (admin approves)
CREATE TABLE IF NOT EXISTS semester_registrations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- LECTURER TABLES
-- ============================================================================

-- Lecturers Table
CREATE TABLE lecturers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    lecturer_id VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL,
    department VARCHAR(100) NULL,
    qualifications TEXT NULL,
    specialization VARCHAR(200) NULL,
    office_location VARCHAR(100) NULL,
    photo VARCHAR(255) NULL,
    status ENUM('active', 'inactive', 'retired') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_lecturer_id (lecturer_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Course Assignments Table (Lecturer to Course)
CREATE TABLE course_assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    lecturer_id INT NOT NULL,
    course_id INT NOT NULL,
    semester_id INT NOT NULL,
    assigned_date DATE NOT NULL,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    UNIQUE KEY unique_assignment (lecturer_id, course_id, semester_id),
    INDEX idx_lecturer (lecturer_id),
    INDEX idx_course (course_id),
    INDEX idx_semester (semester_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ADMIN TABLES
-- ============================================================================

-- Admins Table
CREATE TABLE admins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Finance Staff Table
CREATE TABLE finance_staff (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- COURSE REGISTRATION TABLES
-- ============================================================================

-- Course Registrations Table
CREATE TABLE course_registrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    semester_id INT NOT NULL,
    registration_date DATE NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'dropped') DEFAULT 'pending',
    approved_by INT NULL,
    approved_date DATETIME NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES admins(id) ON DELETE SET NULL,
    UNIQUE KEY unique_registration (student_id, course_id, semester_id),
    INDEX idx_student (student_id),
    INDEX idx_course (course_id),
    INDEX idx_semester (semester_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- RESULTS TABLES
-- ============================================================================

-- Results Table
CREATE TABLE results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    semester_id INT NOT NULL,
    assignment_marks DECIMAL(5,2) DEFAULT 0.00,
    test_marks DECIMAL(5,2) DEFAULT 0.00,
    midterm_marks DECIMAL(5,2) DEFAULT 0.00,
    final_exam_marks DECIMAL(5,2) DEFAULT 0.00,
    participation_marks DECIMAL(5,2) DEFAULT 0.00,
    total_marks DECIMAL(5,2) DEFAULT 0.00,
    grade VARCHAR(5) NULL,
    grade_points DECIMAL(3,2) NULL,
    status ENUM('draft', 'submitted', 'approved', 'published') DEFAULT 'draft',
    entered_by INT NOT NULL,
    approved_by INT NULL,
    submitted_date DATETIME NULL,
    approved_date DATETIME NULL,
    published_date DATETIME NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    FOREIGN KEY (entered_by) REFERENCES lecturers(id) ON DELETE RESTRICT,
    FOREIGN KEY (approved_by) REFERENCES admins(id) ON DELETE SET NULL,
    UNIQUE KEY unique_result (student_id, course_id, semester_id),
    INDEX idx_student (student_id),
    INDEX idx_course (course_id),
    INDEX idx_semester (semester_id),
    INDEX idx_status (status),
    INDEX idx_grade (grade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GPA Calculations Table (Cached GPAs for performance)
CREATE TABLE student_gpas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    semester_id INT NOT NULL,
    semester_gpa DECIMAL(3,2) NULL,
    cumulative_gpa DECIMAL(3,2) NULL,
    total_credits_attempted INT DEFAULT 0,
    total_credits_earned INT DEFAULT 0,
    total_grade_points DECIMAL(10,2) DEFAULT 0.00,
    academic_standing ENUM('Good Standing', 'Probation', 'Suspension') DEFAULT 'Good Standing',
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_semester (student_id, semester_id),
    INDEX idx_student (student_id),
    INDEX idx_semester (semester_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FINANCE TABLES
-- ============================================================================

-- Fees Structure Table
CREATE TABLE fees_structure (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fee_name VARCHAR(200) NOT NULL,
    fee_type VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    program_id INT NULL,
    level_year INT NULL,
    semester_id INT NULL,
    mandatory ENUM('yes', 'no') DEFAULT 'yes',
    due_date DATE NULL,
    description TEXT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    INDEX idx_program (program_id),
    INDEX idx_semester (semester_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices Table
CREATE TABLE invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    student_id INT NOT NULL,
    semester_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    due_date DATE NULL,
    status ENUM('pending', 'partial', 'paid', 'overdue') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    INDEX idx_invoice_number (invoice_number),
    INDEX idx_student (student_id),
    INDEX idx_semester (semester_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoice Items Table
CREATE TABLE invoice_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    fee_structure_id INT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (fee_structure_id) REFERENCES fees_structure(id) ON DELETE SET NULL,
    INDEX idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments Table
CREATE TABLE payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_id VARCHAR(50) UNIQUE NOT NULL,
    student_id INT NOT NULL,
    invoice_id INT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'mobile_money', 'cheque', 'card') NOT NULL,
    reference_number VARCHAR(100) NULL,
    received_by INT NOT NULL,
    semester_id INT NOT NULL,
    notes TEXT NULL,
    receipt_number VARCHAR(50) UNIQUE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (received_by) REFERENCES finance_staff(id) ON DELETE RESTRICT,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    INDEX idx_payment_id (payment_id),
    INDEX idx_student (student_id),
    INDEX idx_semester (semester_id),
    INDEX idx_payment_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student Balances Table (Cached balances for quick access)
CREATE TABLE student_balances (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    semester_id INT NOT NULL,
    total_fees DECIMAL(10,2) DEFAULT 0.00,
    total_paid DECIMAL(10,2) DEFAULT 0.00,
    balance DECIMAL(10,2) DEFAULT 0.00,
    last_payment_date DATE NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_semester (student_id, semester_id),
    INDEX idx_student (student_id),
    INDEX idx_semester (semester_id),
    INDEX idx_balance (balance)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SYSTEM TABLES
-- ============================================================================

-- Roles Table
CREATE TABLE roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    role_name VARCHAR(50) UNIQUE NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default roles
INSERT INTO roles (role_name, description) VALUES
('admin', 'System Administrator with full access'),
('lecturer', 'Lecturer with course and results management access'),
('student', 'Student with limited access to own records'),
('finance', 'Finance staff with payment management access');

-- Permissions Table
CREATE TABLE permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    permission_name VARCHAR(100) UNIQUE NOT NULL,
    module VARCHAR(50) NOT NULL,
    action VARCHAR(50) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Role Permissions Table
CREATE TABLE role_permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_permission (role_id, permission_id),
    INDEX idx_role (role_id),
    INDEX idx_permission (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity Logs Table
CREATE TABLE activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50) NOT NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_module (module),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications Table
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
    read_status ENUM('read', 'unread') DEFAULT 'unread',
    link VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_read_status (read_status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings Table
CREATE TABLE settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NULL,
    category VARCHAR(50) NULL,
    description TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO settings (setting_key, setting_value, category, description) VALUES
('institution_name', 'Seminary Institution', 'general', 'Name of the institution'),
('institution_email', 'info@seminary.edu', 'general', 'Institution email address'),
('institution_phone', '+1234567890', 'general', 'Institution phone number'),
('institution_address', '123 Seminary Street', 'general', 'Institution physical address'),
('academic_year_format', 'YYYY/YYYY', 'academic', 'Format for academic year display'),
('student_id_prefix', 'STD', 'academic', 'Prefix for student ID generation'),
('min_credit_hours', '12', 'academic', 'Minimum credit hours per semester'),
('max_credit_hours', '21', 'academic', 'Maximum credit hours per semester'),
('pass_mark', '50', 'academic', 'Minimum passing mark'),
('results_submission_deadline_days', '14', 'academic', 'Days before end of semester for results submission'),
('timezone', 'UTC', 'system', 'System timezone'),
('date_format', 'Y-m-d', 'system', 'Date format for display'),
('session_timeout', '3600', 'system', 'Session timeout in seconds'),
('max_login_attempts', '5', 'security', 'Maximum failed login attempts before lockout'),
('account_lockout_duration', '30', 'security', 'Account lockout duration in minutes');

-- Email Templates Table
CREATE TABLE email_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    template_name VARCHAR(100) UNIQUE NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    variables TEXT NULL COMMENT 'JSON array of available variables',
    category VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_template_name (template_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default email templates
INSERT INTO email_templates (template_name, subject, body, variables, category) VALUES
('welcome_email', 'Welcome to {{institution_name}}', 
'Dear {{full_name}},

Welcome to {{institution_name}}! Your account has been created successfully.

Your login credentials are:
Username: {{username}}
Password: {{password}}

Please login and change your password immediately.

Best regards,
{{institution_name}} Administration', 
'["institution_name", "full_name", "username", "password"]', 'authentication'),

('password_reset', 'Password Reset Request', 
'Dear {{full_name}},

You have requested to reset your password. Click the link below to reset your password:

{{reset_link}}

This link will expire in 1 hour. If you did not request this, please ignore this email.

Best regards,
{{institution_name}} Administration', 
'["full_name", "reset_link", "institution_name"]', 'authentication'),

('results_published', 'Semester Results Published', 
'Dear {{student_name}},

Your results for {{semester_name}}, {{academic_year}} have been published.

Semester GPA: {{semester_gpa}}
Cumulative GPA: {{cumulative_gpa}}

Login to view your complete results.

Best regards,
{{institution_name}} Administration', 
'["student_name", "semester_name", "academic_year", "semester_gpa", "cumulative_gpa", "institution_name"]', 'academic'),

('payment_receipt', 'Payment Receipt', 
'Dear {{student_name}},

Thank you for your payment.

Receipt Number: {{receipt_number}}
Amount Paid: {{amount}}
Payment Date: {{payment_date}}
Payment Method: {{payment_method}}
Current Balance: {{balance}}

Best regards,
{{institution_name}} Finance Department', 
'["student_name", "receipt_number", "amount", "payment_date", "payment_method", "balance", "institution_name"]', 'finance'),

('fee_reminder', 'Fee Payment Reminder', 
'Dear {{student_name}},

This is a reminder that you have an outstanding balance of {{balance}} for {{semester_name}}.

Due Date: {{due_date}}

Please make payment at your earliest convenience to avoid any inconvenience.

Best regards,
{{institution_name}} Finance Department', 
'["student_name", "balance", "semester_name", "due_date", "institution_name"]', 'finance');

-- Announcements Table
CREATE TABLE announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    target_audience ENUM('all', 'students', 'lecturers', 'finance', 'admin') DEFAULT 'all',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_target_audience (target_audience),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- VIEWS FOR COMMON QUERIES
-- ============================================================================

-- View for Student Details with Program Info
CREATE OR REPLACE VIEW vw_student_details AS
SELECT 
    s.id,
    s.student_id,
    s.user_id,
    CONCAT(s.first_name, ' ', IFNULL(s.middle_name, ''), ' ', s.last_name) AS full_name,
    s.first_name,
    s.middle_name,
    s.last_name,
    s.date_of_birth,
    s.gender,
    s.phone,
    s.email,
    s.address,
    s.city,
    s.country,
    s.program_id,
    p.program_code,
    p.program_name,
    p.department,
    s.level_year,
    s.entry_year,
    s.photo,
    s.status,
    u.username,
    u.email AS user_email,
    u.status AS user_status
FROM students s
INNER JOIN users u ON s.user_id = u.id
INNER JOIN programs p ON s.program_id = p.id;

-- View for Lecturer Details
CREATE OR REPLACE VIEW vw_lecturer_details AS
SELECT 
    l.id,
    l.lecturer_id,
    l.user_id,
    CONCAT(l.first_name, ' ', IFNULL(l.middle_name, ''), ' ', l.last_name) AS full_name,
    l.first_name,
    l.middle_name,
    l.last_name,
    l.phone,
    l.email,
    l.department,
    l.qualifications,
    l.specialization,
    l.office_location,
    l.photo,
    l.status,
    u.username,
    u.email AS user_email,
    u.status AS user_status
FROM lecturers l
INNER JOIN users u ON l.user_id = u.id;

-- View for Student Results with Course Info
CREATE OR REPLACE VIEW vw_student_results AS
SELECT 
    r.id,
    r.student_id,
    s.student_id AS student_number,
    CONCAT(s.first_name, ' ', s.last_name) AS student_name,
    r.course_id,
    c.course_code,
    c.course_name,
    c.credit_hours,
    r.semester_id,
    sem.semester_name,
    ay.year_name AS academic_year,
    r.assignment_marks,
    r.test_marks,
    r.midterm_marks,
    r.final_exam_marks,
    r.participation_marks,
    r.total_marks,
    r.grade,
    r.grade_points,
    r.status,
    r.entered_by,
    CONCAT(lec.first_name, ' ', lec.last_name) AS lecturer_name,
    r.submitted_date,
    r.approved_date,
    r.published_date
FROM results r
INNER JOIN students s ON r.student_id = s.id
INNER JOIN courses c ON r.course_id = c.id
INNER JOIN semesters sem ON r.semester_id = sem.id
INNER JOIN academic_years ay ON sem.academic_year_id = ay.id
LEFT JOIN lecturers lec ON r.entered_by = lec.id;

-- View for Student Financial Summary
CREATE OR REPLACE VIEW vw_student_finances AS
SELECT 
    sb.student_id,
    s.student_id AS student_number,
    CONCAT(s.first_name, ' ', s.last_name) AS student_name,
    sb.semester_id,
    sem.semester_name,
    ay.year_name AS academic_year,
    sb.total_fees,
    sb.total_paid,
    sb.balance,
    sb.last_payment_date,
    CASE 
        WHEN sb.balance > 0 THEN 'Has Balance'
        ELSE 'Fully Paid'
    END AS payment_status
FROM student_balances sb
INNER JOIN students s ON sb.student_id = s.id
INNER JOIN semesters sem ON sb.semester_id = sem.id
INNER JOIN academic_years ay ON sem.academic_year_id = ay.id;

-- ============================================================================
-- STORED PROCEDURES
-- ============================================================================

DELIMITER //

-- Procedure to calculate student GPA
CREATE PROCEDURE sp_calculate_student_gpa(
    IN p_student_id INT,
    IN p_semester_id INT
)
BEGIN
    DECLARE v_semester_gpa DECIMAL(3,2);
    DECLARE v_cumulative_gpa DECIMAL(3,2);
    DECLARE v_semester_credits INT;
    DECLARE v_semester_points DECIMAL(10,2);
    DECLARE v_total_credits INT;
    DECLARE v_total_points DECIMAL(10,2);
    
    -- Calculate semester GPA
    SELECT 
        SUM(c.credit_hours),
        SUM(r.grade_points * c.credit_hours)
    INTO v_semester_credits, v_semester_points
    FROM results r
    INNER JOIN courses c ON r.course_id = c.id
    WHERE r.student_id = p_student_id 
        AND r.semester_id = p_semester_id
        AND r.status = 'published'
        AND r.grade_points IS NOT NULL;
    
    IF v_semester_credits > 0 THEN
        SET v_semester_gpa = v_semester_points / v_semester_credits;
    ELSE
        SET v_semester_gpa = 0.00;
    END IF;
    
    -- Calculate cumulative GPA
    SELECT 
        SUM(c.credit_hours),
        SUM(r.grade_points * c.credit_hours)
    INTO v_total_credits, v_total_points
    FROM results r
    INNER JOIN courses c ON r.course_id = c.id
    WHERE r.student_id = p_student_id
        AND r.status = 'published'
        AND r.grade_points IS NOT NULL;
    
    IF v_total_credits > 0 THEN
        SET v_cumulative_gpa = v_total_points / v_total_credits;
    ELSE
        SET v_cumulative_gpa = 0.00;
    END IF;
    
    -- Insert or update GPA record
    INSERT INTO student_gpas (
        student_id, 
        semester_id, 
        semester_gpa, 
        cumulative_gpa,
        total_credits_attempted,
        total_credits_earned,
        total_grade_points,
        academic_standing
    ) VALUES (
        p_student_id,
        p_semester_id,
        v_semester_gpa,
        v_cumulative_gpa,
        v_total_credits,
        v_total_credits,
        v_total_points,
        CASE 
            WHEN v_cumulative_gpa >= 2.0 THEN 'Good Standing'
            WHEN v_cumulative_gpa >= 1.5 THEN 'Probation'
            ELSE 'Suspension'
        END
    )
    ON DUPLICATE KEY UPDATE
        semester_gpa = v_semester_gpa,
        cumulative_gpa = v_cumulative_gpa,
        total_credits_attempted = v_total_credits,
        total_credits_earned = v_total_credits,
        total_grade_points = v_total_points,
        academic_standing = CASE 
            WHEN v_cumulative_gpa >= 2.0 THEN 'Good Standing'
            WHEN v_cumulative_gpa >= 1.5 THEN 'Probation'
            ELSE 'Suspension'
        END;
        
END //

-- Procedure to update student balance
CREATE PROCEDURE sp_update_student_balance(
    IN p_student_id INT,
    IN p_semester_id INT
)
BEGIN
    DECLARE v_total_fees DECIMAL(10,2);
    DECLARE v_total_paid DECIMAL(10,2);
    DECLARE v_balance DECIMAL(10,2);
    DECLARE v_last_payment_date DATE;
    
    -- Calculate total fees from invoices
    SELECT IFNULL(SUM(total_amount), 0.00)
    INTO v_total_fees
    FROM invoices
    WHERE student_id = p_student_id 
        AND semester_id = p_semester_id;
    
    -- Calculate total paid
    SELECT IFNULL(SUM(amount), 0.00), MAX(payment_date)
    INTO v_total_paid, v_last_payment_date
    FROM payments
    WHERE student_id = p_student_id 
        AND semester_id = p_semester_id;
    
    -- Calculate balance
    SET v_balance = v_total_fees - v_total_paid;
    
    -- Insert or update balance record
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
        
END //

DELIMITER ;

-- ============================================================================
-- TRIGGERS
-- ============================================================================

DELIMITER //

-- Trigger to calculate total marks and grade when result is inserted/updated
CREATE TRIGGER trg_calculate_result BEFORE INSERT ON results
FOR EACH ROW
BEGIN
    DECLARE v_grade_letter VARCHAR(5);
    DECLARE v_grade_point DECIMAL(3,2);
    
    -- Calculate total marks
    SET NEW.total_marks = IFNULL(NEW.assignment_marks, 0) + 
                          IFNULL(NEW.test_marks, 0) + 
                          IFNULL(NEW.midterm_marks, 0) + 
                          IFNULL(NEW.final_exam_marks, 0) + 
                          IFNULL(NEW.participation_marks, 0);
    
    -- Get grade based on total marks
    SELECT grade_letter, grade_point
    INTO v_grade_letter, v_grade_point
    FROM grades
    WHERE NEW.total_marks BETWEEN min_mark AND max_mark
    LIMIT 1;
    
    SET NEW.grade = v_grade_letter;
    SET NEW.grade_points = v_grade_point;
END //

CREATE TRIGGER trg_calculate_result_update BEFORE UPDATE ON results
FOR EACH ROW
BEGIN
    DECLARE v_grade_letter VARCHAR(5);
    DECLARE v_grade_point DECIMAL(3,2);
    
    -- Calculate total marks
    SET NEW.total_marks = IFNULL(NEW.assignment_marks, 0) + 
                          IFNULL(NEW.test_marks, 0) + 
                          IFNULL(NEW.midterm_marks, 0) + 
                          IFNULL(NEW.final_exam_marks, 0) + 
                          IFNULL(NEW.participation_marks, 0);
    
    -- Get grade based on total marks
    SELECT grade_letter, grade_point
    INTO v_grade_letter, v_grade_point
    FROM grades
    WHERE NEW.total_marks BETWEEN min_mark AND max_mark
    LIMIT 1;
    
    SET NEW.grade = v_grade_letter;
    SET NEW.grade_points = v_grade_point;
END //

-- Trigger to update invoice balance when payment is made
CREATE TRIGGER trg_update_invoice_after_payment AFTER INSERT ON payments
FOR EACH ROW
BEGIN
    DECLARE v_total_paid DECIMAL(10,2);
    DECLARE v_balance DECIMAL(10,2);
    DECLARE v_status VARCHAR(20);
    
    IF NEW.invoice_id IS NOT NULL THEN
        -- Calculate total paid for this invoice
        SELECT IFNULL(SUM(amount), 0.00)
        INTO v_total_paid
        FROM payments
        WHERE invoice_id = NEW.invoice_id;
        
        -- Update invoice
        UPDATE invoices
        SET amount_paid = v_total_paid,
            balance = total_amount - v_total_paid,
            status = CASE 
                WHEN (total_amount - v_total_paid) <= 0 THEN 'paid'
                WHEN v_total_paid > 0 THEN 'partial'
                ELSE 'pending'
            END
        WHERE id = NEW.invoice_id;
    END IF;
    
    -- Update student balance
    CALL sp_update_student_balance(NEW.student_id, NEW.semester_id);
END //

DELIMITER ;

-- ============================================================================
-- INDEXES FOR PERFORMANCE OPTIMIZATION
-- ============================================================================

-- Additional composite indexes for common queries
CREATE INDEX idx_results_student_semester ON results(student_id, semester_id, status);
CREATE INDEX idx_registrations_student_semester ON course_registrations(student_id, semester_id, status);
CREATE INDEX idx_payments_student_date ON payments(student_id, payment_date);
CREATE INDEX idx_activity_user_date ON activity_logs(user_id, created_at);

-- ============================================================================
-- SAMPLE DATA (Optional - for testing)
-- ============================================================================

-- Insert a default admin user
INSERT INTO users (username, email, password_hash, role, status) VALUES
('admin', 'admin@seminary.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active');
-- Default password is 'password' - CHANGE THIS IN PRODUCTION!

INSERT INTO admins (user_id, first_name, last_name, phone, email) VALUES
(1, 'System', 'Administrator', '+1234567890', 'admin@seminary.edu');

