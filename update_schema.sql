-- Add missing columns to students table
ALTER TABLE students
ADD COLUMN title VARCHAR(10) NULL AFTER last_name,
ADD COLUMN national_id VARCHAR(20) NULL AFTER date_of_birth,
ADD COLUMN specialization VARCHAR(200) NULL AFTER level_year,
ADD COLUMN qualifications TEXT NULL AFTER specialization;

-- Add missing columns to lecturers table
ALTER TABLE lecturers
ADD COLUMN title VARCHAR(10) NULL AFTER last_name,
ADD COLUMN date_of_birth DATE NULL AFTER last_name;