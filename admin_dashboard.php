<?php
// admin_dashboard.php — Dashboard with Revenue Line Graph (500px) + NO Recent Payments table

if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

// adjust allowed roles if needed
$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($role, ['admin','staff'], true)) {
    header('Location: home.php');
    exit;
}

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
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

function has_column(mysqli $conn, string $table, string $col): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('ss', $table, $col);
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

function peso($n){
    return '₱' . number_format((float)$n, 2);
}

// -------------------- METRICS --------------------
$revToday = 0.0;
$revMonth = 0.0;
$revTotal = 0.0;

$members = 0;
$trainers = 0;
$staffs = 0;
$pending = 0;

// Users counts
if (table_exists($conn,'users') && has_column($conn,'users','role')) {
    $sql = "SELECT
              SUM(CASE WHEN LOWER(role)='member' THEN 1 ELSE 0 END) AS members,
              SUM(CASE WHEN LOWER(role)='trainer' THEN 1 ELSE 0 END) AS trainers,
              SUM(CASE WHEN LOWER(role)='staff' THEN 1 ELSE 0 END) AS staffs
            FROM users";
    if ($res = $conn->query($sql)) {
        $r = $res->fetch_assoc();
        $members  = (int)($r['members'] ?? 0);
        $trainers = (int)($r['trainers'] ?? 0);
        $staffs   = (int)($r['staffs'] ?? 0);
        $res->free();
    }
}
if (table_exists($conn,'users') && has_column($conn,'users','status')) {
    $sql = "SELECT COUNT(*) AS c FROM users WHERE LOWER(status)='pending'";
    if ($res = $conn->query($sql)) {
        $pending = (int)($res->fetch_assoc()['c'] ?? 0);
        $res->free();
    }
}

// Revenue from payments
$paymentsTable = table_exists($conn,'payments') ? 'payments' : null;
$amtCol = null;
$dateCol = null;

if ($paymentsTable) {
    $amtCol  = first_col($conn, $paymentsTable, ['amount','total','paid_amount']);
    $dateCol = first_col($conn, $paymentsTable, ['paid_at','created_at','payment_date','date_paid','transaction_date','datetime','date_time']);

    if ($amtCol) {
        // total
        $sql = "SELECT COALESCE(SUM(`{$amtCol}`),0) AS s FROM `{$paymentsTable}`";
        if ($res = $conn->query($sql)) {
            $revTotal = (float)($res->fetch_assoc()['s'] ?? 0);
            $res->free();
        }

        if ($dateCol) {
            // today
            $sql = "SELECT COALESCE(SUM(`{$amtCol}`),0) AS s
                    FROM `{$paymentsTable}` WHERE DATE(`{$dateCol}`)=?";
            if ($st = $conn->prepare($sql)) {
                $st->bind_param('s', $today);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $revToday = (float)($row['s'] ?? 0);
                $st->close();
            }

            // month
            $sql = "SELECT COALESCE(SUM(`{$amtCol}`),0) AS s
                    FROM `{$paymentsTable}`
                    WHERE DATE(`{$dateCol}`) BETWEEN ? AND ?";
            if ($st = $conn->prepare($sql)) {
                $st->bind_param('ss', $monthStart, $monthEnd);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $revMonth = (float)($row['s'] ?? 0);
                $st->close();
            }
        }
    }
}

// -------------------- CHART DATA (Daily revenue this month) --------------------
$labels = [];
$values = [];

$start = new DateTime($monthStart);
$end   = new DateTime($monthEnd);
$end->setTime(0,0,0);

$map = []; // date => sum
$period = new DatePeriod($start, new DateInterval('P1D'), (clone $end)->modify('+1 day'));
foreach ($period as $d) {
    $k = $d->format('Y-m-d');
    $map[$k] = 0.0;
}

if ($paymentsTable && $amtCol && $dateCol) {
    $sql = "SELECT DATE(`{$dateCol}`) AS d, COALESCE(SUM(`{$amtCol}`),0) AS s
            FROM `{$paymentsTable}`
            WHERE DATE(`{$dateCol}`) BETWEEN ? AND ?
            GROUP BY DATE(`{$dateCol}`)
            ORDER BY d ASC";
    if ($st = $conn->prepare($sql)) {
        $st->bind_param('ss', $monthStart, $monthEnd);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $d = $r['d'];
            if (isset($map[$d])) $map[$d] = (float)$r['s'];
        }
        $st->close();
    }
}

