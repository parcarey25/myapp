<?php
// home_staff.php — Staff dashboard (black/red/white cashier theme)

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
$cashierHeroImage = 'photo/cashier-bg.jpg'; // Put your cashier/gym image here

$pendingUsers = 'N/A';
if (table_exists($conn, 'users') && has_column($conn, 'users', 'status')) {
    $sql = "SELECT COUNT(*) AS c FROM users WHERE LOWER(role)='member' AND LOWER(status)='pending'";
    if ($res = $conn->query($sql)) {
        $pendingUsers = (int)($res->fetch_assoc()['c'] ?? 0);
        $res->free();
    } else {
        $sql = "SELECT COUNT(*) AS c FROM users WHERE LOWER(role)='member' AND (status IS NULL OR status='')";
        if ($res = $conn->query($sql)) {
            $pendingUsers = (int)($res->fetch_assoc()['c'] ?? 0);
            $res->free();
        }
    }
} elseif (table_exists($conn, 'users')) {
    $pendingUsers = 'N/A';
}

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

/*
|--------------------------------------------------------------------------
| TODAY'S SALES
|--------------------------------------------------------------------------
| Count ONLY real money received:
| ✅ RFID wallet load / Cash Load / RFID Load
| ✅ GCash / GCash QR
|
| Do NOT count:
| ❌ RFID Wallet / RFID Balance payments
|--------------------------------------------------------------------------
*/

$paymentsTodayCount = 0;
$paymentsTodaySum   = '0.00';
$paymentsTable = table_exists($conn, 'payments') ? 'payments' : null;

if ($paymentsTable) {
    $amtCol    = first_col($conn, $paymentsTable, ['amount','total','paid_amount']);
    $dateCol   = first_col($conn, $paymentsTable, ['created_at','paid_at','date_paid','payment_date','transaction_date']);
    $methodCol = first_col($conn, $paymentsTable, ['method','payment_method','mode_of_payment','payment_type']);
    $noteCol   = first_col($conn, $paymentsTable, ['note','description','remarks','reference']);

    if ($amtCol) {
        if ($dateCol) {
            $where = [];
            $where[] = "DATE(`{$dateCol}`)=?";

            /*
              Method filter:
              - Include RFID load wallet / Cash Load / RFID Load
              - Include GCash QR / GCash
              - Exclude RFID Wallet / RFID Balance
            */
            if ($methodCol) {
                $where[] = "
                    (
                        LOWER(TRIM(`{$methodCol}`)) IN (
                            'cash (load)',
                            'cash load',
                            'rfid load',
                            'rfid wallet load',
                            'wallet load',
                            'gcash',
                            'gcash qr',
                            'gcash_qr',
                            'gcash qr payment'
                        )
                        OR LOWER(TRIM(`{$methodCol}`)) LIKE '%gcash%'
                        OR LOWER(TRIM(`{$methodCol}`)) LIKE '%load%'
                    )
                    AND LOWER(TRIM(`{$methodCol}`)) NOT IN (
                        'rfid wallet',
                        'rfid balance',
                        'wallet balance'
                    )
                ";
            }

            /*
              If method column is missing but note/description exists,
              still try to count wallet loads and GCash from notes.
            */
            if (!$methodCol && $noteCol) {
                $where[] = "
                    (
                        LOWER(TRIM(`{$noteCol}`)) LIKE '%rfid wallet load%'
                        OR LOWER(TRIM(`{$noteCol}`)) LIKE '%wallet load%'
                        OR LOWER(TRIM(`{$noteCol}`)) LIKE '%cash load%'
                        OR LOWER(TRIM(`{$noteCol}`)) LIKE '%gcash%'
                    )
                    AND LOWER(TRIM(`{$noteCol}`)) NOT LIKE '%rfid wallet payment%'
                    AND LOWER(TRIM(`{$noteCol}`)) NOT LIKE '%rfid balance%'
                ";
            }

            $sql = "SELECT COUNT(*) AS c, COALESCE(SUM(`{$amtCol}`),0) AS s
                    FROM `{$paymentsTable}`
                    WHERE " . implode(' AND ', $where);

            if ($st = $conn->prepare($sql)) {
                $st->bind_param('s', $today);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $paymentsTodayCount = (int)($row['c'] ?? 0);
                $paymentsTodaySum = number_format((float)($row['s'] ?? 0), 2);
                $st->close();
            }
        } else {
            /*
              Fallback only if your payments table has no date column.
              Still filters out RFID Wallet / RFID Balance.
            */
            $where = [];

            if ($methodCol) {
                $where[] = "
                    (
                        LOWER(TRIM(`{$methodCol}`)) IN (
                            'cash (load)',
                            'cash load',
                            'rfid load',
                            'rfid wallet load',
                            'wallet load',
                            'gcash',
                            'gcash qr',
                            'gcash_qr',
                            'gcash qr payment'
                        )
                        OR LOWER(TRIM(`{$methodCol}`)) LIKE '%gcash%'
                        OR LOWER(TRIM(`{$methodCol}`)) LIKE '%load%'
                    )
                    AND LOWER(TRIM(`{$methodCol}`)) NOT IN (
                        'rfid wallet',
                        'rfid balance',
                        'wallet balance'
                    )
                ";
            }

            if (!$methodCol && $noteCol) {
                $where[] = "
                    (
                        LOWER(TRIM(`{$noteCol}`)) LIKE '%rfid wallet load%'
                        OR LOWER(TRIM(`{$noteCol}`)) LIKE '%wallet load%'
                        OR LOWER(TRIM(`{$noteCol}`)) LIKE '%cash load%'
                        OR LOWER(TRIM(`{$noteCol}`)) LIKE '%gcash%'
                    )
                    AND LOWER(TRIM(`{$noteCol}`)) NOT LIKE '%rfid wallet payment%'
                    AND LOWER(TRIM(`{$noteCol}`)) NOT LIKE '%rfid balance%'
                ";
            }

            $sql = "SELECT COUNT(*) AS c, COALESCE(SUM(`{$amtCol}`),0) AS s FROM `{$paymentsTable}`";

            if ($where) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }

            if ($res = $conn->query($sql)) {
                $row = $res->fetch_assoc();
                $paymentsTodayCount = (int)($row['c'] ?? 0);
                $paymentsTodaySum = number_format((float)($row['s'] ?? 0), 2);
                $res->free();
            }
        }
    }
}

