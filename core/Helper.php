<?php
/**
 * Helper Functions Class
 * Provides utility functions
 */
class Helper {
    
    /**
     * Format date
     */
    public static function formatDate($date, $format = 'M d, Y') {
        if (empty($date)) return '';
        return date($format, strtotime($date));
    }
    
    /**
     * Format datetime
     */
    public static function formatDateTime($datetime, $format = 'M d, Y g:i A') {
        if (empty($datetime)) return '';
        return date($format, strtotime($datetime));
    }
    
    /**
     * Time ago
     */
    public static function timeAgo($datetime) {
        $time = strtotime($datetime);
        $diff = time() - $time;
        
        if ($diff < 60) {
            return 'just now';
        } else if ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } else if ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } else if ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else if ($diff < 2592000) {
            $weeks = floor($diff / 604800);
            return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
        } else if ($diff < 31536000) {
            $months = floor($diff / 2592000);
            return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
        } else {
            $years = floor($diff / 31536000);
            return $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
        }
    }
    
    /**
     * Format currency
     */
    public static function formatCurrency($amount, $currency = 'USD') {
        return '$' . number_format($amount, 2);
    }
    
    /**
     * Format number
     */
    public static function formatNumber($number, $decimals = 0) {
        return number_format($number, $decimals);
    }
    
    /**
     * Generate student ID
     */
    public static function generateStudentID($prefix = 'STD', $lastId = 0) {
        $nextId = $lastId + 1;
        return $prefix . str_pad($nextId, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate lecturer ID
     */
    public static function generateLecturerID($prefix = 'LEC', $lastId = 0) {
        $nextId = $lastId + 1;
        return $prefix . str_pad($nextId, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate invoice number
     */
    public static function generateInvoiceNumber($prefix = 'INV', $year = null) {
        $year = $year ?? date('Y');
        $random = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return $prefix . '-' . $year . '-' . $random;
    }
    
    /**
     * Generate receipt number
     */
    public static function generateReceiptNumber($prefix = 'REC') {
        $timestamp = time();
        $random = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        return $prefix . '-' . $timestamp . '-' . $random;
    }
    
    /**
     * Get academic status color
     */
    public static function getStatusColor($status) {
        $colors = [
            'active' => 'success',
            'inactive' => 'secondary',
            'pending' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            'suspended' => 'danger',
            'completed' => 'info',
            'published' => 'success',
            'graduated' => 'primary'
        ];
        
        return $colors[strtolower($status)] ?? 'secondary';
    }
    
    /**
     * Get GPA color
     */
    public static function getGPAColor($gpa) {
        if ($gpa >= 3.5) return 'success';
        if ($gpa >= 3.0) return 'info';
        if ($gpa >= 2.5) return 'primary';
        if ($gpa >= 2.0) return 'success'; // 2.0 (D grade) is passing
        return 'danger';
    }
    
    /**
     * Get grade letter color (for badges)
     */
    public static function getGradeColor($gradeLetter) {
        $gradeLetter = strtoupper(trim($gradeLetter));
        
        // Passing grades show green/blue, failing shows red
        switch($gradeLetter) {
            case 'A':
                return 'success'; // green
            case 'B+':
            case 'B':
                return 'info'; // blue
            case 'C+':
            case 'C':
                return 'primary'; // darker blue
            case 'D+':
            case 'D':
                return 'success'; // green (passing grade)
            case 'F':
                return 'danger'; // red (fail)
            default:
                return 'secondary'; // gray
        }
    }
    
    /**
     * Redirect
     */
    public static function redirect($url) {
        header('Location: ' . $url);
        exit;
    }
    
    /**
     * Redirect with message
     */
    public static function redirectWithMessage($url, $type, $message) {
        $session = new Session();
        $session->setFlash($type, $message);
        self::redirect($url);
    }
    
    /**
     * Get current academic year
     */
    public static function getCurrentAcademicYear() {
        $db = new Database();
        $conn = $db->getConnection();
        
        $sql = "SELECT * FROM academic_years WHERE status = 'active' ORDER BY start_date DESC LIMIT 1";
        $stmt = $conn->query($sql);
        return $stmt->fetch();
    }
    
    /**
     * Get current semester
     */
    public static function getCurrentSemester() {
        $db = new Database();
        $conn = $db->getConnection();
        
        $sql = "SELECT * FROM semesters WHERE status = 'active' ORDER BY start_date DESC LIMIT 1";
        $stmt = $conn->query($sql);
        return $stmt->fetch();
    }
    
    /**
     * Paginate results
     */
    public static function paginate($totalItems, $itemsPerPage, $currentPage = 1) {
        $totalPages = ceil($totalItems / $itemsPerPage);
        $currentPage = max(1, min($currentPage, $totalPages));
        $offset = ($currentPage - 1) * $itemsPerPage;
        
        return [
            'total_items' => $totalItems,
            'items_per_page' => $itemsPerPage,
            'total_pages' => $totalPages,
            'current_page' => $currentPage,
            'offset' => $offset,
            'has_prev' => $currentPage > 1,
            'has_next' => $currentPage < $totalPages
        ];
    }
    
    /**
     * Truncate text
     */
    public static function truncate($text, $length = 100, $suffix = '...') {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . $suffix;
    }
    
    /**
     * Get user full name
     */
    public static function getFullName($firstName, $middleName, $lastName) {
        $name = $firstName;
        if (!empty($middleName)) {
            $name .= ' ' . $middleName;
        }
        $name .= ' ' . $lastName;
        return $name;
    }
}
