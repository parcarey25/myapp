<?php
// FILE: home_trainer.php
// Trainer dashboard – member-like UI + burger sidebar + trainer stats (no core logic removed)

if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__.'/db.php';

// Allow trainer and admin to use this dashboard
$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($role, ['trainer','admin'], true)) {
    header('Location: home.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$user   = [
    'username'  => $_SESSION['username'] ?? '',
    'email'     => '',
    'full_name' => ''
];

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1";
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

function has_column(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
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

function first_col(mysqli $conn, string $table, array $cands): ?string {
    foreach ($cands as $c) {
        if (has_column($conn, $table, $c)) return $c;
    }
    return null;
}

function safe_date_str(?string $dt): ?string {
    if (!$dt) return null;
    $ts = strtotime($dt);
    if ($ts === false) return null;
    return date('Y-m-d', $ts);
}

// Load trainer basic info
if ($st = $conn->prepare("SELECT full_name, email FROM users WHERE id=? LIMIT 1")) {
    $st->bind_param('i', $userId);
    $st->execute();
    if ($rs = $st->get_result()) {
        if ($row = $rs->fetch_assoc()) {
            $user['full_name'] = $row['full_name'] ?? '';
            $user['email']     = $row['email'] ?? '';
        }
        $rs->free();
    }
    $st->close();
}

// Optional small stats (safe, simple)
$totalMembers = 0;
if ($r = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='member'")) {
    $totalMembers = (int)$r->fetch_assoc()['c'];
    $r->free();
}

$avatarPath  = 'photo/logo.jpg';
$displayName = $user['full_name'] ?: $user['username'];
$today       = date('Y-m-d');

/* ============================================================
   Trainer dashboard data (safe detection)
   - booking table: pending requests count, today sessions, lists
   - availability table: today available slots count
   ============================================================ */

// Detect booking table (adjust if your schedules.php uses a different table)
$BOOKING_TABLE_CANDIDATES = [
    'schedules',
    'bookings',
    'schedule_requests',
    'trainer_bookings',
    'facility_bookings'
];
$bookTable = null;
foreach ($BOOKING_TABLE_CANDIDATES as $t) {
    if (table_exists($conn, $t)) { $bookTable = $t; break; }
}

$pendingCount = 'N/A';
$todaySessionsCount = 'N/A';
$pendingList = [];
$todaySchedule = [];
$bookError = null;

if ($bookTable) {
    $idCol      = first_col($conn, $bookTable, ['id','schedule_id','booking_id']);
    $trainerCol = first_col($conn, $bookTable, ['trainer_id','coach_id']);
    $statusCol  = first_col($conn, $bookTable, ['status','booking_status','request_status']);
    $dateCol    = first_col($conn, $bookTable, ['date','booking_date','session_date','schedule_date','schedule_day']);
    $rangeCol   = first_col($conn, $bookTable, ['time_slot','time_range','slot']);
    $startCol   = first_col($conn, $bookTable, ['time_start','start_time','from_time']);
    $endCol     = first_col($conn, $bookTable, ['time_end','end_time','to_time']);
    $timeOnlyCol= first_col($conn, $bookTable, ['time']);
    $nameCol    = first_col($conn, $bookTable, ['full_name','name']);
    $notesCol   = first_col($conn, $bookTable, ['notes','remark','remarks','comment']);
    $createdCol = first_col($conn, $bookTable, ['created_at','created']);

    if (!$trainerCol || !$statusCol || !$dateCol) {
        $bookError = "Booking table detected (`{$bookTable}`) but missing needed columns.";
    } else {
        $whereDate = "DATE(`{$dateCol}`)=?";
        // pending = PENDING
        $wherePending = "UPPER(TRIM(`{$statusCol}`))='PENDING'";
        // today sessions = APPROVED/ACCEPTED
        $whereApproved = "UPPER(TRIM(`{$statusCol}`)) IN ('APPROVED','ACCEPTED')";

        // Pending count for trainer OR any trainer (0/null)
        $sql = "SELECT COUNT(*) AS c
                FROM `{$bookTable}`
                WHERE {$whereDate}
                  AND {$wherePending}
                  AND (`{$trainerCol}`=? OR `{$trainerCol}`=0 OR `{$trainerCol}` IS NULL)";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('si', $today, $userId);
            if ($st->execute()) {
                $r = $st->get_result()->fetch_assoc();
                $pendingCount = $r ? (int)$r['c'] : 0;
            }
            $st->close();
        }

        // Today sessions count assigned to this trainer
        $sql = "SELECT COUNT(*) AS c
                FROM `{$bookTable}`
                WHERE {$whereDate}
                  AND {$whereApproved}
                  AND `{$trainerCol}`=?";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('si', $today, $userId);
            if ($st->execute()) {
                $r = $st->get_result()->fetch_assoc();
                $todaySessionsCount = $r ? (int)$r['c'] : 0;
            }
            $st->close();
        }

        // Pending list (latest 6)
        $sel = [];
        $sel[] = $idCol ? "`{$idCol}` AS bid" : "0 AS bid";
        $sel[] = "`{$trainerCol}` AS btrainer";
        $sel[] = "`{$dateCol}` AS bdate";
        if ($nameCol) $sel[] = "`{$nameCol}` AS bname";
        if ($notesCol) $sel[] = "`{$notesCol}` AS bnotes";
        if ($rangeCol) $sel[] = "`{$rangeCol}` AS brange";
        if ($startCol) $sel[] = "`{$startCol}` AS bstart";
        if ($endCol) $sel[] = "`{$endCol}` AS bend";
        if ($timeOnlyCol) $sel[] = "`{$timeOnlyCol}` AS btime";
        if ($createdCol) $sel[] = "`{$createdCol}` AS bcreated";

        $orderBy = $createdCol ? "`{$createdCol}` DESC" : ($idCol ? "`{$idCol}` DESC" : "1 DESC");

        $sql = "SELECT ".implode(',', $sel)."
                FROM `{$bookTable}`
                WHERE {$whereDate}
                  AND {$wherePending}
                  AND (`{$trainerCol}`=? OR `{$trainerCol}`=0 OR `{$trainerCol}` IS NULL)
                ORDER BY {$orderBy}
                LIMIT 6";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('si', $today, $userId);
            if ($st->execute()) {
                $res = $st->get_result();
                while ($row = $res->fetch_assoc()) {
                    // compute display time
                    $slot = $row['brange'] ?? '';
                    if (!$slot && !empty($row['bstart']) && !empty($row['bend'])) {
                        $slot = substr($row['bstart'],0,5)."-".substr($row['bend'],0,5);
                    }
                    if (!$slot && !empty($row['btime'])) {
                        $slot = substr($row['btime'],0,5);
                    }
                    $pendingList[] = [
                        'id' => (int)($row['bid'] ?? 0),
                        'member' => $row['bname'] ?? ('Booking #'.($row['bid'] ?? '')),
                        'time' => $slot ?: '—',
                        'type' => ((int)($row['btrainer'] ?? 0) === 0 || empty($row['btrainer'])) ? 'ANY TRAINER' : 'ASSIGNED',
                        'notes' => $row['bnotes'] ?? ''
                    ];
                }
                $res->free();
            }
            $st->close();
        }

        // Today schedule list (approved/accepted for this trainer) latest 6
        $sql = "SELECT ".implode(',', $sel)."
                FROM `{$bookTable}`
                WHERE {$whereDate}
                  AND {$whereApproved}
                  AND `{$trainerCol}`=?
                ORDER BY {$orderBy}
                LIMIT 6";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('si', $today, $userId);
            if ($st->execute()) {
                $res = $st->get_result();
                while ($row = $res->fetch_assoc()) {
                    $slot = $row['brange'] ?? '';
                    if (!$slot && !empty($row['bstart']) && !empty($row['bend'])) {
                        $slot = substr($row['bstart'],0,5)."-".substr($row['bend'],0,5);
                    }
                    if (!$slot && !empty($row['btime'])) {
                        $slot = substr($row['btime'],0,5);
                    }
                    $todaySchedule[] = [
                        'member' => $row['bname'] ?? ('Booking #'.($row['bid'] ?? '')),
                        'time' => $slot ?: '—',
                        'notes' => $row['bnotes'] ?? ''
                    ];
                }
                $res->free();
            }
            $st->close();
        }
    }
} else {
    $bookError = "Booking table not detected. (Add your table to BOOKING_TABLE_CANDIDATES in home_trainer.php)";
}

