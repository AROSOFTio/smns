<?php
/**
 * Africa's Talking bulk SMS helper.
 */
class AfricasTalkingSmsService {
    private $username;
    private $apiKey;
    private $endpoint;
    private $timeout;

    public function __construct($username = null, $apiKey = null, $endpoint = null, $timeout = null) {
        $this->username = trim((string)($username !== null ? $username : (defined('AFRICASTALKING_USERNAME') ? AFRICASTALKING_USERNAME : '')));
        $this->apiKey = trim((string)($apiKey !== null ? $apiKey : (defined('AFRICASTALKING_API_KEY') ? AFRICASTALKING_API_KEY : '')));
        $this->endpoint = trim((string)($endpoint !== null ? $endpoint : (defined('AFRICASTALKING_SMS_URL') ? AFRICASTALKING_SMS_URL : 'https://api.africastalking.com/version1/messaging/bulk')));
        $this->timeout = (int)($timeout !== null ? $timeout : (defined('AFRICASTALKING_SMS_TIMEOUT') ? AFRICASTALKING_SMS_TIMEOUT : 30));
        if ($this->timeout <= 0) {
            $this->timeout = 30;
        }
    }

    public function sendBulkMessage($message, array $phoneNumbers, array $options = []) {
        $message = trim((string)$message);
        if ($message === '') {
            return ['success' => false, 'message' => 'Message is required.'];
        }
        if (empty($phoneNumbers)) {
            return ['success' => false, 'message' => 'At least one phone number is required.'];
        }
        if ($this->username === '' || $this->isPlaceholderValue($this->username)) {
            return ['success' => false, 'message' => 'Africa\'s Talking username is not configured.'];
        }
        if ($this->apiKey === '' || $this->isPlaceholderValue($this->apiKey)) {
            return ['success' => false, 'message' => 'Africa\'s Talking API key is not configured.'];
        }
        if ($this->endpoint === '') {
            return ['success' => false, 'message' => 'Africa\'s Talking SMS endpoint is not configured.'];
        }

        $normalizedNumbers = [];
        foreach ($phoneNumbers as $phoneNumber) {
            $phoneNumber = trim((string)$phoneNumber);
            if ($phoneNumber !== '') {
                $normalizedNumbers[] = $phoneNumber;
            }
        }
        $normalizedNumbers = array_values(array_unique($normalizedNumbers));
        if (empty($normalizedNumbers)) {
            return ['success' => false, 'message' => 'At least one valid phone number is required.'];
        }

        $payload = [
            'username' => $this->username,
            'message' => $message,
            'phoneNumbers' => $normalizedNumbers,
        ];

        $senderId = trim((string)($options['senderId'] ?? (defined('AFRICASTALKING_DEFAULT_SENDER_ID') ? AFRICASTALKING_DEFAULT_SENDER_ID : '')));
        if ($senderId !== '') {
            $payload['senderId'] = $senderId;
        }

        $maskedNumber = trim((string)($options['maskedNumber'] ?? (defined('AFRICASTALKING_DEFAULT_MASKED_NUMBER') ? AFRICASTALKING_DEFAULT_MASKED_NUMBER : '')));
        if ($maskedNumber !== '') {
            $payload['maskedNumber'] = $maskedNumber;
        }

        $telco = trim((string)($options['telco'] ?? (defined('AFRICASTALKING_DEFAULT_TELCO') ? AFRICASTALKING_DEFAULT_TELCO : '')));
        if ($telco !== '') {
            $payload['telco'] = $telco;
        }

        return $this->postJson($payload);
    }

    private function postJson(array $payload) {
        $json = json_encode($payload);
        if ($json === false) {
            return ['success' => false, 'message' => 'Failed to encode SMS payload.'];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'apiKey: ' . $this->apiKey,
        ];

        $result = [
            'success' => false,
            'http_code' => 0,
            'message' => '',
            'error' => '',
            'raw_response' => '',
            'response' => null,
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($this->endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            $raw = curl_exec($ch);
            if ($raw === false) {
                $result['error'] = (string)curl_error($ch);
                $result['message'] = $result['error'] !== '' ? $result['error'] : 'Africa\'s Talking request failed.';
            } else {
                $result['raw_response'] = (string)$raw;
            }
            $result['http_code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'content' => $json,
                    'timeout' => $this->timeout,
                    'ignore_errors' => true,
                ]
            ]);
            $raw = @file_get_contents($this->endpoint, false, $context);
            if ($raw === false) {
                $result['error'] = 'HTTP request failed.';
                $result['message'] = $result['error'];
            } else {
                $result['raw_response'] = (string)$raw;
            }

            if (!empty($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $line) {
                    if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/i', (string)$line, $m)) {
                        $result['http_code'] = (int)($m[1] ?? 0);
                        break;
                    }
                }
            }
        }

        if ($result['raw_response'] !== '') {
            $decoded = json_decode($result['raw_response'], true);
            if (is_array($decoded)) {
                $result['response'] = $decoded;
            }
        }

        $result['success'] = $result['http_code'] >= 200 && $result['http_code'] < 300 && $result['error'] === '';
        if ($result['message'] === '') {
            if ($result['success']) {
                $result['message'] = 'SMS request accepted.';
            } elseif (is_array($result['response']) && !empty($result['response']['errorMessage'])) {
                $result['message'] = (string)$result['response']['errorMessage'];
            } else {
                $result['message'] = 'Africa\'s Talking request failed.';
            }
        }

        return $result;
    }

    private function isPlaceholderValue($value) {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return true;
        }

        return strpos($value, 'replace-with') !== false
            || strpos($value, 'your-') === 0
            || strpos($value, 'myapp') !== false
            || $value === 'username';
    }
}
