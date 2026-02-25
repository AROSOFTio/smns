<?php
/**
 * Admin Communication Service
 * Sends institution messages to students via portal notifications and/or email.
 */
class AdminCommunicationService {
    private $conn;
    private $logger;
    const JOB_STATUS_QUEUED = 'queued';
    const JOB_STATUS_PROCESSING = 'processing';
    const JOB_STATUS_COMPLETED = 'completed';
    const JOB_STATUS_FAILED = 'failed';

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

        $this->conn->exec("CREATE TABLE IF NOT EXISTS admin_communication_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            communication_id INT NULL,
            actor_user_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message MEDIUMTEXT NOT NULL,
            options_json MEDIUMTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            attempts INT NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            result_json MEDIUMTEXT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status_created (status, created_at),
            INDEX idx_comm (communication_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function sendToStudents($actorUserId, $title, $message, $options = []) {
        // Bulk email dispatch can run for a long time; keep processing resilient
        // even if the browser disconnects while the request is in-flight.
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(0);
            if (function_exists('ignore_user_abort')) {
                @ignore_user_abort(true);
            }
        }

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

        $communicationId = isset($options['communication_id']) ? (int)$options['communication_id'] : 0;
        if ($communicationId > 0) {
            $existingStmt = $this->conn->prepare("SELECT id FROM admin_communications WHERE id = :id LIMIT 1");
            $existingStmt->execute(['id' => $communicationId]);
            if (!$existingStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('Communication queue reference not found.');
            }

            $updExisting = $this->conn->prepare("UPDATE admin_communications
                SET communication_type = :communication_type,
                    source = :source,
                    source_ref_id = :source_ref_id,
                    title = :title,
                    message = :message,
                    audience_scope = :audience_scope,
                    audience_program_id = :audience_program_id,
                    audience_level_year = :audience_level_year,
                    audience_semester_id = :audience_semester_id,
                    send_portal = :send_portal,
                    send_email = :send_email,
                    total_recipients = :total_recipients,
                    portal_success_count = 0,
                    email_success_count = 0,
                    email_fail_count = 0,
                    status = 'processing',
                    created_by_user_id = :created_by_user_id
                WHERE id = :id");
            $updExisting->execute([
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
                'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                'id' => $communicationId
            ]);

            $delRecipients = $this->conn->prepare("DELETE FROM admin_communication_recipients WHERE communication_id = :communication_id");
            $delRecipients->execute(['communication_id' => $communicationId]);
        } else {
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
        }

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
        $emailContextLabel = trim((string)($options['email_context_label'] ?? 'Student Bulk Communication'));
        if ($emailContextLabel === '') {
            $emailContextLabel = 'Student Bulk Communication';
        }
        $emailSourcePage = trim((string)($options['email_source_page'] ?? '/views/admin/communications.php'));
        if ($emailSourcePage === '') {
            $emailSourcePage = '/views/admin/communications.php';
        }

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
                            'context_label' => $emailContextLabel,
                            'source_page' => $emailSourcePage,
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

        if ($sendEmail) {
            $missingEmailOutcomes = $totalRecipients - ($emailSuccessCount + $emailFailCount);
            if ($missingEmailOutcomes > 0) {
                $emailFailCount += $missingEmailOutcomes;
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

    public function queueToStudents($actorUserId, $title, $message, $options = []) {
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

        $totalRecipients = $this->countStudentRecipients(
            $scope === 'program' ? $programId : null,
            $scope === 'level_year' ? $levelYear : null,
            $scope === 'semester' ? $semesterId : null
        );

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
        $emailContextLabel = trim((string)($options['email_context_label'] ?? 'Student Bulk Communication'));
        if ($emailContextLabel === '') {
            $emailContextLabel = 'Student Bulk Communication';
        }
        $emailSourcePage = trim((string)($options['email_source_page'] ?? '/views/admin/communications.php'));
        if ($emailSourcePage === '') {
            $emailSourcePage = '/views/admin/communications.php';
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
            'status' => self::JOB_STATUS_QUEUED,
            'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null
        ]);
        $communicationId = (int)$this->conn->lastInsertId();

        $jobOptions = [
            'communication_id' => $communicationId,
            'communication_type' => $communicationType,
            'source' => $source,
            'source_ref_id' => $sourceRefId,
            'audience_scope' => $scope,
            'program_id' => $scope === 'program' ? $programId : null,
            'level_year' => $scope === 'level_year' ? $levelYear : null,
            'semester_id' => $scope === 'semester' ? $semesterId : null,
            'send_portal' => $sendPortal,
            'send_email' => $sendEmail,
            'link' => $portalLink,
            'notification_type' => $notificationType,
            'email_context_label' => $emailContextLabel,
            'email_source_page' => $emailSourcePage
        ];
        $encodedOptions = json_encode($jobOptions);
        if (!is_string($encodedOptions) || $encodedOptions === '') {
            $encodedOptions = '{}';
        }

        $insJob = $this->conn->prepare("INSERT INTO admin_communication_jobs
            (communication_id, actor_user_id, title, message, options_json, status)
            VALUES
            (:communication_id, :actor_user_id, :title, :message, :options_json, :status)");
        $insJob->execute([
            'communication_id' => $communicationId,
            'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'title' => $title,
            'message' => $message,
            'options_json' => $encodedOptions,
            'status' => self::JOB_STATUS_QUEUED
        ]);
        $jobId = (int)$this->conn->lastInsertId();

        $workerTriggered = false;
        $autoLaunchWorker = !array_key_exists('auto_launch_worker', $options) || (bool)$options['auto_launch_worker'];
        if ($autoLaunchWorker) {
            $workerTriggered = $this->launchQueuedDeliveryWorker($jobId);
        }

        $this->logger->log(
            $actorUserId,
            'dispatch',
            'communications',
            'Queued communication #' . $communicationId
                . ' [job #' . $jobId . ']'
                . '; audience=' . $scope
                . '; recipients=' . $totalRecipients
                . '; portal=' . ($sendPortal ? 'on' : 'off')
                . '; email=' . ($sendEmail ? 'on' : 'off')
                . '; source=' . $source
        );

        return [
            'queued' => true,
            'queue_job_id' => $jobId,
            'communication_id' => $communicationId,
            'total_recipients' => $totalRecipients,
            'portal_success_count' => 0,
            'email_success_count' => 0,
            'email_fail_count' => 0,
            'status' => self::JOB_STATUS_QUEUED,
            'worker_triggered' => $workerTriggered
        ];
    }

    public function processQueuedJobs($limit = 3, $jobId = 0) {
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(0);
            if (function_exists('ignore_user_abort')) {
                @ignore_user_abort(true);
            }
        }

        $this->ensureTables();
        $limit = (int)$limit;
        if ($limit <= 0) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }
        $jobId = (int)$jobId;

        $processed = 0;
        $completed = 0;
        $failed = 0;

        while ($processed < $limit) {
            $job = $jobId > 0 ? $this->claimQueuedJobById($jobId) : $this->claimNextQueuedJob();
            if (!$job) {
                break;
            }
            $processed++;

            $claimedJobId = (int)($job['id'] ?? 0);
            $communicationId = (int)($job['communication_id'] ?? 0);

            try {
                $decodedOptions = json_decode((string)($job['options_json'] ?? ''), true);
                $sendOptions = is_array($decodedOptions) ? $decodedOptions : [];
                if ($communicationId > 0) {
                    $sendOptions['communication_id'] = $communicationId;
                }

                $result = $this->sendToStudents(
                    (int)($job['actor_user_id'] ?? 0),
                    (string)($job['title'] ?? ''),
                    (string)($job['message'] ?? ''),
                    $sendOptions
                );

                $resultJson = json_encode($result);
                if (!is_string($resultJson) || $resultJson === '') {
                    $resultJson = '{}';
                }
                $resolvedCommunicationId = (int)($result['communication_id'] ?? $communicationId);

                $updJob = $this->conn->prepare("UPDATE admin_communication_jobs
                    SET status = :status,
                        communication_id = :communication_id,
                        result_json = :result_json,
                        error_message = NULL,
                        finished_at = NOW()
                    WHERE id = :id");
                $updJob->execute([
                    'status' => self::JOB_STATUS_COMPLETED,
                    'communication_id' => $resolvedCommunicationId > 0 ? $resolvedCommunicationId : null,
                    'result_json' => $resultJson,
                    'id' => $claimedJobId
                ]);
                $completed++;
            } catch (Throwable $e) {
                $err = $this->truncateText((string)$e->getMessage(), 1900);
                $updJob = $this->conn->prepare("UPDATE admin_communication_jobs
                    SET status = :status,
                        error_message = :error_message,
                        finished_at = NOW()
                    WHERE id = :id");
                $updJob->execute([
                    'status' => self::JOB_STATUS_FAILED,
                    'error_message' => $err,
                    'id' => $claimedJobId
                ]);
                if ($communicationId > 0) {
                    $this->markCommunicationFailedIfPending($communicationId);
                }
                $failed++;
            }

            if ($jobId > 0) {
                break;
            }
        }

        return [
            'processed' => $processed,
            'completed' => $completed,
            'failed' => $failed
        ];
    }

    public function launchQueuedDeliveryWorker($jobId = 0) {
        $scriptPath = BASE_PATH . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'process_admin_communications.php';
        if (!is_file($scriptPath)) {
            return false;
        }

        $jobId = (int)$jobId;
        $phpBinary = function_exists('resolvePhpExecBinary') ? resolvePhpExecBinary(true) : '';
        if (!is_string($phpBinary) || trim($phpBinary) === '') {
            $phpBinary = defined('PHP_BINARY') ? (string)PHP_BINARY : 'php';
        }

        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath);
        if ($jobId > 0) {
            $command .= ' --job-id=' . $jobId . ' --max-jobs=1';
        } else {
            $command .= ' --max-jobs=5';
        }

        try {
            if (DIRECTORY_SEPARATOR === '\\') {
                $handle = @popen('start "" /B ' . $command, 'r');
                if (is_resource($handle)) {
                    @pclose($handle);
                    return true;
                }
                return false;
            }

            @exec($command . ' > /dev/null 2>&1 &');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function claimQueuedJobById($jobId) {
        $jobId = (int)$jobId;
        if ($jobId <= 0) {
            return null;
        }

        $upd = $this->conn->prepare("UPDATE admin_communication_jobs
            SET status = :processing,
                started_at = COALESCE(started_at, NOW()),
                attempts = attempts + 1,
                error_message = NULL
            WHERE id = :id AND status = :queued");
        $upd->execute([
            'processing' => self::JOB_STATUS_PROCESSING,
            'id' => $jobId,
            'queued' => self::JOB_STATUS_QUEUED
        ]);
        if ((int)$upd->rowCount() <= 0) {
            return null;
        }

        $stmt = $this->conn->prepare("SELECT * FROM admin_communication_jobs WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function claimNextQueuedJob() {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $stmt = $this->conn->query("SELECT id FROM admin_communication_jobs
                WHERE status = '" . self::JOB_STATUS_QUEUED . "'
                ORDER BY created_at ASC, id ASC
                LIMIT 1");
            $nextId = (int)($stmt ? $stmt->fetchColumn() : 0);
            if ($nextId <= 0) {
                return null;
            }

            $claimed = $this->claimQueuedJobById($nextId);
            if ($claimed) {
                return $claimed;
            }
        }
        return null;
    }

    private function markCommunicationFailedIfPending($communicationId) {
        $communicationId = (int)$communicationId;
        if ($communicationId <= 0) {
            return;
        }
        $upd = $this->conn->prepare("UPDATE admin_communications
            SET status = 'failed'
            WHERE id = :id AND status IN ('queued', 'processing')");
        $upd->execute(['id' => $communicationId]);
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
            'link' => BASE_URL . '/views/student/academic-calendar.php',
            'email_source_page' => (string)($options['email_source_page'] ?? '/views/admin/academic-calendar.php')
        ]);

        if (!empty($options['queue_delivery'])) {
            return $this->queueToStudents($actorUserId, $title, $message, $sendOptions);
        }

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

    private function countStudentRecipients($programId = null, $levelYear = null, $semesterId = null) {
        $sql = "SELECT COUNT(DISTINCT u.id)
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

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
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

    private function truncateText($value, $limit = 1000) {
        $limit = (int)$limit;
        if ($limit <= 0) {
            return '';
        }
        $text = (string)$value;
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit);
        }
        return substr($text, 0, $limit);
    }
}