$facilitiesCount = 'N/A';
if (table_exists($conn, 'facilities')) {
    $sql = "SELECT COUNT(*) AS c FROM facilities";
    if ($res = $conn->query($sql)) {
        $facilitiesCount = (int)($res->fetch_assoc()['c'] ?? 0);
        $res->free();
    }
}

$recentPayments = [];
if ($paymentsTable) {
    $uidCol = first_col($conn, $paymentsTable, ['user_id','member_id']);
    $amtCol = first_col($conn, $paymentsTable, ['amount','total','paid_amount']);
    $refCol = first_col($conn, $paymentsTable, ['reference','description','remarks','note']);
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
  --bg:#000000;
  --bg-soft:#050505;
  --panel:#0a0b0d;
  --panel-2:#0f1014;
  --stroke:rgba(255,255,255,.11);
  --stroke-strong:rgba(255,255,255,.18);
  --text:#ffffff;
  --muted:#d1bc9f;
  --muted-soft:#bfc5ce;
  --danger:#730000;
  --danger-2:#b50000;
  --danger-3:#ff2a2a;
  --shadow:0 20px 45px rgba(0,0,0,.55);
  --radius:20px;
  --radius-sm:14px;
}

*{box-sizing:border-box}

html,body{
  margin:0;
  min-height:100%;
  background:
    radial-gradient(circle at top center, rgba(126,0,0,.08), transparent 18%),
    linear-gradient(180deg, #000000 0%, #000000 100%);
  color:var(--text);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
}

.topbar{
  position:sticky;
  top:0;
  z-index:1100;
  height:92px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:0 18px;
  background:linear-gradient(90deg, #460000 0%, #780000 44%, #b10000 100%);
  border-bottom:1px solid rgba(255,255,255,.08);
}

.topbar-left,
.topbar-right{
  display:flex;
  align-items:center;
  gap:14px;
}

.brand{
  display:flex;
  align-items:center;
  gap:14px;
  text-decoration:none;
  color:#fff;
}

.brand img{
  width:58px;
  height:58px;
  border-radius:14px;
  object-fit:cover;
  border:1px solid rgba(255,255,255,.20);
}

.brand-text{
  display:flex;
  flex-direction:column;
  line-height:1.05;
}

.brand-text strong{
  font-size:1.55rem;
  letter-spacing:.02em;
  color:#fff;
  font-weight:900;
}

.brand-text span{
  font-size:.88rem;
  color:rgba(255,255,255,.85);
  text-transform:uppercase;
  letter-spacing:.22em;
}

.icon-btn{
  border:1px solid rgba(255,255,255,.24);
  background:rgba(0,0,0,.16);
  color:#fff;
  width:46px;
  height:46px;
  border-radius:14px;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:center;
  transition:.18s ease;
}
.icon-btn:hover{
  background:rgba(255,255,255,.08);
  transform:translateY(-1px);
}
.icon-btn .burger-lines{
  position:relative;
  width:18px;
  height:2px;
  border-radius:999px;
  background:#fff;
  display:block;
}
.icon-btn .burger-lines::before,
.icon-btn .burger-lines::after{
  content:"";
  position:absolute;
  left:0;
  width:18px;
  height:2px;
  border-radius:999px;
  background:#fff;
}
.icon-btn .burger-lines::before{top:-6px;}
.icon-btn .burger-lines::after{top:6px;}

.user-welcome{
  display:flex;
  align-items:center;
  gap:10px;
  color:#ffffff;
  font-size:1rem;
}

.role-badge{
  padding:6px 12px;
  border-radius:999px;
  background:rgba(0,0,0,.20);
  border:1px solid rgba(255,255,255,.16);
  font-size:.74rem;
  font-weight:800;
  letter-spacing:.12em;
  text-transform:uppercase;
  color:#ffffff;
}

.profile-wrap{position:relative}
.profile-circle{
  width:56px;
  height:56px;
  border:none;
  border-radius:16px;
  overflow:hidden;
  padding:0;
  background:#1a1a1a;
  cursor:pointer;
  box-shadow:0 8px 18px rgba(0,0,0,.3);
}
.profile-img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}

.profile-panel{
  position:absolute;
  right:0;
  top:calc(100% + 12px);
  width:320px;
  max-width:92vw;
  padding:16px;
  border-radius:18px;
  background:rgba(10,10,10,.98);
  border:1px solid rgba(255,255,255,.10);
  box-shadow:var(--shadow);
  display:none;
}
.profile-panel.show{display:block}

.profile-head{
  display:flex;
  align-items:center;
  gap:12px;
  margin-bottom:12px;
}
.profile-head img{
  width:52px;
  height:52px;
  border-radius:14px;
  object-fit:cover;
}
.profile-head strong{
  display:block;
  font-size:.98rem;
  color:#fff;
}
.profile-head span{
  font-size:.82rem;
  color:var(--muted-soft);
}

.panel-row{
  display:flex;
  justify-content:space-between;
  gap:12px;
  font-size:.88rem;
  margin:7px 0;
  color:#fff;
}
.panel-row span:first-child{color:var(--muted-soft)}

.profile-actions{
  display:grid;
  gap:10px;
  margin-top:14px;
}
.profile-actions a{
  text-decoration:none;
  text-align:center;
  padding:11px 14px;
  border-radius:12px;
  font-weight:700;
  transition:.18s ease;
}
.profile-actions a.secondary{
  color:#fff;
  background:#151515;
  border:1px solid rgba(255,255,255,.16);
}
.profile-actions a.primary{
  color:#fff;
  background:linear-gradient(135deg, #7e0000, #d40000);
  box-shadow:0 14px 24px rgba(126,0,0,.32);
}

.sidebar-overlay{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.65);
  opacity:0;
  visibility:hidden;
  transition:.22s ease;
  z-index:1000;
}
.sidebar-overlay.open{
  opacity:1;
  visibility:visible;
}

.sidebar{
  position:fixed;
  top:92px;
  left:0;
  width:285px;
  height:calc(100vh - 92px);
  padding:20px 16px;
  background:rgba(7,7,7,.98);
  border-right:1px solid rgba(255,255,255,.08);
  transform:translateX(-295px);
  transition:.22s ease;
  z-index:1050;
  overflow-y:auto;
}
.sidebar.open{transform:translateX(0)}

.sidebar-top{
  margin-bottom:18px;
}
.sidebar-top small{
  display:block;
  color:rgba(255,255,255,.72);
  text-transform:uppercase;
  letter-spacing:.18em;
  font-size:.72rem;
  margin-bottom:8px;
}
.sidebar-top strong{
  font-size:1.05rem;
  color:#fff;
}

.sidebar-menu{
  list-style:none;
  padding:0;
  margin:0;
  display:grid;
  gap:8px;
}
.sidebar-link{
  display:flex;
  align-items:center;
  gap:12px;
  text-decoration:none;
  color:#ffffff;
  padding:12px 14px;
  border-radius:14px;
  background:transparent;
  border:1px solid transparent;
  transition:.18s ease;
}
.sidebar-link:hover{
  background:rgba(126,0,0,.28);
  border-color:rgba(255,255,255,.08);
  transform:translateX(2px);
  color:#fff;
}
.sidebar-link .icon{
  width:34px;
  height:34px;
  border-radius:10px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:rgba(255,255,255,.06);
  font-size:1rem;
}

.main-wrap{
  max-width:1300px;
  margin:26px auto 42px;
  padding:0 18px;
}

.hero{
  position:relative;
  overflow:hidden;
  border-radius:28px;
  padding:32px 26px;
  margin-bottom:18px;
  min-height:260px;
  background:#0a0a0a;
  border:1px solid rgba(255,255,255,.10);
  box-shadow:var(--shadow);
}
.hero::before{
  content:"";
  position:absolute;
  inset:0;
  background-image:
    linear-gradient(90deg, rgba(0,0,0,.92) 0%, rgba(35,0,0,.78) 35%, rgba(0,0,0,.76) 62%, rgba(0,0,0,.86) 100%),
    url('<?= h($cashierHeroImage) ?>');
  background-size:cover;
  background-position:center right;
  background-repeat:no-repeat;
  filter:grayscale(100%) brightness(.44) contrast(1.08);
  transform:scale(1.02);
}
.hero::after{
  content:"";
  position:absolute;
  inset:0;
  background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,0));
  pointer-events:none;
}

