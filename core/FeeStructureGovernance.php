<?php
/**
 * Fee structure workflow support:
 * - Finance creates draft versions and fee lines.
 * - Admin approves/publishes and locks versions.
 * - Students consume published read-only versions.
 */
class FeeStructureGovernance
{
    public static function ensureSchema(PDO $conn)
    {
        // Version master table (workflow state lives here).
        $conn->exec("
            CREATE TABLE IF NOT EXISTS fee_structure_versions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                version_name VARCHAR(150) NOT NULL,
                academic_year_id INT NULL,
                program_id INT NULL,
                status ENUM('draft','pending_approval','approved','published','archived') NOT NULL DEFAULT 'draft',
                locked TINYINT(1) NOT NULL DEFAULT 0,
                created_by INT NOT NULL,
                approved_by INT NULL,
                published_by INT NULL,
                notes TEXT NULL,
                approved_at DATETIME NULL,
                published_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_program (program_id),
                INDEX idx_academic_year (academic_year_id),
                INDEX idx_created_by (created_by),
                INDEX idx_locked (locked)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        self::addColumnIfMissing(
            $conn,
            'fees_structure',
            'version_id',
            "INT NULL AFTER semester_id"
        );
        self::addColumnIfMissing(
            $conn,
            'fees_structure',
            'fine_amount',
            "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER due_date"
        );
        self::addColumnIfMissing(
            $conn,
            'fees_structure',
            'discount_amount',
            "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER fine_amount"
        );
        self::addColumnIfMissing(
            $conn,
            'fees_structure',
            'created_by_finance_id',
            "INT NULL AFTER discount_amount"
        );
        self::addColumnIfMissing(
            $conn,
            'fees_structure',
            'locked_at',
            "DATETIME NULL AFTER created_by_finance_id"
        );

        self::addIndexIfMissing($conn, 'fees_structure', 'idx_version_id', 'version_id');
        self::addCompositeIndexIfMissing($conn, 'fees_structure', 'idx_version_year_sem', 'version_id, level_year, semester_id');
    }

    public static function findPreferredPublishedVersion(PDO $conn, $programId, $academicYearId = 0)
    {
        $programId = (int)$programId;
        $academicYearId = (int)$academicYearId;

        $sql = "
            SELECT v.*
            FROM fee_structure_versions v
            WHERE v.status = 'published'
              AND v.locked = 1
              AND (v.program_id = :program_id OR v.program_id IS NULL)
        ";
        $params = ['program_id' => $programId];

        if ($academicYearId > 0) {
            $sql .= " AND (v.academic_year_id = :academic_year_id OR v.academic_year_id IS NULL) ";
            $params['academic_year_id'] = $academicYearId;
        }

        $sql .= "
            ORDER BY
                (v.program_id = :program_id_rank) DESC,
                (v.academic_year_id = :academic_year_rank) DESC,
                COALESCE(v.published_at, v.updated_at, v.created_at) DESC,
                v.id DESC
            LIMIT 1
        ";
        $params['program_id_rank'] = $programId;
        $params['academic_year_rank'] = $academicYearId;

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function resolveSuperAdminUserId(PDO $conn)
    {
        // Optional DB-level safeguard:
        // - Prefer explicit setting `super_admin_user_id`
        // - Fallback to the first admin user in the system
        try {
            $stmt = $conn->prepare("
                SELECT setting_value
                FROM settings
                WHERE setting_key = 'super_admin_user_id'
                LIMIT 1
            ");
            $stmt->execute();
            $raw = trim((string)$stmt->fetchColumn());
            $id = (int)$raw;
            if ($id > 0) {
                return $id;
            }
        } catch (Exception $e) {
            // ignore and fallback
        }

        try {
            $fallback = $conn->query("
                SELECT u.id
                FROM users u
                WHERE u.role = 'admin'
                ORDER BY u.id ASC
                LIMIT 1
            ")->fetchColumn();
            return (int)$fallback;
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function isSuperAdmin(PDO $conn, $userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        $superAdminId = (int)self::resolveSuperAdminUserId($conn);
        return $superAdminId > 0 && $superAdminId === $userId;
    }

    private static function addColumnIfMissing(PDO $conn, $table, $column, $definitionSql)
    {
        if (!self::columnExists($conn, $table, $column)) {
            $conn->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definitionSql}");
        }
    }

    private static function addIndexIfMissing(PDO $conn, $table, $indexName, $column)
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND index_name = :index_name
        ");
        $stmt->execute([
            'table_name' => $table,
            'index_name' => $indexName
        ]);
        if ((int)$stmt->fetchColumn() === 0) {
            $conn->exec("ALTER TABLE {$table} ADD INDEX {$indexName} ({$column})");
        }
    }

    private static function addCompositeIndexIfMissing(PDO $conn, $table, $indexName, $columns)
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND index_name = :index_name
        ");
        $stmt->execute([
            'table_name' => $table,
            'index_name' => $indexName
        ]);
        if ((int)$stmt->fetchColumn() === 0) {
            $conn->exec("ALTER TABLE {$table} ADD INDEX {$indexName} ({$columns})");
        }
    }

    private static function columnExists(PDO $conn, $table, $column)
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND column_name = :column_name
        ");
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
