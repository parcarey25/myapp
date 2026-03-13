<?php 
// home_member.php - Member dashboard with OLD design preserved + NEW wireframe sections ADDED

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId   = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'member';
$role     = $_SESSION['role'] ?? 'member';

$expiryText   = 'Not set';
$expiryDate   = null;
$statusText   = 'Unknown';
$statusBadge  = 'badge-secondary';
$idNumber     = null;
$avatarPath   = null;
$expires      = null;
$fullName     = '';

/* ---------------- Helpers ---------------- */
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool {
    $t = $conn->real_escape_string($table);
    $sql = "SHOW TABLES LIKE '{$t}'";
    if ($res = $conn->query($sql)) {
        $ok = $res->num_rows > 0;
        $res->free();
        return $ok;
    }
    return false;
}

function columns_of(mysqli $conn, string $table): array {
    $cols = [];
    $t = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($t === '') return $cols;

    if ($res = $conn->query("SHOW COLUMNS FROM `{$t}`")) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
        $res->free();
    }
    return $cols;
}

function first_existing_table(mysqli $conn, array $candidates): ?string {
    foreach ($candidates as $t) {
        if (table_exists($conn, $t)) return $t;
    }
    return null;
}

function first_existing_column(array $columns, array $candidates): ?string {
    $map = array_flip($columns);
    foreach ($candidates as $c) {
        if (isset($map[$c])) return $c;
    }
    return null;
}

function safe_dt(?string $dt): ?int {
    if (!$dt) return null;
    $ts = strtotime($dt);
    return ($ts === false) ? null : $ts;
}

/* ---------------- Get user record ---------------- */
$membershipStart = null;

if ($userId > 0) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $username = $row['username'] ?? $username;
            $fullName = $row['full_name'] ?? '';

            $expires  = $row['membership_expires_at'] ?? null;

            // try membership start if your table has it
            $membershipStart = $row['membership_started_at']
                ?? ($row['membership_start']
                ?? ($row['membership_start_date'] ?? null));

            // ID number fallbacks
            $idNumber = $row['id_number']
                ?? ($row['rfid_number'] ?? ($row['member_id'] ?? null));

            // avatar fallbacks
            $avatarPath = $row['avatar'] ?? ($row['profile_pic'] ?? null);
        }
        $res->free();
    }
    $stmt->close();

    // Membership status logic
    if ($expires) {
        $expiryDate = $expires;
        $ts = strtotime($expires);
        if ($ts !== false) {
            if ($ts >= time()) {
                $days = (int)floor(($ts - time()) / 86400);
                $expiryText   = "Active — expires in {$days} day(s) on " . date('M d, Y g:ia', $ts);
                $statusText   = 'Active';
                $statusBadge  = 'badge-success';
            } else {
                $expiryText   = "Expired on " . date('M d, Y g:ia', $ts);
                $statusText   = 'Expired';
                $statusBadge  = 'badge-danger';
            }
        }
    } else {
        $expiryText  = 'No membership expiry set.';
        $statusText  = 'No membership';
        $statusBadge = 'badge-warning';
    }
}

/* ---------------- Display helpers ---------------- */
$memberIdDisplay = $idNumber ? $idNumber : ('#' . $userId);
$avatarUrl = (!empty($avatarPath)) ? $avatarPath : 'photo/logo.jpg';

$current = basename($_SERVER['PHP_SELF'] ?? '');
function active_link(string $file, string $current): string {
    return ($file === $current) ? ' active' : '';
}

/* ---------------- NEW WIRE-FRAME DATA (safe fallbacks) ---------------- */

// Days left (0 if expired/none)
$daysLeft = null;
$expiresTs = safe_dt($expiryDate);
if ($expiresTs !== null) {
    $daysLeft = (int)ceil(($expiresTs - time()) / 86400);
    if ($daysLeft < 0) $daysLeft = 0;
}

