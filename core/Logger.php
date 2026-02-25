<?php
/**
 * Logger Class
 * Handles activity logging
 */
class Logger {
    private $db;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    /**
     * Log activity
     */
    public function log($userId, $action, $module, $description, $additionalData = []) {
        try {
            $descriptionWithMeta = $this->appendChangeMetadata($description, $additionalData);
            $sql = "INSERT INTO activity_logs (user_id, action, module, description, ip_address, user_agent) 
                    VALUES (:user_id, :action, :module, :description, :ip_address, :user_agent)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'user_id' => $userId,
                'action' => $action,
                'module' => $module,
                'description' => $descriptionWithMeta,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            return true;
        } catch (Exception $e) {
            error_log("Logging error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user activities
     */
    public function getUserActivities($userId, $limit = 50) {
        try {
            $sql = "SELECT * FROM activity_logs WHERE user_id = :user_id 
                    ORDER BY created_at DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Get activities error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get recent activities
     */
    public function getRecentActivities($limit = 100) {
        try {
            $sql = "SELECT 
                        al.*, 
                        u.username,
                        u.role AS user_role,
                        COALESCE(
                            NULLIF(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), ''),
                            NULLIF(TRIM(CONCAT_WS(' ', l.first_name, l.last_name)), ''),
                            NULLIF(TRIM(CONCAT_WS(' ', f.first_name, f.last_name)), ''),
                            NULLIF(TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)), ''),
                            u.username
                        ) AS display_name
                    FROM activity_logs al
                    LEFT JOIN users u ON al.user_id = u.id
                    LEFT JOIN admins a ON u.id = a.user_id
                    LEFT JOIN lecturers l ON u.id = l.user_id
                    LEFT JOIN finance_staff f ON u.id = f.user_id
                    LEFT JOIN students s ON u.id = s.user_id
                    ORDER BY al.created_at DESC 
                    LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Get recent activities error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get normalized change timeline records with optional filters.
     */
    public function getModuleChangeTimeline($filters = [], $limit = 200) {
        try {
            $limit = (int)$limit;
            if ($limit <= 0) {
                $limit = 200;
            }
            if ($limit > 2000) {
                $limit = 2000;
            }

            $fetchLimit = min(5000, max($limit * 4, $limit));
            $moduleFilter = strtolower(trim((string)($filters['module'] ?? '')));
            $actionFilter = strtolower(trim((string)($filters['action'] ?? '')));
            $search = trim((string)($filters['q'] ?? ''));
            $partFilter = strtolower(trim((string)($filters['part'] ?? '')));
            $whereFilter = strtolower(trim((string)($filters['where'] ?? '')));
            $from = trim((string)($filters['from'] ?? ''));
            $to = trim((string)($filters['to'] ?? ''));

            $sql = "SELECT
                        al.*,
                        u.username,
                        u.role AS user_role,
                        COALESCE(
                            NULLIF(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), ''),
                            NULLIF(TRIM(CONCAT_WS(' ', l.first_name, l.last_name)), ''),
                            NULLIF(TRIM(CONCAT_WS(' ', f.first_name, f.last_name)), ''),
                            NULLIF(TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)), ''),
                            u.username,
                            'System'
                        ) AS display_name
                    FROM activity_logs al
                    LEFT JOIN users u ON al.user_id = u.id
                    LEFT JOIN admins a ON u.id = a.user_id
                    LEFT JOIN lecturers l ON u.id = l.user_id
                    LEFT JOIN finance_staff f ON u.id = f.user_id
                    LEFT JOIN students s ON u.id = s.user_id
                    WHERE 1=1";

            $params = [];
            if ($moduleFilter !== '') {
                $sql .= " AND LOWER(al.module) = :module";
                $params['module'] = $moduleFilter;
            }
            if ($actionFilter !== '') {
                $sql .= " AND LOWER(al.action) = :action";
                $params['action'] = $actionFilter;
            }
            if ($from !== '') {
                $sql .= " AND al.created_at >= :from_ts";
                $params['from_ts'] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $sql .= " AND al.created_at <= :to_ts";
                $params['to_ts'] = $to . ' 23:59:59';
            }
            if ($search !== '') {
                $sql .= " AND (
                    al.description LIKE :q
                    OR al.module LIKE :q
                    OR al.action LIKE :q
                    OR u.username LIKE :q
                )";
                $params['q'] = '%' . $search . '%';
            }

            $sql .= " ORDER BY al.created_at DESC LIMIT :lim";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
            }
            $stmt->bindValue(':lim', $fetchLimit, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $result = [];
            foreach ($rows as $row) {
                $meta = self::extractChangeMetadata((string)($row['description'] ?? ''));
                $partValue = strtolower((string)($meta['part'] ?? ''));
                $whereValue = strtolower((string)($meta['where'] ?? ''));
                $plainDescription = strtolower((string)($meta['description'] ?? ''));

                if ($partFilter !== '' && strpos($partValue, $partFilter) === false && strpos($plainDescription, $partFilter) === false) {
                    continue;
                }
                if ($whereFilter !== '' && strpos($whereValue, $whereFilter) === false) {
                    continue;
                }

                $row['description_plain'] = (string)($meta['description'] ?? '');
                $row['change_part'] = (string)($meta['part'] ?? '');
                $row['change_where'] = (string)($meta['where'] ?? '');
                $row['change_target'] = (string)($meta['target'] ?? '');
                $result[] = $row;

                if (count($result) >= $limit) {
                    break;
                }
            }

            return $result;
        } catch (Exception $e) {
            error_log("Get module change timeline error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get login sessions with duration (login + matching logout)
     */
    public function getLoginSessions($limit = 50) {
        try {
            // Pair each login with the first logout before the next login.
            // If no logout exists but another login exists, close the session at next login (superseded).
            $sql = "SELECT
                        login.id,
                        login.user_id,
                        login.description AS login_description,
                        login.created_at AS login_time,
                        u.username,
                        u.role AS user_role,
                        COALESCE(
                            NULLIF(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), ''),
                            NULLIF(TRIM(CONCAT_WS(' ', l.first_name, l.last_name)), ''),
                            NULLIF(TRIM(CONCAT_WS(' ', f.first_name, f.last_name)), ''),
                            NULLIF(TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)), ''),
                            u.username
                        ) AS display_name,
                        (
                            SELECT MIN(next_login.created_at)
                            FROM activity_logs next_login
                            WHERE next_login.user_id = login.user_id
                              AND next_login.action = 'login'
                              AND next_login.created_at > login.created_at
                        ) AS next_login_time,
                        (
                            SELECT MIN(next_logout.created_at)
                            FROM activity_logs next_logout
                            WHERE next_logout.user_id = login.user_id
                              AND next_logout.action = 'logout'
                              AND next_logout.created_at > login.created_at
                              AND (
                                    (
                                        SELECT MIN(nl.created_at)
                                        FROM activity_logs nl
                                        WHERE nl.user_id = login.user_id
                                          AND nl.action = 'login'
                                          AND nl.created_at > login.created_at
                                    ) IS NULL
                                    OR next_logout.created_at < (
                                        SELECT MIN(nl2.created_at)
                                        FROM activity_logs nl2
                                        WHERE nl2.user_id = login.user_id
                                          AND nl2.action = 'login'
                                          AND nl2.created_at > login.created_at
                                    )
                              )
                        ) AS logout_time
                    FROM activity_logs login
                    LEFT JOIN users u ON login.user_id = u.id
                    LEFT JOIN admins a ON u.id = a.user_id
                    LEFT JOIN lecturers l ON u.id = l.user_id
                    LEFT JOIN finance_staff f ON u.id = f.user_id
                    LEFT JOIN students s ON u.id = s.user_id
                    WHERE login.action = 'login'
                    ORDER BY login.created_at DESC
                    LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sessions as &$session) {
                $actualLogout = $session['logout_time'] ?? null;
                $nextLogin = $session['next_login_time'] ?? null;

                $sessionEnd = null;
                $endType = 'active';
                if (!empty($actualLogout)) {
                    $sessionEnd = $actualLogout;
                    $endType = 'logout';
                } elseif (!empty($nextLogin)) {
                    $sessionEnd = $nextLogin;
                    $endType = 'superseded';
                }

                $session['session_end_time'] = $sessionEnd;
                $session['end_type'] = $endType;
                $session['session_duration_minutes'] = null;

                if (!empty($sessionEnd)) {
                    $loginTs = strtotime((string)$session['login_time']);
                    $endTs = strtotime((string)$sessionEnd);
                    if ($loginTs && $endTs && $endTs >= $loginTs) {
                        $session['session_duration_minutes'] = (int)floor(($endTs - $loginTs) / 60);
                    }
                }
            }
            unset($session);

            return $sessions;
        } catch (Exception $e) {
            error_log("Get login sessions error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Parse [part:...], [where:...], [target:...] metadata from description text.
     */
    public static function extractChangeMetadata($description) {
        $raw = trim((string)$description);
        $meta = [
            'description' => $raw,
            'part' => '',
            'where' => '',
            'target' => ''
        ];

        if ($raw === '') {
            return $meta;
        }

        if (preg_match_all('/\[(part|where|target):([^\]]*)\]/i', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = strtolower(trim((string)($match[1] ?? '')));
                $value = trim((string)($match[2] ?? ''));
                if ($key !== '' && array_key_exists($key, $meta)) {
                    $meta[$key] = $value;
                }
            }
            $clean = preg_replace('/\[(part|where|target):[^\]]*\]/i', '', $raw);
            $clean = preg_replace('/\s{2,}/', ' ', (string)$clean);
            $meta['description'] = trim((string)$clean);
        }

        return $meta;
    }

    private function appendChangeMetadata($description, $additionalData = []) {
        $existing = self::extractChangeMetadata((string)$description);
        $cleanDescription = trim((string)($existing['description'] ?? ''));

        $part = trim((string)($additionalData['part'] ?? $additionalData['change_part'] ?? $existing['part']));
        $where = trim((string)($additionalData['where'] ?? $additionalData['location'] ?? $additionalData['path'] ?? $existing['where']));
        if ($where === '') {
            $where = trim((string)($_SERVER['REQUEST_URI'] ?? ''));
        }
        $target = trim((string)($additionalData['target'] ?? $additionalData['record'] ?? $existing['target']));

        $tokens = [];
        if ($part !== '') {
            $tokens[] = '[part:' . $this->sanitizeMetaValue($part) . ']';
        }
        if ($where !== '') {
            $tokens[] = '[where:' . $this->sanitizeMetaValue($where) . ']';
        }
        if ($target !== '') {
            $tokens[] = '[target:' . $this->sanitizeMetaValue($target) . ']';
        }

        if (empty($tokens)) {
            return $cleanDescription;
        }
        return trim($cleanDescription . ' ' . implode(' ', $tokens));
    }

    private function sanitizeMetaValue($value) {
        $clean = str_replace(["\r", "\n", "\t"], ' ', (string)$value);
        $clean = str_replace(['[', ']'], '', $clean);
        $clean = preg_replace('/\s{2,}/', ' ', $clean);
        return trim((string)$clean);
    }
}
