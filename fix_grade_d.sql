-- Fix Grade D to have grade_point 2.00 instead of 1.00
-- This ensures that a 50% score (D grade) is correctly valued and displayed in green (passing)

UPDATE grades 
SET grade_point = 2.00 
WHERE grade_letter = 'D' AND min_mark = 50.00 AND max_mark = 54.99;

-- Recalculate grade_points for all existing results with grade 'D'
UPDATE results
SET grade_points = 2.00
WHERE grade = 'D' AND total_marks BETWEEN 50.00 AND 54.99;

SELECT 'Grade D updated successfully. grade_point is now 2.00 (was 1.00)' AS message;
