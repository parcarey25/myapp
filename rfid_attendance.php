<?php
// rfid_attendance.php
// Backend for all_attendance.php RFID modal.
// Do not open this file directly unless testing with ?card_id=RFID_NUMBER

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require __DIR__ . '/db.php';

date_default_timezone_set('Asia/Manila');

function json_response(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

function table_exists(mysqli $conn, string $table): bool
{
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ";

    $st = $conn->prepare($sql);
    if (!$st) return false;

    $st->bind_param('s', $table);
    $st->execute();

    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;

    if ($res) $res->free();
    $st->close();

    return $ok;
}

function has_column(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $st = $conn->prepare($sql);
    if (!$st) return false;

    $st->bind_param('ss', $table, $column);
    $st->execute();

    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;

    if ($res) $res->free();
    $st->close();

    return $ok;
}

function first_column(mysqli $conn, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (has_column($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function bind_dynamic(mysqli_stmt $stmt, string $types, array &$params): bool
{
    $refs = [];
    $refs[] = &$types;

    foreach ($params as $k => &$v) {
        $refs[] = &$v;
    }

    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

function member_has_active_membership(mysqli $conn, int $userId, ?string $membershipExpiresAt): array
{
    $now = date('Y-m-d H:i:s');

    // Check users.membership_expires_at first
    if ($membershipExpiresAt !== null && trim($membershipExpiresAt) !== '') {
        $expires = strtotime($membershipExpiresAt);

        if ($expires !== false && $expires >= strtotime($now)) {
            return [
                'active' => true,
                'reason' => 'Active membership from users table',
            ];
        }
    }

    // Check memberships table
    if (!table_exists($conn, 'memberships')) {
        return [
            'active' => false,
            'reason' => 'No membership found',
        ];
    }

    $userCol = first_column($conn, 'memberships', [
        'user_id',
        'member_id',
    ]);

    $startCol = first_column($conn, 'memberships', [
        'start_date',
        'date_start',
        'membership_start',
    ]);

    $endCol = first_column($conn, 'memberships', [
        'end_date',
        'date_end',
        'membership_end',
        'expires_at',
        'membership_expires_at',
    ]);

    $statusCol = first_column($conn, 'memberships', [
        'status',
        'membership_status',
    ]);

    if (!$userCol || !$endCol) {
        return [
            'active' => false,
            'reason' => 'No membership found',
        ];
    }

    // Auto-expire old active memberships
    if ($statusCol) {
        $sqlExpire = "
            UPDATE memberships
            SET `$statusCol` = 'expired'
            WHERE `$userCol` = ?
              AND LOWER(TRIM(`$statusCol`)) = 'active'
              AND DATE(`$endCol`) < CURDATE()
        ";

        if ($stExpire = $conn->prepare($sqlExpire)) {
            $stExpire->bind_param('i', $userId);
            $stExpire->execute();
            $stExpire->close();
        }
    }

    // Check active membership
    $where = [];
    $params = [];
    $types = '';

    $where[] = "`$userCol` = ?";
    $params[] = $userId;
    $types .= 'i';

    if ($startCol) {
        $where[] = "(DATE(`$startCol`) <= CURDATE() OR `$startCol` IS NULL)";
    }

    $where[] = "DATE(`$endCol`) >= CURDATE()";

    if ($statusCol) {
        $where[] = "LOWER(TRIM(`$statusCol`)) IN ('active', 'paid', 'approved', 'current')";
    }

    $sql = "
        SELECT id
        FROM memberships
        WHERE " . implode(' AND ', $where) . "
        ORDER BY DATE(`$endCol`) DESC
        LIMIT 1
    ";

    $st = $conn->prepare($sql);

    if (!$st) {
        return [
            'active' => false,
            'reason' => 'Membership check error',
        ];
    }

    bind_dynamic($st, $types, $params);
    $st->execute();

    $res = $st->get_result();
    $active = $res && $res->num_rows > 0;

    if ($res) $res->free();
    $st->close();

    if ($active) {
        return [
            'active' => true,
            'reason' => 'Active membership from memberships table',
        ];
    }

    // Check if member has any membership at all
    $sqlAny = "
        SELECT id
        FROM memberships
        WHERE `$userCol` = ?
        LIMIT 1
    ";

    $stAny = $conn->prepare($sqlAny);

    if ($stAny) {
        $stAny->bind_param('i', $userId);
        $stAny->execute();

        $resAny = $stAny->get_result();
        $hasAny = $resAny && $resAny->num_rows > 0;

        if ($resAny) $resAny->free();
        $stAny->close();

        if (!$hasAny) {
            return [
                'active' => false,
                'reason' => 'No membership found',
            ];
        }
    }

    return [
        'active' => false,
        'reason' => 'Membership expired or not active',
    ];
}

// Security
if (!isset($_SESSION['user_id'])) {
    json_response(false, 'Session expired. Please login again.');
}

$currentRole = strtolower($_SESSION['role'] ?? '');

if (!in_array($currentRole, ['staff', 'admin'], true)) {
    json_response(false, 'Only staff or admin can log RFID attendance.');
}

// Get RFID from all_attendance.php modal
$rfid = trim(
    $_POST['card_id']
    ?? $_POST['rfid_uid']
    ?? $_POST['rfid']
    ?? $_GET['card_id']
    ?? $_GET['rfid_uid']
    ?? $_GET['rfid']
    ?? ''
);

if ($rfid === '') {
    json_response(false, 'No RFID detected.');
}

// Find user
if (!table_exists($conn, 'users')) {
    json_response(false, 'Users table not found.');
}

$rfidCol = first_column($conn, 'users', [
    'rfid_uid',
    'rfid',
    'rfid_number',
    'card_uid',
]);

if (!$rfidCol) {
    json_response(false, 'RFID column not found in users table.');
}

$select = [];
$select[] = 'id';
$select[] = has_column($conn, 'users', 'username') ? 'username' : "'' AS username";
$select[] = has_column($conn, 'users', 'full_name') ? 'full_name' : "'' AS full_name";
$select[] = has_column($conn, 'users', 'role') ? 'role' : "'member' AS role";
$select[] = has_column($conn, 'users', 'status') ? 'status' : "'active' AS status";
$select[] = has_column($conn, 'users', 'membership_expires_at') ? 'membership_expires_at' : "NULL AS membership_expires_at";

$sqlUser = "
    SELECT " . implode(', ', $select) . "
    FROM users
    WHERE `$rfidCol` = ?
    LIMIT 1
";

$stUser = $conn->prepare($sqlUser);

if (!$stUser) {
    json_response(false, 'Database error while checking RFID.');
}

$stUser->bind_param('s', $rfid);
$stUser->execute();

$resUser = $stUser->get_result();
$user = $resUser ? $resUser->fetch_assoc() : null;

if ($resUser) $resUser->free();
$stUser->close();

if (!$user) {
    json_response(false, 'RFID not registered.');
}

$targetUserId = (int)$user['id'];

$targetName = trim($user['full_name'] ?? '') !== ''
    ? trim($user['full_name'])
    : trim($user['username'] ?? 'User');

$targetRole = strtolower(trim($user['role'] ?? 'member'));
$targetStatus = strtolower(trim($user['status'] ?? 'active'));

// Block inactive/pending accounts
if ($targetStatus !== '' && $targetStatus !== 'active') {
    json_response(false, $targetName . ' cannot enter. Account status is ' . strtoupper($targetStatus) . '.');
}

// Block member if no/expired membership
if ($targetRole === 'member') {
    $membershipCheck = member_has_active_membership(
        $conn,
        $targetUserId,
        $user['membership_expires_at'] ?? null
    );

    if (!$membershipCheck['active']) {
        if ($membershipCheck['reason'] === 'No membership found') {
            json_response(false, $targetName . ' cannot enter. No membership found.');
        }

        json_response(false, $targetName . ' cannot enter. Membership is expired or not active.');
    }
}

// Attendance table
if (!table_exists($conn, 'attendance')) {
    json_response(false, 'Attendance table not found.');
}

$userIdCol = first_column($conn, 'attendance', ['user_id', 'member_id']);
$dateCol = first_column($conn, 'attendance', ['attendance_date', 'date']);
$timeInCol = first_column($conn, 'attendance', ['time_in']);
$timeOutCol = first_column($conn, 'attendance', ['time_out']);
$checkInCol = first_column($conn, 'attendance', ['check_in']);
$checkOutCol = first_column($conn, 'attendance', ['check_out']);
$createdAtCol = first_column($conn, 'attendance', ['created_at']);
$updatedAtCol = first_column($conn, 'attendance', ['updated_at']);

if (!$userIdCol || !$dateCol) {
    json_response(false, 'Attendance table has no usable user/date columns.');
}

if (!$timeInCol && !$checkInCol) {
    json_response(false, 'Attendance table has no time_in or check_in column.');
}

$today = date('Y-m-d');
$timeNow = date('H:i:s');
$dateTimeNow = date('Y-m-d H:i:s');

// Check existing attendance today
$selectAtt = [];
$selectAtt[] = 'id';
$selectAtt[] = $timeInCol ? "`$timeInCol` AS time_in_value" : "NULL AS time_in_value";
$selectAtt[] = $timeOutCol ? "`$timeOutCol` AS time_out_value" : "NULL AS time_out_value";

$sqlAtt = "
    SELECT " . implode(', ', $selectAtt) . "
    FROM attendance
    WHERE `$userIdCol` = ?
      AND DATE(`$dateCol`) = ?
    ORDER BY id DESC
    LIMIT 1
";

$stAtt = $conn->prepare($sqlAtt);

if (!$stAtt) {
    json_response(false, 'Database error while checking attendance.');
}

$stAtt->bind_param('is', $targetUserId, $today);
$stAtt->execute();

$resAtt = $stAtt->get_result();
$att = $resAtt ? $resAtt->fetch_assoc() : null;

if ($resAtt) $resAtt->free();
$stAtt->close();

// First swipe: time in
if (!$att) {
    $cols = [];
    $vals = [];
    $params = [];
    $types = '';

    $cols[] = "`$userIdCol`";
    $vals[] = '?';
    $params[] = $targetUserId;
    $types .= 'i';

    $cols[] = "`$dateCol`";
    $vals[] = '?';
    $params[] = $today;
    $types .= 's';

    if ($timeInCol) {
        $cols[] = "`$timeInCol`";
        $vals[] = '?';
        $params[] = $timeNow;
        $types .= 's';
    }

    if ($checkInCol) {
        $cols[] = "`$checkInCol`";
        $vals[] = '?';
        $params[] = $dateTimeNow;
        $types .= 's';
    }

    if ($createdAtCol) {
        $cols[] = "`$createdAtCol`";
        $vals[] = '?';
        $params[] = $dateTimeNow;
        $types .= 's';
    }

    if ($updatedAtCol) {
        $cols[] = "`$updatedAtCol`";
        $vals[] = '?';
        $params[] = $dateTimeNow;
        $types .= 's';
    }

    $sqlInsert = "
        INSERT INTO attendance (" . implode(', ', $cols) . ")
        VALUES (" . implode(', ', $vals) . ")
    ";

    $stInsert = $conn->prepare($sqlInsert);

    if (!$stInsert) {
        json_response(false, 'Database error while saving time in.');
    }

    bind_dynamic($stInsert, $types, $params);
    $ok = $stInsert->execute();
    $stInsert->close();

    if (!$ok) {
        json_response(false, 'Failed to save time in.');
    }

    json_response(true, 'Time In recorded for ' . $targetName . ' at ' . date('h:i A') . '.');
}

$attId = (int)$att['id'];
$timeIn = $att['time_in_value'] ?? null;

// If record exists but time_in is empty
if ($timeIn === null || $timeIn === '') {
    $sets = [];
    $params = [];
    $types = '';

    if ($timeInCol) {
        $sets[] = "`$timeInCol` = ?";
        $params[] = $timeNow;
        $types .= 's';
    }

    if ($checkInCol) {
        $sets[] = "`$checkInCol` = ?";
        $params[] = $dateTimeNow;
        $types .= 's';
    }

    if ($updatedAtCol) {
        $sets[] = "`$updatedAtCol` = ?";
        $params[] = $dateTimeNow;
        $types .= 's';
    }

    $params[] = $attId;
    $types .= 'i';

    $sqlUpdate = "
        UPDATE attendance
        SET " . implode(', ', $sets) . "
        WHERE id = ?
        LIMIT 1
    ";

    $stUpdate = $conn->prepare($sqlUpdate);

    if (!$stUpdate) {
        json_response(false, 'Database error while updating time in.');
    }

    bind_dynamic($stUpdate, $types, $params);
    $ok = $stUpdate->execute();
    $stUpdate->close();

    if (!$ok) {
        json_response(false, 'Failed to update time in.');
    }

    json_response(true, 'Time In recorded for ' . $targetName . ' at ' . date('h:i A') . '.');
}

// Second/third swipe: time out
$sets = [];
$params = [];
$types = '';

if ($timeOutCol) {
    $sets[] = "`$timeOutCol` = ?";
    $params[] = $timeNow;
    $types .= 's';
}

if ($checkOutCol) {
    $sets[] = "`$checkOutCol` = ?";
    $params[] = $dateTimeNow;
    $types .= 's';
}

if ($updatedAtCol) {
    $sets[] = "`$updatedAtCol` = ?";
    $params[] = $dateTimeNow;
    $types .= 's';
}

if (!$sets) {
    json_response(false, 'Attendance table has no time_out or check_out column.');
}

$params[] = $attId;
$types .= 'i';

$sqlOut = "
    UPDATE attendance
    SET " . implode(', ', $sets) . "
    WHERE id = ?
    LIMIT 1
";

$stOut = $conn->prepare($sqlOut);

if (!$stOut) {
    json_response(false, 'Database error while updating time out.');
}

bind_dynamic($stOut, $types, $params);
$ok = $stOut->execute();
$stOut->close();

if (!$ok) {
    json_response(false, 'Failed to update time out.');
}

json_response(true, 'Time Out recorded for ' . $targetName . ' at ' . date('h:i A') . '.');