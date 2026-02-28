<?php
/**
 * Privacy consent lifecycle service.
 */
class PrivacyConsentService {
    private $db;

    private static function getSettingValue($key, $default = null) {
        if (function_exists('getSetting')) {
            try {
                $val = getSetting((string)$key, null);
                if ($val !== null && $val !== '') {
                    return $val;
                }
            } catch (Exception $e) {
                // fall through
            }
        }
        return $default;
    }

    private static function getConsentKey() {
        $default = defined('PRIVACY_CONSENT_KEY') ? (string)PRIVACY_CONSENT_KEY : 'privacy_notice';
        return (string)self::getSettingValue('privacy_consent_key', $default);
    }

    private static function getConsentVersion() {
        $default = defined('PRIVACY_NOTICE_VERSION') ? (string)PRIVACY_NOTICE_VERSION : '1';
        return (string)self::getSettingValue('privacy_notice_version', $default);
    }

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function ensureTable() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS privacy_consents (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                consent_key VARCHAR(100) NOT NULL,
                consent_version VARCHAR(50) NOT NULL,
                status ENUM('granted','withdrawn') NOT NULL DEFAULT 'granted',
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                granted_at DATETIME NULL,
                withdrawn_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_key_ver (user_id, consent_key, consent_version),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function hasAcceptedCurrent($userId) {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        $this->ensureTable();

        $consentKey = self::getConsentKey();
        $version = self::getConsentVersion();

        $stmt = $this->db->prepare("
            SELECT id
            FROM privacy_consents
            WHERE user_id = :user_id
              AND consent_key = :consent_key
              AND consent_version = :consent_version
              AND status = 'granted'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'consent_key' => $consentKey,
            'consent_version' => $version
        ]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function grantCurrent($userId, $ipAddress = null, $userAgent = null) {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        $this->ensureTable();

        $consentKey = self::getConsentKey();
        $version = self::getConsentVersion();

        $stmt = $this->db->prepare("
            INSERT INTO privacy_consents (
                user_id, consent_key, consent_version, status, ip_address, user_agent, granted_at
            ) VALUES (
                :user_id, :consent_key, :consent_version, 'granted', :ip_address, :user_agent, NOW()
            )
        ");
        return $stmt->execute([
            'user_id' => $userId,
            'consent_key' => $consentKey,
            'consent_version' => $version,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ]);
    }
}
