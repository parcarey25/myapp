<?php
// attendance_archive_lib.php
// Saves daily attendance records into:
// attendance_storage / YEAR / MONTH / daily_attendance_YYYY-MM-DD.doc
//
// This creates a Word-compatible HTML .doc file.
// If the same date is saved again, the old file is replaced.
// It also deletes the old .csv file for the same date to avoid confusion.

if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    header('Location: attendance_storage.php');
    exit;
}

function archive_valid_date(string $date): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function archive_table_exists(mysqli $conn, string $table): bool
{
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();

    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;

    if ($result) {
        $result->free();
    }

    $stmt->close();

    return $exists;
}

function archive_has_column(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;

    if ($result) {
        $result->free();
    }

    $stmt->close();

    return $exists;
}

function archive_first_column(mysqli $conn, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (archive_has_column($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function archive_create_year_month_folder(string $baseDir, string $year, string $month): bool
{
    $monthDir = $baseDir . '/' . $year . '/' . $month;

    if (!is_dir($monthDir)) {
        return mkdir($monthDir, 0777, true);
    }

    return true;
}

function archive_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function archive_time_display($value): string
{
    $value = trim((string)$value);

    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '—';
    }

    if (preg_match('/^\d{2}:\d{2}/', $value)) {
        return substr($value, 0, 5);
    }

    $time = strtotime($value);

    if ($time === false) {
        return $value;
    }

    return date('H:i', $time);
}

function archive_attendance_day(mysqli $conn, string $date): array
{
    if (!archive_valid_date($date)) {
        return [
            'ok' => false,
            'message' => 'Invalid date.',
            'file_path' => '',
            'file_url' => '',
            'count' => 0,
        ];
    }

    if (!archive_table_exists($conn, 'users')) {
        return [
            'ok' => false,
            'message' => 'Users table not found.',
            'file_path' => '',
            'file_url' => '',
            'count' => 0,
        ];
    }

    if (!archive_table_exists($conn, 'attendance')) {
        return [
            'ok' => false,
            'message' => 'Attendance table not found.',
            'file_path' => '',
            'file_url' => '',
            'count' => 0,
        ];
    }

    $timestamp = strtotime($date);
    $year = date('Y', $timestamp);
    $month = date('F', $timestamp);

    $baseDir = __DIR__ . '/attendance_storage';

    if (!archive_create_year_month_folder($baseDir, $year, $month)) {
        return [
            'ok' => false,
            'message' => 'Failed to create attendance storage folder.',
            'file_path' => '',
            'file_url' => '',
            'count' => 0,
        ];
    }

    $fileName = 'daily_attendance_' . $date . '.doc';
    $filePath = $baseDir . '/' . $year . '/' . $month . '/' . $fileName;

    $fileUrl = 'attendance_storage/'
        . rawurlencode($year) . '/'
        . rawurlencode($month) . '/'
        . rawurlencode($fileName);

    // Delete old CSV for the same date so you will not accidentally open the old plain-text file.
    $oldCsvPath = $baseDir . '/' . $year . '/' . $month . '/daily_attendance_' . $date . '.csv';

    if (is_file($oldCsvPath)) {
        @unlink($oldCsvPath);
    }

    $attendanceUserCol = archive_first_column($conn, 'attendance', [
        'user_id',
        'member_id',
    ]);

    $attendanceDateCol = archive_first_column($conn, 'attendance', [
        'attendance_date',
        'date',
    ]);

    $attendanceTimeInCol = archive_first_column($conn, 'attendance', [
        'time_in',
    ]);

    $attendanceTimeOutCol = archive_first_column($conn, 'attendance', [
        'time_out',
    ]);

    $attendanceCheckInCol = archive_first_column($conn, 'attendance', [
        'check_in',
    ]);

    $attendanceCheckOutCol = archive_first_column($conn, 'attendance', [
        'check_out',
    ]);

    if (!$attendanceUserCol || !$attendanceDateCol) {
        return [
            'ok' => false,
            'message' => 'Attendance table has no usable user/date columns.',
            'file_path' => '',
            'file_url' => '',
            'count' => 0,
        ];
    }

    $userFullNameExpr = archive_has_column($conn, 'users', 'full_name')
        ? "u.full_name"
        : "''";

    $userUsernameExpr = archive_has_column($conn, 'users', 'username')
        ? "u.username"
        : "''";

    $userIdNumberExpr = archive_has_column($conn, 'users', 'id_number')
        ? "u.id_number"
        : "''";

    $userRoleExpr = archive_has_column($conn, 'users', 'role')
        ? "u.role"
        : "''";

    $userEmailExpr = archive_has_column($conn, 'users', 'email')
        ? "u.email"
        : "''";

    $userPhoneExpr = archive_has_column($conn, 'users', 'phone')
        ? "u.phone"
        : (archive_has_column($conn, 'users', 'contact_number') ? "u.contact_number" : "''");

    $userRfidExpr = archive_has_column($conn, 'users', 'rfid_uid')
        ? "u.rfid_uid"
        : (archive_has_column($conn, 'users', 'rfid') ? "u.rfid" : "''");

    $timeInExpr = $attendanceTimeInCol
        ? "a.`{$attendanceTimeInCol}`"
        : ($attendanceCheckInCol ? "a.`{$attendanceCheckInCol}`" : "''");

    $timeOutExpr = $attendanceTimeOutCol
        ? "a.`{$attendanceTimeOutCol}`"
        : ($attendanceCheckOutCol ? "a.`{$attendanceCheckOutCol}`" : "''");

    /*
        This mirrors all_attendance.php:
        It shows all users, even if they have no attendance record for the selected date.
    */
    $sql = "
        SELECT
            u.id AS user_id,
            {$userFullNameExpr} AS full_name,
            {$userUsernameExpr} AS username,
            {$userIdNumberExpr} AS id_number,
            {$userRoleExpr} AS user_role,
            {$userEmailExpr} AS email,
            {$userPhoneExpr} AS phone,
            {$userRfidExpr} AS rfid_uid,
            a.id AS attendance_id,
            {$timeInExpr} AS time_in_value,
            {$timeOutExpr} AS time_out_value
        FROM users u
        LEFT JOIN attendance a
          ON a.`{$attendanceUserCol}` = u.id
         AND DATE(a.`{$attendanceDateCol}`) = ?
        ORDER BY
            {$userFullNameExpr} ASC,
            {$userUsernameExpr} ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [
            'ok' => false,
            'message' => 'Database prepare failed: ' . $conn->error,
            'file_path' => '',
            'file_url' => '',
            'count' => 0,
        ];
    }

    $stmt->bind_param('s', $date);
    $stmt->execute();

    $result = $stmt->get_result();

    $rowsHtml = '';
    $userCount = 0;
    $attendanceCount = 0;

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $userCount++;

            if (!empty($row['attendance_id'])) {
                $attendanceCount++;
            }

            $name = trim((string)($row['full_name'] ?? ''));

            if ($name === '') {
                $name = trim((string)($row['username'] ?? ''));
            }

            if ($name === '') {
                $name = 'User #' . (int)($row['user_id'] ?? 0);
            }

            $timeInDisplay = archive_time_display($row['time_in_value'] ?? '');
            $timeOutDisplay = archive_time_display($row['time_out_value'] ?? '');

            $rowsHtml .= '
                <tr>
                    <td>' . (int)$userCount . '</td>
                    <td>' . archive_e($name) . '</td>
                    <td>' . archive_e($row['id_number'] ?? '') . '</td>
                    <td>' . archive_e(ucfirst((string)($row['user_role'] ?? ''))) . '</td>
                    <td>' . archive_e($date) . '</td>
                    <td>' . archive_e($timeInDisplay) . '</td>
                    <td>' . archive_e($timeOutDisplay) . '</td>
                    <td>' . archive_e($row['rfid_uid'] ?? '') . '</td>
                    <td>' . archive_e($row['email'] ?? '') . '</td>
                    <td>' . archive_e($row['phone'] ?? '') . '</td>
                </tr>
            ';
        }

        $result->free();
    }

    $stmt->close();

    if ($rowsHtml === '') {
        $rowsHtml = '
            <tr>
                <td colspan="10" class="empty">
                    No users found.
                </td>
            </tr>
        ';
    }

    $generatedAt = date('Y-m-d h:i A');

    $docHtml = '<!doctype html>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>Daily Attendance ' . archive_e($date) . '</title>