.hero-grid{
  position:relative;
  z-index:1;
  display:grid;
  grid-template-columns:1.1fr .9fr;
  gap:18px;
  align-items:end;
  min-height:195px;
}

.hero-kicker{
  display:inline-block;
  padding:7px 12px;
  border-radius:999px;
  background:rgba(255,255,255,.06);
  color:#ffffff;
  border:1px solid rgba(255,255,255,.08);
  font-size:.78rem;
  text-transform:uppercase;
  letter-spacing:.16em;
  margin-bottom:12px;
}
.hero h1{
  margin:0 0 8px;
  font-size:2.25rem;
  font-weight:900;
  letter-spacing:-.02em;
  color:#ffffff;
}
.hero p{
  margin:0;
  max-width:700px;
  color:#ffffff;
  font-size:1rem;
  line-height:1.62;
}

.hero-mini{
  display:grid;
  grid-template-columns:repeat(2,1fr);
  gap:12px;
}
.hero-mini-card{
  padding:16px;
  border-radius:18px;
  background:rgba(0,0,0,.34);
  border:1px solid rgba(255,255,255,.08);
  backdrop-filter:blur(6px);
}
.hero-mini-card small{
  display:block;
  color:var(--muted);
  text-transform:uppercase;
  letter-spacing:.14em;
  margin-bottom:6px;
  font-size:.72rem;
}
.hero-mini-card strong{
  font-size:1.4rem;
  color:#fff;
}

