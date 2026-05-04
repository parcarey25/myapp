<?php
// users.php — Clean wide All Member Info page with fixed table design

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function has_column(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COLUMN_NAME
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

function first_existing_column(mysqli $conn, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (has_column($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function badge_class(string $value): string
{
    $value = strtolower(trim($value));

    if ($value === 'active' || $value === 'approved') {
        return 'badge-green';
    }

    if ($value === 'pending') {
        return 'badge-yellow';
    }

    if ($value === 'inactive' || $value === 'none') {
        return 'badge-gray';
    }

    if ($value === 'blocked' || $value === 'rejected' || $value === 'expired') {
        return 'badge-red';
    }

    return 'badge-blue';
}

function clean_date_for_input(?string $date): string
{
    if (!$date) {
        return '';
    }

    $time = strtotime($date);

    if ($time === false) {
        return '';
    }

    return date('Y-m-d', $time);
}

function redirect_back_with_flash(string $type, string $message): void
{
    $_SESSION['users_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    $q = trim((string)($_POST['filter_q'] ?? $_GET['q'] ?? ''));
    $status = trim((string)($_POST['filter_status'] ?? $_GET['status'] ?? 'all'));

    $query = http_build_query([
        'q' => $q,
        'status' => $status,
    ]);

    header('Location: users.php?' . $query);
    exit;
}

$currentUsername = $_SESSION['username'] ?? 'staff';
$currentUserRole = strtolower(trim($_SESSION['role'] ?? 'staff'));
$currentRoleLabel = strtoupper($currentUserRole ?: 'STAFF');

$homeLink = 'home.php';

if ($currentUserRole === 'admin') {
    $homeLink = 'home_admin.php';
} elseif ($currentUserRole === 'staff') {
    $homeLink = 'home_staff.php';
} elseif ($currentUserRole === 'trainer') {
    $homeLink = file_exists(__DIR__ . '/home_trainer.php') ? 'home_trainer.php' : 'home_staff.php';
}

$HAS_ROLE = has_column($conn, 'users', 'role');
$HAS_FULLNAME = has_column($conn, 'users', 'full_name');
$HAS_EMAIL = has_column($conn, 'users', 'email');
$HAS_STATUS = has_column($conn, 'users', 'status');
$HAS_EXPIRY = has_column($conn, 'users', 'membership_expires_at');

$AVATAR_COL = first_existing_column($conn, 'users', [
    'avatar_path',
    'avatar',
    'profile_pic',
    'profile_image',
    'photo',
]);

$VALID_ID_STATUS_COL = first_existing_column($conn, 'users', [
    'valid_id_status',
    'id_status',
    'valid_id_approval',
    'valid_id_state',
]);

$VALID_ID_FILE_COL = first_existing_column($conn, 'users', [
    'valid_id_path',
    'valid_id_file',
    'id_file',
    'id_path',
    'valid_id_image',
]);

$flash = $_SESSION['users_flash'] ?? null;
unset($_SESSION['users_flash']);

/* ---------- Handle updates ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);

    if ($uid <= 0) {
        redirect_back_with_flash('error', 'Invalid member selected.');
    }

    if ($action === 'update_status') {
        if (!$HAS_STATUS) {
            redirect_back_with_flash('error', 'Status column does not exist in users table.');
        }

        $newStatus = trim((string)($_POST['status'] ?? 'Active'));

        if ($newStatus === '') {
            $newStatus = 'Active';
        }

        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ? LIMIT 1");

        if (!$stmt) {
            redirect_back_with_flash('error', 'Failed to prepare status update.');
        }

        $stmt->bind_param('si', $newStatus, $uid);
        $success = $stmt->execute();
        $stmt->close();

        redirect_back_with_flash(
            $success ? 'success' : 'error',
            $success ? 'Status updated successfully.' : 'Failed to update status.'
        );
    }

    if ($action === 'update_expiry') {
        if (!$HAS_EXPIRY) {
            redirect_back_with_flash('error', 'membership_expires_at column does not exist in users table.');
        }

        $expiry = trim((string)($_POST['membership_expires_at'] ?? ''));

        if ($expiry === '') {
            $stmt = $conn->prepare("UPDATE users SET membership_expires_at = NULL WHERE id = ? LIMIT 1");

            if (!$stmt) {
                redirect_back_with_flash('error', 'Failed to prepare expiry update.');
            }

            $stmt->bind_param('i', $uid);
            $success = $stmt->execute();
            $stmt->close();

            redirect_back_with_flash(
                $success ? 'success' : 'error',
                $success ? 'Membership expiry cleared.' : 'Failed to clear membership expiry.'
            );
        }

        $dateObj = DateTime::createFromFormat('Y-m-d', $expiry);

        if (!$dateObj) {
            redirect_back_with_flash('error', 'Invalid expiry date.');
        }

        $expiryValue = $dateObj->format('Y-m-d') . ' 23:59:59';

        $stmt = $conn->prepare("UPDATE users SET membership_expires_at = ? WHERE id = ? LIMIT 1");

        if (!$stmt) {
            redirect_back_with_flash('error', 'Failed to prepare expiry update.');
        }

        $stmt->bind_param('si', $expiryValue, $uid);
        $success = $stmt->execute();
        $stmt->close();

        redirect_back_with_flash(
            $success ? 'success' : 'error',
            $success ? 'Membership expiry updated successfully.' : 'Failed to update membership expiry.'
        );
    }

    if ($action === 'update_valid_id') {
        if (!$VALID_ID_STATUS_COL) {
            redirect_back_with_flash('error', 'Valid ID status column does not exist in users table.');
        }

        $newValidStatus = strtoupper(trim((string)($_POST['valid_id_status'] ?? 'NONE')));

        if ($newValidStatus === '') {
            $newValidStatus = 'NONE';
        }

        $allowedValidStatuses = ['NONE', 'PENDING', 'APPROVED', 'REJECTED'];

        if (!in_array($newValidStatus, $allowedValidStatuses, true)) {
            $newValidStatus = 'NONE';
        }

        $sql = "UPDATE users SET `{$VALID_ID_STATUS_COL}` = ? WHERE id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            redirect_back_with_flash('error', 'Failed to prepare valid ID update.');
        }

        $stmt->bind_param('si', $newValidStatus, $uid);
        $success = $stmt->execute();
        $stmt->close();

        redirect_back_with_flash(
            $success ? 'success' : 'error',
            $success ? 'Valid ID status updated successfully.' : 'Failed to update valid ID status.'
        );
    }

    redirect_back_with_flash('error', 'Invalid action.');
}

/* ---------- Filters ---------- */
$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));

$where = [];
$params = [];
$types = '';

if ($HAS_ROLE) {
    $where[] = "LOWER(`role`) = 'member'";
}

if ($HAS_STATUS && $statusFilter !== '' && strtolower($statusFilter) !== 'all') {
    $where[] = "LOWER(`status`) = ?";
    $params[] = strtolower($statusFilter);
    $types .= 's';
}

if ($q !== '') {
    $like = '%' . $q . '%';

    $searchParts = [];

    $searchParts[] = "CAST(id AS CHAR) LIKE ?";
    $params[] = $like;
    $types .= 's';

    $searchParts[] = "username LIKE ?";
    $params[] = $like;
    $types .= 's';

    if ($HAS_FULLNAME) {
        $searchParts[] = "full_name LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    if ($HAS_EMAIL) {
        $searchParts[] = "email LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

/* ---------- Select columns safely ---------- */
$select = [];
$select[] = 'id';
$select[] = 'username';

if ($HAS_FULLNAME) {
    $select[] = 'full_name';
} else {
    $select[] = "'' AS full_name";
}

if ($HAS_EMAIL) {
    $select[] = 'email';
} else {
    $select[] = "'' AS email";
}

if ($HAS_STATUS) {
    $select[] = 'status';
} else {
    $select[] = "'N/A' AS status";
}

if ($HAS_EXPIRY) {
    $select[] = 'membership_expires_at';
} else {
    $select[] = "NULL AS membership_expires_at";
}

if ($AVATAR_COL) {
    $select[] = "`{$AVATAR_COL}` AS avatar";
} else {
    $select[] = "'photo/logo.jpg' AS avatar";
}

if ($VALID_ID_STATUS_COL) {
    $select[] = "`{$VALID_ID_STATUS_COL}` AS valid_id_status";
} else {
    $select[] = "'NONE' AS valid_id_status";
}

if ($VALID_ID_FILE_COL) {
    $select[] = "`{$VALID_ID_FILE_COL}` AS valid_id_file";
} else {
    $select[] = "'' AS valid_id_file";
}

$sql = "SELECT " . implode(', ', $select) . " FROM users";

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY id DESC";

$rows = [];

$stmt = $conn->prepare($sql);

if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }

    $stmt->close();
}

$totalMembers = count($rows);

$statusOptions = ['All', 'Active', 'Inactive', 'Pending', 'Blocked'];
$inlineStatusOptions = ['Active', 'Inactive', 'Pending', 'Blocked'];
$validStatusOptions = ['NONE', 'PENDING', 'APPROVED', 'REJECTED'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>All Member Info | RJL Fitness</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --red: #ed1c24;
            --red-dark: #8b0000;
            --red-soft: rgba(237, 28, 36, 0.16);
            --bg: #050505;
            --panel: #131313;
            --border: rgba(255, 255, 255, 0.10);
            --border-2: rgba(255, 255, 255, 0.16);
            --text: #ffffff;
            --muted: #b8b8b8;
            --green: #24c46b;
            --yellow: #f5bd3d;
            --blue: #4d8dff;
            --danger: #ff4b5c;
            --shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
            --radius: 24px;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
        }

        body {
            margin: 0;
            color: var(--text);
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(237, 28, 36, 0.16), transparent 34%),
                radial-gradient(circle at bottom right, rgba(237, 28, 36, 0.10), transparent 30%),
                linear-gradient(135deg, #020202, #111 50%, #050505);
            overflow-x: hidden;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        /* Header */
        .rjl-header {
            width: 100%;
            min-height: 92px;
            background: linear-gradient(90deg, #650000 0%, #970000 45%, #c00000 100%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px;
            position: sticky;
            top: 0;
            z-index: 999;
        }

        .rjl-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .rjl-menu-btn {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.35);
            background: rgba(0, 0, 0, 0.12);
            color: #ffffff;
            font-size: 25px;
            line-height: 1;
            display: grid;
            place-items: center;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .rjl-menu-btn:hover {
            background: rgba(0, 0, 0, 0.28);
            transform: translateY(-1px);
        }

        .rjl-brand-logo {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            object-fit: cover;
            background: #111;
            border: 2px solid rgba(255, 255, 255, 0.85);
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.35);
        }

        .rjl-brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.05;
        }

        .rjl-brand-title {
            color: #ffffff;
            font-size: 1.55rem;
            font-weight: 950;
            letter-spacing: -0.03em;
        }

        .rjl-brand-subtitle {
            color: rgba(255, 255, 255, 0.86);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.32em;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .rjl-header-right {
            display: flex;
            align-items: center;
            gap: 13px;
            color: #ffffff;
        }

        .rjl-welcome {
            font-size: 0.92rem;
            color: #ffffff;
            white-space: nowrap;
        }

        .rjl-welcome strong {
            font-weight: 950;
        }

        .rjl-role-pill {
            padding: 8px 15px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.16);
            color: #ffffff;
            font-size: 0.76rem;
            font-weight: 950;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .rjl-right-logo {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            object-fit: cover;
            background: #111;
            border: 2px solid rgba(255, 255, 255, 0.85);
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.35);
        }

        .page-shell {
            width: min(1580px, calc(100vw - 36px));
            max-width: 1580px;
            margin: 0 auto;
            padding: 30px 0 50px;
        }

        .panel {
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.065), rgba(255, 255, 255, 0.025));
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(12px);
        }

        .filter-panel {
            padding: 22px;
            margin-bottom: 18px;
        }

        .filter-title {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
        }

        .filter-title h2 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 950;
        }

        .filter-title p {
            margin: 6px 0 0;
            color: var(--muted);
        }

        .result-pill {
            padding: 9px 13px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border);
            color: var(--muted);
            font-weight: 800;
            white-space: nowrap;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 260px 100px;
            gap: 12px;
            align-items: end;
        }

        .field label {
            display: block;
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 7px;
        }

        .control,
        .select-control,
        .date-control {
            width: 100%;
            height: 42px;
            border-radius: 13px;
            border: 1px solid var(--border-2);
            background: rgba(0, 0, 0, 0.28);
            color: #fff;
            padding: 0 13px;
            outline: none;
            font-size: 0.95rem;
        }

        .control:focus,
        .select-control:focus,
        .date-control:focus {
            border-color: rgba(237, 28, 36, 0.70);
            box-shadow: 0 0 0 4px rgba(237, 28, 36, 0.16);
        }

        select option {
            background: #111;
            color: #fff;
        }

        .btn {
            height: 42px;
            border: 0;
            border-radius: 999px;
            padding: 0 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 950;
            font-size: 0.82rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            cursor: pointer;
            transition: 0.18s ease;
            white-space: nowrap;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-red {
            background: linear-gradient(135deg, var(--red), #ff424a);
            box-shadow: 0 14px 26px rgba(237, 28, 36, 0.30);
        }

        .btn-dark {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border);
        }

        .btn-small {
            height: 36px;
            padding: 0 13px;
            font-size: 0.74rem;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.30);
        }

        .flash {
            padding: 14px 16px;
            border-radius: 18px;
            margin-bottom: 18px;
            border: 1px solid var(--border);
            font-weight: 700;
        }

        .flash-success {
            background: rgba(36, 196, 107, 0.14);
            color: #d9ffe8;
            border-color: rgba(36, 196, 107, 0.35);
        }

        .flash-error {
            background: rgba(255, 75, 92, 0.14);
            color: #ffe1e4;
            border-color: rgba(255, 75, 92, 0.35);
        }

        .warning-box {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(245, 189, 61, 0.14);
            border: 1px solid rgba(245, 189, 61, 0.32);
            color: #fff2c9;
        }

        /* Fixed table design */
        .table-panel {
            overflow: hidden;
        }

        .table-head {
            padding: 18px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            border-bottom: 1px solid var(--border);
            min-height: auto;
        }

        .table-head strong {
            font-size: 1rem;
        }

        .table-head span {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .table-scroll {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            padding: 0;
        }

        .members-table {
            width: 100%;
            min-width: 1380px;
            border-collapse: collapse;
        }

        .members-table thead,
        .members-table tbody,
        .members-table tr,
        .members-table th,
        .members-table td {
            position: static;
        }

        .members-table th {
            background: #191919;
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 950;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            text-align: left;
            padding: 16px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.10);
            white-space: nowrap;
            vertical-align: middle;
        }

        .members-table td {
            padding: 18px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);
            vertical-align: middle;
            color: #fff;
        }

        .members-table tbody tr:hover td {
            background: rgba(255, 255, 255, 0.035);
        }

        .col-id {
            width: 70px;
        }

        .col-avatar {
            width: 92px;
        }

        .col-name {
            width: 210px;
        }

        .col-email {
            width: 280px;
        }

        .col-status {
            width: 230px;
        }

        .col-expiry {
            width: 260px;
        }

        .col-valid {
            width: 260px;
        }

        .col-actions {
            width: 170px;
            text-align: right;
        }

        .avatar-img {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            object-fit: cover;
            display: block;
            border: 2px solid rgba(255, 255, 255, 0.22);
            background: #111;
        }

        .member-username {
            font-weight: 950;
            margin-bottom: 4px;
        }

        .member-name {
            color: var(--muted);
            font-size: 0.88rem;
            line-height: 1.35;
            max-width: 190px;
            white-space: normal;
        }

        .email-text {
            max-width: 270px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .inline-form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
        }

        .inline-form .select-control {
            width: 125px;
            height: 38px;
        }

        .inline-form .date-control {
            width: 150px;
            height: 38px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 0.70rem;
            font-weight: 950;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .badge-green {
            color: #d9ffe8;
            background: rgba(36, 196, 107, 0.18);
            border: 1px solid rgba(36, 196, 107, 0.36);
        }

        .badge-yellow {
            color: #fff2c9;
            background: rgba(245, 189, 61, 0.18);
            border: 1px solid rgba(245, 189, 61, 0.36);
        }

        .badge-gray {
            color: #eef2f7;
            background: rgba(148, 163, 184, 0.18);
            border: 1px solid rgba(148, 163, 184, 0.32);
        }

        .badge-red {
            color: #ffe1e4;
            background: rgba(255, 75, 92, 0.18);
            border: 1px solid rgba(255, 75, 92, 0.36);
        }

        .badge-blue {
            color: #e0ecff;
            background: rgba(77, 141, 255, 0.18);
            border: 1px solid rgba(77, 141, 255, 0.36);
        }

        .valid-box {
            display: grid;
            gap: 8px;
        }

        .valid-current {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            font-size: 0.86rem;
        }

        .valid-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
        }

        .valid-actions .select-control {
            width: 120px;
            height: 38px;
        }

        .row-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
        }

        .empty-state {
            text-align: center;
            padding: 44px 20px;
            color: var(--muted);
        }

        @media (max-width: 900px) {
            .page-shell {
                width: calc(100vw - 20px);
                padding-top: 20px;
            }

            .rjl-header {
                min-height: auto;
                padding: 14px;
                align-items: flex-start;
                flex-direction: column;
                gap: 14px;
            }

            .rjl-header-right {
                width: 100%;
                justify-content: space-between;
            }

            .rjl-brand-title {
                font-size: 1.25rem;
            }

            .rjl-brand-subtitle {
                font-size: 0.66rem;
                letter-spacing: 0.20em;
            }

            .rjl-brand-logo,
            .rjl-right-logo {
                width: 48px;
                height: 48px;
            }

            .filter-title,
            .table-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .members-table {
                min-width: 1320px;
            }
        }
    </style>
