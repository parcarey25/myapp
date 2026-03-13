<?php
// home_staff.php — Staff dashboard (modern RJL UI + burger sidebar + staff metrics)
// Safe: auto-detects common tables/columns and will not break if missing.

if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($role, ['staff','admin'], true)) {
    header('Location: home.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);

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

function first_existing_table(mysqli $conn, array $candidates): ?string {
    foreach ($candidates as $t) {
        if (table_exists($conn, $t)) return $t;
    }
    return null;
}

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

// ========================
// Load staff profile info
// ========================
$displayName = $_SESSION['username'] ?? 'staff';
$email = '';
$fullName = '';

if ($st = $conn->prepare("SELECT full_name, email FROM users WHERE id=? LIMIT 1")) {
    $st->bind_param('i', $userId);
    $st->execute();
    if ($rs = $st->get_result()) {
        if ($row = $rs->fetch_assoc()) {
            $fullName = $row['full_name'] ?? '';
            $email    = $row['email'] ?? '';
        }
        $rs->free();
    }
    $st->close();
}
if ($fullName) $displayName = $fullName;

$avatarPath = 'photo/logo.jpg';

// ========================
// Staff Metrics (SAFE)
// ========================

// Pending Users count
$pendingUsers = 'N/A';
if (table_exists($conn, 'users') && has_column($conn, 'users', 'status')) {
    $sql = "SELECT COUNT(*) AS c FROM users WHERE LOWER(role)='member' AND LOWER(status)='pending'";
    if ($res = $conn->query($sql)) {
        $pendingUsers = (int)($res->fetch_assoc()['c'] ?? 0);
        $res->free();
    } else {
        // fallback if status uses different labels
        $sql = "SELECT COUNT(*) AS c FROM users WHERE LOWER(role)='member' AND (status IS NULL OR status='')";
        if ($res = $conn->query($sql)) {
            $pendingUsers = (int)($res->fetch_assoc()['c'] ?? 0);
            $res->free();
        }
    }
} elseif (table_exists($conn, 'users')) {
    // If no status column, can't compute
    $pendingUsers = 'N/A';
}

// Pending Valid ID count (users.valid_id_status etc.)
$pendingIDs = 'N/A';
$validIdStatusCol = null;
foreach (['valid_id_status','id_status','valid_id_approval','valid_id_state'] as $c) {
    if (has_column($conn, 'users', $c)) { $validIdStatusCol = $c; break; }
}
if ($validIdStatusCol) {
    $sql = "SELECT COUNT(*) AS c FROM users WHERE LOWER(role)='member' AND UPPER(TRIM(`{$validIdStatusCol}`))='PENDING'";
    if ($res = $conn->query($sql)) {
        $pendingIDs = (int)($res->fetch_assoc()['c'] ?? 0);
        $res->free();
    }
}

// Pending Schedule Requests count (from schedules/bookings)
$pendingSchedules = 'N/A';
$bookTable = first_existing_table($conn, ['schedules','bookings','schedule_requests','trainer_bookings','facility_bookings']);
if ($bookTable) {
    $statusCol = first_col($conn, $bookTable, ['status','booking_status','request_status']);
    if ($statusCol) {
        $sql = "SELECT COUNT(*) AS c FROM `{$bookTable}` WHERE UPPER(TRIM(`{$statusCol}`))='PENDING'";
        if ($res = $conn->query($sql)) {
            $pendingSchedules = (int)($res->fetch_assoc()['c'] ?? 0);
            $res->free();
        }
    }
}

// Today's Payments (count + total)
$paymentsTodayCount = 'N/A';
$paymentsTodaySum   = 'N/A';
$paymentsTable = table_exists($conn, 'payments') ? 'payments' : null;

if ($paymentsTable) {
    $amtCol = first_col($conn, $paymentsTable, ['amount','total','paid_amount']);
    $dateCol = first_col($conn, $paymentsTable, ['created_at','paid_at','date_paid','payment_date','transaction_date']);

    // If no date column, still count total rows
    if ($amtCol) {
        if ($dateCol) {
            $sql = "SELECT COUNT(*) AS c, COALESCE(SUM(`{$amtCol}`),0) AS s
                    FROM `{$paymentsTable}`
                    WHERE DATE(`{$dateCol}`)=?";
            if ($st = $conn->prepare($sql)) {
                $st->bind_param('s', $today);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $paymentsTodayCount = (int)($row['c'] ?? 0);
                $paymentsTodaySum = number_format((float)($row['s'] ?? 0), 2);
                $st->close();
            }
        } else {
            $sql = "SELECT COUNT(*) AS c, COALESCE(SUM(`{$amtCol}`),0) AS s FROM `{$paymentsTable}`";
            if ($res = $conn->query($sql)) {
                $row = $res->fetch_assoc();
                $paymentsTodayCount = (int)($row['c'] ?? 0);
                $paymentsTodaySum = number_format((float)($row['s'] ?? 0), 2);
                $res->free();
            }
        }
    }
}

// Facilities count
$facilitiesCount = 'N/A';
if (table_exists($conn, 'facilities')) {
    $sql = "SELECT COUNT(*) AS c FROM facilities";
    if ($res = $conn->query($sql)) {
        $facilitiesCount = (int)($res->fetch_assoc()['c'] ?? 0);
        $res->free();
    }
}

// Recent payments list (last 5)
$recentPayments = [];
if ($paymentsTable) {
    $uidCol = first_col($conn, $paymentsTable, ['user_id','member_id']);
    $amtCol = first_col($conn, $paymentsTable, ['amount','total']);
    $refCol = first_col($conn, $paymentsTable, ['reference','description','remarks']);
    $dateCol= first_col($conn, $paymentsTable, ['created_at','paid_at','payment_date','date_paid']);
    if ($uidCol && $amtCol) {
        $sql = "SELECT `{$uidCol}` AS uid,
                       `{$amtCol}` AS amt"
               . ($refCol ? ", `{$refCol}` AS ref" : ", '' AS ref")
               . ($dateCol? ", `{$dateCol}` AS dt" : ", NULL AS dt")
               . " FROM `{$paymentsTable}` ORDER BY "
               . ($dateCol ? "`{$dateCol}` DESC" : "uid DESC")
               . " LIMIT 5";
        if ($res = $conn->query($sql)) {
            while ($r = $res->fetch_assoc()) {
                $recentPayments[] = $r;
            }
            $res->free();
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Staff Dashboard | RJL Fitness</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
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
*{box-sizing:border-box}
body{
  margin:0;
  min-height:100vh;
  background: radial-gradient(circle at top, #111 0, #000 55%, #000 100%);
  color:var(--text-main);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
}

/* Topbar */
.topbar{
  position:sticky;top:0;z-index:1000;
  height:64px;
  display:flex;align-items:center;justify-content:space-between;
  padding:0 22px;
  background:linear-gradient(90deg,#000,var(--brand-red));
  box-shadow:0 10px 25px rgba(0,0,0,.5);
}
.topbar-left{display:flex;align-items:center;gap:10px;}
.brand-logo{display:flex;align-items:center;color:#fff;text-decoration:none;font-weight:800;letter-spacing:.04em;}
.brand-logo img{height:34px;border-radius:8px;margin-right:10px;}
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
.icon-btn .burger-lines::before,.icon-btn .burger-lines::after{
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
  font-size:.78rem;text-transform:uppercase;letter-spacing:.16em;
  color:var(--muted);margin-bottom:10px;
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
  display:inline-flex;width:22px;justify-content:center;
  margin-right:10px;font-size:1.05rem;
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
  padding:9px 12px;border-radius:999px;font-weight:800;font-size:.86rem;
}
.profile-actions a.secondary{
  background:#181818;color:#fff;border:1px solid rgba(255,255,255,.14);
}
.profile-actions a.primary{
  margin-top:8px;
  background:linear-gradient(120deg,var(--brand-red),var(--brand-red-bright));
  color:#fff;box-shadow:0 10px 20px rgba(179,0,0,.45);
}

/* main */
.main-wrap{max-width:1180px;margin:22px auto 48px;padding:0 18px;}
.hero{
  border-radius:var(--radius-lg);
  border:1px solid var(--panel-border);
  background:linear-gradient(135deg, rgba(179,0,0,.18), rgba(0,0,0,.65));
  box-shadow:var(--shadow-soft);
  padding:18px 20px;margin-bottom:18px;
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
  font-size:1rem;letter-spacing:.08em;text-transform:uppercase;
  color:var(--muted);margin-bottom:12px;
}

/* stats */
.stats-grid{
  display:grid;
  grid-template-columns: repeat(5, minmax(0,1fr));
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

/* grids */
.section-row{
  display:grid;
  grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr);
  gap:18px;
  margin-bottom:18px;
}
.list-clean{list-style:none;padding:0;margin:0;}
.list-clean li{
  padding:10px 0;border-bottom:1px solid rgba(255,255,255,.08);
  color:#e8e8e8;font-size:.92rem;
}
.list-clean li:last-child{border-bottom:none;}
.small-muted{color:var(--muted);font-size:.88rem}

.quick-actions{display:grid;gap:10px;}
.btn-action{
  display:inline-flex;align-items:center;justify-content:center;
  text-decoration:none;border-radius:999px;padding:10px 14px;
  font-weight:800;letter-spacing:.04em;font-size:.88rem;
}
.btn-action.primary{
  background:linear-gradient(120deg,var(--brand-red),var(--brand-red-bright));
  color:#fff;box-shadow:0 10px 20px rgba(179,0,0,.45);
}
.btn-action.secondary{
  background:#181818;color:#fff;border:1px solid rgba(255,255,255,.16);
}

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

    <div class="profile-wrap">
      <button id="profileBtn" class="profile-circle" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="profilePanel">
        <img src="<?= h($avatarPath) ?>" class="profile-img" alt="Profile">
      </button>

      <div id="profilePanel" class="profile-panel" role="dialog" aria-hidden="true">
        <div class="panel-row"><span>Name</span><span><?= h($displayName) ?></span></div>
        <div class="panel-row"><span>Email</span><span><?= h($email ?: '—') ?></span></div>
        <div class="panel-row"><span>Role</span><span><?= h(strtoupper($role)) ?></span></div>
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
  <div class="sidebar-title">Staff Menu</div>
  <ul class="sidebar-menu">

    <li><a class="sidebar-link" href="pending_users.php"><span class="icon">⏳</span><span>Pending Approvals</span></a></li>
    <li><a class="sidebar-link" href="users.php"><span class="icon">👥</span><span>Users</span></a></li>
    <li><a class="sidebar-link" href="extend_membership.php"><span class="icon">💳</span><span>Extend Membership</span></a></li>
    <li><a class="sidebar-link" href="all_attendance.php"><span class="icon">📅</span><span>Attendance</span></a></li>
    <li><a class="sidebar-link" href="rfid_load.php"><span class="icon">💳</span><span>Load RFID Card</span></a></li>
  </ul>
</aside>

<main class="main-wrap">
  <section class="hero">
    <h2>Staff Dashboard</h2>
    <p>Manage users, approvals, facilities, schedules, POS, and RFID wallet transactions.</p>
  </section>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">Pending Users</div>
      <p class="stat-value"><?= h($pendingUsers) ?></p>
    </div>
    <div class="stat-card">
      <div class="stat-label">Pending Valid IDs</div>
      <p class="stat-value"><?= h($pendingIDs) ?></p>
    </div>
    <div class="stat-card">
      <div class="stat-label">Pending Schedules</div>
      <p class="stat-value"><?= h($pendingSchedules) ?></p>
    </div>
    <div class="stat-card">
      <div class="stat-label">Payments Today</div>
      <p class="stat-value"><?= h($paymentsTodayCount) ?></p>
      <div class="small-muted">₱<?= h($paymentsTodaySum) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Facilities</div>
      <p class="stat-value"><?= h($facilitiesCount) ?></p>
    </div>
  </div>

  <div class="section-row">
    <section class="card-dark">
      <h5>Quick Actions</h5>
      <div class="quick-actions">
        <a class="btn-action primary" href="pos.php">Open POS</a>
        <a class="btn-action primary" href="pending_users.php">Review Pending</a>
        <a class="btn-action primary" href="users.php">Browse Users</a>
        <a class="btn-action primary" href="extend_membership.php">Extend Membership</a>
      </div>
      <div class="small-muted mt-3">
        Tip: Use “Pending Approvals” for user approvals and ID verification checks.
      </div>
    </section>

    <section class="card-dark">
      <h5>Recent Payments</h5>
      <ul class="list-clean">
        <?php if (empty($recentPayments)): ?>
          <li>• No payment records found.</li>
        <?php else: foreach ($recentPayments as $p): ?>
          <?php
            $dt = $p['dt'] ? date('M d, g:ia', strtotime($p['dt'])) : '—';
            $ref = trim((string)($p['ref'] ?? ''));
          ?>
          <li>
            <strong>₱<?= number_format((float)$p['amt'], 2) ?></strong>
            <span class="small-muted"> · <?= h($dt) ?></span>
            <div class="small-muted">User #<?= (int)$p['uid'] ?><?= $ref ? ' · '.h($ref) : '' ?></div>
          </li>
        <?php endforeach; endif; ?>
      </ul>
    </section>
  </div>

  <section class="card-dark">
    <h5>Notes</h5>
    <div class="small-muted" style="line-height:1.6;">
      You can expand this later with:
      <ul style="margin:8px 0 0;padding-left:18px;">
        <li>latest check-ins / attendance</li>
        <li>RFID wallet top-ups summary</li>
        <li>schedule approvals per trainer</li>
        <li>daily reports</li>
      </ul>
    </div>
  </section>

</main>

<script>
// Sidebar toggle
const sidebar        = document.getElementById('sidebar');
const sidebarToggle  = document.getElementById('sidebarToggle');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function closeSidebar(){ sidebar.classList.remove('open'); sidebarOverlay.classList.remove('open'); }
function openSidebar(){ sidebar.classList.add('open'); sidebarOverlay.classList.add('open'); }

sidebarToggle?.addEventListener('click', () => {
  if (sidebar.classList.contains('open')) closeSidebar();
  else openSidebar();
});
sidebarOverlay?.addEventListener('click', closeSidebar);
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
});

// Profile dropdown
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