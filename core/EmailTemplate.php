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

            default:
                return [
                    'subject' => (string)($data['subject'] ?? ($appName . ' - Notification')),
                    'text' => (string)($data['text'] ?? '')
                ];
        }
    }
}
