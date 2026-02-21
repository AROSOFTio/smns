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
            $sql = "INSERT INTO activity_logs (user_id, action, module, description, ip_address, user_agent) 
                    VALUES (:user_id, :action, :module, :description, :ip_address, :user_agent)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'user_id' => $userId,
                'action' => $action,
                'module' => $module,
                'description' => $description,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            return true;
        } catch(Exception $e) {
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
        } catch(Exception $e) {
            error_log("Get activities error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent activities
     */
    public function getRecentActivities($limit = 100) {
        try {
            $sql = "SELECT al.*, u.username 
                    FROM activity_logs al
                    LEFT JOIN users u ON al.user_id = u.id
                    ORDER BY al.created_at DESC 
                    LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
        } catch(Exception $e) {
            error_log("Get recent activities error: " . $e->getMessage());
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
        } catch(Exception $e) {
            error_log("Get login sessions error: " . $e->getMessage());
            return [];
        }
    }
}