<style>
    @page {
        size: landscape;
        margin: 0.45in;
    }

    body {
        font-family: Arial, sans-serif;
        color: #111111;
        background: #ffffff;
        margin: 0;
    }

    .header {
        width: 100%;
        border-bottom: 4px solid #b30000;
        padding-bottom: 10px;
        margin-bottom: 14px;
    }

    .brand {
        font-size: 24px;
        font-weight: bold;
        color: #b30000;
        margin: 0;
    }

    .title {
        font-size: 20px;
        font-weight: bold;
        margin: 6px 0 0 0;
        color: #111111;
    }

    .subtitle {
        color: #555555;
        font-size: 12px;
        margin-top: 4px;
    }

    .summary {
        margin: 12px 0 14px 0;
        padding: 10px;
        background: #f3f3f3;
        border-left: 5px solid #b30000;
        font-size: 12px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10px;
    }

    th {
        background: #111111;
        color: #ffffff;
        text-transform: uppercase;
        letter-spacing: 1px;
        padding: 7px;
        border: 1px solid #333333;
        text-align: left;
    }

    td {
        padding: 6px;
        border: 1px solid #cccccc;
        vertical-align: middle;
    }

    tr:nth-child(even) td {
        background: #f7f7f7;
    }

    .empty {
        text-align: center;
        color: #777777;
        padding: 20px;
    }

    .footer {
        margin-top: 14px;
        font-size: 10px;
        color: #666666;
        border-top: 1px solid #cccccc;
        padding-top: 8px;
    }
</style>
</head>

<body>

<div class="header">
    <p class="brand">RJL Fitness</p>
    <p class="title">Daily Attendance Report</p>
    <div class="subtitle">RFID-based Time In / Time Out Record</div>
</div>

<div class="summary">
    <strong>Attendance Date:</strong> ' . archive_e($date) . '<br>
    <strong>Total Users:</strong> ' . (int)$userCount . '<br>
    <strong>Users With Attendance Record:</strong> ' . (int)$attendanceCount . '<br>
    <strong>Generated:</strong> ' . archive_e($generatedAt) . '
</div>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Name</th>
            <th>ID Number</th>
            <th>Role</th>
            <th>Date</th>
            <th>Time In</th>
            <th>Time Out</th>
            <th>RFID UID</th>
            <th>Email</th>
            <th>Phone</th>
        </tr>
    </thead>
    <tbody>
        ' . $rowsHtml . '
    </tbody>
</table>

<div class="footer">
    This document was generated by RJL Fitness Attendance Storage.
</div>

</body>
</html>';

    $saved = file_put_contents($filePath, $docHtml);

    if ($saved === false) {
        return [
            'ok' => false,
            'message' => 'Failed to create Word attendance file.',
            'file_path' => '',
            'file_url' => '',
            'count' => 0,
        ];
    }

    return [
        'ok' => true,
        'message' => 'Attendance saved as Word-style file successfully. Total users: ' . $userCount,
        'file_path' => $filePath,
        'file_url' => $fileUrl,
        'count' => $userCount,
    ];
}