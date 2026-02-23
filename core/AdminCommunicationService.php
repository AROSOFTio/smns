<?php
/**
 * Admin Communication Service
 * Sends institution messages to students via portal notifications and/or email.
 */
class AdminCommunicationService {
    private $conn;
    private $logger;

    public function __construct($connection = null, $logger = null) {
        if ($connection instanceof PDO) {
            $this->conn = $connection;
        } else {
            $db = new Database();
            $this->conn = $db->getConnection();
        }

        $this->logger = $logger instanceof Logger ? $logger : new Logger();
    }

    public function ensureTables() {
        $this->conn->exec("CREATE TABLE IF NOT EXISTS admin_communications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            communication_type VARCHAR(50) NOT NULL DEFAULT 'general',
            source VARCHAR(40) NOT NULL DEFAULT 'manual',
            source_ref_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            audience_scope VARCHAR(40) NOT NULL DEFAULT 'all_students',
            audience_program_id INT NULL,
            audience_level_year INT NULL,
            audience_semester_id INT NULL,
            send_portal TINYINT(1) NOT NULL DEFAULT 1,
            send_email TINYINT(1) NOT NULL DEFAULT 0,
            total_recipients INT NOT NULL DEFAULT 0,
            portal_success_count INT NOT NULL DEFAULT 0,
            email_success_count INT NOT NULL DEFAULT 0,
            email_fail_count INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'completed',
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_created_at (created_at),
            INDEX idx_created_by (created_by_user_id),
            INDEX idx_type (communication_type),
            INDEX idx_source (source)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->conn->exec("CREATE TABLE IF NOT EXISTS admin_communication_recipients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            communication_id INT NOT NULL,
            user_id INT NOT NULL,
            student_id INT NULL,
            recipient_email VARCHAR(180) NULL,
            portal_notified TINYINT(1) NOT NULL DEFAULT 0,
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            email_error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comm_user (communication_id, user_id),
            INDEX idx_comm (communication_id),
            INDEX idx_user (user_id),
            INDEX idx_email_sent (email_sent)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function sendToStudents($actorUserId, $title, $message, $options = []) {
        $this->ensureTables();

        $actorUserId = (int)$actorUserId;
        $title = trim((string)$title);
        $message = trim((string)$message);
        if ($title === '' || $message === '') {
            throw new Exception('Title and message are required.');
        }

        $sendPortal = !array_key_exists('send_portal', $options) || (bool)$options['send_portal'];
        $sendEmail = !empty($options['send_email']);
        if (!$sendPortal && !$sendEmail) {
            throw new Exception('Select at least one delivery channel.');
        }

        $scope = (string)($options['audience_scope'] ?? 'all_students');
        $allowedScopes = ['all_students', 'program', 'level_year', 'semester'];
        if (!in_array($scope, $allowedScopes, true)) {
            $scope = 'all_students';
        }

        $programId = isset($options['program_id']) ? (int)$options['program_id'] : null;
        $levelYear = isset($options['level_year']) ? (int)$options['level_year'] : null;
        $semesterId = isset($options['semester_id']) ? (int)$options['semester_id'] : null;
        if ($programId !== null && $programId <= 0) {
            $programId = null;
        }
        if ($levelYear !== null && $levelYear <= 0) {
            $levelYear = null;
        }
        if ($semesterId !== null && $semesterId <= 0) {
            $semesterId = null;
        }

        if ($scope === 'program' && $programId === null) {
            throw new Exception('Program is required for program-based audience.');
        }
        if ($scope === 'level_year' && $levelYear === null) {
            throw new Exception('Level year is required for year-based audience.');
        }
        if ($scope === 'semester' && $semesterId === null) {
            throw new Exception('Semester is required for semester-based audience.');
        }

        $recipients = $this->fetchStudentRecipients(
            $scope === 'program' ? $programId : null,
            $scope === 'level_year' ? $levelYear : null,
            $scope === 'semester' ? $semesterId : null
        );

        $totalRecipients = count($recipients);
        $communicationType = trim((string)($options['communication_type'] ?? 'general'));
        if ($communicationType === '') {
            $communicationType = 'general';
        }
        $source = trim((string)($options['source'] ?? 'manual'));
        if ($source === '') {
            $source = 'manual';
        }
        $sourceRefId = isset($options['source_ref_id']) ? (int)$options['source_ref_id'] : null;
        if ($sourceRefId !== null && $sourceRefId <= 0) {
            $sourceRefId = null;
        }
        $portalLink = trim((string)($options['link'] ?? (BASE_URL . '/views/student/notifications.php')));
        if ($portalLink === '') {
            $portalLink = null;
        }
        $notificationType = trim((string)($options['notification_type'] ?? 'info'));
        if (!in_array($notificationType, ['info', 'success', 'warning', 'error'], true)) {
            $notificationType = 'info';
        }

        $insComm = $this->conn->prepare("INSERT INTO admin_communications
            (communication_type, source, source_ref_id, title, message, audience_scope, audience_program_id, audience_level_year, audience_semester_id, send_portal, send_email, total_recipients, status, created_by_user_id)
            VALUES
            (:communication_type, :source, :source_ref_id, :title, :message, :audience_scope, :audience_program_id, :audience_level_year, :audience_semester_id, :send_portal, :send_email, :total_recipients, :status, :created_by_user_id)");
        $insComm->execute([
            'communication_type' => $communicationType,
            'source' => $source,
            'source_ref_id' => $sourceRefId,
            'title' => $title,
            'message' => $message,
            'audience_scope' => $scope,
            'audience_program_id' => $scope === 'program' ? $programId : null,
            'audience_level_year' => $scope === 'level_year' ? $levelYear : null,
            'audience_semester_id' => $scope === 'semester' ? $semesterId : null,
            'send_portal' => $sendPortal ? 1 : 0,
            'send_email' => $sendEmail ? 1 : 0,
            'total_recipients' => $totalRecipients,
            'status' => 'processing',
            'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null
        ]);
        $communicationId = (int)$this->conn->lastInsertId();

        $this->logger->log(
            $actorUserId,
            'dispatch',
            'communications',
            'Started communication #' . $communicationId
                . ' [' . $communicationType . ']'
                . '; audience=' . $scope
                . '; recipients=' . $totalRecipients
                . '; portal=' . ($sendPortal ? 'on' : 'off')
                . '; email=' . ($sendEmail ? 'on' : 'off')
                . '; source=' . $source
        );

        $portalSuccessCount = 0;
        $emailSuccessCount = 0;
        $emailFailCount = 0;

        $insRecipient = $this->conn->prepare("INSERT INTO admin_communication_recipients
            (communication_id, user_id, student_id, recipient_email, portal_notified, email_sent, email_error)
            VALUES
            (:communication_id, :user_id, :student_id, :recipient_email, :portal_notified, :email_sent, :email_error)");

        $insNotification = null;
        if ($sendPortal) {
            $insNotification = $this->conn->prepare("INSERT INTO notifications
                (user_id, title, message, type, link, created_at)
                VALUES
                (:user_id, :title, :message, :type, :link, NOW())");
        }

        $mailSubjectPrefix = $this->buildEmailPrefix($communicationType);
        $mailSubject = APP_NAME . ' - ' . $mailSubjectPrefix . ': ' . $title;
        $mailBody = $this->buildEmailBody($title, $message, $communicationType);

        foreach ($recipients as $recipient) {
            $userId = (int)($recipient['user_id'] ?? 0);
            $studentId = (int)($recipient['student_id'] ?? 0);
            $email = trim((string)($recipient['email'] ?? ''));

            if ($userId <= 0) {
                continue;
            }

            $portalNotified = 0;
            $emailSent = 0;
            $emailError = '';

            if ($sendPortal && $insNotification) {
                try {
                    $insNotification->execute([
                        'user_id' => $userId,
                        'title' => $title,
                        'message' => $message,
                        'type' => $notificationType,
                        'link' => $portalLink
                    ]);
                    $portalNotified = 1;
                    $portalSuccessCount++;
                } catch (Exception $portalEx) {
                    $emailError = 'Portal notification failed: ' . $portalEx->getMessage();
                }
            }

            if ($sendEmail) {
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $mailOk = Helper::sendEmail(
                        [$email],
                        $mailSubject,
                        $mailBody,
                        [
                            'context_label' => 'Student Bulk Communication',
                            'source_page' => '/views/admin/communications.php',
                            'retry_attempts' => 1,
                            'allow_php_fallback' => false
                        ]
                    );
                    if ($mailOk) {
                        $emailSent = 1;
                        $emailSuccessCount++;
                    } else {
                        $emailFailCount++;
                        $mailErr = trim((string)Helper::getLastEmailError());
                        $emailError = trim(($emailError !== '' ? $emailError . ' | ' : '') . ($mailErr !== '' ? $mailErr : 'Email delivery failed.'));
                    }
                } else {
                    $emailFailCount++;
                    $emailError = trim(($emailError !== '' ? $emailError . ' | ' : '') . 'Recipient email missing or invalid.');
                }
            }

            try {
                $insRecipient->execute([
                    'communication_id' => $communicationId,
                    'user_id' => $userId,
                    'student_id' => $studentId > 0 ? $studentId : null,
                    'recipient_email' => $email !== '' ? $email : null,
                    'portal_notified' => $portalNotified,
                    'email_sent' => $emailSent,
                    'email_error' => $emailError !== '' ? $emailError : null
                ]);
            } catch (Exception $e) {
                // Keep sending to the rest; this row is only for tracking.
            }
        }

        $status = 'completed';
        if ($sendEmail && $emailFailCount > 0 && $emailSuccessCount === 0) {
            $status = 'failed';
        } elseif (($sendEmail && $emailFailCount > 0) || ($sendPortal && $portalSuccessCount < $totalRecipients)) {
            $status = 'partial';
        }

        $updComm = $this->conn->prepare("UPDATE admin_communications
            SET portal_success_count = :portal_success_count,
                email_success_count = :email_success_count,
                email_fail_count = :email_fail_count,
                status = :status
            WHERE id = :id");
        $updComm->execute([
            'portal_success_count' => $portalSuccessCount,
            'email_success_count' => $emailSuccessCount,
            'email_fail_count' => $emailFailCount,
            'status' => $status,
            'id' => $communicationId
        ]);

        $this->logger->log(
            $actorUserId,
            'create',
            'communications',
            'Sent communication #' . $communicationId
                . ' [' . $communicationType . ']'
                . '; audience=' . $scope
                . '; recipients=' . $totalRecipients
                . '; portal=' . $portalSuccessCount
                . '; email_success=' . $emailSuccessCount
                . '; email_fail=' . $emailFailCount
                . '; source=' . $source
        );

        return [
            'communication_id' => $communicationId,
            'total_recipients' => $totalRecipients,
            'portal_success_count' => $portalSuccessCount,
            'email_success_count' => $emailSuccessCount,
            'email_fail_count' => $emailFailCount,
            'status' => $status
        ];
    }

    public function sendAnnouncementToStudents($actorUserId, $announcement, $options = []) {
        $targetAudience = strtolower(trim((string)($announcement['target_audience'] ?? 'all')));
        if (!in_array($targetAudience, ['all', 'students'], true)) {
            return [
                'skipped' => true,
                'reason' => 'Announcement audience is not student-facing.'
            ];
        }

        $title = trim((string)($announcement['title'] ?? 'Academic Announcement'));
        $content = trim((string)($announcement['content'] ?? ''));
        $startDate = trim((string)($announcement['start_date'] ?? ''));
        $endDate = trim((string)($announcement['end_date'] ?? ''));
        $periodLabel = '';
        if ($startDate !== '' || $endDate !== '') {
            $periodLabel = "\n\nAnnouncement Period: "
                . ($startDate !== '' ? $startDate : '-')
                . ' to '
                . ($endDate !== '' ? $endDate : '-');
        }

        $message = $content . $periodLabel;
        $sendOptions = array_merge($options, [
            'communication_type' => (string)($options['communication_type'] ?? 'institution_announcement'),
            'source' => (string)($options['source'] ?? 'calendar_announcement'),
            'source_ref_id' => (int)($announcement['id'] ?? 0),
            'audience_scope' => 'all_students',
            'link' => BASE_URL . '/views/student/academic-calendar.php'
        ]);

        return $this->sendToStudents($actorUserId, $title, $message, $sendOptions);
    }

    public function getCommunications($limit = 100) {
        $this->ensureTables();
        $limit = (int)$limit;
        if ($limit <= 0) {
            $limit = 100;
        }

        $sql = "SELECT
                    c.*,
                    u.username,
                    u.role AS user_role,
                    COALESCE(
                        NULLIF(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), ''),
                        NULLIF(TRIM(CONCAT_WS(' ', l.first_name, l.last_name)), ''),
                        NULLIF(TRIM(CONCAT_WS(' ', f.first_name, f.last_name)), ''),
                        NULLIF(TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)), ''),
                        u.username
                    ) AS sender_name
                FROM admin_communications c
                LEFT JOIN users u ON c.created_by_user_id = u.id
                LEFT JOIN admins a ON u.id = a.user_id
                LEFT JOIN lecturers l ON u.id = l.user_id
                LEFT JOIN finance_staff f ON u.id = f.user_id
                LEFT JOIN students s ON u.id = s.user_id
                ORDER BY c.created_at DESC
                LIMIT :limit";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getCommunicationRecipients($communicationId, $limit = 800) {
        $this->ensureTables();
        $communicationId = (int)$communicationId;
        if ($communicationId <= 0) {
            return [];
        }
        $limit = (int)$limit;
        if ($limit <= 0) {
            $limit = 800;
        }

        $sql = "SELECT
                    r.*,
                    u.username,
                    s.student_id AS registration_number,
                    COALESCE(
                        NULLIF(TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)), ''),
                        u.username
                    ) AS recipient_name
                FROM admin_communication_recipients r
                LEFT JOIN users u ON r.user_id = u.id
                LEFT JOIN students s ON r.student_id = s.id
                WHERE r.communication_id = :communication_id
                ORDER BY r.id DESC
                LIMIT :limit";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':communication_id', $communicationId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchStudentRecipients($programId = null, $levelYear = null, $semesterId = null) {
        $sql = "SELECT DISTINCT
                    u.id AS user_id,
                    s.id AS student_id,
                    COALESCE(NULLIF(TRIM(s.email), ''), NULLIF(TRIM(u.email), '')) AS email
                FROM users u
                INNER JOIN students s ON s.user_id = u.id";
        $params = [];

        if ($semesterId !== null) {
            $sql .= " INNER JOIN semester_registrations sr
                        ON sr.student_id = s.id
                       AND sr.semester_id = :semester_id
                       AND sr.status = 'approved'";
            $params['semester_id'] = (int)$semesterId;
        }

        $sql .= " WHERE u.role = 'student'
                    AND u.status = 'active'
                    AND s.status = 'active'";

        if ($programId !== null) {
            $sql .= " AND s.program_id = :program_id";
            $params['program_id'] = (int)$programId;
        }

        if ($levelYear !== null) {
            $sql .= " AND s.level_year = :level_year";
            $params['level_year'] = (int)$levelYear;
        }

        $sql .= " ORDER BY s.first_name ASC, s.last_name ASC, s.id ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function buildEmailPrefix($communicationType) {
        $map = [
            'institution_announcement' => 'Institution Announcement',
            'exam_timetable' => 'Examination Timetable',
            'public_holiday' => 'Public Holiday Notice',
            'campus_event' => 'Campus Event',
            'academic_announcement' => 'Academic Announcement',
            'general' => 'Student Communication'
        ];
        return $map[$communicationType] ?? 'Student Communication';
    }

    private function buildEmailBody($title, $message, $communicationType) {
        $prefix = $this->buildEmailPrefix($communicationType);
        $footer = "\n\nRegards,\n" . (defined('APP_NAME') ? APP_NAME : 'Institution') . " Administration";
        return $prefix . "\n\n" . $title . "\n\n" . $message . $footer;
    }
}