.panel{
  background:linear-gradient(180deg, rgba(14,15,18,.96), rgba(7,7,8,.96));
  border:1px solid rgba(255,255,255,.10);
  border-radius:22px;
  box-shadow:var(--shadow);
}

.section-title{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-bottom:16px;
}
.section-title h3{
  margin:0;
  font-size:1rem;
  text-transform:uppercase;
  letter-spacing:.12em;
  color:var(--muted);
}
.section-title span{
  font-size:.82rem;
  color:var(--muted-soft);
}

.stats-grid{
  display:grid;
  grid-template-columns:repeat(5, minmax(0,1fr));
  gap:14px;
  margin-bottom:20px;
}

.stat-card{
  position:relative;
  overflow:hidden;
  padding:18px;
  border-radius:18px;
  background:linear-gradient(180deg, #090b0f 0%, #050608 100%);
  border:1px solid rgba(255,255,255,.11);
  box-shadow:0 18px 36px rgba(0,0,0,.24);
}
.stat-card::before{
  content:"";
  position:absolute;
  top:0;
  left:0;
  right:0;
  height:1px;
  background:linear-gradient(90deg, rgba(255,255,255,.03), rgba(255,255,255,.12), rgba(255,255,255,.03));
}
.stat-top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-bottom:12px;
}
.stat-label{
  font-size:.72rem;
  color:var(--muted);
  text-transform:uppercase;
  letter-spacing:.16em;
}
.stat-icon{
  width:40px;
  height:40px;
  border-radius:12px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.08);
  font-size:1rem;
}
.stat-value{
  margin:0;
  font-size:1.8rem;
  font-weight:900;
  letter-spacing:-.03em;
  color:#ffffff;
}
.stat-sub{
  margin-top:6px;
  color:#ffffff;
  font-size:.88rem;
  opacity:.9;
}