// Availability slots today
$availCount = 'N/A';
if (table_exists($conn, 'trainer_availability')) {
    $tCol = has_column($conn,'trainer_availability','trainer_id') ? 'trainer_id' : null;
    $dCol = has_column($conn,'trainer_availability','avail_date') ? 'avail_date' : (has_column($conn,'trainer_availability','date') ? 'date' : null);
    $sCol = has_column($conn,'trainer_availability','time_slot') ? 'time_slot' : (has_column($conn,'trainer_availability','slot') ? 'slot' : null);
    $iCol = has_column($conn,'trainer_availability','is_available') ? 'is_available' : null;

    if ($tCol && $dCol && $sCol) {
        $sql = "SELECT COUNT(DISTINCT `{$sCol}`) AS c
                FROM trainer_availability
                WHERE `{$tCol}`=? AND `{$dCol}`=? ".($iCol ? " AND `{$iCol}`=1" : "");
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('is', $userId, $today);
            if ($st->execute()) {
                $r = $st->get_result()->fetch_assoc();
                $availCount = $r ? (int)$r['c'] : 0;
            }
            $st->close();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trainer Dashboard | RJL Fitness</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">

<style>
:root{
  --brand-red:#b30000;
  --brand-red-bright:#ff3333;
  --panel-dark:#111111;
  --panel-border:#2a2a2a;
  --muted:#aaaaaa;
  --text-main:#f5f5f5;
  --accent-soft:#222222;
  --radius-lg:18px;
  --shadow-soft:0 18px 35px rgba(0,0,0,0.55);
}

/* base */
*{box-sizing:border-box}
body{
  margin:0;
  min-height:100vh;
  background: radial-gradient(circle at top, #111 0, #000 55%, #000 100%);
  color:var(--text-main);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
}

/* topbar */
.topbar{
  position:sticky; top:0; z-index:1000;
  height:64px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:0 22px;
  background:linear-gradient(90deg,#000,var(--brand-red));
  box-shadow:0 10px 25px rgba(0,0,0,.5);
}
.topbar-left{display:flex;align-items:center;gap:10px;}
.brand-logo{
  display:flex;align-items:center;color:#fff;text-decoration:none;
  font-weight:700;letter-spacing:.04em;
}
.brand-logo img{height:34px;margin-right:10px;border-radius:8px;}
.topbar-right{display:flex;align-items:center;gap:12px;}
.topbar-welcome{font-size:.92rem;color:#fbeaea;white-space:nowrap;}
.badge-role{
  margin-left:8px;
  background:rgba(0,0,0,.45);
  border:1px solid rgba(255,255,255,.15);
  border-radius:999px;
  padding:2px 10px;
  font-size:.72rem;
  text-transform:uppercase;
  letter-spacing:.10em;
}

/* burger */
.icon-btn{
  border:none;background:transparent;color:#fff;
  padding:6px 10px;border-radius:999px;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;outline:none !important;
}
.icon-btn:hover{background:rgba(255,255,255,.08);}
.icon-btn .burger-lines{
  display:inline-block;position:relative;
  width:18px;height:2px;background:#fff;border-radius:999px;
}
.icon-btn .burger-lines::before,
.icon-btn .burger-lines::after{
  content:"";position:absolute;left:0;width:18px;height:2px;background:#fff;border-radius:999px;
}
.icon-btn .burger-lines::before{top:-6px;}
.icon-btn .burger-lines::after{top:6px;}

/* sidebar */
.sidebar-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.55);
  opacity:0;visibility:hidden;transition:opacity .2s ease;
  z-index:980;
}
.sidebar-overlay.open{opacity:1;visibility:visible;}

.sidebar{
  position:fixed;top:64px;left:0;
  width:260px;height:calc(100vh - 64px);
  background:var(--panel-dark);
  border-right:1px solid var(--panel-border);
  box-shadow:var(--shadow-soft);
  padding:18px 16px;
  transform:translateX(-270px);
  transition:transform .22s ease-out;
  z-index:990;
  display:flex;flex-direction:column;
}
.sidebar.open{transform:translateX(0);}
.sidebar-title{
  font-size:.78rem;text-transform:uppercase;
  letter-spacing:.16em;color:var(--muted);margin-bottom:10px;
}
.sidebar-menu{list-style:none;padding:0;margin:0;}
.sidebar-menu li{margin-bottom:6px;}
.sidebar-link{
  display:flex;align-items:center;
  padding:9px 10px;border-radius:10px;
  color:#f7f7f7;font-size:.92rem;text-decoration:none;
  transition:background .15s, transform .1s, box-shadow .15s;
}
.sidebar-link span.icon{
  display:inline-flex;width:22px;justify-content:center;margin-right:10px;font-size:1.05rem;
}
.sidebar-link:hover{
  background:var(--accent-soft);
  transform:translateY(-1px);
  box-shadow:0 10px 20px rgba(0,0,0,.35);
}
.sidebar-link.active{
  background:linear-gradient(120deg,var(--brand-red),#ff4d4d);
  box-shadow:0 12px 28px rgba(179,0,0,.55);
}

/* profile */
.profile-wrap{position:relative}
.profile-circle{
  width:40px;height:40px;border-radius:999px;overflow:hidden;
  border:2px solid #fff;background:#222;cursor:pointer;padding:0;outline:none;
  box-shadow:0 0 0 2px rgba(0,0,0,.35);
}
.profile-img{width:100%;height:100%;object-fit:cover;display:block}
.profile-panel{
  position:absolute;right:0;top:calc(100% + 10px);
  width:320px;max-width:92vw;
  background:var(--panel-dark);
  border:1px solid var(--panel-border);
  border-radius:16px;
  box-shadow:var(--shadow-soft);
  padding:14px 14px 12px;
  display:none;z-index:3000;
}
.profile-panel.show{display:block}
.panel-row{display:flex;justify-content:space-between;margin:7px 0;font-size:.88rem}
.panel-row span:first-child{color:var(--muted)}
.profile-actions a{
  display:block;text-decoration:none;text-align:center;
  padding:9px 12px;border-radius:999px;font-weight:700;font-size:.86rem;
}
.profile-actions a.secondary{
  background:#181818;color:#fff;border:1px solid rgba(255,255,255,.14);
}
.profile-actions a.primary{
  margin-top:8px;
  background:linear-gradient(120deg,var(--brand-red),var(--brand-red-bright));
  color:#fff;box-shadow:0 10px 20px rgba(179,0,0,.45);
}

/* main layout */
.main-wrap{
  max-width:1180px;
  margin:22px auto 48px;
  padding:0 18px;
}
.hero{
  border-radius:var(--radius-lg);
  border:1px solid var(--panel-border);
  background:linear-gradient(135deg, rgba(179,0,0,.18), rgba(0,0,0,.65));
  box-shadow:var(--shadow-soft);
  padding:18px 20px;
  margin-bottom:18px;
}
.hero h2{font-size:1.25rem;font-weight:900;margin:0 0 4px;}
.hero p{margin:0;color:#d7d7d7;font-size:.92rem;}

.card-dark{
  background: radial-gradient(circle at top, #222 0, #111 45%, #080808 100%);
  border-radius:var(--radius-lg);
  border:1px solid var(--panel-border);
  box-shadow:var(--shadow-soft);
  padding:18px 20px;
}
.card-dark h5{
  font-size:1rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);
  margin-bottom:12px;
}

/* stats */
.stats-grid{
  display:grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap:14px;
  margin: 0 0 18px;
}
.stat-card{
  background:#0f0f0f;
  border:1px solid var(--panel-border);
  border-radius:16px;
  box-shadow:0 14px 28px rgba(0,0,0,.45);
  padding:14px 14px 12px;
}
.stat-label{
  color:var(--muted);
  font-size:.72rem;
  text-transform:uppercase;
  letter-spacing:.14em;
  margin-bottom:6px;
}
.stat-value{font-size:1.25rem;font-weight:900;margin:0;}
.stat-sub{color:#cfcfcf;font-size:.85rem;margin-top:4px;opacity:.85}

/* content grid */
.section-row{
  display:grid;
  grid-template-columns: minmax(0, 1.6fr) minmax(0, 1.2fr);
  gap:18px;
  margin-bottom:18px;
}

.list-clean{list-style:none;padding:0;margin:0;}
.list-clean li{
  padding:10px 0;
  border-bottom:1px solid rgba(255,255,255,.08);
  color:#e8e8e8;
  font-size:.92rem;
}
.list-clean li:last-child{border-bottom:none;}
.pill{
  display:inline-flex;
  padding:3px 10px;
  border-radius:999px;
  font-weight:800;
  font-size:.72rem;
  letter-spacing:.06em;
  border:1px solid rgba(255,255,255,.18);
  background:#111;
  color:#ddd;
}
.pill.any{color:#ffc107;border-color:rgba(255,193,7,.55);background:rgba(255,193,7,.12);}

.quick-actions{display:grid;gap:10px;}
.btn-action{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  text-decoration:none;
  border-radius:999px;
  padding:10px 14px;
  font-weight:800;
  letter-spacing:.04em;
  font-size:.88rem;
}
.btn-action.primary{
  background:linear-gradient(120deg,var(--brand-red),var(--brand-red-bright));
  color:#fff;
  box-shadow:0 10px 20px rgba(179,0,0,.45);
}
.btn-action.secondary{
  background:#181818;
  color:#fff;
  border:1px solid rgba(255,255,255,.16);
}
.callout{
  margin-top:12px;
  border-radius:16px;
  border:1px solid rgba(255,255,255,.12);
  background:#111;
  padding:12px 14px;
  color:#dcdcdc;
}
.callout code{color:#ff7b7b}

@media (max-width: 991.98px){
  .stats-grid{grid-template-columns: repeat(2, minmax(0,1fr));}
  .section-row{grid-template-columns: 1fr;}
  .topbar-welcome{display:none;}
}
@media (max-width: 575.98px){
  .stats-grid{grid-template-columns: 1fr;}
}
</style>
</head>

<body>

<header class="topbar">
  <div class="topbar-left">
    <button class="icon-btn" id="sidebarToggle" aria-label="Toggle menu">
      <span class="burger-lines"></span>
    </button>

    <a class="brand-logo" href="home.php">
      <img src="photo/logo.jpg" alt="RJL Fitness">
      <span>RJL Fitness</span>
    </a>
  </div>

  <div class="topbar-right">
    <div class="topbar-welcome">
      Welcome, <strong><?= h($displayName) ?></strong>
      <span class="badge-role"><?= h($role) ?></span>
    </div>

    <div class="profile-wrap" id="user-info">
      <button id="profileBtn" class="profile-circle" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="profilePanel">
        <img src="<?= h($avatarPath) ?>" class="profile-img" alt="Profile">
      </button>

      <div id="profilePanel" class="profile-panel" role="dialog" aria-hidden="true">
        <div class="panel-row">
          <span>Name</span>
          <span><?= h($displayName) ?></span>
        </div>
        <div class="panel-row">
          <span>Email</span>
          <span><?= h($user['email'] ?: '—') ?></span>
        </div>
        <div class="panel-row">
          <span>Role</span>
          <span><?= h(strtoupper($role)) ?></span>
        </div>
        <hr style="border-color:rgba(255,255,255,.12);margin:10px 0 12px;">
        <div class="profile-actions">
          <a href="change_password.php" class="secondary">Change Password</a>
          <a href="logout.php" class="primary">Logout</a>
        </div>
      </div>
    </div>
  </div>
</header>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-title">Trainer Menu</div>
  <ul class="sidebar-menu">

    <li>
      <a class="sidebar-link" href="facilities_trainer.php">
        <span class="icon">📅</span><span>Trainer Facilities</span>
      </a>
    </li>
    <li>
      <a class="sidebar-link" href="trainer_pending.php">
        <span class="icon">⏳</span><span>Trainer Pending</span>
      </a>
    </li>
    <li>
      <a class="sidebar-link" href="user_info.php">
        <span class="icon">🪪</span><span>Trainer Info</span>
      </a>
    </li>
   
  </ul>
</aside>

<main class="main-wrap">

  <section class="hero">
    <h2>Trainer Dashboard</h2>
    <p>Manage availability, approve schedules, and guide members’ training plans.</p>
  </section>

  <?php if ($bookError): ?>
    <div class="card-dark" style="margin-bottom:18px;">
      <h5>System Notice</h5>
      <div style="color:#d7d7d7;font-size:.92rem;"><?= h($bookError) ?></div>
    </div>
  <?php endif; ?>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">Total Members</div>
      <p class="stat-value"><?= (int)$totalMembers ?></p>
      <div class="stat-sub">Registered members</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Pending Requests</div>
      <p class="stat-value"><?= h($pendingCount) ?></p>
      <div class="stat-sub">Today (assigned/any)</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Today Sessions</div>
      <p class="stat-value"><?= h($todaySessionsCount) ?></p>
      <div class="stat-sub">Approved for you</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Available Slots</div>
      <p class="stat-value"><?= h($availCount) ?></p>
      <div class="stat-sub">From your availability</div>
    </div>
  </div>

  <div class="section-row">
    <section class="card-dark">
      <h5>Today’s Schedule</h5>
      <ul class="list-clean">
        <?php if (empty($todaySchedule)): ?>
          <li>• No approved sessions for today.</li>
        <?php else: foreach ($todaySchedule as $s): ?>
          <li>
            <strong><?= h($s['time']) ?></strong> — <?= h($s['member']) ?>
            <?php if (!empty($s['notes'])): ?>
              <div style="color:#bdbdbd;font-size:.86rem;margin-top:4px;"><?= h($s['notes']) ?></div>
            <?php endif; ?>
          </li>
        <?php endforeach; endif; ?>
      </ul>
    </section>

    <section class="card-dark">
      <h5>Quick Actions</h5>
      <div class="quick-actions">
        <a class="btn-action primary" href="facilities_trainer.php">Manage Availability</a>
        <a class="btn-action secondary" href="trainer_pending.php">View Pending Requests</a>
        <a class="btn-action secondary" href="user_info.php">Update Trainer Info</a>
        <a class="btn-action secondary" href="attendance.php">Attendance</a>
      </div>

      <div class="callout">
        Members will see your plans in <strong>Training &amp; Meal Plan</strong>
        (page: <code>member_training.php</code>).
      </div>
    </section>
  </div>

  <section class="card-dark">
    <h5>Pending Requests (Today)</h5>
    <ul class="list-clean">
      <?php if (empty($pendingList)): ?>
        <li>• No pending requests today.</li>
      <?php else: foreach ($pendingList as $p): ?>
        <li>
          <strong><?= h($p['time']) ?></strong> — <?= h($p['member']) ?>
          <span class="pill <?= ($p['type']==='ANY TRAINER') ? 'any' : '' ?>" style="margin-left:8px;"><?= h($p['type']) ?></span>
          <?php if (!empty($p['notes'])): ?>
            <div style="color:#bdbdbd;font-size:.86rem;margin-top:4px;"><?= h($p['notes']) ?></div>
          <?php endif; ?>
        </li>
      <?php endforeach; endif; ?>
    </ul>
  </section>

</main>

<script>
// Sidebar toggle (same as member)
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
sidebarToggle?.addEventListener('click', () => {
  if (sidebar.classList.contains('open')) closeSidebar();
  else openSidebar();
});
sidebarOverlay?.addEventListener('click', closeSidebar);
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
});
</script>

<script>
// Profile dropdown (your original logic kept)
(function(){
  const profileBtn   = document.getElementById('profileBtn');
  const profilePanel = document.getElementById('profilePanel');

  function openPanel(){
    if(!profilePanel) return;
    profilePanel.classList.add('show');
    profilePanel.setAttribute('aria-hidden','false');
    if(profileBtn) profileBtn.setAttribute('aria-expanded','true');
  }
  function closePanel(){
    if(!profilePanel) return;
    profilePanel.classList.remove('show');
    profilePanel.setAttribute('aria-hidden','true');
    if(profileBtn) profileBtn.setAttribute('aria-expanded','false');
  }
  function togglePanel(){
    if(!profilePanel) return;
    profilePanel.classList.contains('show') ? closePanel() : openPanel();
  }

  if(profileBtn){
    profileBtn.addEventListener('click',function(e){
      e.preventDefault();
      e.stopPropagation();
      togglePanel();
    });
  }
  if(profilePanel){
    profilePanel.addEventListener('click',function(e){
      e.stopPropagation();
    });
  }
  document.addEventListener('click',function(){
    if(profilePanel && profilePanel.classList.contains('show')) closePanel();
  });
  document.addEventListener('keydown',function(e){
    if(e.key==='Escape' && profilePanel && profilePanel.classList.contains('show')){
      e.preventDefault();
      closePanel();
    }
  });
})();
</script>

</body>
</html>