// build labels/values
foreach ($map as $d => $sum) {
    $labels[] = date('M d', strtotime($d));
    $values[] = (float)$sum;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>RJL Fitness Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">

<style>
:root{
  --brand:#b30000;
  --brand2:#ff3333;
  --bg:#0b0b0b;
  --line:#2a2a2a;
  --muted:#a9a9a9;
  --shadow:0 18px 35px rgba(0,0,0,.55);
  --r:18px;
}
*{box-sizing:border-box}
body{
  margin:0;
  min-height:100vh;
  background: radial-gradient(circle at top, #111 0, #000 55%, #000 100%);
  color:#f5f5f5;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
}
.topbar{
  position:sticky; top:0; z-index:1000;
  height:64px; display:flex; align-items:center; justify-content:space-between;
  padding:0 22px;
  background:linear-gradient(90deg,#000,var(--brand));
  box-shadow:0 10px 25px rgba(0,0,0,.5);
}
.brand{
  display:flex; align-items:center;
  color:#fff; text-decoration:none;
  font-weight:900; letter-spacing:.04em;
}
.brand img{height:34px;border-radius:8px;margin-right:10px;}
.navbtn a{
  color:#fff; text-decoration:none;
  border:1px solid rgba(255,255,255,.18);
  padding:6px 10px;
  border-radius:10px;
  margin-left:8px;
  font-weight:800;
  font-size:.85rem;
}
.navbtn a:hover{background:rgba(255,255,255,.08);}

.main-wrap{max-width:1180px;margin:22px auto 48px;padding:0 18px;}

.card-dark{
  background: radial-gradient(circle at top, #222 0, #111 55%, #0b0b0b 100%);
  border:1px solid var(--line);
  border-radius:var(--r);
  box-shadow:var(--shadow);
  padding:16px 18px;
}
.card-dark h5{
  margin:0 0 10px;
  color:var(--muted);
  font-size:.9rem;
  text-transform:uppercase;
  letter-spacing:.12em;
}

.grid-top{
  display:grid;
  grid-template-columns: repeat(3, minmax(0,1fr));
  gap:14px;
  margin-bottom:14px;
}
.grid-mid{
  display:grid;
  grid-template-columns: repeat(4, minmax(0,1fr));
  gap:14px;
  margin-bottom:18px;
}
@media(max-width: 991px){
  .grid-top{grid-template-columns:1fr;}
  .grid-mid{grid-template-columns:repeat(2, minmax(0,1fr));}
}
@media(max-width: 575px){
  .grid-mid{grid-template-columns:1fr;}
}

.big-value{
  font-size:1.65rem;
  font-weight:1000;
  margin:0;
}
.small-muted{color:var(--muted);font-size:.88rem;margin-top:4px;}

.chart-card{
  padding:16px 18px 18px;
}
.chart-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  margin-bottom:12px;
}
.chart-title{
  font-weight:1000;
  margin:0;
}
.chart-sub{color:var(--muted);font-size:.9rem;margin:0;}

.chart-box{
  height:500px;            /* ✅ requested 500px height */
  border-radius:16px;
  border:1px solid rgba(255,255,255,.10);
  background:#0f0f0f;
  padding:10px;
}
</style>
</head>

<body>
<header class="topbar">
  <a class="brand" href="home.php">
    <img src="photo/logo.jpg" alt="RJL">
    <span>RJL Fitness Admin</span>
  </a>
  <div class="navbtn">
    <a href="admin_users.php">Users</a>
    <a href="admin_pending.php">Pending</a>
    <a href="admin_pos.php">POS</a>
    <a href="logout.php" style="border:none;background:linear-gradient(120deg,var(--brand),var(--brand2));">Logout</a>
  </div>
</header>

<main class="main-wrap">

  <!-- Revenue Cards -->
  <div class="grid-top">
    <div class="card-dark">
      <h5>Revenue Today</h5>
      <p class="big-value"><?= h(peso($revToday)) ?></p>
      <div class="small-muted"><?= h(date('M d, Y')) ?></div>
    </div>

    <div class="card-dark">
      <h5>Revenue This Month</h5>
      <p class="big-value"><?= h(peso($revMonth)) ?></p>
      <div class="small-muted"><?= h(date('F Y')) ?></div>
    </div>

    <div class="card-dark">
      <h5>Revenue Total</h5>
      <p class="big-value"><?= h(peso($revTotal)) ?></p>
      <div class="small-muted">All-time payments</div>
    </div>
  </div>

  <!-- Counts -->
  <div class="grid-mid">
    <div class="card-dark">
      <h5>Members</h5>
      <p class="big-value"><?= (int)$members ?></p>
    </div>
    <div class="card-dark">
      <h5>Trainers</h5>
      <p class="big-value"><?= (int)$trainers ?></p>
    </div>
    <div class="card-dark">
      <h5>Staff</h5>
      <p class="big-value"><?= (int)$staffs ?></p>
    </div>
    <div class="card-dark">
      <h5>Pending</h5>
      <p class="big-value"><?= (int)$pending ?></p>
      <div class="small-muted">Users pending (if status exists)</div>
    </div>
  </div>

  <!-- ✅ Revenue Report Graph (replaces Recent Payments) -->
  <section class="card-dark chart-card">
    <div class="chart-header">
      <div>
        <h3 class="chart-title" style="font-weight:1000;margin:0;">Revenue Report</h3>
        <p class="chart-sub">Daily revenue for <?= h(date('F Y')) ?> (line graph)</p>
      </div>
      <div class="small-muted">
        Source: payments table <?= $paymentsTable ? '' : '(not detected)' ?>
      </div>
    </div>

    <div class="chart-box">
      <canvas id="revenueChart"></canvas>
    </div>
  </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const labels = <?= json_encode($labels) ?>;
const values = <?= json_encode($values) ?>;

const ctx = document.getElementById('revenueChart');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: labels,
    datasets: [{
      label: 'Revenue (₱)',
      data: values,
      tension: 0.35,
      fill: true,
      pointRadius: 2,
      pointHoverRadius: 5,
      borderWidth: 2
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false, // allows 500px container height
    plugins: {
      legend: { display: true, labels: { color: '#ddd' } },
      tooltip: {
        callbacks: {
          label: (ctx) => ' ₱' + Number(ctx.parsed.y || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})
        }
      }
    },
    scales: {
      x: {
        ticks: { color: '#bbb' },
        grid: { color: 'rgba(255,255,255,0.06)' }
      },
      y: {
        ticks: {
          color: '#bbb',
          callback: (v) => '₱' + Number(v).toLocaleString()
        },
        grid: { color: 'rgba(255,255,255,0.06)' }
      }
    }
  }
});
</script>
</body>
</html>