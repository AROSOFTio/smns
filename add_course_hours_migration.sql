-- Migration: Add course hours columns to courses table
-- Date: February 14, 2026
-- Description: Adds lecture_hours, tutorial_hours, practical_hours, and contact_hours columns

USE smns;

-- Add new columns to courses table
ALTER TABLE courses
ADD COLUMN lecture_hours INT NOT NULL DEFAULT 0 COMMENT 'LH - Lecture Hours' AFTER credit_hours,
ADD COLUMN tutorial_hours INT NOT NULL DEFAULT 0 COMMENT 'TH - Tutorial Hours' AFTER lecture_hours,
ADD COLUMN practical_hours INT NOT NULL DEFAULT 0 COMMENT 'PH - Practical Hours' AFTER tutorial_hours,
ADD COLUMN contact_hours INT GENERATED ALWAYS AS (lecture_hours + tutorial_hours + practical_hours) STORED COMMENT 'CH - Contact Hours (LH + TH + PH)' AFTER practical_hours;

-- Update existing records to have some default values (optional)
-- You can modify these values as needed for existing courses
UPDATE courses SET
    lecture_hours = FLOOR(credit_hours * 1.5),
    tutorial_hours = FLOOR(credit_hours * 0.5),
    practical_hours = 0
WHERE lecture_hours = 0 AND tutorial_hours = 0 AND practical_hours = 0;

COMMIT;