// Progress used (%). Best case: we have start + expiry. Otherwise: estimate using 30-day cycle.
$progressUsed = null;
$progressNote = '';
$startTs = safe_dt($membershipStart);

if ($startTs !== null && $expiresTs !== null && $expiresTs > $startTs) {
    $total = $expiresTs - $startTs;
    $used  = time() - $startTs;
    $pct = (int)round(max(0, min(1, $used / $total)) * 100);
    $progressUsed = $pct;
} elseif ($daysLeft !== null) {
    $cycle = 30; // estimate
    $pct = (int)round(100 - max(0, min(1, $daysLeft / $cycle)) * 100);
    $progressUsed = max(0, min(100, $pct));
    $progressNote = 'Estimated (30-day cycle).';
} else {
    $progressUsed = 0;
    $progressNote = 'No membership data.';
}

// Attendance stats (tries multiple tables/columns; shows N/A if none)
$totalVisits = 'N/A';
$lastCheckIn = 'N/A';
$attendanceHistory = [];

$attTable = first_existing_table($conn, [
    'attendance', 'attendances', 'attendance_logs', 'member_attendance', 'rfid_attendance'
]);

if ($attTable) {
    $cols = columns_of($conn, $attTable);
    $userCol = first_existing_column($cols, ['user_id', 'member_id', 'uid', 'userID', 'memberID']);
    $timeCol = first_existing_column($cols, ['time_in', 'check_in_time', 'check_in', 'created_at', 'datetime', 'date_time']);

    if ($userCol && $timeCol) {
        // total visits
        $sql = "SELECT COUNT(*) AS c FROM `{$attTable}` WHERE `{$userCol}` = ?";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('i', $userId);
            if ($st->execute()) {
                $r = $st->get_result()->fetch_assoc();
                $totalVisits = $r ? (int)$r['c'] : 'N/A';
            }
            $st->close();
        }

        // last check-in
        $sql = "SELECT `{$timeCol}` AS t FROM `{$attTable}` WHERE `{$userCol}` = ? ORDER BY `{$timeCol}` DESC LIMIT 1";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('i', $userId);
            if ($st->execute()) {
                $r = $st->get_result()->fetch_assoc();
                if (!empty($r['t'])) {
                    $ts = strtotime($r['t']);
                    $lastCheckIn = ($ts !== false) ? date('M d – g:ia', $ts) : h($r['t']);
                }
            }
            $st->close();
        }

        // history (last 5)
        $sql = "SELECT `{$timeCol}` AS t FROM `{$attTable}` WHERE `{$userCol}` = ? ORDER BY `{$timeCol}` DESC LIMIT 5";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('i', $userId);
            if ($st->execute()) {
                $res = $st->get_result();
                while ($row = $res->fetch_assoc()) {
                    $ts = strtotime($row['t']);
                    $attendanceHistory[] = ($ts !== false) ? date('M d – g:ia', $ts) : $row['t'];
                }
            }
            $st->close();
        }
    }
}

// Payment stats (tries multiple possible tables)
$lastPayment = 'N/A';
$payTable = first_existing_table($conn, [
    'payments', 'membership_payments', 'transactions', 'payment_history', 'member_payments'
]);

if ($payTable) {
    $cols = columns_of($conn, $payTable);
    $userCol = first_existing_column($cols, ['user_id', 'member_id', 'uid', 'userID', 'memberID']);
    $dateCol = first_existing_column($cols, ['paid_at', 'payment_date', 'date_paid', 'created_at', 'transaction_date', 'datetime', 'date_time']);

    if ($userCol && $dateCol) {
        $sql = "SELECT `{$dateCol}` AS d FROM `{$payTable}` WHERE `{$userCol}` = ? ORDER BY `{$dateCol}` DESC LIMIT 1";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('i', $userId);
            if ($st->execute()) {
                $r = $st->get_result()->fetch_assoc();
                if (!empty($r['d'])) {
                    $ts = strtotime($r['d']);
                    $lastPayment = ($ts !== false) ? date('M d', $ts) : h($r['d']);
                }
            }
            $st->close();
        }
    }
}

