<?php
/**
 * Standardized email template rendering.
 */
class EmailTemplate {
    public static function render($type, $data = []) {
        $appName = defined('APP_NAME') ? APP_NAME : 'System';
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        $recipient = trim((string)($data['recipient_name'] ?? 'User'));
        $loginUrl = (string)($data['login_url'] ?? ($baseUrl . '/views/auth/login.php'));
        $supportEmail = (string)($data['support_email'] ?? (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : ''));
        $footer = "\n\nRegards,\n" . $appName . " Administration";

        switch ($type) {
            case 'credentials_issued':
                $roleLabel = (string)($data['role_label'] ?? 'User');
                $accountIdLabel = (string)($data['account_id_label'] ?? 'Account ID');
                $accountIdValue = (string)($data['account_id_value'] ?? '-');
                $username = (string)($data['username'] ?? '-');
                $password = (string)($data['temporary_password'] ?? '-');
                $customNote = trim((string)($data['custom_note'] ?? ''));
                return [
                    'subject' => $appName . ' - ' . $roleLabel . ' Account Credentials',
                    'text' => "Dear {$recipient},\n\n" .
                        "Your {$roleLabel} account has been created.\n\n" .
                        "{$accountIdLabel}: {$accountIdValue}\n" .
                        "Username: {$username}\n" .
                        "Temporary Password: {$password}\n" .
                        "Login URL: {$loginUrl}\n\n" .
                        "Please change your password immediately after first login." .
                        ($customNote !== '' ? "\n\n" . $customNote : '') .
                        $footer
                ];

            case 'password_reset':
                $username = (string)($data['username'] ?? '-');
                $password = (string)($data['temporary_password'] ?? '-');
                return [
                    'subject' => $appName . ' - Password Reset',
                    'text' => "Dear {$recipient},\n\n" .
                        "Your password has been reset by an administrator.\n\n" .
                        "Username: {$username}\n" .
                        "Temporary Password: {$password}\n" .
                        "Login URL: {$loginUrl}\n\n" .
                        "Please log in and change your password immediately for security reasons." .
                        $footer
                ];

            case 'approval_status':
                $status = strtolower((string)($data['status'] ?? 'approved'));
                $requestLabel = (string)($data['request_label'] ?? 'request');
                $adminResponse = trim((string)($data['admin_response'] ?? ''));
                $username = (string)($data['username'] ?? '');
                $password = (string)($data['temporary_password'] ?? '');
                $isApproved = $status === 'approved';
                $statusLabel = $isApproved ? 'Approved' : 'Rejected';
                $text = "Dear {$recipient},\n\n" .
                    "Your {$requestLabel} has been {$statusLabel}.\n";
                if ($isApproved) {
                    $text .= "\n";
                    if ($username !== '') $text .= "Username: {$username}\n";
                    if ($password !== '') $text .= "Temporary Password: {$password}\n";
                    $text .= "Login URL: {$loginUrl}\n";
                }
                if ($adminResponse !== '') {
                    $text .= "\nAdmin Response: {$adminResponse}\n";
                }
                if (!$isApproved) {
                    $text .= "\nIf you need clarification, please contact administration";
                    if ($supportEmail !== '') {
                        $text .= " at {$supportEmail}";
                    }
                    $text .= ".\n";
                }
                return [
                    'subject' => $appName . ' - ' . $requestLabel . ' ' . $statusLabel,
                    'text' => $text . $footer
                ];

            case 'request_response':
                $status = strtolower((string)($data['status'] ?? 'approved'));
                $requestType = (string)($data['request_type'] ?? 'Request');
                $requestId = (string)($data['request_id'] ?? '');
                $adminResponse = trim((string)($data['admin_response'] ?? ''));
                $actionUrl = (string)($data['action_url'] ?? ($baseUrl . '/views/student/dashboard.php'));
                $statusLabel = ucfirst($status);
                return [
                    'subject' => $appName . ' - ' . $requestType . ' ' . $statusLabel,
                    'text' => "Dear {$recipient},\n\n" .
                        "Your {$requestType} has been {$statusLabel}." .
                        ($requestId !== '' ? "\nRequest ID: {$requestId}" : '') .
                        ($adminResponse !== '' ? "\nAdmin Response: {$adminResponse}" : '') .
                        "\n\nView details: {$actionUrl}" .
                        $footer
                ];

            case 'finance_alert':
                $alertTitle = (string)($data['alert_title'] ?? 'Finance Notification');
                $alertMessage = (string)($data['alert_message'] ?? 'A finance update is available.');
                $reference = (string)($data['reference'] ?? '');
                $amount = (string)($data['amount'] ?? '');
                $actionUrl = (string)($data['action_url'] ?? ($baseUrl . '/views/finance/dashboard.php'));
                return [
                    'subject' => $appName . ' - Finance Alert: ' . $alertTitle,
                    'text' => "Dear {$recipient},\n\n" .
                        $alertMessage .
                        ($reference !== '' ? "\nReference: {$reference}" : '') .
                        ($amount !== '' ? "\nAmount: {$amount}" : '') .
                        "\n\nAction link: {$actionUrl}" .
                        $footer
                ];

            case 'invite_notice':
                $roleLabel = (string)($data['role_label'] ?? 'User');
                $username = (string)($data['username'] ?? '-');
                $customNote = trim((string)($data['custom_note'] ?? ''));
                return [
                    'subject' => $appName . ' - ' . $roleLabel . ' Invitation',
                    'text' => "Dear {$recipient},\n\n" .
                        "You have been invited to access the {$roleLabel} portal.\n\n" .
                        "Username: {$username}\n" .
                        "Login URL: {$loginUrl}" .
                        ($customNote !== '' ? "\n\n" . $customNote : '') .
                        $footer
                ];

            case 'scheduled_report':
                $reportName = (string)($data['report_name'] ?? 'Scheduled Report');
                $reportType = (string)($data['report_type'] ?? 'general');
                $downloadUrl = (string)($data['download_url'] ?? '');
                return [
                    'subject' => $appName . ' - Scheduled Report: ' . $reportName,
                    'text' => "Dear {$recipient},\n\n" .
                        "Your scheduled {$reportType} report is ready." .
                        ($downloadUrl !== '' ? "\nDownload: {$downloadUrl}" : '') .
                        $footer
                ];

            case 'security_new_device_login':
                $moduleLabel = (string)($data['module_label'] ?? 'Portal');
                $deviceLabel = (string)($data['device_label'] ?? 'Unknown device');
                $ipAddress = (string)($data['ip_address'] ?? 'Unknown');
                $usedCount = (int)($data['used_count'] ?? 1);
                if ($usedCount < 1) {
                    $usedCount = 1;
                }
                $loginTime = (string)($data['login_time'] ?? date('M j, Y, g:i A'));
                $accountLabel = (string)($data['account_label'] ?? 'Username');
                $accountValue = (string)($data['account_value'] ?? '-');
                $serviceDeskLabel = (string)($data['service_desk_label'] ?? 'ICT Service Desk');
                $resetUrl = trim((string)($data['reset_url'] ?? ''));
                $greetingName = trim((string)($data['greeting_name'] ?? $recipient));
                $botName = defined('APP_SHORT_NAME') ? APP_SHORT_NAME . ' Bot' : $appName . ' Bot';

                $subject = "Security alert: New device sign-in to your {$moduleLabel} account detected!";

                $text = "Dear {$greetingName},\n\n" .
                    "A new device has just logged in to your account.\n" .
                    "The following device was detected:\n\n" .
                    "- Device: {$deviceLabel}\n" .
                    "- IP: {$ipAddress}\n" .
                    "- Used: {$usedCount} " . ($usedCount === 1 ? 'time' : 'times') . "\n" .
                    "- Last login: {$loginTime}\n" .
                    "- Module: {$moduleLabel}\n" .
                    "- {$accountLabel}: {$accountValue}\n\n" .
                    "If this was you, no action is required. If you do not recognize this activity, please reset your password immediately and contact the {$serviceDeskLabel} for assistance." .
                    ($resetUrl !== '' ? "\nReset password: {$resetUrl}" : '') .
                    "\n\nStay secure,\n" .
                    $botName;

                $esc = function ($value) {
                    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                };
                $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f3f4f6;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">' .
                    '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">' .
                    '<tr><td align="center">' .
                    '<table role="presentation" width="680" cellpadding="0" cellspacing="0" style="max-width:680px;width:100%;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">' .
                    '<tr><td style="padding:18px 22px;background:#0f172a;color:#f8fafc;font-size:22px;font-weight:700;line-height:1.35;">' .
                    $esc($subject) .
                    '</td></tr>' .
                    '<tr><td style="padding:22px;font-size:16px;line-height:1.65;color:#1f2937;">' .
                    '<p style="margin:0 0 12px;">Dear ' . $esc($greetingName) . ',</p>' .
                    '<p style="margin:0 0 6px;">A new device has just logged in to your account.</p>' .
                    '<p style="margin:0 0 14px;">The following device was detected:</p>' .
                    '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc;">' .
                    '<tr><td style="padding:12px 14px;border-bottom:1px solid #e5e7eb;"><strong>Device:</strong> ' . $esc($deviceLabel) . '</td></tr>' .
                    '<tr><td style="padding:12px 14px;border-bottom:1px solid #e5e7eb;"><strong>IP:</strong> ' . $esc($ipAddress) . '</td></tr>' .
                    '<tr><td style="padding:12px 14px;border-bottom:1px solid #e5e7eb;"><strong>Used:</strong> ' . $esc($usedCount . ' ' . ($usedCount === 1 ? 'time' : 'times')) . '</td></tr>' .
                    '<tr><td style="padding:12px 14px;border-bottom:1px solid #e5e7eb;"><strong>Last login:</strong> ' . $esc($loginTime) . '</td></tr>' .
                    '<tr><td style="padding:12px 14px;border-bottom:1px solid #e5e7eb;"><strong>Module:</strong> ' . $esc($moduleLabel) . '</td></tr>' .
                    '<tr><td style="padding:12px 14px;"><strong>' . $esc($accountLabel) . ':</strong> ' . $esc($accountValue) . '</td></tr>' .
                    '</table>' .
                    '<p style="margin:16px 0 0;">If this was you, no action is required. If you do not recognize this activity, please reset your password immediately and contact the ' . $esc($serviceDeskLabel) . ' for assistance.</p>' .
                    ($resetUrl !== '' ? '<p style="margin:12px 0 0;"><a href="' . $esc($resetUrl) . '" style="color:#2563eb;text-decoration:underline;">Reset password</a></p>' : '') .
                    '<p style="margin:20px 0 0;">Stay secure,<br>' . $esc($botName) . '</p>' .
                    '</td></tr>' .
                    '</table>' .
                    '</td></tr>' .
                    '</table>' .
                    '</body></html>';

                return [
                    'subject' => $subject,
                    'text' => $text,
                    'html' => $html
                ];

            default:
                return [
                    'subject' => (string)($data['subject'] ?? ($appName . ' - Notification')),
                    'text' => (string)($data['text'] ?? '')
                ];
        }
    }
}
