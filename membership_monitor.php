<?php
// membership_monitor.php — Staff Membership Expiry Monitor

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

function clean_date_display(?string $date): string
{
    if (!$date) {
        return 'Not set';
    }

    $time = strtotime($date);

    if ($time === false) {
        return 'Invalid date';
    }

    return date('M d, Y', $time);
}

function get_days_remaining(?string $expiry): ?int
{
    if (!$expiry) {
        return null;
    }

    $time = strtotime($expiry);

    if ($time === false) {
        return null;
    }

    return (int)ceil(($time - time()) / 86400);
}

function membership_level(?int $days): string
{
    if ($days === null) {
        return 'unknown';
    }

    if ($days < 0) {
        return 'expired';
    }

    if ($days <= 7) {
        return 'warning';
    }

    return 'good';
}

function membership_label(string $level): string
{
    if ($level === 'expired') {
        return 'Expired';
    }

    if ($level === 'warning') {
        return 'Expiring Soon';
    }

    if ($level === 'good') {
        return 'Good';
    }

    return 'No Expiry';
}

function days_text(?int $days): string
{
    if ($days === null) {
        return 'No expiry set';
    }

    if ($days < 0) {
        return abs($days) . ' day(s) expired';
    }

    if ($days === 0) {
        return 'Expires today';
    }

    return $days . ' day(s) remaining';
}

function gmail_compose_link(string $email, string $name, string $level, ?string $expiryDate, ?int $days): string
{
    if ($level === 'expired') {
        $subject = 'RJL Fitness Membership Expired';

        $body = "Hello {$name},\n\n"
            . "Our records show that your RJL Fitness membership has already expired.\n\n"
            . "Membership expiry date: " . clean_date_display($expiryDate) . "\n"
            . "Status: Expired\n\n"
            . "Please visit RJL Fitness or contact our staff to renew your membership and continue your access.\n\n"
            . "Thank you,\n"
            . "RJL Fitness";
    } elseif ($level === 'warning') {
        $subject = 'RJL Fitness Membership Expiry Reminder';

        $body = "Hello {$name},\n\n"
            . "This is a friendly reminder that your RJL Fitness membership is about to expire soon.\n\n"
            . "Membership expiry date: " . clean_date_display($expiryDate) . "\n"
            . "Days remaining: " . ($days ?? 0) . " day(s)\n\n"
            . "Please renew your membership before the expiry date to avoid interruption of access.\n\n"
            . "Thank you,\n"
            . "RJL Fitness";
    } else {
        $subject = 'RJL Fitness Membership Status';

        $body = "Hello {$name},\n\n"
            . "Your RJL Fitness membership is currently active and in good status.\n\n"
            . "Membership expiry date: " . clean_date_display($expiryDate) . "\n\n"
            . "Thank you,\n"
            . "RJL Fitness";
    }

    return 'https://mail.google.com/mail/?view=cm&fs=1'
        . '&to=' . rawurlencode($email)
        . '&su=' . rawurlencode($subject)
        . '&body=' . rawurlencode($body);
}

// Login and role protection
$userId = (int)($_SESSION['user_id'] ?? 0);
$currentUsername = $_SESSION['username'] ?? 'staff';
$currentRole = strtolower(trim($_SESSION['role'] ?? ''));

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

// Staff exclusive. Admin also allowed.
if (!in_array($currentRole, ['staff', 'admin'], true)) {
    header('Location: home.php');
    exit;
}

$currentRoleLabel = strtoupper($currentRole ?: 'STAFF');
$homeLink = $currentRole === 'admin' ? 'home_admin.php' : 'home_staff.php';

// Detect columns
$HAS_ROLE = has_column($conn, 'users', 'role');
$HAS_FULLNAME = has_column($conn, 'users', 'full_name');
$HAS_EMAIL = has_column($conn, 'users', 'email');
$HAS_EXPIRY = has_column($conn, 'users', 'membership_expires_at');
$HAS_STATUS = has_column($conn, 'users', 'status');

$AVATAR_COL = first_existing_column($conn, 'users', [
    'avatar_path',
    'avatar',
    'profile_pic',
    'profile_image',
    'photo',
]);

// Filters
$q = trim((string)($_GET['q'] ?? ''));
$levelFilter = strtolower(trim((string)($_GET['level'] ?? 'all')));

$where = [];
$params = [];
$types = '';

