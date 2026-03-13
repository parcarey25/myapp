<?php
// rfid_attendance.php
// Handles RFID swipes and logs time_in / time_out in attendance table.
// Called via AJAX from all_attendance.php

if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';   // uses your existing DB connection

header('Content-Type: application/json');

// Make sure time is correct for PH
date_default_timezone_set('Asia/Manila');

$response = [
    'success' => false,
    'message' => 'Unknown error.'
];

try {
    // 1) Read RFID UID from POST
    $cardId = trim($_POST['card_id'] ?? '');
    if ($cardId === '') {
        $response['message'] = 'No RFID UID received.';
        echo json_encode($response);
        exit;
    }

    // 2) Look up user by RFID
    // ⚠️ This assumes your users table has a column named `rfid_uid`
    // If your column is named differently, change it here.
    if (!$st = $conn->prepare("SELECT id, full_name, username FROM users WHERE rfid_uid = ? LIMIT 1")) {
        throw new Exception('Failed to prepare user lookup.');
    }
    $st->bind_param('s', $cardId);
    $st->execute();
    $res = $st->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    if ($res) $res->free();
    $st->close();

    if (!$user) {
        $response['message'] = 'RFID card not registered to any user.';
        echo json_encode($response);
        exit;
    }

    $userId   = (int)$user['id'];
    $fullName = $user['full_name'] ?: $user['username'];

    // 3) Determine today's date and current time
    $today = date('Y-m-d');
    $now   = date('H:i:s');  // 24h time, e.g. 20:30:15

    // 4) Check if this user already has attendance for today
    if (!$st = $conn->prepare("SELECT id, time_in, time_out FROM attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1")) {
        throw new Exception('Failed to prepare attendance lookup.');
    }
    $st->bind_param('is', $userId, $today);
    $st->execute();
    $res = $st->get_result();
    $att = $res ? $res->fetch_assoc() : null;
    if ($res) $res->free();
    $st->close();

    // Logic:
    // - No row yet → create row with time_in = now
    // - Row exists, time_in is NULL → set time_in = now
    // - Row exists, time_out is NULL → set time_out = now
    // - Row exists, both set → update time_out = now (3rd+ swipe)
    if (!$att) {
        // 4a) Insert new attendance row
        if (!$st = $conn->prepare("INSERT INTO attendance (user_id, attendance_date, time_in, time_out)
                                   VALUES (?, ?, ?, NULL)")) {
            throw new Exception('Failed to prepare insert.');
        }
        $st->bind_param('iss', $userId, $today, $now);
        $st->execute();
        $st->close();

        $response['success'] = true;
        $response['message'] = 'Time IN recorded for ' . $fullName . ' at ' . date('H:i', strtotime($now));
    } else {
        $attId   = (int)$att['id'];
        $timeIn  = $att['time_in'];
        $timeOut = $att['time_out'];

        if (empty($timeIn)) {
            // 4b) Set time_in
            if (!$st = $conn->prepare("UPDATE attendance SET time_in = ? WHERE id = ?")) {
                throw new Exception('Failed to prepare update time_in.');
            }
            $st->bind_param('si', $now, $attId);
            $st->execute();
            $st->close();

            $response['success'] = true;
            $response['message'] = 'Time IN recorded for ' . $fullName . ' at ' . date('H:i', strtotime($now));
        } elseif (empty($timeOut) || $timeOut === '00:00:00') {
            // 4c) Set time_out
            if (!$st = $conn->prepare("UPDATE attendance SET time_out = ? WHERE id = ?")) {
                throw new Exception('Failed to prepare update time_out.');
            }
            $st->bind_param('si', $now, $attId);
            $st->execute();
            $st->close();

            $response['success'] = true;
            $response['message'] = 'Time OUT recorded for ' . $fullName . ' at ' . date('H:i', strtotime($now));
        } else {
            // 4d) Both present → update time_out (3rd+ swipe)
            if (!$st = $conn->prepare("UPDATE attendance SET time_out = ? WHERE id = ?")) {
                throw new Exception('Failed to prepare update time_out (overwrite).');
            }
            $st->bind_param('si', $now, $attId);
            $st->execute();
            $st->close();

            $response['success'] = true;
            $response['message'] = 'Time OUT UPDATED for ' . $fullName . ' to ' . date('H:i', strtotime($now));
        }
    }

} catch (Throwable $e) {
    // If something explodes (e.g. column name wrong), send message back
    $response['success'] = false;
    // You can change this to a generic message if you don’t want to expose details:
    $response['message'] = 'Server error: ' . $e->getMessage();
}

echo json_encode($response);