.content-grid{
  display:grid;
  grid-template-columns:1.1fr .9fr;
  gap:20px;
  margin-bottom:20px;
}

.panel-inner{
  padding:22px;
}

.quick-actions{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:12px;
}
.action-card{
  display:flex;
  align-items:center;
  gap:14px;
  padding:16px;
  border-radius:18px;
  text-decoration:none;
  color:#fff;
  background:linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
  border:1px solid rgba(255,255,255,.08);
  transition:.18s ease;
}
.action-card:hover{
  transform:translateY(-2px);
  border-color:rgba(196,0,0,.34);
  box-shadow:0 14px 30px rgba(0,0,0,.24);
  color:#fff;
}
.action-icon{
  width:46px;
  height:46px;
  border-radius:14px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:linear-gradient(135deg, rgba(126,0,0,.40), rgba(255,35,35,.24));
  border:1px solid rgba(255,255,255,.10);
  flex:0 0 46px;
}
.action-card strong{
  display:block;
  font-size:.96rem;
  color:#fff;
}
.action-card span{
  display:block;
  color:#e6e6e6;
  font-size:.82rem;
  margin-top:2px;
}

.tip-box{
  margin-top:16px;
  padding:14px 16px;
  border-radius:16px;
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.07);
  color:#dcdcdc;
  font-size:.9rem;
}

.payment-list{
  list-style:none;
  margin:0;
  padding:0;
  display:grid;
  gap:12px;
}
.payment-item{
  padding:14px 14px;
  border-radius:16px;
  background:rgba(255,255,255,.025);
  border:1px solid rgba(255,255,255,.06);
}
.payment-top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:6px;
}
.payment-amount{
  font-weight:900;
  font-size:1.08rem;
  color:#ffffff;
}
.payment-date{
  color:var(--muted-soft);
  font-size:.84rem;
}
.payment-meta{
  color:#e6e6e6;
  font-size:.88rem;
}

.notes-box{
  padding:22px;
}
.notes-list{
  margin:12px 0 0;
  padding-left:18px;
  color:#e5e5e5;
}
.notes-list li{margin-bottom:8px}

.footer-space{
  height:10px;
}

@media (max-width: 1199.98px){
  .stats-grid{
    grid-template-columns:repeat(3, minmax(0,1fr));
  }
}

@media (max-width: 991.98px){
  .user-welcome{display:none}
  .hero-grid,
  .content-grid{
    grid-template-columns:1fr;
  }
  .stats-grid{
    grid-template-columns:repeat(2, minmax(0,1fr));
  }
  .topbar{
    height:84px;
  }
  .sidebar{
    top:84px;
    height:calc(100vh - 84px);
  }
  .brand img{
    width:50px;
    height:50px;
  }
  .brand-text strong{
    font-size:1.3rem;
  }
}

@media (max-width: 575.98px){
  .main-wrap{padding:0 14px}
  .hero{padding:22px 18px; min-height:220px;}
  .hero h1{font-size:1.7rem}
  .hero-mini{
    grid-template-columns:1fr;
  }
  .stats-grid,
  .quick-actions{
    grid-template-columns:1fr;
  }
  .topbar{padding:0 12px; height:78px;}
  .sidebar{top:78px; height:calc(100vh - 78px);}
  .brand img{width:44px; height:44px;}
  .brand-text strong{font-size:1.06rem;}
  .brand-text span{font-size:.7rem; letter-spacing:.15em;}
  .profile-circle{width:46px; height:46px;}
}
</style>
</head>

<body>

