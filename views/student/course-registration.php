<?php
/**
 * Student - Course Registration
 * Modified to require admin approval before showing courses
 */
require_once '../../config.php';



$session = new Session('student');
$auth = new Auth('student');

// Verify student access
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true || $_SESSION['student_role'] !== 'student') {
    header('Location: ' . BASE_URL . '/views/student/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$studentProfile = $currentUser['profile'];

$db = new Database();
$conn = $db->getConnection();

// Semesters for selection
$sstmt = $conn->query("SELECT s.*, ay.year_name FROM semesters s JOIN academic_years ay ON s.academic_year_id = ay.id ORDER BY ay.year_name DESC, s.semester_number DESC");
$semesters = $sstmt->fetchAll();

// Academic years for dropdown
$academicYears = $conn->query("SELECT id, year_name, start_date FROM academic_years ORDER BY start_date DESC")->fetchAll();

// Default semester & academic year (current)
$currentSemester = Helper::getCurrentSemester();
$defaultSemesterId = $currentSemester['id'] ?? 0;
$defaultAcademicYearId = Helper::getCurrentAcademicYear()['id'] ?? ($academicYears[0]['id'] ?? 0);

// Determine selected academic year & semester number from GET (or fallbacks)
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : $defaultAcademicYearId;
$selectedSemesterNumber = isset($_GET['semester_number']) ? (int)$_GET['semester_number'] : ($currentSemester['semester_number'] ?? 1);

// Validate semester number - only 1 or 2 are valid
if ($selectedSemesterNumber < 1 || $selectedSemesterNumber > 2) {
    $selectedSemesterNumber = 1;
}

// Update semesterId logic to use semester_offered
$semesterOffered = $selectedSemesterNumber; // Use semester_number directly

// Map academic_year + semester_number to a semester id
$semesterId = 0;
if (isset($_GET['semester_id']) && (int)$_GET['semester_id'] > 0) {
    $semesterId = (int)$_GET['semester_id'];
} else {
    $mapStmt = $conn->prepare("SELECT id FROM semesters WHERE academic_year_id = :ay AND semester_number = :sn LIMIT 1");
    $mapStmt->execute(['ay' => $selectedAcademicYearId, 'sn' => $selectedSemesterNumber]);
    $row = $mapStmt->fetch();
    $semesterId = $row['id'] ?? $defaultSemesterId;
}

if (isset($_POST['semester_id'])) {
    $semesterId = (int)$_POST['semester_id'];
}

// Compute Year of study
// Allow overriding via GET (user-selectable)
$yearOfStudy = isset($_GET['year_of_study']) ? (int)$_GET['year_of_study'] : (int)($studentProfile['level_year'] ?? 1);

// Only auto-calculate year of study if user hasn't explicitly selected one via the dropdown
if (!isset($_GET['year_of_study']) && !empty($studentProfile['entry_year']) && $selectedAcademicYearId) {
    $ayStmt = $conn->prepare("SELECT start_date FROM academic_years WHERE id = :id LIMIT 1");
    $ayStmt->execute(['id' => $selectedAcademicYearId]);
    $ayRow = $ayStmt->fetch();
    if ($ayRow && !empty($ayRow['start_date'])) {
        $startYear = (int)date('Y', strtotime($ayRow['start_date']));
        $entryYear = (int)$studentProfile['entry_year'];
        $calc = ($startYear - $entryYear) + 1;
        $yearOfStudy = max(1, min(10, $calc));
    } else {
        $yearOfStudy = (int)($studentProfile['level_year'] ?? $yearOfStudy);
    }
}

// Check if student has an APPROVED semester registration for the selected semester
$approvalCheckStmt = $conn->prepare("
    SELECT sr.*, ay.year_name 
    FROM semester_registrations sr
    JOIN semesters s ON sr.semester_id = s.id
    JOIN academic_years ay ON s.academic_year_id = ay.id
    WHERE sr.student_id = :student_id 
    AND sr.semester_id = :semester_id 
    AND sr.status = 'approved'
    LIMIT 1
");
$approvalCheckStmt->execute([
    'student_id' => $studentProfile['id'], 
    'semester_id' => $semesterId
]);
$semesterApproval = $approvalCheckStmt->fetch();

// If approval exists but year_of_study has changed, reassign courses
if ($semesterApproval && isset($semesterApproval['year_of_study']) && $semesterApproval['year_of_study'] != $yearOfStudy) {
    try {
        // Update year_of_study in semester_registrations
        $updateYearStmt = $conn->prepare("
            UPDATE semester_registrations 
            SET year_of_study = :year_of_study, updated_at = NOW()
            WHERE student_id = :student_id AND semester_id = :semester_id
        ");
        $updateYearStmt->execute([
            'student_id' => $studentProfile['id'],
            'semester_id' => $semesterId,
            'year_of_study' => $yearOfStudy
        ]);
        
        // Delete old course registrations for this semester
        $deleteCoursesStmt = $conn->prepare("
            DELETE FROM course_registrations 
            WHERE student_id = :student_id AND semester_id = :semester_id
        ");
        $deleteCoursesStmt->execute([
            'student_id' => $studentProfile['id'],
            'semester_id' => $semesterId
        ]);
        
        // Re-assign courses for new year/semester
        $coursesStmt = $conn->prepare("
            SELECT id FROM courses 
            WHERE semester_offered = :semester_offered
            AND level_year = :level_year
            AND status = 'active' 
            AND (program_id = :program_id OR program_id IS NULL OR program_id = 0)
            ORDER BY course_code ASC
        ");
        $coursesStmt->execute([
            'semester_offered' => $selectedSemesterNumber, 
            'program_id' => $studentProfile['program_id'] ?? 0, 
            'level_year' => $yearOfStudy
        ]);
        $courseIds = $coursesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Fallback: relaxed search without program filter
        if (empty($courseIds)) {
            $relaxedStmt = $conn->prepare("
                SELECT id FROM courses 
                WHERE semester_offered = :semester_offered
                AND level_year = :level_year
                AND status = 'active'
                ORDER BY course_code ASC
            ");
            $relaxedStmt->execute([
                'semester_offered' => $selectedSemesterNumber,
                'level_year' => $yearOfStudy
            ]);
            $courseIds = $relaxedStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // Assign new courses
        if (!empty($courseIds)) {
            $insCourseStmt = $conn->prepare("
                INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_date, created_at) 
                VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', NOW(), NOW())
            ");
            $assignedCount = 0;
            foreach ($courseIds as $cid) {
                try {
                    $insCourseStmt->execute([
                        'student_id' => $studentProfile['id'], 
                        'course_id' => $cid, 
                        'semester_id' => $semesterId
                    ]);
                    $assignedCount++;
                } catch (Exception $courseEx) {
                    error_log("Failed to assign course $cid: " . $courseEx->getMessage());
                }
            }
            
            if ($assignedCount > 0) {
                $session->setFlash('success', "Courses updated! $assignedCount course(s) assigned for Year $yearOfStudy, Semester $selectedSemesterNumber.");
            }
        } else {
            $session->setFlash('warning', "No courses available for Year $yearOfStudy, Semester $selectedSemesterNumber in the database.");
        }
        
        // Update $semesterApproval with new year_of_study
        $semesterApproval['year_of_study'] = $yearOfStudy;
        
    } catch (Exception $e) {
        error_log("Course reassignment error: " . $e->getMessage());
        $session->setFlash('error', "Failed to update courses: " . $e->getMessage());
    }
}

// AUTO-REGISTER: If no approval exists, automatically create one and assign courses
if (!$semesterApproval && $semesterId > 0) {
    try {
        // First check if there's ANY registration (pending, approved, rejected) for this semester
        $existingRegStmt = $conn->prepare("
            SELECT * FROM semester_registrations 
            WHERE student_id = :student_id AND semester_id = :semester_id
        ");
        $existingRegStmt->execute([
            'student_id' => $studentProfile['id'],
            'semester_id' => $semesterId
        ]);
        $existingReg = $existingRegStmt->fetch();
        
        if ($existingReg) {
            // Update existing registration to approved
            $updateRegStmt = $conn->prepare("
                UPDATE semester_registrations 
                SET status = 'approved', year_of_study = :year_of_study, updated_at = NOW()
                WHERE student_id = :student_id AND semester_id = :semester_id
            ");
            $updateRegStmt->execute([
                'student_id' => $studentProfile['id'],
                'semester_id' => $semesterId,
                'year_of_study' => $yearOfStudy
            ]);
        } else {
            // Create new semester registration
            $autoRegStmt = $conn->prepare("
                INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, created_at, updated_at) 
                VALUES (:student_id, :semester_id, :year_of_study, 'approved', NOW(), NOW(), NOW())
            ");
            $autoRegStmt->execute([
                'student_id' => $studentProfile['id'],
                'semester_id' => $semesterId,
                'year_of_study' => $yearOfStudy
            ]);
        }

        // Auto-assign courses
        $courseIds = [];
        $assignedCount = 0;
        
        // Fetch courses matching student's year and semester
        $coursesStmt = $conn->prepare("
            SELECT id FROM courses 
            WHERE semester_offered = :semester_offered
            AND level_year = :level_year
            AND status = 'active' 
            AND (program_id = :program_id OR program_id IS NULL OR program_id = 0)
            ORDER BY course_code ASC
        ");
        $coursesStmt->execute([
            'semester_offered' => $selectedSemesterNumber, 
            'program_id' => $studentProfile['program_id'] ?? 0, 
            'level_year' => $yearOfStudy
        ]);
        $courseIds = $coursesStmt->fetchAll(PDO::FETCH_COLUMN);

        // Relaxed search if no courses found
        if (empty($courseIds)) {
            $relaxedStmt = $conn->prepare("
                SELECT id FROM courses 
                WHERE semester_offered = :semester_offered
                AND level_year = :level_year
                AND status = 'active'
                ORDER BY course_code ASC
            ");
            $relaxedStmt->execute([
                'semester_offered' => $selectedSemesterNumber,
                'level_year' => $yearOfStudy
            ]);
            $courseIds = $relaxedStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // Even more relaxed - get all active courses
        if (empty($courseIds)) {
            $allCoursesStmt = $conn->prepare("SELECT id FROM courses WHERE status = 'active' ORDER BY course_code ASC");
            $allCoursesStmt->execute();
            $courseIds = $allCoursesStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // Assign courses to student
        if (!empty($courseIds)) {
            $checkCourseStmt = $conn->prepare("SELECT id FROM course_registrations WHERE student_id = :student_id AND course_id = :course_id AND semester_id = :semester_id");
            $insCourseStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', NULL, NOW(), NOW())");

            foreach ($courseIds as $cid) {
                try {
                    $checkCourseStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $cid, 'semester_id' => $semesterId]);
                    if ($checkCourseStmt->fetch()) continue;
                    $insCourseStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $cid, 'semester_id' => $semesterId]);
                    $assignedCount++;
                } catch (Exception $courseEx) {
                    error_log("Failed to assign course $cid: " . $courseEx->getMessage());
                }
            }
        }

        // Re-fetch approval status
        $approvalCheckStmt->execute([
            'student_id' => $studentProfile['id'], 
            'semester_id' => $semesterId
        ]);
        $semesterApproval = $approvalCheckStmt->fetch();
        
        if ($assignedCount > 0) {
            $session->setFlash('success', "Welcome! You have been automatically registered. $assignedCount course(s) assigned.");
        } elseif (!empty($courseIds)) {
            $session->setFlash('info', "You have been registered. Courses are already in your list.");
        } else {
            $session->setFlash('info', "You have been registered. No courses available yet for Year $yearOfStudy, Semester $selectedSemesterNumber.");
        }
    } catch (Exception $e) {
        error_log("Auto-registration error: " . $e->getMessage());
        $session->setFlash('error', "Registration error: " . $e->getMessage() . ". Please contact administration.");
        
        // Try to re-fetch in case registration succeeded but course assignment failed
        $approvalCheckStmt->execute([
            'student_id' => $studentProfile['id'], 
            'semester_id' => $semesterId
        ]);
        $semesterApproval = $approvalCheckStmt->fetch();
    }
}

// Check if student has a pending request
// Specify table alias for the ambiguous 'status' column
$pendingCheckStmt = $conn->prepare("
    SELECT * FROM semester_registrations sr
    WHERE sr.student_id = :student_id 
    AND sr.semester_id = :semester_id 
    AND sr.status = 'pending'
    LIMIT 1
");
$pendingCheckStmt->execute([
    'student_id' => $studentProfile['id'], 
    'semester_id' => $semesterId
]);
$pendingRequest = $pendingCheckStmt->fetch();

// Auto-create missing semester registration if not found
/*
$regCheckStmt = $conn->prepare("SELECT id FROM semester_registrations WHERE student_id = :student_id AND semester_id = :semester_id");
$regCheckStmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
if (!$regCheckStmt->fetch()) {
    $now = date('Y-m-d H:i:s');
    $autoRegStmt = $conn->prepare("INSERT INTO semester_registrations (student_id, semester_id, status, request_date, created_at, updated_at) VALUES (:student_id, :semester_id, :status, :request_date, :created_at, :updated_at)");
    $autoRegStmt->execute([
        'student_id' => $studentProfile['id'],
        'semester_id' => $semesterId,
        'status' => 'pending',
        'request_date' => $now,
        'created_at' => $now,
        'updated_at' => $now
    ]);
}
*/

// Handle semester registration request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_semester_registration') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    // Allow all students to register again (remove block for existing registration)
    // Optionally, you can keep a log of previous registrations if needed

    // Create semester registration request (include year_of_study)
    $insertStmt = $conn->prepare("
        INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, created_at) 
        VALUES (:student_id, :semester_id, :year_of_study, 'pending', NOW(), NOW())
    ");
    
    try {
        // Get the year_of_study from POST or use selected value
        $registrationYearOfStudy = isset($_POST['year_of_study']) ? (int)$_POST['year_of_study'] : $yearOfStudy;
        
        // Immediately approve semester registration so students can register for courses
        $insertStmt = $conn->prepare("INSERT INTO semester_registrations (student_id, semester_id, year_of_study, status, request_date, created_at, updated_at) VALUES (:student_id, :semester_id, :year_of_study, 'approved', NOW(), NOW(), NOW())");
        $insertStmt->execute([
            'student_id' => $studentProfile['id'],
            'semester_id' => $semesterId,
            'year_of_study' => $registrationYearOfStudy
        ]);

        // Auto-assign available courses for this semester to the student so they appear immediately
        $courseIds = [];
        $assignedCount = 0;
        try {

            // 1) First try: Strict matching - exact year, exact semester, matching program
            try {
                $coursesStmt = $conn->prepare("
                    SELECT id FROM courses 
                    WHERE semester_offered = :semester_offered
                    AND level_year = :level_year
                    AND status = 'active' 
                    AND (program_id = :program_id OR program_id IS NULL OR program_id = 0)
                    ORDER BY course_code ASC
                ");
                $coursesStmt->execute([
                    'semester_offered' => $selectedSemesterNumber, 
                    'program_id' => $studentProfile['program_id'] ?? 0, 
                    'level_year' => $registrationYearOfStudy
                ]);
                $courseIds = $coursesStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Log for debugging
                error_log("Course search (strict): semester=$selectedSemesterNumber, year=$registrationYearOfStudy, program=" . ($studentProfile['program_id'] ?? 0) . ", found=" . count($courseIds));
            } catch (Exception $e) {
                error_log("Failed to fetch courses (strict): " . $e->getMessage());
            }

            // 2) Second try: Relaxed program matching
            if (empty($courseIds)) {
                try {
                    $relaxedStmt = $conn->prepare("
                        SELECT id FROM courses 
                        WHERE semester_offered = :semester_offered
                        AND level_year = :level_year
                        AND status = 'active'
                        ORDER BY course_code ASC
                    ");
                    $relaxedStmt->execute([
                        'semester_offered' => $selectedSemesterNumber,
                        'level_year' => $registrationYearOfStudy
                    ]);
                    $courseIds = $relaxedStmt->fetchAll(PDO::FETCH_COLUMN);
                    error_log("Course search (relaxed): found=" . count($courseIds));
                } catch (Exception $e) {
                    error_log("Failed to fetch courses (relaxed): " . $e->getMessage());
                }
            }

            // 3) Third try: Show all active courses (debugging fallback)
            if (empty($courseIds)) {
                try {
                    $allStmt = $conn->prepare("
                        SELECT id FROM courses 
                        WHERE status = 'active'
                        ORDER BY course_code ASC
                    ");
                    $allStmt->execute();
                    $courseIds = $allStmt->fetchAll(PDO::FETCH_COLUMN);
                    error_log("Course search (all active): found=" . count($courseIds));
                } catch (Exception $e) {
                    error_log("Failed to fetch all courses: " . $e->getMessage());
                }
            }

            // 4) If still no courses found, check course_assignments table
            if (empty($courseIds)) {
                try {
                    $caStmt = $conn->prepare("
                        SELECT ca.course_id FROM course_assignments ca 
                        WHERE ca.semester_id = :semester_id 
                        AND (ca.program_id = :program_id OR ca.program_id IS NULL) 
                        AND ca.year_of_study = :year_of_study 
                        AND ca.status = 'active'
                    ");
                    $caStmt->execute([
                        'semester_id' => $semesterId, 
                        'program_id' => $studentProfile['program_id'] ?? 0, 
                        'year_of_study' => $registrationYearOfStudy
                    ]);
                    $courseIds = $caStmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $ignore) {
                    // course_assignments may not exist; fall through
                }
            }

            // 3) Assign found courses to the student
            if (!empty($courseIds)) {
                $checkCourseStmt = $conn->prepare("SELECT id FROM course_registrations WHERE student_id = :student_id AND course_id = :course_id AND semester_id = :semester_id");
                $insCourseStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', NULL, NOW(), NOW())");

                foreach ($courseIds as $cid) {
                    $checkCourseStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $cid, 'semester_id' => $semesterId]);
                    if ($checkCourseStmt->fetch()) continue;
                    $insCourseStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $cid, 'semester_id' => $semesterId]);
                    $assignedCount++;
                }
                error_log("Assigned $assignedCount courses to student " . $studentProfile['id']);
            }
        } catch (PDOException $innerEx) {
            // non-fatal: if course auto-assign fails, continue — student can still select courses manually
            error_log("Course auto-assign error: " . $innerEx->getMessage());
        }

        // Set appropriate flash message
        if (!empty($courseIds) && $assignedCount > 0) {
            $session->setFlash('success', "Semester registration approved! $assignedCount course(s) have been assigned to you.");
        } elseif (!empty($courseIds)) {
            $session->setFlash('info', 'Semester registration approved! You are already registered for all available courses.');
        } else {
            $session->setFlash('info', 'Semester registration approved! No courses are currently available for your year and semester. Please contact administration.');
        }
        
        // Redirect with proper parameters to show registered courses
        $redirectUrl = 'course-registration.php?semester_id=' . $semesterId 
                     . '&academic_year_id=' . $selectedAcademicYearId 
                     . '&semester_number=' . $selectedSemesterNumber 
                     . '&year_of_study=' . $registrationYearOfStudy;
        header('Location: ' . $redirectUrl);
        exit;

    } catch (PDOException $e) {
        error_log("Semester registration error: " . $e->getMessage());
        $session->setFlash('error', 'Failed to submit registration request. Please try again.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }
}

// Handle course registration submission (only if semester registration is approved)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_course_registration') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $session->setFlash('error', 'Invalid CSRF token.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    // Verify semester registration is approved
    if (!$semesterApproval) {
        $session->setFlash('error', 'You must have an approved semester registration before registering for courses.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

        // Server-side guard: if courses were already assigned (approved) or admin-managed
        // course_assignments exist for this semester/program/year, disallow manual selection.
        try {
            $assignedCountStmt = $conn->prepare("SELECT COUNT(*) FROM course_registrations WHERE student_id = :student_id AND semester_id = :semester_id AND status = 'approved'");
            $assignedCountStmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
            if ($assignedCountStmt->fetchColumn() > 0) {
                $session->setFlash('error', 'Your courses have already been assigned for this semester. Selection is disabled.');
                header('Location: course-registration.php?semester_id=' . $semesterId);
                exit;
            }
        } catch (Exception $e) {
            // ignore and continue
        }

        try {
            $caCheck = $conn->prepare("SELECT COUNT(*) FROM course_assignments WHERE semester_id = :semester_id AND (program_id = :program_id OR program_id IS NULL) AND year_of_study = :year_of_study AND status = 'active'");
            $caCheck->execute(['semester_id' => $semesterId, 'program_id' => $studentProfile['program_id'] ?? 0, 'year_of_study' => $yearOfStudy]);
            if ($caCheck->fetchColumn() > 0) {
                $session->setFlash('error', 'Courses for this semester are assigned by the administration; manual selection is disabled.');
                header('Location: course-registration.php?semester_id=' . $semesterId);
                exit;
            }
        } catch (Exception $e) {
            // if course_assignments table missing, ignore
        }

    $selected = $_POST['courses'] ?? [];
    if (!is_array($selected) || count($selected) === 0) {
        $session->setFlash('error', 'Please select at least one course to register.');
        header('Location: course-registration.php?semester_id=' . $semesterId);
        exit;
    }

    $inserted = 0;
    $duplicates = 0;
    $errors = 0;

    $checkStmt = $conn->prepare("SELECT id FROM course_registrations WHERE student_id = :student_id AND course_id = :course_id AND semester_id = :semester_id");
    $insStmt = $conn->prepare("INSERT INTO course_registrations (student_id, course_id, semester_id, registration_date, status, approved_by, approved_date, created_at) VALUES (:student_id, :course_id, :semester_id, NOW(), 'approved', NULL, NOW(), NOW())");

    foreach ($selected as $cid) {
        $courseId = (int)$cid;
        if ($courseId <= 0) continue;

        // skip if already registered
        $checkStmt->execute(['student_id' => $studentProfile['id'], 'course_id' => $courseId, 'semester_id' => $semesterId]);
        if ($checkStmt->fetch()) {
            $duplicates++;
            continue;
        }

        try {
            $insStmt->execute([
                'student_id' => $studentProfile['id'],
                'course_id' => $courseId,
                'semester_id' => $semesterId
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $errors++;
            continue;
        }
    }

    // Build notification message
    $msgParts = [];
    if ($inserted > 0) {
        $msgParts[] = "$inserted course(s) registered successfully.";
    }
    if ($duplicates) $msgParts[] = "$duplicates course(s) were already registered.";
    if ($errors) $msgParts[] = "$errors course(s) failed to register.";
    if (empty($msgParts)) $msgParts[] = 'No changes were made.';

    $session->setFlash('success', implode(' ', $msgParts));
    header('Location: course-registration.php?semester_id=' . $semesterId);
    exit;
}

// Fetch available courses: only show courses matching student's specific year, semester, and program
$availableCourses = [];
$fromAssignments = false;

// 1) First try: Strict matching - exact year, exact semester, matching program
$availableCoursesStmt = $conn->prepare("
    SELECT * FROM courses 
    WHERE semester_offered = :semester_offered
    AND level_year = :level_year
    AND status = 'active' 
    AND (program_id = :program_id OR program_id IS NULL OR program_id = 0)
    ORDER BY course_code ASC
");
$availableCoursesStmt->execute([
    'semester_offered' => $selectedSemesterNumber,
    'program_id' => $studentProfile['program_id'] ?? 0,
    'level_year' => $yearOfStudy
]);
$availableCourses = $availableCoursesStmt->fetchAll();

// 2) Second try: Relaxed program matching (if student has no program or courses have no program restriction)
if (empty($availableCourses)) {
    $relaxedStmt = $conn->prepare("
        SELECT * FROM courses 
        WHERE semester_offered = :semester_offered
        AND level_year = :level_year
        AND status = 'active'
        ORDER BY course_code ASC
    ");
    $relaxedStmt->execute([
        'semester_offered' => $selectedSemesterNumber,
        'level_year' => $yearOfStudy
    ]);
    $availableCourses = $relaxedStmt->fetchAll();
}

// 3) Third try: Show all active courses for any year/semester (debugging fallback)
if (empty($availableCourses)) {
    $allCoursesStmt = $conn->prepare("
        SELECT * FROM courses 
        WHERE status = 'active'
        ORDER BY level_year ASC, semester_offered ASC, course_code ASC
    ");
    $allCoursesStmt->execute();
    $availableCourses = $allCoursesStmt->fetchAll();
}

// 4) If still no courses found, check course_assignments
if (empty($availableCourses)) {
    try {
        $caStmt = $conn->prepare("
            SELECT c.* FROM course_assignments ca 
            JOIN courses c ON ca.course_id = c.id 
            WHERE ca.semester_id = :semester_id 
            AND (ca.program_id = :program_id OR ca.program_id IS NULL) 
            AND ca.year_of_study = :year_of_study 
            AND ca.status = 'active' 
            AND c.status = 'active' 
            ORDER BY c.course_code
        ");
        $caStmt->execute([
            'semester_id' => $semesterId, 
            'program_id' => $studentProfile['program_id'] ?? 0, 
            'year_of_study' => $yearOfStudy
        ]);
        $availableCourses = $caStmt->fetchAll();
        $fromAssignments = !empty($availableCourses);
    } catch (Exception $e) {
        // ignore if course_assignments table missing
    }
}

// Already registered courses for this student & semester
$registered = [];
if ($semesterId && $semesterApproval) {
    $rstmt = $conn->prepare("SELECT course_id, status FROM course_registrations WHERE student_id = :student_id AND semester_id = :semester_id");
    $rstmt->execute(['student_id' => $studentProfile['id'], 'semester_id' => $semesterId]);
    while ($r = $rstmt->fetch()) {
        $registered[$r['course_id']] = $r['status'] ?? null; // Ensure 'course_id' exists
    }
}

// Determine if student still needs to select any courses (i.e., there are available courses not yet registered)
$needsSelection = false;
if (!empty($availableCourses)) {
    foreach ($availableCourses as $c) {
        if (!isset($registered[$c['id']])) { $needsSelection = true; break; }
    }
}

// Fetch approved registrations (courses student is supposed to attend)
$approvedCourses = [];
if ($semesterId && $semesterApproval) {
    $ac = $conn->prepare("SELECT cr.course_id, c.course_code, c.course_name, c.credit_hours, c.level_year, c.semester_offered
                          FROM course_registrations cr
                          JOIN courses c ON cr.course_id = c.id
                          WHERE cr.student_id = :student_id 
                          AND cr.semester_id = :semester_id 
                          AND cr.status = 'approved'
                          AND c.level_year = :level_year
                          AND c.semester_offered = :semester_offered
                          ORDER BY c.course_code");
    $ac->execute([
        'student_id' => $studentProfile['id'], 
        'semester_id' => $semesterId,
        'level_year' => $yearOfStudy,
        'semester_offered' => $selectedSemesterNumber
    ]);
    $approvedCourses = $ac->fetchAll();
}

// Determine whether selection should be allowed. If courses were assigned by admin
// (`course_assignments`) or the student already has approved course_registrations,
// do not allow manual selection — show read-only list instead.
$selectionAllowed = true;
if (!empty($approvedCourses) || $fromAssignments) {
    $selectionAllowed = false;
}

$pageTitle = 'Course Registration - ' . APP_NAME;
include '../../includes/header.php';
?>

<style>
    /* Prevent horizontal scrolling on the entire page */
    html, body {
        overflow-x: hidden;
        max-width: 100%;
    }
    
    .main-content {
        overflow-x: hidden;
        max-width: 100%;
    }
    
    .content-area.container {
        max-width: 100% !important;
        overflow-x: hidden;
        padding: 1rem;
    }
    
    /* Ensure cards don't overflow */
    .card {
        max-width: 100%;
        overflow-x: hidden;
    }
    
    .card-body {
        overflow-x: hidden;
    }
    
    /* Make form responsive */
    .form-row {
        margin-left: -5px;
        margin-right: -5px;
    }
    
    .form-row > .form-group {
        padding-left: 5px;
        padding-right: 5px;
    }
    
    /* Responsive adjustments for smaller screens */
    @media (max-width: 992px) {
        .content-area.container {
            padding: 0.75rem;
        }
        
        .card-body {
            padding: 1rem !important;
        }
    }
    
    @media (max-width: 768px) {
        .content-area.container {
            padding: 0.5rem;
        }
        
        .form-group.d-flex {
            flex-direction: column !important;
            align-items: flex-start !important;
        }
        
        .form-group label {
            margin-bottom: 0.5rem !important;
        }
        
        /* Reduce table font size on mobile */
        .table-responsive table {
            font-size: 0.7rem !important;
        }
        
        .table-responsive th,
        .table-responsive td {
            padding: 0.4rem 0.2rem !important;
            font-size: 0.7rem !important;
        }
        
        .card-body {
            padding: 0.75rem !important;
        }
        
        h3 {
            font-size: 1.25rem !important;
        }
        
        h4 {
            font-size: 1rem !important;
        }
        
        h5 {
            font-size: 0.9rem !important;
        }
    }
    
    /* Ensure table container has proper scroll behavior */
    .table-responsive {
        -webkit-overflow-scrolling: touch;
        margin-bottom: 1rem;
    }
    
    /* Additional fixes for narrow screens */
    @media (max-width: 576px) {
        .table-responsive th,
        .table-responsive td {
            padding: 0.3rem 0.15rem !important;
            font-size: 0.65rem !important;
        }
    }
</style>

<?php include '../../includes/student/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>My Courses</h4>
        </div>
        <div class="topbar-right">
            <?php include '../../includes/notification_bell.php'; ?>
        </div>
    </div>

    <div class="content-area container p-4">
        <?php if ($session->getFlash('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" style="background:#d4edda;border-color:#c3e6cb;color:#155724;border-left:4px solid #28a745;">
                <i class="fas fa-check-circle"></i> <?php echo e($session->getFlash('success')); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($session->getFlash('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-left:4px solid #dc3545;">
                <i class="fas fa-exclamation-circle"></i> <?php echo e($session->getFlash('error')); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($session->getFlash('info')): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert" style="border-left:4px solid #17a2b8;">
                <i class="fas fa-info-circle"></i> <?php echo e($session->getFlash('info')); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="border:none;background:transparent;font-size:20px;line-height:1;color:inherit;opacity:0.9;">&times;</button>
            </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body" style="padding: 1rem;">
                <form id="courseFilterForm" method="GET" class="mb-3">
                    <?php echo csrfField(); ?>
                    <div class="form-row">
                        <div class="form-group col-12 col-md-4 mb-3">
                            <label class="mb-2" style="color:#374151; font-weight:600; font-size: 0.875rem;">Academic year</label>
                            <select name="academic_year_id" class="form-control form-control-sm">
                                <?php foreach ($academicYears as $ay): ?>
                                    <option value="<?php echo $ay['id']; ?>" <?php echo $selectedAcademicYearId == $ay['id'] ? 'selected' : ''; ?>><?php echo e($ay['year_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group col-12 col-md-4 mb-3">
                            <label class="mb-2" style="color:#374151; font-weight:600; font-size: 0.875rem;">Semester</label>
                            <select name="semester_number" class="form-control form-control-sm">
                                <option value="1" <?php echo $selectedSemesterNumber == 1 ? 'selected' : ''; ?>>Semester 1</option>
                                <option value="2" <?php echo $selectedSemesterNumber == 2 ? 'selected' : ''; ?>>Semester 2</option>
                            </select>
                        </div>

                        <div class="form-group col-12 col-md-4 mb-3">
                            <label class="mb-2" style="color:#374151; font-weight:600; font-size: 0.875rem;">Year of study</label>
                            <select id="year_of_study_select" name="year_of_study" class="form-control form-control-sm">
                                <?php for ($y = 1; $y <= 4; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $yearOfStudy == $y ? 'selected' : ''; ?>>Year <?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </form>

                <script>
                // Auto-submit form when filters change to reload courses
                (function(){
                    var form = document.getElementById('courseFilterForm');
                    if (!form) return;
                    var selects = form.querySelectorAll('select[name="academic_year_id"], select[name="semester_number"], select[name="year_of_study"]');
                    var timeout = null;

                    function debouncedSubmit() {
                        clearTimeout(timeout);
                        timeout = setTimeout(function(){
                            form.submit();
                        }, 300);
                    }

                    selects.forEach(function(s){ 
                        s.addEventListener('change', debouncedSubmit); 
                    });
                })();
                </script>

                <?php if (!$semesterApproval): ?>
                    <!-- This should rarely show since auto-registration happens above -->
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Unable to auto-register.</strong>
                        <?php if (defined('APP_DEBUG') && APP_DEBUG): ?>
                            <div class="mt-2">
                                <p>Debugging Information:</p>
                                <ul style="margin-bottom: 0;">
                                    <li>Student ID: <?php echo $studentProfile['id']; ?></li>
                                    <li>Semester ID: <?php echo $semesterId; ?> <?php if ($semesterId <= 0) echo '<strong>(INVALID - Must be greater than 0)</strong>'; ?></li>
                                    <li>Year of Study: <?php echo $yearOfStudy; ?></li>
                                    <li>Semester Number: <?php echo $selectedSemesterNumber; ?></li>
                                    <li>Academic Year ID: <?php echo $selectedAcademicYearId; ?></li>
                                </ul>
                            </div>
                            <?php if ($semesterId <= 0): ?>
                                <div class="mt-2">
                                    <strong>Issue:</strong> Invalid semester ID. The semester you selected may not exist in the database.
                                    <br>Please ensure:
                                    <ol>
                                        <li>The academic year "<?php echo $selectedAcademicYearId; ?>" exists in the academic_years table</li>
                                        <li>A semester with number "<?php echo $selectedSemesterNumber; ?>" exists for that academic year</li>
                                    </ol>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="mt-2">
                            <small>Please contact administration with this information.</small>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Semester registration is approved - show courses -->
                    <div class="card mb-4" style="border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                        <div class="card-body" style="padding: 1.5rem;">
                            <h3 style="margin-bottom: 1rem; font-weight: 700; color: #1a1a1a; font-size: 1.5rem;">Current Courses</h3>
                            
                            <!-- Academic Year Label -->
                            <div style="margin-bottom: 1rem;">
                                <h4 style="font-weight: 700; color: #2d3748; font-size: 1.1rem; border-bottom: 3px solid #e2e8f0; padding-bottom: 0.5rem;">
                                    <i class="fas fa-graduation-cap" style="color: #4a5568; margin-right: 0.5rem;"></i>
                                    Academic Year 2025/2026
                                </h4>
                            </div>
                            
                            <!-- Study Info -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.5rem; padding: 0.75rem 0; border-bottom: 1px solid #e5e7eb;">
                                <div style="display: flex; align-items: center;">
                                    <span style="font-weight: 400; color: #6b7280; margin-right: 0.5rem;">Study year:</span>
                                    <span style="font-weight: 600; color: #1f2937;"><?php echo $yearOfStudy; ?></span>
                                </div>
                                <div style="display: flex; align-items: center;">
                                    <span style="font-weight: 400; color: #6b7280; margin-right: 0.5rem;">Semester:</span>
                                    <span style="font-weight: 600; color: #1f2937;"><?php echo $selectedSemesterNumber == 1 ? 'I' : ($selectedSemesterNumber == 2 ? 'II' : $selectedSemesterNumber); ?></span>
                                </div>
                            </div>

                            <!-- Core courses section -->
                            <div style="background: #f9fafb; padding: 1rem; border-radius: 8px;">
                                <h5 style="margin-bottom: 1rem; padding: 0.5rem; background: #6b7280; color: white; border-radius: 4px; font-weight: 600; font-size: 1rem;">Core courses</h5>
                                
                                <div class="table-responsive" style="background: white; border-radius: 4px; overflow-x: auto; overflow-y: visible; max-width: 100%;">
                                    <table class="table table-hover table-sm" style="margin-bottom: 0; width: 100%; font-size: 0.8rem;">
                                        <thead style="background: #f3f4f6;">
                                            <tr>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 40px; text-align: center; font-size: 0.75rem;">S/N</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 95px; white-space: nowrap; font-size: 0.75rem;">Code</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; min-width: 200px; font-size: 0.75rem;">Course name</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 85px; white-space: nowrap; font-size: 0.75rem;">Sem.</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">Yr</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">CU</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">LH</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">TH</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">PH</th>
                                                <th style="padding: 0.6rem 0.4rem; color: #374151; font-weight: 600; border-bottom: 2px solid #e5e7eb; width: 45px; text-align: center; font-size: 0.75rem;">CH</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($approvedCourses)): ?>
                                                <?php $sn = 1; foreach ($approvedCourses as $ac): ?>
                                                    <?php
                                                    // Fetch full course details to get hours
                                                    $fullCourseStmt = $conn->prepare("SELECT * FROM courses WHERE id = :id");
                                                    $fullCourseStmt->execute(['id' => $ac['course_id']]);
                                                    $fullCourse = $fullCourseStmt->fetch();
                                                    
                                                    $lh = $fullCourse['lecture_hours'] ?? 0;
                                                    $th = $fullCourse['tutorial_hours'] ?? 0;
                                                    $ph = $fullCourse['practical_hours'] ?? 0;
                                                    $ch = $lh + $th + $ph;
                                                    $semester_display = $fullCourse['semester_offered'] == 1 ? 'Sem I' : ($fullCourse['semester_offered'] == 2 ? 'Sem II' : 'Sem ' . $fullCourse['semester_offered']);
                                                    ?>
                                                    <tr style="border-bottom: 1px solid #f3f4f6;">
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo $sn++; ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; font-weight: 500; white-space: nowrap; font-size: 0.8rem;"><?php echo e($ac['course_code']); ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #374151; word-wrap: break-word; word-break: break-word; line-height: 1.3; font-size: 0.8rem;"><?php echo e($ac['course_name']); ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; white-space: nowrap; font-size: 0.8rem;"><?php echo $semester_display; ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo e($ac['level_year']); ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo e($ac['credit_hours']); ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo $lh; ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo $th; ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo $ph; ?></td>
                                                        <td style="padding: 0.6rem 0.4rem; color: #6b7280; text-align: center; font-size: 0.8rem;"><?php echo $ch; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="10" style="padding: 2rem; text-align: center; color: #6b7280;">
                                                        <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                                                        No courses have been assigned yet. Please contact administration.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>


                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>