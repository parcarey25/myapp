<?php
// sms_functions.php

require __DIR__ . '/db.php';
require __DIR__ . '/sms_config.php';

function sms_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalize_phone_number(string $phone): string {
    $phone = trim($phone);
    $phone = preg_replace('/[^0-9+]/', '', $phone);

    // Philippines format support
    if (preg_match('/^09\d{9}$/', $phone)) {
        return '+63' . substr($phone, 1);
    }

    if (preg_match('/^639\d{9}$/', $phone)) {
        return '+' . $phone;
    }

    if (preg_match('/^\+639\d{9}$/', $phone)) {
        return $phone;
    }

    return $phone;
}

function ensure_sms_logs_table(mysqli $conn): void {
    $sql = "
        CREATE TABLE IF NOT EXISTS sms_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            phone VARCHAR(30) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            send_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            gateway_response TEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $conn->query($sql);
}

function log_sms_result(
    mysqli $conn,
    ?int $userId,
    string $phone,
    string $eventType,
    string $message,
    string $status,
    ?string $gatewayResponse = null,
    ?string $errorMessage = null
): void {
    ensure_sms_logs_table($conn);

    $sql = "INSERT INTO sms_logs (
                user_id,
                phone,
                event_type,
                message,
                send_status,
                gateway_response,
                error_message
            ) VALUES (?, ?, ?, ?, ?, ?, ?)";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param(
            'issssss',
            $userId,
            $phone,
            $eventType,
            $message,
            $status,
            $gatewayResponse,
            $errorMessage
        );
        $stmt->execute();
        $stmt->close();
    }
}

function send_sms_via_gateway(string $phone, string $message): array {
    if (!SMS_ENABLED) {
        return [
            'success'  => false,
            'response' => null,
            'error'    => 'SMS is disabled in config.'
        ];
    }

    $payload = [
        'token'   => SMS_GATEWAY_TOKEN,
        'phone'   => $phone,
        'message' => $message
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, SMS_GATEWAY_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($error) {
        return [
            'success'  => false,
            'response' => $response,
            'error'    => $error
        ];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success'  => true,
            'response' => $response,
            'error'    => null
        ];
    }

    return [
        'success'  => false,
        'response' => $response,
        'error'    => 'Gateway returned HTTP ' . $httpCode
    ];
}

function send_and_log_sms(
    mysqli $conn,
    ?int $userId,
    string $phone,
    string $eventType,
    string $message
): array {
    $phone = normalize_phone_number($phone);

    if ($phone === '') {
        log_sms_result(
            $conn,
            $userId,
            '',
            $eventType,
            $message,
            'failed',
            null,
            'Phone number is empty.'
        );

        return [
            'success' => false,
            'error'   => 'Phone number is empty.'
        ];
    }

    $result = send_sms_via_gateway($phone, $message);

    log_sms_result(
        $conn,
        $userId,
        $phone,
        $eventType,
        $message,
        $result['success'] ? 'sent' : 'failed',
        $result['response'] ?? null,
        $result['error'] ?? null
    );

    return $result;
}

function build_registration_plan_sms(string $memberName = ''): string {
    $namePart = trim($memberName) !== '' ? 'Hello ' . trim($memberName) . ', ' : 'Hello, ';

    return $namePart .
        'welcome to ' . SMS_SENDER_NAME .
        '. Available plans: Body Building, Boxing, Muay Thai, and Zumba. Please choose the plan you want to apply for. Thank you.';
}

function build_payment_sms(
    string $memberName = '',
    float $amount = 0,
    bool $success = true,
    string $reference = ''
): string {
    $namePart   = trim($memberName) !== '' ? 'Hello ' . trim($memberName) . ', ' : 'Hello, ';
    $amountText = 'PHP ' . number_format($amount, 2);
    $refText    = trim($reference) !== '' ? ' Ref#: ' . trim($reference) . '.' : '';

    if ($success) {
        return $namePart .
            'your payment was successful. Amount received: ' . $amountText . '.' .
            $refText .
            ' Thank you for your transaction with ' . SMS_SENDER_NAME . '.';
    }

    return $namePart .
        'your payment was not successful. Please contact the staff of ' . SMS_SENDER_NAME . ' for assistance.';
}

function build_rfid_load_sms(
    string $memberName = '',
    float $amountLoaded = 0,
    ?float $newBalance = null
): string {
    $namePart   = trim($memberName) !== '' ? 'Hello ' . trim($memberName) . ', ' : 'Hello, ';
    $amountText = 'PHP ' . number_format($amountLoaded, 2);

    $balanceText = '';
    if ($newBalance !== null) {
        $balanceText = ' Your new RFID balance is PHP ' . number_format($newBalance, 2) . '.';
    }

    return $namePart .
        'your RFID card was successfully loaded with ' . $amountText . '.' .
        $balanceText .
        ' Thank you for using ' . SMS_SENDER_NAME . '.';
}

function send_registration_plan_sms(
    mysqli $conn,
    ?int $userId,
    string $phone,
    string $memberName = ''
): array {
    $message = build_registration_plan_sms($memberName);
    return send_and_log_sms($conn, $userId, $phone, 'registration_plan', $message);
}

function send_payment_result_sms(
    mysqli $conn,
    ?int $userId,
    string $phone,
    string $memberName,
    float $amount,
    bool $success,
    string $reference = ''
): array {
    $message = build_payment_sms($memberName, $amount, $success, $reference);
    return send_and_log_sms($conn, $userId, $phone, 'payment_result', $message);
}

function send_rfid_load_sms(
    mysqli $conn,
    ?int $userId,
    string $phone,
    string $memberName,
    float $amountLoaded,
    ?float $newBalance = null
): array {
    $message = build_rfid_load_sms($memberName, $amountLoaded, $newBalance);
    return send_and_log_sms($conn, $userId, $phone, 'rfid_load', $message);
}