if ($HAS_ROLE) {
    $where[] = "LOWER(`role`) = 'member'";
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

if ($HAS_EXPIRY) {
    $select[] = 'membership_expires_at';
} else {
    $select[] = "NULL AS membership_expires_at";
}

if ($HAS_STATUS) {
    $select[] = 'status';
} else {
    $select[] = "'N/A' AS status";
}

if ($AVATAR_COL) {
    $select[] = "`{$AVATAR_COL}` AS avatar";
} else {
    $select[] = "'photo/logo.jpg' AS avatar";
}

$sql = "SELECT " . implode(', ', $select) . " FROM users";

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

if ($HAS_EXPIRY) {
    $sql .= " ORDER BY membership_expires_at IS NULL ASC, membership_expires_at ASC, id DESC";
} else {
    $sql .= " ORDER BY id DESC";
}

$rows = [];

$stmt = $conn->prepare($sql);

if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $days = get_days_remaining($row['membership_expires_at'] ?? null);
            $level = membership_level($days);

            if ($levelFilter !== 'all' && $levelFilter !== $level) {
                continue;
            }

            $row['_days'] = $days;
            $row['_level'] = $level;

            $rows[] = $row;
        }

        $result->free();
    }

    $stmt->close();
}

// Count all members by level
$countExpired = 0;
$countWarning = 0;
$countGood = 0;
$countUnknown = 0;

if ($HAS_EXPIRY) {
    $countSql = "SELECT membership_expires_at FROM users";

    if ($HAS_ROLE) {
        $countSql .= " WHERE LOWER(`role`) = 'member'";
    }

    $countResult = $conn->query($countSql);

    if ($countResult) {
        while ($countRow = $countResult->fetch_assoc()) {
            $days = get_days_remaining($countRow['membership_expires_at'] ?? null);
            $level = membership_level($days);

            if ($level === 'expired') {
                $countExpired++;
            } elseif ($level === 'warning') {
                $countWarning++;
            } elseif ($level === 'good') {
                $countGood++;
            } else {
                $countUnknown++;
            }
        }

        $countResult->free();
    }
}