// Button availability (won’t break your system if file not yet created)
$receiptFile = __DIR__ . '/download_receipt.php';
$receiptEnabled = file_exists($receiptFile);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Member Dashboard | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">

<style>
:root {
    --brand-red: #b30000;
    --brand-red-bright: #ff3333;
    --brand-dark: #050505;
    --panel-dark: #111111;
    --panel-darker: #0b0b0b;
    --panel-border: #2a2a2a;
    --muted: #aaaaaa;
    --text-main: #f5f5f5;
    --accent-soft: #222222;
    --radius-lg: 18px;
    --radius-md: 12px;
    --shadow-soft: 0 18px 35px rgba(0,0,0,0.55);
}

/* ---------- Global ---------- */
*,
*::before,
*::after { box-sizing: border-box; }

body {
    margin: 0;
    min-height: 100vh;
    background: radial-gradient(circle at top, #111 0, #000 50%, #000 100%);
    color: var(--text-main);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

/* ---------- Top bar ---------- */
.topbar {
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    height: 64px;
    background: linear-gradient(90deg,#000,var(--brand-red));
    box-shadow: 0 10px 25px rgba(0,0,0,0.5);
}

.topbar-left { display: flex; align-items: center; }

.brand-logo {
    display: flex;
    align-items: center;
    color: #fff;
    text-decoration: none;
    font-weight: 600;
    letter-spacing: .04em;
    margin-right: 16px;
}
.brand-logo img {
    height: 34px;
    width: auto;
    margin-right: 10px;
    border-radius: 8px;
}

.icon-btn {
    border: none;
    background: transparent;
    color: #fff;
    padding: 6px 10px;
    margin-right: 4px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    outline: none !important;
}
.icon-btn:hover { background: rgba(255,255,255,0.08); }

.icon-btn .burger-lines {
    display: inline-block;
    position: relative;
    width: 18px;
    height: 2px;
    background: #fff;
    border-radius: 999px;
}
.icon-btn .burger-lines::before,
.icon-btn .burger-lines::after {
    content: "";
    position: absolute;
    left: 0;
    width: 18px;
    height: 2px;
    background: #fff;
    border-radius: 999px;
}
.icon-btn .burger-lines::before { top: -6px; }
.icon-btn .burger-lines::after { top: 6px; }

.topbar-right { display: flex; align-items: center; gap: 12px; }

.topbar-welcome { font-size: 0.9rem; color: #fceaea; }

.badge-role {
    background: rgba(0,0,0,0.45);
    border-radius: 999px;
    padding: 2px 10px;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: .08em;
}

.avatar-btn { border: none; background: transparent; padding: 0; cursor: pointer; outline: none !important; }

.avatar-circle {
    height: 38px; width: 38px;
    border-radius: 999px;
    overflow: hidden;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px rgba(0,0,0,0.35);
}
.avatar-circle img { width: 100%; height: 100%; object-fit: cover; }

/* ---------- Sidebar ---------- */
.sidebar-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.55);
    opacity: 0; visibility: hidden;
    transition: opacity .2s ease;
    z-index: 89;
}
.sidebar-overlay.open { opacity: 1; visibility: visible; }

.sidebar {
    position: fixed;
    top: 64px; left: 0;
    width: 260px;
    height: calc(100vh - 64px);
    background: var(--panel-dark);
    border-right: 1px solid var(--panel-border);
    box-shadow: var(--shadow-soft);
    padding: 18px 16px;
    transform: translateX(-270px);
    transition: transform .22s ease-out;
    z-index: 90;
    display: flex;
    flex-direction: column;
}
.sidebar.open { transform: translateX(0); }

.sidebar-title {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: .16em;
    color: var(--muted);
    margin-bottom: 10px;
}

.sidebar-menu { list-style: none; padding: 0; margin: 0; }
.sidebar-menu li { margin-bottom: 6px; }

.sidebar-link {
    display: flex;
    align-items: center;
    padding: 9px 10px;
    border-radius: 10px;
    color: #f7f7f7;
    font-size: 0.92rem;
    text-decoration: none;
    background: transparent;
    transition: background .15s, transform .1s, box-shadow .15s;
}
.sidebar-link span.icon {
    display: inline-flex;
    width: 22px;
    justify-content: center;
    margin-right: 10px;
    font-size: 1.05rem;
}
.sidebar-link:hover {
    background: var(--accent-soft);
    transform: translateY(-1px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.35);
}
.sidebar-link.active {
    background: linear-gradient(120deg,var(--brand-red),#ff4d4d);
    box-shadow: 0 12px 28px rgba(179,0,0,0.55);
}

/* ---------- Profile Panel ---------- */
.profile-overlay {
    position: fixed; inset: 0;
    background: transparent;
    opacity: 0; visibility: hidden;
    transition: opacity .2s ease;
    z-index: 80;
}
.profile-overlay.open { visibility: visible; opacity: 1; }

.profile-panel {
    position: fixed;
    top: 72px; right: 18px;
    width: 280px;
    background: var(--panel-dark);
    border-radius: 16px;
    border: 1px solid var(--panel-border);
    box-shadow: var(--shadow-soft);
    padding: 16px 16px 14px;
    z-index: 95;
    opacity: 0;
    pointer-events: none;
    transform: translateY(-8px);
    transition: opacity .18s ease, transform .18s ease;
}
.profile-panel.open {
    opacity: 1; pointer-events: auto;
    transform: translateY(0);
}
.profile-header { display: flex; align-items: center; margin-bottom: 10px; }
.profile-header .avatar-circle { height: 44px; width: 44px; margin-right: 10px; }
.profile-name { font-weight: 600; font-size: 0.98rem; }
.profile-meta { font-size: 0.78rem; color: var(--muted); }

.profile-list {
    list-style: none;
    padding: 0;
    margin: 10px 0 12px;
    font-size: 0.8rem;
    color: #dddddd;
}
.profile-list li { display: flex; justify-content: space-between; margin-bottom: 4px; }

.profile-actions a {
    display: block;
    font-size: 0.85rem;
    padding: 6px 10px;
    border-radius: 999px;
    text-align: center;
    text-decoration: none;
    margin-bottom: 6px;
}
.profile-actions a.primary {
    background: linear-gradient(120deg,var(--brand-red),var(--brand-red-bright));
    color: #fff;
}
.profile-actions a.secondary { background: #181818; color: #f5f5f5; }

/* ---------- Main layout ---------- */
.main-wrap {
    max-width: 1180px;
    margin: 24px auto 40px;
    padding: 0 18px 40px;
}

.section-row {
    display: grid;
    grid-template-columns: minmax(0, 2.3fr) minmax(0, 1.4fr);
    grid-gap: 20px;
    margin-bottom: 22px;
}

/* cards */
.card-dark {
    background: radial-gradient(circle at top, #222 0, #111 45%, #080808 100%);
    border-radius: var(--radius-lg);
    border: 1px solid var(--panel-border);
    box-shadow: var(--shadow-soft);
    padding: 20px 22px;
}
.card-dark h5 {
    font-size: 1rem;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 10px;
}
.membership-title { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }

.btn-pill-red {
    border-radius: 999px;
    border: none;
    padding: 8px 18px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    background: linear-gradient(120deg,var(--brand-red),var(--brand-red-bright));
    color: #fff;
    box-shadow: 0 10px 20px rgba(179,0,0,0.65);
}
.btn-pill-red:hover { background: linear-gradient(120deg,#ff4444,#ff7777); color:#fff; }
.btn-outline-soft {
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.35);
    padding: 8px 18px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    background: transparent;
    color: #fff;
}
.btn-outline-soft.disabled, .btn-outline-soft:disabled { opacity:.55; pointer-events:none; }

.card-light {
    background: #fdfdfd;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-soft);
    padding: 18px 22px;
    color: #111;
}

.hero-banner {
    margin-bottom: 18px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--panel-border);
    background: linear-gradient(135deg, rgba(179,0,0,0.25), rgba(0,0,0,0.65));
    box-shadow: var(--shadow-soft);
    padding: 18px 20px;
}
.hero-title { font-size: 1.25rem; font-weight: 800; margin: 0 0 4px; }
.hero-sub { margin: 0; color: #d7d7d7; font-size: 0.92rem; }

.progress-wrap { margin-top: 10px; }
.progress { height: 12px; border-radius: 999px; background: rgba(255,255,255,0.08); overflow: hidden; }
.progress-bar { background: linear-gradient(90deg, var(--brand-red), var(--brand-red-bright)); }
.progress-meta { margin-top: 6px; font-size: 0.82rem; color: var(--muted); display: flex; justify-content: space-between; gap: 10px; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin: 16px 0 22px;
}
.stat-card {
    background: #0f0f0f;
    border: 1px solid var(--panel-border);
    border-radius: 16px;
    box-shadow: 0 14px 28px rgba(0,0,0,0.45);
    padding: 14px 14px 12px;
}
.stat-label { color: var(--muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: .14em; margin-bottom: 6px; }
.stat-value { font-size: 1.25rem; font-weight: 800; margin: 0; }

.list-clean { list-style: none; padding: 0; margin: 0; }
.list-clean li { padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.08); color: #e8e8e8; font-size: 0.92rem; }
.list-clean li:last-child { border-bottom: none; }

.quick-actions { display: grid; gap: 10px; }
.btn-action { display: inline-flex; align-items: center; justify-content: center; text-decoration: none; border-radius: 999px; padding: 10px 14px; font-weight: 700; letter-spacing: .04em; font-size: 0.88rem; }
.btn-action.primary { background: linear-gradient(120deg,var(--brand-red),var(--brand-red-bright)); color: #fff; box-shadow: 0 10px 20px rgba(179,0,0,0.45); }
.btn-action.secondary { background: #181818; color: #fff; border: 1px solid rgba(255,255,255,0.16); }

.tip-card { margin-top: 18px; }

/* ✅ Cross-fade carousel (same width as container, 500px height) */
.fade-carousel{
    position: relative;
    width: 100%;
    height: 500px;              /* ✅ requested */
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid var(--panel-border);
    background: #000;
    box-shadow: var(--shadow-soft);
}

.fade-slide{
    position: absolute;
    inset: 0;
    opacity: 0;
    transition: opacity 1200ms ease-in-out;
    pointer-events: none;
}
.fade-slide.active{ opacity: 1; pointer-events: auto; }

.fade-slide img{
    width: 100%;
    height: 100%;
    object-fit: cover;          /* ✅ fills the box */
    object-position: center;
    display: block;
}

/* < and > buttons */
.fade-nav{
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 46px;
    height: 46px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.25);
    background: rgba(0,0,0,0.45);
    color: #fff;
    font-size: 26px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    outline: none;
    z-index: 5;
}
.fade-nav:hover{
    background: rgba(179,0,0,0.35);
    border-color: rgba(255,255,255,0.35);
}
.fade-nav.prev{ left: 14px; }
.fade-nav.next{ right: 14px; }

/* Responsive */
@media (max-width: 991.98px) {
    .section-row { grid-template-columns: minmax(0,1fr); }
    .stats-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
    .profile-panel { right: 10px; width: 260px; }
    .fade-carousel { height: 380px; }
}
@media (max-width: 575.98px) {
    .topbar { padding: 0 12px; }
    .topbar-welcome { display: none; }
    .main-wrap { padding: 0 12px 32px; }
    .card-dark, .card-light { padding: 16px 16px 18px; }
    .stats-grid { grid-template-columns: 1fr; }
    .fade-carousel { height: 260px; }
}
</style>
</head>
<body>

<header class="topbar">
    <div class="topbar-left">
        <button class="icon-btn" id="sidebarToggle" aria-label="Toggle menu">
            <span class="burger-lines"></span>
        </button>
        <a href="home.php" class="brand-logo">
            <img src="photo/logo.jpg" alt="RJL Fitness">
            <span>RJL Fitness</span>
        </a>
    </div>
    <div class="topbar-right">
        <div class="topbar-welcome">
            Welcome, <strong><?= h($username) ?></strong>
            <span class="badge-role"><?= h($role) ?></span>
        </div>
        <button class="avatar-btn" id="profileToggle" aria-label="Open profile">
            <div class="avatar-circle">
                <img src="<?= h($avatarUrl) ?>" alt="Profile">
            </div>
        </button>
    </div>
</header>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-title">Member Dashboard</div>
    <ul class="sidebar-menu">
        <li>
            <a class="sidebar-link<?= active_link('facilities.php', $current) ?>" href="facilities.php">
                <span class="icon">🏋️‍♂️</span><span>Facilities</span>
            </a>
        </li>
        <li>
            <a class="sidebar-link<?= active_link('user_info.php', $current) ?>" href="user_info.php">
                <span class="icon">🪪</span><span>User Info (Valid ID)</span>
            </a>
        </li>
        <li>
            <a class="sidebar-link<?= active_link('attendance.php', $current) ?>" href="attendance.php">
                <span class="icon">📅</span><span>Attendance</span>
            </a>
        </li>
        <li>
            <a class="sidebar-link<?= active_link('schedules.php', $current) ?>" href="schedules.php">
                <span class="icon">📆</span><span>My Reservation Slot / Booking</span>
            </a>
        </li>
        <li>
            <a class="sidebar-link<?= active_link('activity_list.php', $current) ?>" href="activity_list.php">
                <span class="icon">📋</span><span>Activity List</span>
            </a>
        </li>
       
    </ul>
</aside>

<div class="profile-overlay" id="profileOverlay"></div>

<div class="profile-panel" id="profilePanel">
    <div class="profile-header">
        <div class="avatar-circle">
            <img src="<?= h($avatarUrl) ?>" alt="Profile">
        </div>
        <div>
            <div class="profile-name"><?= h($username) ?></div>
            <div class="profile-meta">ID Number: <?= h($memberIdDisplay) ?></div>
        </div>
    </div>
    <ul class="profile-list">
        <li>
            <span>Status</span>
            <span><span class="badge <?= h($statusBadge) ?>"><?= h($statusText) ?></span></span>
        </li>
        <?php if ($expiryDate): ?>
        <li>
            <span>Expires</span>
            <span><?= h(date('M d, Y', strtotime($expiryDate))) ?></span>
        </li>
        <?php endif; ?>
    </ul>
    <div class="profile-actions">
        <a href="upload_avatar.php" class="secondary">Change Profile Picture</a>
        <a href="upload_id.php" class="secondary">View Valid ID</a>
        <a href="id_rfid_card.php" class="secondary">View RFID CARD</a>
        <a href="change_password.php" class="secondary">Change Password</a>
        <a href="logout.php" class="primary">Logout</a>
    </div>
</div>

<main class="main-wrap">

    <section class="hero-banner">
        <h2 class="hero-title">👋 Welcome Back, <?= h($username) ?></h2>
        <p class="hero-sub">Here’s your membership summary and today’s fitness update.</p>
    </section>

    <div class="section-row">
        <section class="card-dark">
            <div class="membership-title">
                <h5>Membership Status</h5>
                <span class="badge badge-pill <?= h($statusBadge) ?>"><?= h($statusText) ?></span>
            </div>

            <div class="row">
                <div class="col-md-6 mb-2">
                    <p class="mb-1 text-muted" style="font-size:0.85rem;">Expiration Date</p>
                    <h3 class="mb-2" style="font-weight:800;">
                        <?= $expiryDate ? h(date('M d, Y', strtotime($expiryDate))) : 'Not set'; ?>
                    </h3>
                </div>
                <div class="col-md-6 mb-2">
                    <p class="mb-1 text-muted" style="font-size:0.85rem;">Days Remaining</p>
                    <h3 class="mb-2" style="font-weight:800;">
                        <?= ($daysLeft !== null) ? h($daysLeft) . ' day(s)' : 'N/A'; ?>
                    </h3>
                </div>
            </div>

            <p class="mb-2" style="font-size:0.9rem;color:var(--muted);">
                <?= h($expiryText) ?>
            </p>

            <div class="progress-wrap">
                <div class="progress">
                    <div class="progress-bar" role="progressbar" style="width: <?= (int)$progressUsed ?>%;"
                         aria-valuenow="<?= (int)$progressUsed ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <div class="progress-meta">
                    <span><?= (int)$progressUsed ?>% Used</span>
                    <span><?= h($progressNote) ?></span>
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center mt-3" style="gap:10px;">
                <a href="extend_membership.php" class="btn btn-pill-red">Extend Membership</a>

                <?php if ($receiptEnabled): ?>
                    <a href="download_receipt.php" class="btn btn-outline-soft">Download Receipt</a>
                <?php else: ?>
                    <button class="btn btn-outline-soft disabled" type="button" title="Create download_receipt.php to enable">
                        Download Receipt
                    </button>
                <?php endif; ?>
            </div>
        </section>

        <section class="card-dark">
            <h5>Quick Summary</h5>
            <p class="mb-0" style="color:var(--muted);font-size:.92rem;">
                Tip: Use the sidebar to check schedules, attendance, and activities.
            </p>
        </section>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Visits</div>
            <p class="stat-value"><?= h($totalVisits) ?></p>
        </div>
        <div class="stat-card">
            <div class="stat-label">Last Check-in</div>
            <p class="stat-value" style="font-size:1.05rem; font-weight:800;"><?= h($lastCheckIn) ?></p>
        </div>
        <div class="stat-card">
            <div class="stat-label">Last Payment</div>
            <p class="stat-value" style="font-size:1.05rem; font-weight:800;"><?= h($lastPayment) ?></p>
        </div>
        <div class="stat-card">
            <div class="stat-label">Days Left</div>
            <p class="stat-value"><?= ($daysLeft !== null) ? h($daysLeft) : 'N/A' ?></p>
        </div>
    </div>

    <div class="section-row">
        <section class="card-dark">
            <h5>Attendance History</h5>
            <ul class="list-clean">
                <?php if (!empty($attendanceHistory)): ?>
                    <?php foreach ($attendanceHistory as $t): ?>
                        <li>• <?= h($t) ?></li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li>• No attendance records found (or table not detected).</li>
                <?php endif; ?>
            </ul>
        </section>

        <section class="card-dark">
            <h5>Quick Actions</h5>
            <div class="quick-actions">
                <a class="btn-action primary" href="schedules.php">View Class Schedule</a>
                <a class="btn-action secondary" href="attendance.php">Attendance History</a>
                <a class="btn-action secondary" href="facilities.php">Contact Trainer</a>
                <a class="btn-action secondary" href="user_info.php">Update Profile</a>
            </div>
        </section>
    </div>

    <!-- ✅ SAME WIDTH as container, 500px height, fills box -->
    <section class="card-dark tip-card">
        <div class="membership-title" style="margin-bottom:10px;">
            <h5 style="margin:0;">🔥 Today’s Warm-Up Tip</h5>
        </div>

        <div class="fade-carousel" id="warmupFadeCarousel">
            <div class="fade-slide active"><img src="photo/boxer.jpg" alt="Warm-up slide 1"></div>
            <div class="fade-slide"><img src="photo/bodybuilder.jpeg" alt="Warm-up slide 2"></div>
            <div class="fade-slide"><img src="photo/boxer.jpg" alt="Warm-up slide 3"></div>
            <div class="fade-slide"><img src="photo/muay_thai.jpeg" alt="Warm-up slide 4"></div>
            <div class="fade-slide"><img src="photo/zumbainstractor.jpeg" alt="Warm-up slide 5"></div>

            <button class="fade-nav prev" type="button" aria-label="Previous">&lt;</button>
            <button class="fade-nav next" type="button" aria-label="Next">&gt;</button>
        </div>
    </section>

</main>

<script>
// Sidebar toggle
const sidebar        = document.getElementById('sidebar');
const sidebarToggle  = document.getElementById('sidebarToggle');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function closeSidebar() {
    sidebar.classList.remove('open');
    sidebarOverlay.classList.remove('open');
}
function openSidebar() {
    sidebar.classList.add('open');
    sidebarOverlay.classList.add('open');
}

sidebarToggle.addEventListener('click', () => {
    if (sidebar.classList.contains('open')) closeSidebar();
    else openSidebar();
});
sidebarOverlay.addEventListener('click', closeSidebar);

// Profile panel toggle
const profilePanel   = document.getElementById('profilePanel');
const profileToggle  = document.getElementById('profileToggle');
const profileOverlay = document.getElementById('profileOverlay');

function closeProfile() {
    profilePanel.classList.remove('open');
    profileOverlay.classList.remove('open');
}

profileToggle.addEventListener('click', () => {
    const isOpen = profilePanel.classList.contains('open');
    if (isOpen) closeProfile();
    else {
        profilePanel.classList.add('open');
        profileOverlay.classList.add('open');
    }
});
profileOverlay.addEventListener('click', closeProfile);
</script>

<!-- ✅ Cross-fade carousel JS -->
<script>
(() => {
    const carousel = document.getElementById('warmupFadeCarousel');
    if (!carousel) return;

    const slides = Array.from(carousel.querySelectorAll('.fade-slide'));
    const btnPrev = carousel.querySelector('.fade-nav.prev');
    const btnNext = carousel.querySelector('.fade-nav.next');

    const AUTO_INTERVAL = 10000; // 10s
    const AUTO_FADE_MS  = 1200;  // smooth
    const CLICK_FADE_MS = 250;   // fast on click

    let idx = slides.findIndex(s => s.classList.contains('active'));
    if (idx < 0) idx = 0;

    let timer = null;

    function setFadeDuration(ms){
        slides.forEach(s => s.style.transitionDuration = ms + 'ms');
    }

    function go(toIndex, { fast = false } = {}) {
        if (toIndex === idx) return;

        setFadeDuration(fast ? CLICK_FADE_MS : AUTO_FADE_MS);

        slides[idx].classList.remove('active');
        idx = (toIndex + slides.length) % slides.length;
        slides[idx].classList.add('active');
    }

    function next(opts){ go(idx + 1, opts); }
    function prev(opts){ go(idx - 1, opts); }

    function startAuto(){
        stopAuto();
        timer = setInterval(() => next({ fast:false }), AUTO_INTERVAL);
    }

    function stopAuto(){
        if (timer) clearInterval(timer);
        timer = null;
    }

    function resetAuto(){ startAuto(); }

    btnNext?.addEventListener('click', () => { next({ fast:true }); resetAuto(); });
    btnPrev?.addEventListener('click', () => { prev({ fast:true }); resetAuto(); });

    // pause on hover
    carousel.addEventListener('mouseenter', stopAuto);
    carousel.addEventListener('mouseleave', startAuto);

    slides.forEach((s, i) => s.classList.toggle('active', i === idx));
    setFadeDuration(AUTO_FADE_MS);
    startAuto();
})();
</script>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>