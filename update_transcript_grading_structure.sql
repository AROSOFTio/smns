START TRANSACTION;

DELETE FROM grades;

INSERT INTO grades (grade_letter, min_mark, max_mark, grade_point, description, pass_status) VALUES
('A', 80.00, 100.00, 5.00, 'Excellent', 'pass'),
('B+', 75.00, 79.99, 4.00, 'Very Good', 'pass'),
('B', 70.00, 74.99, 3.50, 'Good', 'pass'),
('C+', 65.00, 69.99, 3.00, 'Above Average', 'pass'),
('C', 60.00, 64.99, 2.50, 'Average', 'pass'),
('D', 50.00, 59.99, 2.00, 'Pass', 'pass'),
('E', 40.00, 49.99, 1.00, 'Marginal Fail', 'fail'),
('F', 0.00, 39.99, 0.00, 'Fail', 'fail');

UPDATE results
SET
    grade = CASE
        WHEN total_marks >= 80.00 THEN 'A'
        WHEN total_marks >= 75.00 THEN 'B+'
        WHEN total_marks >= 70.00 THEN 'B'
        WHEN total_marks >= 65.00 THEN 'C+'
        WHEN total_marks >= 60.00 THEN 'C'
        WHEN total_marks >= 50.00 THEN 'D'
        WHEN total_marks >= 40.00 THEN 'E'
        ELSE 'F'
    END,
    grade_points = CASE
        WHEN total_marks >= 80.00 THEN 5.00
        WHEN total_marks >= 75.00 THEN 4.00
        WHEN total_marks >= 70.00 THEN 3.50
        WHEN total_marks >= 65.00 THEN 3.00
        WHEN total_marks >= 60.00 THEN 2.50
        WHEN total_marks >= 50.00 THEN 2.00
        WHEN total_marks >= 40.00 THEN 1.00
        ELSE 0.00
    END;

COMMIT;
