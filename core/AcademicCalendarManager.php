<?php
/**
 * Keeps academic years/semesters aligned to the system calendar model.
 * Model:
 * - Academic year: Aug 01 -> Jun 30
 * - Semester 1 (August intake): Aug 01 -> Dec 20
 * - Semester 2 (January intake): Jan 15 -> Jun 30
 */
class AcademicCalendarManager
{
    /**
     * Ensure normalized academic years and semesters exist from a minimum year to N years ahead.
     */
    public static function ensureStandardCalendar(PDO $conn, $minimumStartYear = 2025, $yearsAhead = 5)
    {
        $minimumStartYear = max(2000, (int)$minimumStartYear);
        $yearsAhead = max(1, min(12, (int)$yearsAhead));

        $nowYear = (int)gmdate('Y');
        $nowMonth = (int)gmdate('n');
        $currentAcademicStartYear = $nowMonth >= 8 ? $nowYear : ($nowYear - 1);

        // Always include the requested baseline year (2025/2026) and extend into future years.
        $startYear = min($minimumStartYear, $currentAcademicStartYear);
        $endYear = max($minimumStartYear, $currentAcademicStartYear + $yearsAhead);

        $wasInTransaction = $conn->inTransaction();
        if (!$wasInTransaction) {
            $conn->beginTransaction();
        }

        try {
            for ($year = $startYear; $year <= $endYear; $year++) {
                $academicYearId = self::upsertAcademicYear($conn, $year);
                self::upsertSemester($conn, $academicYearId, $year, 1);
                self::upsertSemester($conn, $academicYearId, $year, 2);
            }

            if (!$wasInTransaction) {
                $conn->commit();
            }
        } catch (Exception $e) {
            if (!$wasInTransaction && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    private static function upsertAcademicYear(PDO $conn, $startYear)
    {
        $startYear = (int)$startYear;
        $yearName = $startYear . '/' . ($startYear + 1);
        $startDate = sprintf('%04d-08-01', $startYear);
        $endDate = sprintf('%04d-06-30', $startYear + 1);

        $findStmt = $conn->prepare('SELECT id, start_date, end_date FROM academic_years WHERE year_name = :year_name LIMIT 1');
        $findStmt->execute(['year_name' => $yearName]);
        $existing = $findStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $existingId = (int)($existing['id'] ?? 0);

        if ($existingId > 0) {
            $currentStartDate = (string)($existing['start_date'] ?? '');
            $currentEndDate = (string)($existing['end_date'] ?? '');
            if ($currentStartDate !== $startDate || $currentEndDate !== $endDate) {
                $updateStmt = $conn->prepare('UPDATE academic_years SET start_date = :start_date, end_date = :end_date WHERE id = :id');
                $updateStmt->execute([
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'id' => $existingId
                ]);
            }
            return $existingId;
        }

        $insertStmt = $conn->prepare(
            "INSERT INTO academic_years (year_name, start_date, end_date, status)
             VALUES (:year_name, :start_date, :end_date, 'inactive')"
        );
        $insertStmt->execute([
            'year_name' => $yearName,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);

        return (int)$conn->lastInsertId();
    }

    private static function upsertSemester(PDO $conn, $academicYearId, $startYear, $semesterNumber)
    {
        $academicYearId = (int)$academicYearId;
        $startYear = (int)$startYear;
        $semesterNumber = (int)$semesterNumber;

        if ($semesterNumber === 1) {
            $semesterName = 'Semester 1';
            $startDate = sprintf('%04d-08-01', $startYear);
            $endDate = sprintf('%04d-12-20', $startYear);
            $registrationStartDate = sprintf('%04d-08-01', $startYear);
            $registrationEndDate = sprintf('%04d-09-15', $startYear);
        } else {
            $semesterName = 'Semester 2';
            $startDate = sprintf('%04d-01-15', $startYear + 1);
            $endDate = sprintf('%04d-06-30', $startYear + 1);
            $registrationStartDate = sprintf('%04d-01-15', $startYear + 1);
            $registrationEndDate = sprintf('%04d-03-31', $startYear + 1);
        }

        $findStmt = $conn->prepare(
            'SELECT id, semester_name, start_date, end_date, registration_start_date, registration_end_date
             FROM semesters
             WHERE academic_year_id = :academic_year_id AND semester_number = :semester_number
             ORDER BY id ASC
             LIMIT 1'
        );
        $findStmt->execute([
            'academic_year_id' => $academicYearId,
            'semester_number' => $semesterNumber
        ]);
        $existing = $findStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $existingId = (int)($existing['id'] ?? 0);

        if ($existingId > 0) {
            $currentSemesterName = (string)($existing['semester_name'] ?? '');
            $currentStartDate = (string)($existing['start_date'] ?? '');
            $currentEndDate = (string)($existing['end_date'] ?? '');
            $currentRegStartDate = (string)($existing['registration_start_date'] ?? '');
            $currentRegEndDate = (string)($existing['registration_end_date'] ?? '');

            $needsUpdate = $currentSemesterName !== $semesterName
                || $currentStartDate !== $startDate
                || $currentEndDate !== $endDate
                || $currentRegStartDate !== $registrationStartDate
                || $currentRegEndDate !== $registrationEndDate;

            if ($needsUpdate) {
                $updateStmt = $conn->prepare(
                    'UPDATE semesters
                     SET semester_name = :semester_name,
                         start_date = :start_date,
                         end_date = :end_date,
                         registration_start_date = :registration_start_date,
                         registration_end_date = :registration_end_date
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    'semester_name' => $semesterName,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'registration_start_date' => $registrationStartDate,
                    'registration_end_date' => $registrationEndDate,
                    'id' => $existingId
                ]);
            }
            return;
        }

        $insertStmt = $conn->prepare(
            "INSERT INTO semesters
                (academic_year_id, semester_name, semester_number, start_date, end_date, registration_start_date, registration_end_date, status)
             VALUES
                (:academic_year_id, :semester_name, :semester_number, :start_date, :end_date, :registration_start_date, :registration_end_date, 'inactive')"
        );
        $insertStmt->execute([
            'academic_year_id' => $academicYearId,
            'semester_name' => $semesterName,
            'semester_number' => $semesterNumber,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'registration_start_date' => $registrationStartDate,
            'registration_end_date' => $registrationEndDate
        ]);
    }
}