</head>

<body>

<header class="rjl-header">
    <div class="rjl-header-left">
        <button type="button" class="rjl-menu-btn" onclick="window.location.href='<?= h($homeLink) ?>'">
            ☰
        </button>

        <img src="photo/logo.jpg" alt="RJL Fitness Logo" class="rjl-brand-logo">

        <div class="rjl-brand-text">
            <div class="rjl-brand-title">RJL Fitness</div>
            <div class="rjl-brand-subtitle">Staff Control Panel</div>
        </div>
    </div>

    <div class="rjl-header-right">
        <div class="rjl-welcome">
            Welcome, <strong><?= h($currentUsername) ?></strong>
        </div>

        <div class="rjl-role-pill">
            <?= h($currentRoleLabel) ?>
        </div>

        <img src="photo/logo.jpg" alt="RJL Fitness Logo" class="rjl-right-logo">
    </div>
</header>

<main class="page-shell">

    <?php if (!$HAS_ROLE): ?>
        <div class="warning-box">
            Warning: users.role column was not found. The page cannot strictly filter members only without a role column.
        </div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="flash <?= $flash['type'] === 'success' ? 'flash-success' : 'flash-error' ?>">
            <?= h($flash['message']) ?>
        </div>
    <?php endif; ?>

    <section class="panel filter-panel">
        <div class="filter-title">
            <div>
                <h2>Search Members</h2>
                <p>Search by ID, name, username, or email.</p>
            </div>

            <div class="result-pill">
                Results: <?= (int)$totalMembers ?>
            </div>
        </div>

        <form method="get" action="users.php">
            <div class="filter-grid">
                <div class="field">
                    <label for="q">Search</label>
                    <input
                        type="text"
                        id="q"
                        name="q"
                        class="control"
                        value="<?= h($q) ?>"
                        placeholder="name, username, email, or ID"
                    >
                </div>

                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="select-control" <?= !$HAS_STATUS ? 'disabled' : '' ?>>
                        <?php foreach ($statusOptions as $option): ?>
                            <option value="<?= h($option) ?>" <?= strtolower($statusFilter) === strtolower($option) ? 'selected' : '' ?>>
                                <?= h($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-red">Go</button>
            </div>
        </form>
    </section>

    <section class="panel table-panel">
        <div class="table-head">
            <strong>Members List</strong>
            <span>Showing members only • Wider clean layout</span>
        </div>

        <div class="table-scroll">
            <table class="members-table">
                <thead>
                    <tr>
                        <th class="col-id">#</th>
                        <th class="col-avatar">Avatar</th>
                        <th class="col-name">Username / Name</th>
                        <th class="col-email">Email</th>
                        <th class="col-status">Status</th>
                        <th class="col-expiry">Membership Expires</th>
                        <th class="col-valid">Valid ID</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    No members found.
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row): ?>
                        <?php
                            $memberId = (int)($row['id'] ?? 0);
                            $avatar = trim((string)($row['avatar'] ?? ''));

                            if ($avatar === '') {
                                $avatar = 'photo/logo.jpg';
                            }

                            $memberUsername = $row['username'] ?? 'member';
                            $fullName = trim((string)($row['full_name'] ?? ''));
                            $email = trim((string)($row['email'] ?? ''));

                            if ($email === '') {
                                $email = 'No email';
                            }

                            $memberStatus = trim((string)($row['status'] ?? 'N/A'));

                            if ($memberStatus === '') {
                                $memberStatus = 'N/A';
                            }

                            $expiryRaw = $row['membership_expires_at'] ?? null;
                            $expiryInput = clean_date_for_input($expiryRaw);

                            $validStatus = strtoupper(trim((string)($row['valid_id_status'] ?? 'NONE')));

                            if ($validStatus === '') {
                                $validStatus = 'NONE';
                            }

                            $validFile = trim((string)($row['valid_id_file'] ?? ''));
                            $hasValidFile = $validFile !== '';
                        ?>

                        <tr>
                            <td class="col-id">
                                <?= $memberId ?>
                            </td>

                            <td class="col-avatar">
                                <img
                                    src="<?= h($avatar) ?>"
                                    alt="Avatar"
                                    class="avatar-img"
                                    onerror="this.src='photo/logo.jpg';"
                                >
                            </td>

                            <td class="col-name">
                                <div class="member-username"><?= h($memberUsername) ?></div>

                                <div class="member-name">
                                    <?= $fullName !== '' ? h($fullName) : 'No full name' ?>
                                </div>
                            </td>

                            <td class="col-email">
                                <div class="email-text" title="<?= h($email) ?>">
                                    <?= h($email) ?>
                                </div>
                            </td>

                            <td class="col-status">
                                <?php if ($HAS_STATUS): ?>
                                    <form method="post" action="users.php" class="inline-form">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="user_id" value="<?= $memberId ?>">
                                        <input type="hidden" name="filter_q" value="<?= h($q) ?>">
                                        <input type="hidden" name="filter_status" value="<?= h($statusFilter) ?>">

                                        <select name="status" class="select-control">
                                            <?php foreach ($inlineStatusOptions as $option): ?>
                                                <option value="<?= h($option) ?>" <?= strtolower($memberStatus) === strtolower($option) ? 'selected' : '' ?>>
                                                    <?= h($option) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit" class="btn btn-small btn-outline">Save</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-gray">No status column</span>
                                <?php endif; ?>
                            </td>

                            <td class="col-expiry">
                                <?php if ($HAS_EXPIRY): ?>
                                    <form method="post" action="users.php" class="inline-form">
                                        <input type="hidden" name="action" value="update_expiry">
                                        <input type="hidden" name="user_id" value="<?= $memberId ?>">
                                        <input type="hidden" name="filter_q" value="<?= h($q) ?>">
                                        <input type="hidden" name="filter_status" value="<?= h($statusFilter) ?>">

                                        <input
                                            type="date"
                                            name="membership_expires_at"
                                            class="date-control"
                                            value="<?= h($expiryInput) ?>"
                                        >

                                        <button type="submit" class="btn btn-small btn-outline">Update</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-gray">No expiry column</span>
                                <?php endif; ?>
                            </td>

                            <td class="col-valid">
                                <div class="valid-box">
                                    <div class="valid-current">
                                        <span>Status:</span>
                                        <span class="badge <?= h(badge_class($validStatus)) ?>">
                                            <?= h($validStatus) ?>
                                        </span>
                                    </div>

                                    <div class="valid-actions">
                                        <?php if ($VALID_ID_STATUS_COL): ?>
                                            <form method="post" action="users.php" class="inline-form">
                                                <input type="hidden" name="action" value="update_valid_id">
                                                <input type="hidden" name="user_id" value="<?= $memberId ?>">
                                                <input type="hidden" name="filter_q" value="<?= h($q) ?>">
                                                <input type="hidden" name="filter_status" value="<?= h($statusFilter) ?>">

                                                <select name="valid_id_status" class="select-control">
                                                    <?php foreach ($validStatusOptions as $option): ?>
                                                        <option value="<?= h($option) ?>" <?= $validStatus === $option ? 'selected' : '' ?>>
                                                            <?= h($option) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <button type="submit" class="btn btn-small btn-outline">Save</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="badge badge-gray">No valid ID status column</span>
                                        <?php endif; ?>

                                        <?php if ($hasValidFile): ?>
                                            <a href="<?= h($validFile) ?>" target="_blank" class="btn btn-small btn-dark">
                                                View
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <td class="col-actions">
                                <div class="row-actions">
                                    <a href="edit_user.php?id=<?= $memberId ?>" class="btn btn-small btn-outline">
                                        Edit
                                    </a>

                                    <?php if ($hasValidFile): ?>
                                        <a href="<?= h($validFile) ?>" target="_blank" class="btn btn-small btn-red">
                                            Valid ID
                                        </a>
                                    <?php else: ?>
                                        <span class="btn btn-small btn-dark" style="opacity: .55; cursor: not-allowed;">
                                            No ID
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>