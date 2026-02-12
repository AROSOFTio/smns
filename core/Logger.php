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
            // Get login events with their corresponding logout
            $sql = "SELECT 
                        login.id,
                        login.user_id,
                        login.description as login_description,
                        login.created_at as login_time,
                        logout.created_at as logout_time,
                        u.username,
                        CASE 
                            WHEN logout.created_at IS NOT NULL 
                            THEN TIMESTAMPDIFF(MINUTE, login.created_at, logout.created_at)
                            ELSE NULL 
                        END as session_duration_minutes
                    FROM activity_logs login
                    LEFT JOIN users u ON login.user_id = u.id
                    LEFT JOIN activity_logs logout ON (
                        logout.user_id = login.user_id 
                        AND logout.action = 'logout' 
                        AND logout.created_at > login.created_at
                        AND logout.created_at = (
                            SELECT MIN(lo.created_at) 
                            FROM activity_logs lo 
                            WHERE lo.user_id = login.user_id 
                            AND lo.action = 'logout' 
                            AND lo.created_at > login.created_at
                        )
                    )
                    WHERE login.action = 'login'
                    ORDER BY login.created_at DESC 
                    LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
        } catch(Exception $e) {
            error_log("Get login sessions error: " . $e->getMessage());
            return [];
        }
    }
}
