<?php
/**
 * Student <-> Finance threaded messaging linked to PRN/transaction context.
 */
class FinanceMessagingService {
    private $conn;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
    }

    public function ensureSchema() {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS finance_messages (
                id INT(11) NOT NULL AUTO_INCREMENT,
                thread_key VARCHAR(120) NOT NULL,
                student_id INT(11) NOT NULL,
                prn_reference VARCHAR(64) DEFAULT NULL,
                transaction_ref VARCHAR(64) DEFAULT NULL,
                sender_role ENUM('student','finance') NOT NULL,
                sender_user_id INT(11) NOT NULL,
                recipient_user_id INT(11) DEFAULT NULL,
                message_text TEXT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_fm_thread_created (thread_key, id),
                KEY idx_fm_student_thread (student_id, thread_key),
                KEY idx_fm_sender (sender_user_id),
                KEY idx_fm_recipient_read (recipient_user_id, is_read)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function resolveThreadKey($studentId, $prnReference = '', $transactionRef = '') {
        $studentId = (int)$studentId;
        $prn = $this->normalizeReference($prnReference);
        $tx = $this->normalizeReference($transactionRef);
        if ($prn !== '') {
            return 'PRN:' . $prn;
        }
        if ($tx !== '') {
            return 'TX:' . $tx;
        }
        return 'STU:' . max(0, $studentId);
    }

    public function sendStudentMessage($studentUserId, $studentId, $prnReference, $transactionRef, $messageText) {
        $studentUserId = (int)$studentUserId;
        $studentId = (int)$studentId;
        $messageText = trim((string)$messageText);
        if ($studentUserId <= 0 || $studentId <= 0) {
            return ['success' => false, 'message' => 'Student profile is missing.'];
        }
        if ($messageText === '') {
            return ['success' => false, 'message' => 'Message cannot be empty.'];
        }

        $prn = $this->normalizeReference($prnReference);
        $tx = $this->normalizeReference($transactionRef);
        $threadKey = $this->resolveThreadKey($studentId, $prn, $tx);

        $inserted = $this->insertMessage(
            $threadKey,
            $studentId,
            $prn !== '' ? $prn : null,
            $tx !== '' ? $tx : null,
            'student',
            $studentUserId,
            null,
            $messageText
        );
        if (empty($inserted['success'])) {
            return $inserted;
        }

        $studentLabel = $this->resolveStudentLabel($studentId);
        $contextLabel = $prn !== '' ? ('PRN ' . $prn) : ($tx !== '' ? ('TX ' . $tx) : ('Student #' . $studentId));
        $this->notifyFinanceUsers(
            'New Student Message',
            $studentLabel . ' sent a message for ' . $contextLabel . '.',
            BASE_URL . '/views/finance/dashboard.php?section=messages&msg_student_id=' . $studentId .
                '&msg_prn=' . urlencode($prn) . '&msg_tx=' . urlencode($tx) . '#finance-messages-section'
        );

        return [
            'success' => true,
            'message' => 'Message sent to finance.',
            'thread_key' => $threadKey
        ];
    }

    public function sendFinanceReply($financeUserId, $studentId, $prnReference, $transactionRef, $messageText) {
        $financeUserId = (int)$financeUserId;
        $studentId = (int)$studentId;
        $messageText = trim((string)$messageText);
        if ($financeUserId <= 0) {
            return ['success' => false, 'message' => 'Finance account is missing.'];
        }
        if ($studentId <= 0) {
            return ['success' => false, 'message' => 'Student context is required.'];
        }
        if ($messageText === '') {
            return ['success' => false, 'message' => 'Reply cannot be empty.'];
        }

        $studentUserId = $this->resolveStudentUserId($studentId);
        if ($studentUserId <= 0) {
            return ['success' => false, 'message' => 'Student account was not found.'];
        }

        $prn = $this->normalizeReference($prnReference);
        $tx = $this->normalizeReference($transactionRef);
        $threadKey = $this->resolveThreadKey($studentId, $prn, $tx);

        $inserted = $this->insertMessage(
            $threadKey,
            $studentId,
            $prn !== '' ? $prn : null,
            $tx !== '' ? $tx : null,
            'finance',
            $financeUserId,
            $studentUserId,
            $messageText
        );
        if (empty($inserted['success'])) {
            return $inserted;
        }

        $contextLabel = $prn !== '' ? ('PRN ' . $prn) : ($tx !== '' ? ('TX ' . $tx) : 'your account');
        $studentLink = BASE_URL . '/views/student/payments.php?section=transactions&tx_tab=check_prn';
        if ($prn !== '') {
            $studentLink .= '&prn_ref=' . urlencode($prn);
        }
        if ($tx !== '') {
            $studentLink .= '&tx_ref=' . urlencode($tx);
        }
        $this->notifyUser(
            $studentUserId,
            'Finance Reply',
            'Finance replied to your message for ' . $contextLabel . '.',
            'info',
            $studentLink
        );

        return [
            'success' => true,
            'message' => 'Reply sent to student.',
            'thread_key' => $threadKey
        ];
    }

    public function getStudentThreadMessages($studentId, $prnReference = '', $transactionRef = '', $limit = 50) {
        $studentId = (int)$studentId;
        if ($studentId <= 0) {
            return [];
        }
        $threadKey = $this->resolveThreadKey($studentId, $prnReference, $transactionRef);
        $limit = max(1, min(200, (int)$limit));

        $stmt = $this->conn->prepare("
            SELECT
                fm.id,
                fm.thread_key,
                fm.student_id,
                fm.prn_reference,
                fm.transaction_ref,
                fm.sender_role,
                fm.sender_user_id,
                fm.message_text,
                fm.is_read,
                fm.created_at
            FROM finance_messages fm
            WHERE fm.student_id = :student_id
              AND fm.thread_key = :thread_key
            ORDER BY fm.id ASC
            LIMIT {$limit}
        ");
        $stmt->execute([
            'student_id' => $studentId,
            'thread_key' => $threadKey
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Mark finance replies as read for this student thread view.
        $update = $this->conn->prepare("
            UPDATE finance_messages
            SET is_read = 1
            WHERE student_id = :student_id
              AND thread_key = :thread_key
              AND sender_role = 'finance'
              AND is_read = 0
        ");
        $update->execute([
            'student_id' => $studentId,
            'thread_key' => $threadKey
        ]);

        return $rows;
    }

    public function getFinanceThreadSummaries($limit = 60) {
        $limit = max(1, min(300, (int)$limit));
        $stmt = $this->conn->query("
            SELECT
                latest.thread_key,
                latest.student_id,
                latest.prn_reference,
                latest.transaction_ref,
                latest.message_text AS last_message,
                latest.sender_role AS last_sender_role,
                latest.created_at AS last_message_at,
                s.student_id AS registration_number,
                s.first_name,
                s.last_name,
                COALESCE(stats.unread_for_finance, 0) AS unread_for_finance,
                COALESCE(stats.message_count, 0) AS message_count
            FROM finance_messages latest
            INNER JOIN (
                SELECT thread_key, MAX(id) AS latest_id
                FROM finance_messages
                GROUP BY thread_key
            ) latest_map ON latest_map.latest_id = latest.id
            INNER JOIN students s ON s.id = latest.student_id
            LEFT JOIN (
                SELECT
                    thread_key,
                    SUM(CASE WHEN sender_role = 'student' AND is_read = 0 THEN 1 ELSE 0 END) AS unread_for_finance,
                    COUNT(*) AS message_count
                FROM finance_messages
                GROUP BY thread_key
            ) stats ON stats.thread_key = latest.thread_key
            ORDER BY latest.id DESC
            LIMIT {$limit}
        ");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function getFinanceThreadMessages($studentId, $prnReference = '', $transactionRef = '', $limit = 80) {
        $studentId = (int)$studentId;
        if ($studentId <= 0) {
            return [];
        }
        $threadKey = $this->resolveThreadKey($studentId, $prnReference, $transactionRef);
        $limit = max(1, min(300, (int)$limit));

        $stmt = $this->conn->prepare("
            SELECT
                fm.id,
                fm.thread_key,
                fm.student_id,
                fm.prn_reference,
                fm.transaction_ref,
                fm.sender_role,
                fm.sender_user_id,
                fm.message_text,
                fm.is_read,
                fm.created_at
            FROM finance_messages fm
            WHERE fm.student_id = :student_id
              AND fm.thread_key = :thread_key
            ORDER BY fm.id ASC
            LIMIT {$limit}
        ");
        $stmt->execute([
            'student_id' => $studentId,
            'thread_key' => $threadKey
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Mark student messages as read once finance opens the thread.
        $update = $this->conn->prepare("
            UPDATE finance_messages
            SET is_read = 1
            WHERE student_id = :student_id
              AND thread_key = :thread_key
              AND sender_role = 'student'
              AND is_read = 0
        ");
        $update->execute([
            'student_id' => $studentId,
            'thread_key' => $threadKey
        ]);

        return $rows;
    }

    private function insertMessage($threadKey, $studentId, $prnReference, $transactionRef, $senderRole, $senderUserId, $recipientUserId, $messageText) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO finance_messages (
                    thread_key,
                    student_id,
                    prn_reference,
                    transaction_ref,
                    sender_role,
                    sender_user_id,
                    recipient_user_id,
                    message_text,
                    is_read,
                    created_at
                ) VALUES (
                    :thread_key,
                    :student_id,
                    :prn_reference,
                    :transaction_ref,
                    :sender_role,
                    :sender_user_id,
                    :recipient_user_id,
                    :message_text,
                    0,
                    NOW()
                )
            ");
            $stmt->execute([
                'thread_key' => (string)$threadKey,
                'student_id' => (int)$studentId,
                'prn_reference' => $prnReference,
                'transaction_ref' => $transactionRef,
                'sender_role' => (string)$senderRole,
                'sender_user_id' => (int)$senderUserId,
                'recipient_user_id' => $recipientUserId !== null ? (int)$recipientUserId : null,
                'message_text' => (string)$messageText
            ]);
            return ['success' => true, 'id' => (int)$this->conn->lastInsertId()];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Unable to save message right now.'];
        }
    }

    private function notifyFinanceUsers($title, $message, $link = null) {
        try {
            $stmt = $this->conn->query("
                SELECT id
                FROM users
                WHERE role = 'finance'
                  AND COALESCE(status, 'active') = 'active'
            ");
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($rows as $row) {
                $uid = (int)($row['id'] ?? 0);
                if ($uid > 0) {
                    $this->notifyUser($uid, $title, $message, 'info', $link);
                }
            }
        } catch (Exception $e) {
        }
    }

    private function notifyUser($userId, $title, $message, $type = 'info', $link = null) {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO notifications (user_id, title, message, type, read_status, link, created_at)
                VALUES (:user_id, :title, :message, :type, 'unread', :link, NOW())
            ");
            $stmt->execute([
                'user_id' => $userId,
                'title' => (string)$title,
                'message' => (string)$message,
                'type' => (string)$type,
                'link' => $link
            ]);
        } catch (Exception $e) {
        }
    }

    private function resolveStudentUserId($studentId) {
        $studentId = (int)$studentId;
        if ($studentId <= 0) {
            return 0;
        }
        try {
            $stmt = $this->conn->prepare("SELECT user_id FROM students WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $studentId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function resolveStudentLabel($studentId) {
        $studentId = (int)$studentId;
        if ($studentId <= 0) {
            return 'Student';
        }
        try {
            $stmt = $this->conn->prepare("
                SELECT student_id, first_name, last_name
                FROM students
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $studentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (empty($row)) {
                return 'Student #' . $studentId;
            }
            $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
            $sid = trim((string)($row['student_id'] ?? ''));
            if ($name !== '' && $sid !== '') {
                return $name . ' (' . $sid . ')';
            }
            if ($name !== '') {
                return $name;
            }
            if ($sid !== '') {
                return $sid;
            }
            return 'Student #' . $studentId;
        } catch (Exception $e) {
            return 'Student #' . $studentId;
        }
    }

    private function normalizeReference($value) {
        return strtoupper(trim((string)$value));
    }
}
