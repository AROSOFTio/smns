<?php
/**
 * Immutable transcript issuance records with append-only ledger chaining.
 */
class TranscriptIssuanceService {
    private $conn;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
    }

    public function ensureSchema(): void
    {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS transcript_issuances (
                id INT PRIMARY KEY AUTO_INCREMENT,
                student_id INT NOT NULL,
                transcript_hash CHAR(64) NOT NULL,
                verification_code VARCHAR(24) NOT NULL,
                verification_token CHAR(32) NOT NULL,
                status ENUM('active','revoked') NOT NULL DEFAULT 'active',
                export_format VARCHAR(16) NOT NULL DEFAULT 'xml',
                source_channel VARCHAR(32) NOT NULL DEFAULT 'student_export',
                issued_by_user_id INT NULL,
                issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                snapshot_json MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_transcript_issuance_token (verification_token),
                KEY idx_transcript_issuance_student (student_id),
                KEY idx_transcript_issuance_hash (transcript_hash),
                KEY idx_transcript_issuance_status (status),
                KEY idx_transcript_issuance_issued_at (issued_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $columnsStmt = $this->conn->query("SHOW COLUMNS FROM transcript_issuances");
        $existingColumns = [];
        foreach (($columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $column) {
            $existingColumns[(string)($column['Field'] ?? '')] = true;
        }
        if (!isset($existingColumns['distribution_status'])) {
            $this->conn->exec("ALTER TABLE transcript_issuances ADD COLUMN distribution_status ENUM('to_be_distributed','dispatched','distributed') NOT NULL DEFAULT 'to_be_distributed' AFTER status");
        }
        if (!isset($existingColumns['dispatch_method'])) {
            $this->conn->exec("ALTER TABLE transcript_issuances ADD COLUMN dispatch_method VARCHAR(32) NULL AFTER distribution_status");
        }
        if (!isset($existingColumns['dispatch_reference'])) {
            $this->conn->exec("ALTER TABLE transcript_issuances ADD COLUMN dispatch_reference VARCHAR(120) NULL AFTER dispatch_method");
        }
        if (!isset($existingColumns['dispatch_notes'])) {
            $this->conn->exec("ALTER TABLE transcript_issuances ADD COLUMN dispatch_notes TEXT NULL AFTER dispatch_reference");
        }
        if (!isset($existingColumns['dispatched_by_user_id'])) {
            $this->conn->exec("ALTER TABLE transcript_issuances ADD COLUMN dispatched_by_user_id INT NULL AFTER dispatch_notes");
        }
        if (!isset($existingColumns['dispatched_at'])) {
            $this->conn->exec("ALTER TABLE transcript_issuances ADD COLUMN dispatched_at DATETIME NULL AFTER dispatched_by_user_id");
        }
        if (!isset($existingColumns['distributed_by_user_id'])) {
            $this->conn->exec("ALTER TABLE transcript_issuances ADD COLUMN distributed_by_user_id INT NULL AFTER dispatched_at");
        }
        if (!isset($existingColumns['distributed_at'])) {
            $this->conn->exec("ALTER TABLE transcript_issuances ADD COLUMN distributed_at DATETIME NULL AFTER distributed_by_user_id");
        }

        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS transcript_issuance_ledger (
                id INT PRIMARY KEY AUTO_INCREMENT,
                issuance_id INT NOT NULL,
                event_type VARCHAR(32) NOT NULL,
                previous_entry_hash CHAR(64) NULL,
                entry_hash CHAR(64) NOT NULL,
                payload_json MEDIUMTEXT NOT NULL,
                created_by_user_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_transcript_ledger_issuance (issuance_id),
                KEY idx_transcript_ledger_created (created_at),
                KEY idx_transcript_ledger_hash (entry_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function issueTranscript(int $studentId, array $snapshot, string $format = 'xml', ?int $actorUserId = null, string $sourceChannel = 'student_export'): array
    {
        $studentId = (int)$studentId;
        if ($studentId <= 0) {
            throw new InvalidArgumentException('Valid student ID is required for transcript issuance.');
        }

        $snapshotJson = $this->encodeSnapshot($snapshot);
        $hash = hash('sha256', $snapshotJson);
        $token = bin2hex(random_bytes(16));
        $verificationCode = strtoupper(substr($hash, 0, 16));
        $format = strtolower(trim($format));
        if ($format === '') {
            $format = 'xml';
        }
        $sourceChannel = trim($sourceChannel);
        if ($sourceChannel === '') {
            $sourceChannel = 'student_export';
        }

        $stmt = $this->conn->prepare("
            INSERT INTO transcript_issuances
                (student_id, transcript_hash, verification_code, verification_token, status, export_format, source_channel, issued_by_user_id, issued_at, snapshot_json, created_at)
            VALUES
                (:student_id, :transcript_hash, :verification_code, :verification_token, 'active', :export_format, :source_channel, :issued_by_user_id, NOW(), :snapshot_json, NOW())
        ");
        $stmt->execute([
            'student_id' => $studentId,
            'transcript_hash' => $hash,
            'verification_code' => $verificationCode,
            'verification_token' => $token,
            'export_format' => $format,
            'source_channel' => $sourceChannel,
            'issued_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'snapshot_json' => $snapshotJson,
        ]);

        $issuanceId = (int)$this->conn->lastInsertId();
        $this->appendLedgerEntry($issuanceId, 'issued', [
            'student_id' => $studentId,
            'transcript_hash' => $hash,
            'verification_code' => $verificationCode,
            'verification_token' => $token,
            'export_format' => $format,
            'source_channel' => $sourceChannel,
        ], $actorUserId);

        return $this->getIssuanceByToken($token) ?: [];
    }

    public function getIssuanceByToken(string $token): ?array
    {
        $token = strtolower(trim($token));
        if ($token === '') {
            return null;
        }

        $stmt = $this->conn->prepare("
            SELECT ti.*,
                   s.student_id AS student_identifier,
                   s.first_name,
                   s.last_name,
                   p.program_code,
                   p.program_name
            FROM transcript_issuances ti
            INNER JOIN students s ON s.id = ti.student_id
            LEFT JOIN programs p ON p.id = s.program_id
            WHERE ti.verification_token = :verification_token
            LIMIT 1
        ");
        $stmt->execute(['verification_token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        if (function_exists('getStudentEffectiveProgram')) {
            $effectiveProgram = getStudentEffectiveProgram($this->conn, (int)($row['student_id'] ?? 0), [
                'program_id' => 0,
                'program_code' => (string)($row['program_code'] ?? ''),
                'program_name' => (string)($row['program_name'] ?? ''),
            ]);
            $row['program_code'] = (string)($effectiveProgram['program_code'] ?? ($row['program_code'] ?? ''));
            $row['program_name'] = (string)($effectiveProgram['program_name'] ?? ($row['program_name'] ?? ''));
        }

        $row['snapshot'] = $this->decodeSnapshot((string)($row['snapshot_json'] ?? ''));
        $row['verification_url'] = $this->buildVerificationUrl((string)$row['verification_token']);
        $row['ledger_valid'] = $this->isLedgerChainValid((int)($row['id'] ?? 0));
        return $row;
    }

    public function markDispatched(int $issuanceId, ?int $actorUserId = null, string $method = '', string $reference = '', string $notes = ''): bool
    {
        $issuanceId = (int)$issuanceId;
        if ($issuanceId <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare("
            UPDATE transcript_issuances
            SET distribution_status = 'dispatched',
                dispatch_method = :dispatch_method,
                dispatch_reference = :dispatch_reference,
                dispatch_notes = :dispatch_notes,
                dispatched_by_user_id = :dispatched_by_user_id,
                dispatched_at = NOW()
            WHERE id = :id
              AND status = 'active'
              AND distribution_status = 'to_be_distributed'
            LIMIT 1
        ");
        $stmt->execute([
            'dispatch_method' => $method !== '' ? $method : null,
            'dispatch_reference' => $reference !== '' ? $reference : null,
            'dispatch_notes' => $notes !== '' ? $notes : null,
            'dispatched_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'id' => $issuanceId,
        ]);
        if ($stmt->rowCount() <= 0) {
            return false;
        }

        $this->appendLedgerEntry($issuanceId, 'dispatched', [
            'dispatch_method' => trim($method),
            'dispatch_reference' => trim($reference),
            'dispatch_notes' => trim($notes),
        ], $actorUserId);

        return true;
    }

    public function markDistributed(int $issuanceId, ?int $actorUserId = null, string $notes = ''): bool
    {
        $issuanceId = (int)$issuanceId;
        if ($issuanceId <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare("
            UPDATE transcript_issuances
            SET distribution_status = 'distributed',
                dispatch_notes = CASE
                    WHEN :distribution_notes IS NULL OR :distribution_notes = '' THEN dispatch_notes
                    WHEN dispatch_notes IS NULL OR dispatch_notes = '' THEN :distribution_notes
                    ELSE CONCAT(dispatch_notes, '\n', :distribution_notes)
                END,
                distributed_by_user_id = :distributed_by_user_id,
                distributed_at = NOW()
            WHERE id = :id
              AND status = 'active'
              AND distribution_status IN ('to_be_distributed', 'dispatched')
            LIMIT 1
        ");
        $stmt->execute([
            'distribution_notes' => $notes !== '' ? $notes : null,
            'distributed_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'id' => $issuanceId,
        ]);
        if ($stmt->rowCount() <= 0) {
            return false;
        }

        $this->appendLedgerEntry($issuanceId, 'distributed', [
            'notes' => trim($notes),
        ], $actorUserId);

        return true;
    }

    public function revokeIssuance(int $issuanceId, ?int $actorUserId = null, string $reason = ''): bool
    {
        $issuanceId = (int)$issuanceId;
        if ($issuanceId <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare("
            UPDATE transcript_issuances
            SET status = 'revoked',
                revoked_at = NOW()
            WHERE id = :id
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute(['id' => $issuanceId]);
        if ($stmt->rowCount() <= 0) {
            return false;
        }

        $this->appendLedgerEntry($issuanceId, 'revoked', [
            'reason' => trim($reason),
        ], $actorUserId);

        return true;
    }

    public function buildVerificationUrl(string $token): string
    {
        return rtrim(BASE_URL, '/') . '/views/verify/transcript.php?token=' . urlencode($token);
    }

    public function findLatestActiveIssuanceForHash(int $studentId, string $transcriptHash): ?array
    {
        $studentId = (int)$studentId;
        $transcriptHash = strtolower(trim($transcriptHash));
        if ($studentId <= 0 || $transcriptHash === '') {
            return null;
        }

        $stmt = $this->conn->prepare("
            SELECT verification_token
            FROM transcript_issuances
            WHERE student_id = :student_id
              AND transcript_hash = :transcript_hash
              AND status = 'active'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'student_id' => $studentId,
            'transcript_hash' => $transcriptHash,
        ]);
        $token = $stmt->fetchColumn();
        return $token ? $this->getIssuanceByToken((string)$token) : null;
    }

    public function computeSnapshotHash(array $snapshot): string
    {
        return hash('sha256', $this->encodeSnapshot($snapshot));
    }

    private function appendLedgerEntry(int $issuanceId, string $eventType, array $payload, ?int $actorUserId): void
    {
        $prevStmt = $this->conn->prepare("
            SELECT entry_hash
            FROM transcript_issuance_ledger
            WHERE issuance_id = :issuance_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $prevStmt->execute(['issuance_id' => $issuanceId]);
        $previousHash = (string)($prevStmt->fetchColumn() ?: '');

        $payloadJson = $this->encodeSnapshot($payload);
        $entryHash = hash('sha256', implode('|', [
            $issuanceId,
            $eventType,
            $previousHash,
            $payloadJson,
        ]));

        $stmt = $this->conn->prepare("
            INSERT INTO transcript_issuance_ledger
                (issuance_id, event_type, previous_entry_hash, entry_hash, payload_json, created_by_user_id, created_at)
            VALUES
                (:issuance_id, :event_type, :previous_entry_hash, :entry_hash, :payload_json, :created_by_user_id, NOW())
        ");
        $stmt->execute([
            'issuance_id' => $issuanceId,
            'event_type' => $eventType,
            'previous_entry_hash' => $previousHash !== '' ? $previousHash : null,
            'entry_hash' => $entryHash,
            'payload_json' => $payloadJson,
            'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ]);
    }

    private function isLedgerChainValid(int $issuanceId): bool
    {
        if ($issuanceId <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare("
            SELECT id, event_type, previous_entry_hash, entry_hash, payload_json
            FROM transcript_issuance_ledger
            WHERE issuance_id = :issuance_id
            ORDER BY id ASC
        ");
        $stmt->execute(['issuance_id' => $issuanceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($rows)) {
            return false;
        }

        $previousHash = '';
        foreach ($rows as $row) {
            $expectedHash = hash('sha256', implode('|', [
                $issuanceId,
                (string)($row['event_type'] ?? ''),
                $previousHash,
                (string)($row['payload_json'] ?? ''),
            ]));
            if (!hash_equals($expectedHash, (string)($row['entry_hash'] ?? ''))) {
                return false;
            }
            if ((string)($row['previous_entry_hash'] ?? '') !== ($previousHash !== '' ? $previousHash : '')) {
                return false;
            }
            $previousHash = (string)($row['entry_hash'] ?? '');
        }

        return true;
    }

    private function encodeSnapshot(array $snapshot): string
    {
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode transcript issuance payload.');
        }
        return $json;
    }

    private function decodeSnapshot(string $snapshotJson): array
    {
        if ($snapshotJson === '') {
            return [];
        }
        $decoded = json_decode($snapshotJson, true);
        return is_array($decoded) ? $decoded : [];
    }
}