$totalMembers = $countExpired + $countWarning + $countGood + $countUnknown;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Membership Monitor | RJL Fitness</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --red: #ed1c24;
            --red-dark: #8b0000;
            --yellow: #f5bd3d;
            --green: #24c46b;
            --bg: #050505;
            --panel: #141414;
            --panel-2: #1c1c1c;
            --border: rgba(255, 255, 255, 0.10);
            --border-2: rgba(255, 255, 255, 0.18);
            --text: #ffffff;
            --muted: #b8b8b8;
            --shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
            --radius: 24px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
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

        .hero-card {
            position: relative;
            overflow: hidden;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background:
                linear-gradient(135deg, rgba(237, 28, 36, 0.82), rgba(16, 16, 16, 0.96)),
                linear-gradient(145deg, #191919, #080808);
            box-shadow: var(--shadow);
            padding: 28px;
            margin-bottom: 18px;
        }

        .hero-card::after {
            content: "";
            position: absolute;
            width: 340px;
            height: 340px;
            right: -120px;
            top: -150px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.10);
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-kicker {
            display: inline-flex;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            font-size: 0.72rem;
            font-weight: 900;
            letter-spacing: 0.10em;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .hero-card h1 {
            margin: 0;
            font-size: clamp(2rem, 4vw, 3.2rem);
            line-height: 1.05;
            letter-spacing: -0.05em;
            font-weight: 950;
        }

        .hero-card p {
            max-width: 850px;
            margin: 10px 0 0;
            color: rgba(255, 255, 255, 0.86);
            line-height: 1.55;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }

        .stat-card {
            border-radius: 22px;
            border: 1px solid var(--border);
            background: linear-gradient(145deg, rgba(255,255,255,0.065), rgba(255,255,255,0.025));
            box-shadow: 0 18px 45px rgba(0,0,0,0.35);
            padding: 22px;
            overflow: hidden;
            position: relative;
        }

        .stat-card::after {
            content: "";
            position: absolute;
            width: 120px;
            height: 120px;
            right: -45px;
            top: -45px;
            border-radius: 50%;
            opacity: 0.25;
        }

        .stat-red::after {
            background: var(--red);
        }

        .stat-yellow::after {
            background: var(--yellow);
        }

        .stat-green::after {
            background: var(--green);
        }

        .stat-gray::after {
            background: #9aa3af;
        }

        .stat-card small {
            position: relative;
            z-index: 2;
            display: block;
            color: var(--muted);
            font-size: 0.74rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.10em;
            margin-bottom: 8px;
        }

        .stat-card strong {
            position: relative;
            z-index: 2;
            display: block;
            font-size: 2rem;
            font-weight: 950;
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
            gap: 16px;
            margin-bottom: 18px;
        }

        .filter-title h2 {
            margin: 0;
            font-size: 1.25rem;
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
            grid-template-columns: 1fr 260px 110px;
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
        .select-control {
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
        .select-control:focus {
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

        .btn-gmail {
            background: linear-gradient(135deg, #ea4335, #c5221f);
            box-shadow: 0 14px 26px rgba(234, 67, 53, 0.24);
        }

        .btn-dark {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border);
        }

        .btn-small {
            height: 36px;
            padding: 0 13px;
            font-size: 0.73rem;
        }

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
        }

        .members-table {
            width: 100%;
            min-width: 1240px;
            border-collapse: collapse;
        }

        .members-table thead {
            display: table-header-group;
        }

        .members-table tbody {
            display: table-row-group;
        }

        .members-table tr {
            display: table-row;
        }

        .members-table th,
        .members-table td {
            display: table-cell;
            vertical-align: middle;
        }

        .members-table th {
            position: static !important;
            top: auto !important;
            z-index: auto !important;
            background: #191919;
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 950;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            text-align: left;
            padding: 16px 14px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .members-table td {
            padding: 18px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);
            color: #fff;
        }

        .members-table tbody tr:hover td {
            background: rgba(255, 255, 255, 0.035);
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

        .member-name-box strong {
            display: block;
            font-size: 0.95rem;
            font-weight: 900;
            color: #fff;
            margin-bottom: 4px;
        }

        .member-name-box span {
            display: block;
            font-size: 0.85rem;
            color: #b8b8b8;
        }

        .email-text {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .level-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 125px;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 950;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .level-expired {
            background: rgba(255, 75, 92, 0.18);
            border: 1px solid rgba(255, 75, 92, 0.38);
            color: #ffe1e4;
        }

        .level-warning {
            background: rgba(245, 189, 61, 0.18);
            border: 1px solid rgba(245, 189, 61, 0.38);
            color: #fff2c9;
        }

        .level-good {
            background: rgba(36, 196, 107, 0.18);
            border: 1px solid rgba(36, 196, 107, 0.38);
            color: #d9ffe8;
        }

        .level-unknown {
            background: rgba(148, 163, 184, 0.18);
            border: 1px solid rgba(148, 163, 184, 0.32);
            color: #eef2f7;
        }

        .days-red {
            color: #ff808a;
            font-weight: 900;
        }

        .days-yellow {
            color: #ffd76a;
            font-weight: 900;
        }

        .days-green {
            color: #7cffaa;
            font-weight: 900;
        }

        .days-gray {
            color: var(--muted);
            font-weight: 900;
        }

        .action-row {
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .empty-state {
            text-align: center;
            padding: 44px 20px;
            color: var(--muted);
        }

        .note-box {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(245, 189, 61, 0.14);
            border: 1px solid rgba(245, 189, 61, 0.32);
            color: #fff2c9;
        }

        @media (max-width: 900px) {
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

            .page-shell {
                width: calc(100vw - 20px);
                padding-top: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
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
                min-width: 1160px;
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

    <section class="hero-card">
        <div class="hero-content">
            <div class="hero-kicker">Membership Expiry Monitor</div>
            <h1>Member Status Monitoring</h1>
            <p>
                Monitor expired memberships, memberships expiring within 7 days, and members in good standing.
                Staff can open Gmail with a ready-made renewal reminder.
            </p>
        </div>
    </section>

    <?php if (!$HAS_EXPIRY): ?>
        <div class="note-box">
            The users.membership_expires_at column was not found. Please add this column first to monitor membership expiry.
        </div>
    <?php endif; ?>

    <section class="stats-grid">
        <a href="membership_monitor.php?level=expired" class="stat-card stat-red">
            <small>Red Level</small>
            <strong><?= (int)$countExpired ?></strong>
            <small>Expired Members</small>
        </a>

        <a href="membership_monitor.php?level=warning" class="stat-card stat-yellow">
            <small>Yellow Level</small>
            <strong><?= (int)$countWarning ?></strong>
            <small>7 Days or Below</small>
        </a>

        <a href="membership_monitor.php?level=good" class="stat-card stat-green">
            <small>Green Level</small>
            <strong><?= (int)$countGood ?></strong>
            <small>Good Standing</small>
        </a>

        <a href="membership_monitor.php?level=all" class="stat-card stat-gray">
            <small>Total Members</small>
            <strong><?= (int)$totalMembers ?></strong>
            <small>All Status Levels</small>
        </a>
    </section>

    <section class="panel filter-panel">
        <div class="filter-title">
            <div>
                <h2>Search and Filter</h2>
                <p>Filter by member name, email, username, or membership level.</p>
            </div>

            <div class="result-pill">
                Showing: <?= (int)count($rows) ?> member(s)
            </div>
        </div>

        <form method="get" action="membership_monitor.php">
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
                    <label for="level">Membership Level</label>
                    <select id="level" name="level" class="select-control">
                        <option value="all" <?= $levelFilter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="expired" <?= $levelFilter === 'expired' ? 'selected' : '' ?>>Red - Expired</option>
                        <option value="warning" <?= $levelFilter === 'warning' ? 'selected' : '' ?>>Yellow - 7 Days Below</option>
                        <option value="good" <?= $levelFilter === 'good' ? 'selected' : '' ?>>Green - Good</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-red">Go</button>
            </div>
        </form>
    </section>

    <section class="panel table-panel">
        <div class="table-head">
            <strong>Membership Expiry List</strong>
            <span>Red = expired • Yellow = 7 days below • Green = good</span>
        </div>

        <div class="table-scroll">
            <table class="members-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Avatar</th>
                        <th>Username / Name</th>
                        <th>Email</th>
                        <th>Expiry Date</th>
                        <th>Days Remaining</th>
                        <th>Level</th>
                        <th>Gmail Reminder</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    No members found for this filter.
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row): ?>
                        <?php
                            $memberId = (int)($row['id'] ?? 0);
                            $username = trim((string)($row['username'] ?? 'member'));
                            $fullName = trim((string)($row['full_name'] ?? ''));
                            $displayName = $fullName !== '' ? $fullName : $username;

                            $email = trim((string)($row['email'] ?? ''));
                            $emailDisplay = $email !== '' ? $email : 'No email';

                            $avatar = trim((string)($row['avatar'] ?? ''));

                            if ($avatar === '') {
                                $avatar = 'photo/logo.jpg';
                            }

                            $expiryRaw = $row['membership_expires_at'] ?? null;
                            $expiryDisplay = clean_date_display($expiryRaw);
                            $days = $row['_days'];
                            $level = $row['_level'];
                            $label = membership_label($level);
                            $dayText = days_text($days);

                            $levelClass = 'level-unknown';
                            $daysClass = 'days-gray';
                            $dot = '⚪';

                            if ($level === 'expired') {
                                $levelClass = 'level-expired';
                                $daysClass = 'days-red';
                                $dot = '🔴';
                            } elseif ($level === 'warning') {
                                $levelClass = 'level-warning';
                                $daysClass = 'days-yellow';
                                $dot = '🟡';
                            } elseif ($level === 'good') {
                                $levelClass = 'level-good';
                                $daysClass = 'days-green';
                                $dot = '🟢';
                            }

                            $gmailLink = $email !== ''
                                ? gmail_compose_link($email, $displayName, $level, $expiryRaw, $days)
                                : '';
                        ?>

                        <tr>
                            <td><?= $memberId ?></td>

                            <td>
                                <img
                                    src="<?= h($avatar) ?>"
                                    alt="Avatar"
                                    class="avatar-img"
                                    onerror="this.src='photo/logo.jpg';"
                                >
                            </td>

                            <td>
                                <div class="member-name-box">
                                    <strong><?= h($username) ?></strong>
                                    <span><?= h($displayName) ?></span>
                                </div>
                            </td>

                            <td>
                                <div class="email-text" title="<?= h($emailDisplay) ?>">
                                    <?= h($emailDisplay) ?>
                                </div>
                            </td>

                            <td><?= h($expiryDisplay) ?></td>

                            <td>
                                <span class="<?= h($daysClass) ?>">
                                    <?= h($dayText) ?>
                                </span>
                            </td>

                            <td>
                                <span class="level-badge <?= h($levelClass) ?>">
                                    <?= $dot ?> <?= h($label) ?>
                                </span>
                            </td>

                            <td>
                                <div class="action-row">
                                    <?php if ($email !== ''): ?>
                                        <a
                                            href="<?= h($gmailLink) ?>"
                                            target="_blank"
                                            class="btn btn-small btn-gmail"
                                        >
                                            Send Gmail
                                        </a>
                                    <?php else: ?>
                                        <span class="btn btn-small btn-dark" style="opacity:.55;cursor:not-allowed;">
                                            No Email
                                        </span>
                                    <?php endif; ?>

                                    <a href="users.php?q=<?= urlencode((string)$memberId) ?>" class="btn btn-small btn-dark">
                                        View
                                    </a>
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