<header class="topbar">
  <div class="topbar-left">
    <button class="icon-btn" id="sidebarToggle" aria-label="Toggle menu">
      <span class="burger-lines"></span>
    </button>

    <a class="brand" href="home.php">
      <img src="photo/logo.jpg" alt="RJL Fitness">
      <div class="brand-text">
        <strong>RJL Fitness</strong>
        <span>Staff Control Panel</span>
      </div>
    </a>
  </div>

  <div class="topbar-right">
    <div class="user-welcome">
      Welcome, <strong><?= h($displayName) ?></strong>
      <span class="role-badge"><?= h($role) ?></span>
    </div>

    <div class="profile-wrap">
      <button id="profileBtn" class="profile-circle" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="profilePanel">
        <img src="<?= h($avatarPath) ?>" class="profile-img" alt="Profile">
      </button>

      <div id="profilePanel" class="profile-panel" role="dialog" aria-hidden="true">
        <div class="profile-head">
          <img src="<?= h($avatarPath) ?>" alt="Profile">
          <div>
            <strong><?= h($displayName) ?></strong>
            <span><?= h($email ?: 'No email available') ?></span>
          </div>
        </div>

        <div class="panel-row"><span>Name</span><span><?= h($displayName) ?></span></div>
        <div class="panel-row"><span>Email</span><span><?= h($email ?: '—') ?></span></div>
        <div class="panel-row"><span>Role</span><span><?= h(strtoupper($role)) ?></span></div>

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
  <div class="sidebar-top">
    <small>Navigation</small>
    <strong>Staff Menu</strong>
  </div>

  <ul class="sidebar-menu">
    <li><a class="sidebar-link" href="pending_users.php"><span class="icon">⏳</span><span>Pending Approvals</span></a></li>
    <li><a class="sidebar-link" href="users.php"><span class="icon">👥</span><span>Users</span></a></li>
    <li><a class="sidebar-link" href="extend_membership.php"><span class="icon">💳</span><span>Extend Membership</span></a></li>
    <li><a class="sidebar-link" href="rfid_load.php"><span class="icon">🏷️</span><span>Load RFID Card</span></a></li>
    <li><a class="sidebar-link" href="gcash_pending.php"><span class="icon">⏳</span><span>Gcash Pending Approvals</span></a></li>
    <li><a class="sidebar-link" href="membership_monitor.php"><span class="icon">👥</span><span>member monitoring</span></a></li>
    <li><a class="sidebar-link" href="attendance_storage.php"><span class="icon">📁</span><span>attendance record</span></a></li>
    <li><a class="sidebar-link" href="staff_id_cards.php"><span class="icon">🪪</span><span>RFID card</span></a></li>
  </ul>
</aside>

