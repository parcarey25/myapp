<?php
// home_admin.php — Admin Dashboard with Sidebar, Profile Card, Revenue Graph, and Logout Button

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

date_default_timezone_set('Asia/Manila');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function peso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function table_exists(mysqli $conn, string $table): bool
{
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");

    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();

    return $exists;
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

function get_count(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    $result->free();

    return (int)($row['total'] ?? 0);
}

function get_sum(mysqli $conn, string $sql): float
{
    $result = $conn->query($sql);

    if (!$result) {
        return 0.00;
    }

    $row = $result->fetch_assoc();
    $result->free();

    return (float)($row['total'] ?? 0);
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUsername = $_SESSION['username'] ?? 'admin';
$currentRole = strtolower(trim($_SESSION['role'] ?? 'admin'));

if ($currentUserId <= 0) {
    header('Location: login.php');
    exit;
}

if ($currentRole !== 'admin') {
    if ($currentRole === 'staff' && file_exists(__DIR__ . '/home_staff.php')) {
        header('Location: home_staff.php');
        exit;
    }

    if ($currentRole === 'trainer' && file_exists(__DIR__ . '/home_trainer.php')) {
        header('Location: home_trainer.php');
        exit;
    }

    if ($currentRole === 'member' && file_exists(__DIR__ . '/home_member.php')) {
        header('Location: home_member.php');
        exit;
    }

    header('Location: home.php');
    exit;
}

/* ADMIN INFO */
$admin = [
    'username' => $currentUsername,
    'full_name' => $currentUsername,
    'id_number' => 'ADMIN-' . str_pad((string)$currentUserId, 4, '0', STR_PAD_LEFT),
    'avatar_path' => 'photo/logo.jpg',
];

if (table_exists($conn, 'users')) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");

    if ($stmt) {
        $stmt->bind_param('i', $currentUserId);
        $stmt->execute();

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;

        if ($res) {
            $res->free();
        }

        $stmt->close();

        if ($row) {
            $admin['username'] = $row['username'] ?? $admin['username'];
            $admin['full_name'] = $row['full_name'] ?? $admin['full_name'];
            $admin['id_number'] = $row['id_number'] ?? $admin['id_number'];
            $admin['avatar_path'] =
                $row['avatar_path']
                ?? ($row['avatar']
                ?? ($row['profile_pic']
                ?? 'photo/logo.jpg'));
        }
    }
}

/* DASHBOARD COUNTS */
$hasUsers = table_exists($conn, 'users');
$hasRole = $hasUsers && has_column($conn, 'users', 'role');
$hasStatus = $hasUsers && has_column($conn, 'users', 'status');
$hasExpiry = $hasUsers && has_column($conn, 'users', 'membership_expires_at');

$totalMembers = 0;
$activeMembers = 0;
$pendingMembers = 0;
$expiredMembers = 0;
$expiringSoon = 0;
$pendingValidIds = 0;

$validIdStatusCol = $hasUsers ? first_existing_column($conn, 'users', [
    'valid_id_status',
    'id_status',
    'valid_id_approval',
    'valid_id_state',
]) : null;

if ($hasUsers) {
    $memberWhere = $hasRole ? "WHERE LOWER(role) = 'member'" : "";
    $totalMembers = get_count($conn, "SELECT COUNT(*) AS total FROM users {$memberWhere}");

    if ($hasStatus) {
        $activeWhere = $hasRole
            ? "WHERE LOWER(role) = 'member' AND LOWER(status) = 'active'"
            : "WHERE LOWER(status) = 'active'";

        $pendingWhere = $hasRole
            ? "WHERE LOWER(role) = 'member' AND LOWER(status) = 'pending'"
            : "WHERE LOWER(status) = 'pending'";

        $activeMembers = get_count($conn, "SELECT COUNT(*) AS total FROM users {$activeWhere}");
        $pendingMembers = get_count($conn, "SELECT COUNT(*) AS total FROM users {$pendingWhere}");
    }

    if ($hasExpiry) {
        $rolePrefix = $hasRole ? "LOWER(role) = 'member' AND " : "";

        $expiredMembers = get_count($conn, "
            SELECT COUNT(*) AS total
            FROM users
            WHERE {$rolePrefix}
            membership_expires_at IS NOT NULL
            AND membership_expires_at < NOW()
        ");

        $expiringSoon = get_count($conn, "
            SELECT COUNT(*) AS total
            FROM users
            WHERE {$rolePrefix}
            membership_expires_at IS NOT NULL
            AND membership_expires_at >= NOW()
            AND membership_expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
        ");
    }

    if ($validIdStatusCol) {
        $safeValidCol = preg_replace('/[^a-zA-Z0-9_]/', '', $validIdStatusCol);
        $rolePrefix = $hasRole ? "LOWER(role) = 'member' AND " : "";

        $pendingValidIds = get_count($conn, "
            SELECT COUNT(*) AS total
            FROM users
            WHERE {$rolePrefix}
            LOWER(`{$safeValidCol}`) = 'pending'
        ");
    }
}

/* REVENUE DATA */
$todayRevenue = 0.00;
$totalRevenue = 0.00;
$chartLabels = [];
$chartData = [];

$currentYear = (int)date('Y');
$currentMonth = (int)date('m');
$daysInMonth = (int)date('t');
$monthTitle = date('F Y');

for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateString = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $d);
    $chartLabels[] = date('M d', strtotime($dateString));
    $chartData[] = 0;
}

if (table_exists($conn, 'payments') && has_column($conn, 'payments', 'amount')) {
    $dateColumn = first_existing_column($conn, 'payments', [
        'created_at',
        'payment_date',
        'paid_at',
        'date_created',
        'date',
    ]);

    $totalRevenue = get_sum($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM payments
    ");

    if ($dateColumn) {
        $safeDateColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $dateColumn);

        $todayRevenue = get_sum($conn, "
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM payments
            WHERE DATE(`{$safeDateColumn}`) = CURDATE()
        ");

        $stmt = $conn->prepare("
            SELECT
                DAY(`{$safeDateColumn}`) AS day_num,
                COALESCE(SUM(amount), 0) AS total_amount
            FROM payments
            WHERE YEAR(`{$safeDateColumn}`) = ?
            AND MONTH(`{$safeDateColumn}`) = ?
            GROUP BY DAY(`{$safeDateColumn}`)
            ORDER BY DAY(`{$safeDateColumn}`) ASC
        ");

        if ($stmt) {
            $stmt->bind_param('ii', $currentYear, $currentMonth);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $dayNum = (int)($row['day_num'] ?? 0);
                    $amount = (float)($row['total_amount'] ?? 0);

                    if ($dayNum >= 1 && $dayNum <= $daysInMonth) {
                        $chartData[$dayNum - 1] = $amount;
                    }
                }

                $result->free();
            }

            $stmt->close();
        }
    }
}

$paymentsLink = file_exists(__DIR__ . '/payments.php') ? 'payments.php' : 'payment.php';
$settingsLink = file_exists(__DIR__ . '/settings.php') ? 'settings.php' : 'site_settings.php';

$sidebarMenu = [
    ['label' => 'Dashboard',            'icon' => '🏠', 'href' => 'home_admin.php'],
    ['label' => 'All Users',            'icon' => '👥', 'href' => 'all_user_info.php'],
    ['label' => 'Membership Monitor',   'icon' => '⏰', 'href' => 'membership_monitor.php'],
    ['label' => 'Load RFID Wallet',     'icon' => '🏷️', 'href' => 'rfid_load.php'],
    ['label' => 'Extend Membership',    'icon' => '🧾', 'href' => 'extend_membership.php'],
    ['label' => 'Revenue Report',       'icon' => '📈', 'href' => 'admin_dashboard.php'],
    ['label' => 'Facilities',           'icon' => '🏋️', 'href' => 'admin_trainer_sched.php'],
    ['label' => 'POS',                  'icon' => '📅', 'href' => 'payments.php'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Home Admin | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
    --red: #d80000;
    --red2: #ff2a2a;
    --bg: #050505;
    --panel: #111111;
    --border: rgba(255,255,255,.10);
    --text: #ffffff;
    --muted: #b9b9b9;
    --green: #35c86b;
    --yellow: #f2c94c;
    --danger: #ff5a5a;
    --blue: #2ea8ff;
    --shadow: 0 18px 40px rgba(0,0,0,.45);
    --radius: 22px;
    --sidebar-w: 280px;
    --header-h: 88px;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    min-height: 100%;
    font-family: "Segoe UI", Arial, sans-serif;
    color: var(--text);
    background:
        radial-gradient(circle at top left, rgba(216,0,0,.12), transparent 30%),
        linear-gradient(135deg, #020202, #0d0d0d 60%, #050505);
}

a {
    color: inherit;
    text-decoration: none;
}

.topbar {
    position: sticky;
    top: 0;
    z-index: 1200;
    height: var(--header-h);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 18px;
    background: linear-gradient(90deg, #120000, #7d0000 55%, #b80000);
    border-bottom: 1px solid rgba(255,255,255,.08);
    box-shadow: 0 10px 25px rgba(0,0,0,.35);
}

.topbar-left,
.topbar-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.burger {
    width: 46px;
    height: 46px;
    border: none;
    border-radius: 14px;
    cursor: pointer;
    background: rgba(0,0,0,.18);
    color: #fff;
    font-size: 24px;
    display: grid;
    place-items: center;
    border: 1px solid rgba(255,255,255,.16);
}

.burger:hover {
    background: rgba(0,0,0,.34);
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
}

.brand img {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    object-fit: cover;
    background: #111;
    border: 2px solid rgba(255,255,255,.75);
}

.brand-name {
    font-size: 1.08rem;
    font-weight: 900;
    line-height: 1.1;
}

.brand-sub {
    color: rgba(255,255,255,.85);
    font-size: .76rem;
    letter-spacing: .20em;
    text-transform: uppercase;
    margin-top: 4px;
}

.welcome {
    font-size: .95rem;
    white-space: nowrap;
}

.welcome strong {
    font-weight: 900;
}

.role-pill {
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(0,0,0,.25);
    border: 1px solid rgba(255,255,255,.15);
    font-size: .74rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.logout-top-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 0 16px;
    border-radius: 999px;
    background: linear-gradient(135deg, #1a1a1a, #0e0e0e);
    border: 1px solid rgba(255,255,255,.16);
    color: #fff;
    font-size: .78rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .06em;
    transition: .18s ease;
    box-shadow: 0 8px 20px rgba(0,0,0,.22);
}

.logout-top-btn:hover {
    background: linear-gradient(135deg, #ff2323, #d80000);
    border-color: rgba(255,255,255,.12);
    transform: translateY(-1px);
}

.top-avatar {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255,255,255,.8);
    background: #111;
}

.sidebar {
    position: fixed;
    top: var(--header-h);
    left: 0;
    width: var(--sidebar-w);
    height: calc(100vh - var(--header-h));
    background: linear-gradient(180deg, #101010, #090909);
    border-right: 1px solid rgba(255,255,255,.08);
    transform: translateX(-100%);
    transition: .25s ease;
    z-index: 1300;
    overflow-y: auto;
    box-shadow: 12px 0 30px rgba(0,0,0,.35);
}

.sidebar.open {
    transform: translateX(0);
}

.sidebar-title {
    padding: 18px 18px 10px;
    color: #9d9d9d;
    font-size: .82rem;
    font-weight: 800;
    letter-spacing: .22em;
    text-transform: uppercase;
}

.sidebar-menu {
    padding: 10px 12px 22px;
}

.sidebar-link {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 14px;
    border-radius: 16px;
    color: #f3f3f3;
    margin-bottom: 8px;
    transition: .18s ease;
}

.sidebar-link:hover,
.sidebar-link.active {
    background: rgba(216,0,0,.15);
    border: 1px solid rgba(216,0,0,.25);
}

.sidebar-link .icon {
    width: 22px;
    text-align: center;
    font-size: 1.05rem;
}

.sidebar-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    opacity: 0;
    pointer-events: none;
    transition: .2s ease;
    z-index: 1250;
}

.sidebar-overlay.show {
    opacity: 1;
    pointer-events: auto;
}

.page {
    width: min(1500px, calc(100vw - 30px));
    margin: 24px auto 40px;
}

.hero-grid {
    display: grid;
    grid-template-columns: 1.2fr .85fr;
    gap: 18px;
    margin-bottom: 18px;
}

.hero-card,
.profile-card,
.stats-card,
.panel {
    background: linear-gradient(145deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    border-radius: var(--radius);
}

.hero-card {
    min-height: 260px;
    padding: 28px;
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.10), transparent 34%),
        linear-gradient(135deg, rgba(216,0,0,.86), rgba(16,16,16,.96));
}

.hero-kicker {
    display: inline-flex;
    padding: 7px 12px;
    border-radius: 999px;
    background: rgba(255,255,255,.12);
    font-size: .75rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .08em;
    margin-bottom: 14px;
}

.hero-card h1 {
    margin: 0;
    font-size: clamp(2rem, 5vw, 3.8rem);
    line-height: .95;
    font-weight: 900;
    letter-spacing: -.05em;
    max-width: 700px;
}

.hero-card p {
    max-width: 760px;
    color: rgba(255,255,255,.86);
    line-height: 1.6;
    margin: 14px 0 0;
}

.hero-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 22px;
}

.hero-btn {
    min-height: 44px;
    padding: 0 18px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .82rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .05em;
    border: 1px solid rgba(255,255,255,.14);
    background: rgba(255,255,255,.10);
}

.hero-btn.primary {
    background: #fff;
    color: #a00000;
}

.profile-card {
    padding: 18px;
}

.profile-top {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 18px;
}

.profile-top img {
    width: 72px;
    height: 72px;
    border-radius: 20px;
    object-fit: cover;
    border: 2px solid rgba(255,255,255,.22);
    background: #111;
}

.profile-name {
    font-size: 1.35rem;
    font-weight: 900;
    line-height: 1.1;
}

.profile-id {
    color: var(--muted);
    margin-top: 4px;
}

.profile-status {
    display: grid;
    gap: 12px;
    margin: 16px 0 18px;
}

.profile-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    color: #e8e8e8;
    font-size: .95rem;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 999px;
    background: var(--green);
    color: #fff;
    font-size: .74rem;
    font-weight: 900;
}

.profile-actions {
    display: grid;
    gap: 10px;
}

.profile-btn {
    min-height: 46px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 16px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.08);
    font-weight: 800;
}

.profile-btn.logout {
    background: linear-gradient(135deg, var(--red), var(--red2));
    border: none;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.stats-card {
    padding: 18px;
    min-height: 128px;
    position: relative;
    overflow: hidden;
}

.stats-card::after {
    content: "";
    position: absolute;
    width: 110px;
    height: 110px;
    border-radius: 50%;
    right: -42px;
    top: -42px;
    background: rgba(216,0,0,.14);
}

.stats-label {
    position: relative;
    z-index: 2;
    color: var(--muted);
    font-size: .74rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 10px;
}

.stats-value {
    position: relative;
    z-index: 2;
    font-size: 2rem;
    font-weight: 900;
    letter-spacing: -.05em;
}

.stats-note {
    position: relative;
    z-index: 2;
    margin-top: 8px;
    color: var(--muted);
    font-size: .84rem;
    line-height: 1.4;
}

.content-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 18px;
}

.panel-head {
    padding: 18px 20px 14px;
    border-bottom: 1px solid rgba(255,255,255,.08);
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
}

.panel-head h2 {
    margin: 0;
    font-size: 1.85rem;
    font-weight: 900;
    letter-spacing: -.04em;
}

.panel-head p {
    margin: 6px 0 0;
    color: var(--muted);
}

.panel-body {
    padding: 18px;
}

.chart-wrap {
    width: 100%;
    height: 480px;
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 20px;
    background: #0c0c0c;
    padding: 10px 14px 8px;
}

canvas {
    width: 100% !important;
    height: 100% !important;
}

.quick-links {
    display: grid;
    grid-template-columns: repeat(3, minmax(0,1fr));
    gap: 14px;
}

.quick-link {
    border-radius: 20px;
    background: linear-gradient(145deg, #181818, #0d0d0d);
    border: 1px solid rgba(255,255,255,.08);
    padding: 18px;
    min-height: 160px;
    transition: .18s ease;
}

.quick-link:hover {
    transform: translateY(-3px);
    border-color: rgba(216,0,0,.35);
    box-shadow: 0 16px 30px rgba(216,0,0,.12);
}

.ql-icon {
    width: 48px;
    height: 48px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    background: rgba(216,0,0,.15);
    font-size: 1.35rem;
    margin-bottom: 14px;
}

.ql-title {
    font-size: 1.03rem;
    font-weight: 900;
    margin-bottom: 8px;
}

.ql-desc {
    color: var(--muted);
    font-size: .88rem;
    line-height: 1.45;
}

@media (max-width: 1200px) {
    .hero-grid {
        grid-template-columns: 1fr;
    }

    .stats-grid {
        grid-template-columns: repeat(2, minmax(0,1fr));
    }

    .quick-links {
        grid-template-columns: repeat(2, minmax(0,1fr));
    }
}

@media (max-width: 768px) {
    .topbar {
        padding: 0 12px;
    }

    .brand-sub {
        display: none;
    }

    .welcome {
        display: none;
    }

    .logout-top-btn {
        min-height: 36px;
        padding: 0 12px;
        font-size: .72rem;
    }

    .topbar-right {
        gap: 8px;
    }

    .page {
        width: calc(100vw - 18px);
        margin: 16px auto 28px;
    }

    .hero-card,
    .profile-card,
    .panel-body {
        padding: 16px;
    }

    .stats-grid,
    .quick-links {
        grid-template-columns: 1fr;
    }

    .chart-wrap {
        height: 360px;
    }
}
</style>
</head>

<body>

<header class="topbar">
    <div class="topbar-left">
        <button class="burger" id="burgerBtn" type="button">☰</button>

        <a href="home_admin.php" class="brand">
            <img src="photo/logo.jpg" alt="RJL Fitness">
            <div>
                <div class="brand-name">RJL Fitness</div>
                <div class="brand-sub">Admin Control Panel</div>
            </div>
        </a>
    </div>

    <div class="topbar-right">
        <div class="welcome">
            Welcome, <strong><?= h($admin['username']) ?></strong>
        </div>

        <div class="role-pill">Admin</div>

        <a href="logout.php" class="logout-top-btn">Logout</a>

        <img src="<?= h($admin['avatar_path']) ?>" alt="Admin" class="top-avatar" onerror="this.src='photo/logo.jpg';">
    </div>
</header>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-title">Admin Dashboard</div>

    <nav class="sidebar-menu">
        <?php foreach ($sidebarMenu as $index => $item): ?>
            <a href="<?= h($item['href']) ?>" class="sidebar-link <?= $index === 0 ? 'active' : '' ?>">
                <span class="icon"><?= h($item['icon']) ?></span>
                <span><?= h($item['label']) ?></span>
            </a>
        <?php endforeach; ?>

        <a href="logout.php" class="sidebar-link">
            <span class="icon">🚪</span>
            <span>Logout</span>
        </a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<main class="page">

    <section class="hero-grid">
        <div class="hero-card">
            <div class="hero-kicker">Administrator Dashboard</div>
            <h1>Manage RJL Fitness like a real control center.</h1>
            <p>
                Monitor members, check expiring memberships, load RFID wallet balance,
                extend plans, review payments, and watch revenue performance for the month.
            </p>

            <div class="hero-actions">
                <a href="users.php" class="hero-btn primary">Manage Members</a>
                <a href="membership_monitor.php" class="hero-btn">Monitor Membership</a>
                <a href="rfid_load.php" class="hero-btn">Load RFID</a>
            </div>
        </div>

        <div class="profile-card">
            <div class="profile-top">
                <img src="<?= h($admin['avatar_path']) ?>" alt="Admin Profile" onerror="this.src='photo/logo.jpg';">
                <div>
                    <div class="profile-name"><?= h($admin['full_name']) ?></div>
                    <div class="profile-id">ID Number: <?= h($admin['id_number']) ?></div>
                </div>
            </div>

            <div class="profile-status">
                <div class="profile-row">
                    <span>Status</span>
                    <span class="status-badge">Active</span>
                </div>

                <div class="profile-row">
                    <span>Role</span>
                    <strong>Administrator</strong>
                </div>

                <div class="profile-row">
                    <span>Date</span>
                    <strong><?= h(date('M d, Y')) ?></strong>
                </div>
            </div>

            <div class="profile-actions">
                <a href="users.php" class="profile-btn">Manage Users</a>
                <a href="<?= h($paymentsLink) ?>" class="profile-btn">View Payments</a>
                <a href="membership_monitor.php" class="profile-btn">Membership Monitor</a>
                <a href="logout.php" class="profile-btn logout">Logout</a>
            </div>
        </div>
    </section>

    <section class="stats-grid">
        <div class="stats-card">
            <div class="stats-label">Total Members</div>
            <div class="stats-value"><?= (int)$totalMembers ?></div>
            <div class="stats-note">All registered member accounts</div>
        </div>

        <div class="stats-card">
            <div class="stats-label">Active Members</div>
            <div class="stats-value"><?= (int)$activeMembers ?></div>
            <div class="stats-note">Members currently active</div>
        </div>

        <div class="stats-card">
            <div class="stats-label">Pending Members</div>
            <div class="stats-value"><?= (int)$pendingMembers ?></div>
            <div class="stats-note">Accounts waiting for approval</div>
        </div>

        <div class="stats-card">
            <div class="stats-label">Expiring Soon</div>
            <div class="stats-value"><?= (int)$expiringSoon ?></div>
            <div class="stats-note">Memberships within 7 days</div>
        </div>

        <div class="stats-card">
            <div class="stats-label">Expired Members</div>
            <div class="stats-value"><?= (int)$expiredMembers ?></div>
            <div class="stats-note">Need renewal or follow-up</div>
        </div>

        <div class="stats-card">
            <div class="stats-label">Pending Valid IDs</div>
            <div class="stats-value"><?= (int)$pendingValidIds ?></div>
            <div class="stats-note">Valid IDs waiting for review</div>
        </div>

        <div class="stats-card">
            <div class="stats-label">Today Revenue</div>
            <div class="stats-value"><?= h(peso($todayRevenue)) ?></div>
            <div class="stats-note">Revenue recorded today</div>
        </div>

        <div class="stats-card">
            <div class="stats-label">Total Revenue</div>
            <div class="stats-value"><?= h(peso($totalRevenue)) ?></div>
            <div class="stats-note">All recorded payments</div>
        </div>
    </section>

    <section class="content-grid">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Revenue Report</h2>
                    <p>Daily revenue for <?= h($monthTitle) ?> (line graph)</p>
                </div>

                <div style="color:#cfcfcf;font-size:.95rem;">
                    Source: payments table
                </div>
            </div>

            <div class="panel-body">
                <div class="chart-wrap">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>

        <div class="panel">
            
                </div>
            </div>
        </div>
    </section>

</main>

<script>
const burgerBtn = document.getElementById('burgerBtn');
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    sidebar.classList.add('open');
    sidebarOverlay.classList.add('show');
}

function closeSidebar() {
    sidebar.classList.remove('open');
    sidebarOverlay.classList.remove('show');
}

burgerBtn.addEventListener('click', function () {
    if (sidebar.classList.contains('open')) {
        closeSidebar();
    } else {
        openSidebar();
    }
});

sidebarOverlay.addEventListener('click', closeSidebar);

const chartLabels = <?= json_encode($chartLabels) ?>;
const chartData = <?= json_encode($chartData) ?>;

const ctx = document.getElementById('revenueChart').getContext('2d');

const gradient = ctx.createLinearGradient(0, 0, 0, 420);
gradient.addColorStop(0, 'rgba(46,168,255,0.55)');
gradient.addColorStop(1, 'rgba(46,168,255,0.05)');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: chartLabels,
        datasets: [{
            label: 'Revenue (₱)',
            data: chartData,
            borderColor: '#2ea8ff',
            backgroundColor: gradient,
            fill: true,
            borderWidth: 3,
            pointBackgroundColor: '#2ea8ff',
            pointBorderColor: '#2ea8ff',
            pointRadius: 3,
            pointHoverRadius: 5,
            tension: 0.35
        }]
    },
    options: {
        maintainAspectRatio: false,
        responsive: true,
        plugins: {
            legend: {
                labels: {
                    color: '#f2f2f2',
                    font: {
                        size: 14,
                        weight: 'bold'
                    }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let value = Number(context.parsed.y || 0);
                        return ' Revenue: ₱' + value.toLocaleString('en-PH', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    color: '#cfcfcf',
                    maxRotation: 45,
                    minRotation: 45
                },
                grid: {
                    color: 'rgba(255,255,255,0.06)'
                }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    color: '#cfcfcf',
                    callback: function(value) {
                        return '₱' + Number(value).toLocaleString('en-PH');
                    }
                },
                grid: {
                    color: 'rgba(255,255,255,0.08)'
                }
            }
        }
    }
});
</script>

</body>
</html>