<main class="main-wrap">
  <section class="hero">
    <div class="hero-grid">
      <div>
        <span class="hero-kicker">RJL Power Fitness Center</span>
        <h1>Cashier & Staff Dashboard</h1>
        <p>
          Manage users, approvals, cashier transactions, facilities, schedules, point of sale,
          and RFID wallet activity from one premium control panel.
        </p>
      </div>

      <div class="hero-mini">
        <div class="hero-mini-card">
          <small>Pending Users</small>
          <strong><?= h($pendingUsers) ?></strong>
        </div>
        <div class="hero-mini-card">
          <small>Today’s Sales</small>
          <strong>₱<?= h($paymentsTodaySum) ?></strong>
        </div>
      </div>
    </div>
  </section>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-top">
        <div class="stat-label">Pending Users</div>
        <div class="stat-icon">👥</div>
      </div>
      <p class="stat-value"><?= h($pendingUsers) ?></p>
      <div class="stat-sub">Waiting for approval</div>
    </div>

    <div class="stat-card">
      <div class="stat-top">
        <div class="stat-label">Pending Valid IDs</div>
        <div class="stat-icon">🪪</div>
      </div>
      <p class="stat-value"><?= h($pendingIDs) ?></p>
      <div class="stat-sub">Verification queue</div>
    </div>

    <div class="stat-card">
      <div class="stat-top">
        <div class="stat-label">Pending Schedules</div>
        <div class="stat-icon">📋</div>
      </div>
      <p class="stat-value"><?= h($pendingSchedules) ?></p>
      <div class="stat-sub">Booking requests</div>
    </div>

    <div class="stat-card">
      <div class="stat-top">
        <div class="stat-label">Payments Today</div>
        <div class="stat-icon">💸</div>
      </div>
      <p class="stat-value"><?= h($paymentsTodayCount) ?></p>
      <div class="stat-sub">₱<?= h($paymentsTodaySum) ?> collected</div>
    </div>

    <div class="stat-card">
      <div class="stat-top">
        <div class="stat-label">Facilities</div>
        <div class="stat-icon">🏋️</div>
      </div>
      <p class="stat-value"><?= h($facilitiesCount) ?></p>
      <div class="stat-sub">Available gym spaces</div>
    </div>
  </div>

  <div class="content-grid">
    <section class="panel">
      <div class="panel-inner">
        <div class="section-title">
          <h3>Quick Actions</h3>
          <span>Fast access</span>
        </div>

        <div class="quick-actions">
          <a class="action-card" href="membership_monitor.php">
            <div class="action-icon">✅</div>
            <div>
              <strong>Open Member Status</strong>
              <span>Cashier monitor member status</span>
            </div>
          </a>

          <a class="action-card" href="pending_users.php">
            <div class="action-icon">✅</div>
            <div>
              <strong>Review Pending</strong>
              <span>Approve users and IDs</span>
            </div>
          </a>

          <a class="action-card" href="users.php">
            <div class="action-icon">👤</div>
            <div>
              <strong>Browse Users</strong>
              <span>Open member list</span>
            </div>
          </a>

          <a class="action-card" href="all_attendance.php" target="_blank">
            <div class="action-icon">💳</div>
            <div>
              <strong>RFID Attendance</strong>
              <span>record attendance</span>
            </div>
          </a>
        </div>

        <div class="tip-box">
          Tip: Replace <strong>photo/cashier-bg.jpg</strong> with your own dark cashier image to match RJL Power Fitness Center branding.
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-inner">
        <div class="section-title">
          <h3>Recent Payments</h3>
          <span>Latest 5 records</span>
        </div>

        <ul class="payment-list">
          <?php if (empty($recentPayments)): ?>
            <li class="payment-item">
              <div class="payment-meta">No payment records found.</div>
            </li>
          <?php else: foreach ($recentPayments as $p): ?>
            <?php
              $dt = $p['dt'] ? date('M d, g:ia', strtotime($p['dt'])) : '—';
              $ref = trim((string)($p['ref'] ?? ''));
            ?>
            <li class="payment-item">
              <div class="payment-top">
                <div class="payment-amount">₱<?= number_format((float)$p['amt'], 2) ?></div>
                <div class="payment-date"><?= h($dt) ?></div>
              </div>
              <div class="payment-meta">
                User #<?= (int)$p['uid'] ?><?= $ref ? ' · '.h($ref) : '' ?>
              </div>
            </li>
          <?php endforeach; endif; ?>
        </ul>
      </div>
    </section>
  </div>

  <section class="panel">
    <div class="notes-box">
      <div class="section-title">
        <h3>Future Dashboard Ideas</h3>
        <span>Recommended widgets</span>
      </div>

      <div style="color:#f0f0f0; font-size:.95rem;">
        You can expand this dashboard later with more useful gym cashier and admin features:
      </div>

      <ul class="notes-list">
        <li>Latest member check-ins and attendance overview</li>
        <li>RFID wallet top-up totals and transaction analytics</li>
        <li>Trainer or facility schedule approvals</li>
        <li>Daily sales and revenue summary cards</li>
        <li>Membership expiration alerts</li>
      </ul>
    </div>
  </section>

  <div class="footer-space"></div>
</main>

<script>
const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function closeSidebar(){
  sidebar.classList.remove('open');
  sidebarOverlay.classList.remove('open');
}
function openSidebar(){
  sidebar.classList.add('open');
  sidebarOverlay.classList.add('open');
}

sidebarToggle?.addEventListener('click', () => {
  if (sidebar.classList.contains('open')) closeSidebar();
  else openSidebar();
});

sidebarOverlay?.addEventListener('click', closeSidebar);

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && sidebar.classList.contains('open')) {
    closeSidebar();
  }
});

(function(){
  const profileBtn = document.getElementById('profileBtn');
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
    profileBtn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      togglePanel();
    });
  }

  if(profilePanel){
    profilePanel.addEventListener('click', function(e){
      e.stopPropagation();
    });
  }

  document.addEventListener('click', function(){
    if(profilePanel && profilePanel.classList.contains('show')) {
      closePanel();
    }
  });

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && profilePanel && profilePanel.classList.contains('show')){
      e.preventDefault();
      closePanel();
    }
  });
})();
</script>

</body>
</html>