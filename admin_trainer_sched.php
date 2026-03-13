<?php
// facilities_admin.php — Admin view: see ALL trainer schedules + who enrolled/applied
// Safe detection for different DB schemas.

if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$role = strtolower(trim($_SESSION['role'] ?? ''));
if ($role !== 'admin') {
    header('Location: home.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

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

function first_existing_table(mysqli $conn, array $candidates): ?string {
    foreach ($candidates as $t) {
        if (table_exists($conn, $t)) return $t;
    }
    return null;
}

function pretty_status($s): string {
    $t = strtoupper(trim((string)$s));
    if ($t === '') return 'UNKNOWN';
    // numeric fallback
    if (is_numeric($t)) {
        if ($t === '0') return 'PENDING';
        if ($t === '1') return 'APPROVED';
        if ($t === '2') return 'REJECTED';
    }
    // normalize variants
    if (in_array($t, ['ACCEPTED'], true)) return 'APPROVED';
    return $t;
}

function status_badge_class($s): string {
    $t = pretty_status($s);
    if ($t === 'PENDING') return 'badge-warning';
    if ($t === 'APPROVED') return 'badge-success';
    if ($t === 'REJECTED') return 'badge-danger';
    return 'badge-secondary';
}

// ------------------------ Detect schedules/booking table ------------------------
$bookTable = first_existing_table($conn, [
    'schedules',
    'bookings',
    'schedule_requests',
    'trainer_bookings',
    'facility_bookings'
]);

$schemaOk = true;
$schemaMsg = '';

$col = [
    'id'        => null,
    'trainer'   => null,
    'status'    => null,
    'date'      => null,
    'timeSlot'  => null,
    'timeOnly'  => null,
    'start'     => null,
    'end'       => null,
    'facilitySlug' => null,
    'facilityName' => null,
    'facilityId'   => null,
    'memberId'  => null,
    'fullName'  => null,
    'email'     => null,
    'notes'     => null,
    'created'   => null,
];

if (!$bookTable) {
    $schemaOk = false;
    $schemaMsg = "No schedules table detected. Add your actual table name to the candidates list.";
} else {
    $col['id'] = first_col($conn, $bookTable, ['id','schedule_id','booking_id','request_id']);
    $col['trainer'] = first_col($conn, $bookTable, ['trainer_id','coach_id']);
    $col['status']  = first_col($conn, $bookTable, ['status','booking_status','request_status']);
    $col['date']    = first_col($conn, $bookTable, ['date','booking_date','session_date','schedule_date','schedule_day']);
    $col['timeSlot']= first_col($conn, $bookTable, ['time_slot','time_range','slot']);
    $col['timeOnly']= first_col($conn, $bookTable, ['time']);
    $col['start']   = first_col($conn, $bookTable, ['time_start','start_time','from_time']);
    $col['end']     = first_col($conn, $bookTable, ['time_end','end_time','to_time']);
    $col['facilitySlug'] = first_col($conn, $bookTable, ['facility_slug','facility']);
    $col['facilityName'] = first_col($conn, $bookTable, ['facility_name']);
    $col['facilityId']   = first_col($conn, $bookTable, ['facility_id']);
    $col['memberId'] = first_col($conn, $bookTable, ['user_id','member_id']);
    $col['fullName'] = first_col($conn, $bookTable, ['full_name','name']);
    $col['email']    = first_col($conn, $bookTable, ['email']);
    $col['notes']    = first_col($conn, $bookTable, ['notes','remark','remarks','comment']);
    $col['created']  = first_col($conn, $bookTable, ['created_at','created','requested_at']);

    // Minimal required
    if (!$col['trainer'] || !$col['date'] || !$col['status']) {
        $schemaOk = false;
        $schemaMsg = "Detected table `{$bookTable}`, but missing required columns (trainer/date/status).";
    }
}

// ------------------------ Load trainers list ------------------------
$trainers = [];
if ($res = $conn->query("
    SELECT id, COALESCE(full_name, username) AS label
    FROM users
    WHERE LOWER(role)='trainer'
    ORDER BY COALESCE(full_name, username) ASC
")) {
    while ($r = $res->fetch_assoc()) $trainers[] = $r;
    $res->free();
}

// ------------------------ Filters ------------------------
$filterDate = $_GET['date'] ?? date('Y-m-d');
$filterTrainer = (int)($_GET['trainer_id'] ?? 0); // 0 = all
$filterStatus  = strtoupper(trim((string)($_GET['status'] ?? 'ALL'))); // ALL/PENDING/APPROVED/REJECTED

// validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) $filterDate = date('Y-m-d');

// ------------------------ Query schedules rows ------------------------
$rows = [];
$counts = ['PENDING'=>0,'APPROVED'=>0,'REJECTED'=>0,'TOTAL'=>0];

if ($schemaOk) {
    // Build SELECT
    $select = [];
    $select[] = $col['id'] ? "`{$col['id']}` AS sid" : "0 AS sid";
    $select[] = "`{$col['trainer']}` AS trainer_id";
    $select[] = "`{$col['status']}` AS status_val";
    $select[] = "`{$col['date']}` AS date_val";

    if ($col['timeSlot']) $select[] = "`{$col['timeSlot']}` AS time_slot";
    if ($col['timeOnly']) $select[] = "`{$col['timeOnly']}` AS time_only";
    if ($col['start'])    $select[] = "`{$col['start']}` AS t_start";
    if ($col['end'])      $select[] = "`{$col['end']}` AS t_end";

    if ($col['facilityName']) $select[] = "`{$col['facilityName']}` AS facility_name";
    if ($col['facilitySlug']) $select[] = "`{$col['facilitySlug']}` AS facility_slug";
    if ($col['facilityId'])   $select[] = "`{$col['facilityId']}` AS facility_id";

    if ($col['memberId']) $select[] = "`{$col['memberId']}` AS member_id";
    if ($col['fullName']) $select[] = "`{$col['fullName']}` AS full_name";
    if ($col['email'])    $select[] = "`{$col['email']}` AS email";
    if ($col['notes'])    $select[] = "`{$col['notes']}` AS notes";
    if ($col['created'])  $select[] = "`{$col['created']}` AS created_at";

    // join trainer name
    $sql = "SELECT " . implode(",", $select) . ",
                   COALESCE(t.full_name, t.username) AS trainer_name,
                   COALESCE(m.full_name, m.username) AS member_name,
                   m.username AS member_username
            FROM `{$bookTable}` b
            LEFT JOIN users t ON t.id = b.`{$col['trainer']}`
            LEFT JOIN users m ON " . ($col['memberId'] ? "m.id = b.`{$col['memberId']}`" : "1=0") . "
            WHERE DATE(b.`{$col['date']}`) = ?";

    $types = "s";
    $params = [$filterDate];

    if ($filterTrainer > 0) {
        $sql .= " AND b.`{$col['trainer']}` = ?";
        $types .= "i";
        $params[] = $filterTrainer;
    }

    if ($filterStatus !== 'ALL' && $filterStatus !== '') {
        $sql .= " AND UPPER(TRIM(b.`{$col['status']}`)) = ?";
        $types .= "s";
        $params[] = $filterStatus;
    }

    $orderBy = $col['timeSlot'] ? "b.`{$col['timeSlot']}`" : ($col['start'] ? "b.`{$col['start']}`" : "b.`{$col['date']}`");
    $sql .= " ORDER BY t.full_name, t.username, {$orderBy} ASC LIMIT 400";

    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            // compute display time
            $slot = $r['time_slot'] ?? '';
            if (!$slot && !empty($r['t_start']) && !empty($r['t_end'])) {
                $slot = substr($r['t_start'],0,5) . '-' . substr($r['t_end'],0,5);
            }
            if (!$slot && !empty($r['time_only'])) {
                $slot = substr($r['time_only'],0,5);
            }
            $r['_time'] = $slot ?: '—';

            $facility = $r['facility_name'] ?? '';
            if (!$facility) $facility = $r['facility_slug'] ?? '—';
            $r['_facility'] = $facility;

            $member = $r['member_name'] ?? '';
            if (!$member) $member = $r['full_name'] ?? '';
            if (!$member) $member = $r['member_username'] ?? '—';
            $r['_member'] = $member;

            $statusNorm = pretty_status($r['status_val'] ?? '');
            $r['_status'] = $statusNorm;
            $counts['TOTAL']++;
            if (isset($counts[$statusNorm])) $counts[$statusNorm]++;

            $rows[] = $r;
        }
        $res->free();
        $st->close();
    } else {
        $schemaOk = false;
        $schemaMsg = "DB prepare failed for schedules query.";
    }
}

// Group by trainer for display
$grouped = [];
foreach ($rows as $r) {
    $tn = $r['trainer_name'] ?: ('Trainer #' . (int)$r['trainer_id']);
    $grouped[$tn][] = $r;
}
ksort($grouped);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Facilities | RJL Fitness</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">

<style>
:root{
  --brand:#b30000; --brand2:#ff3333;
  --bg:#0b0b0b; --line:#2a2a2a;
  --muted:#a9a9a9; --shadow:0 18px 35px rgba(0,0,0,.55); --r:18px;
}
*{box-sizing:border-box}
body{
  margin:0; min-height:100vh;
  background: radial-gradient(circle at top, #111 0, #000 55%, #000 100%);
  color:#f5f5f5;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
}
.navbar{background:linear-gradient(90deg,#000,var(--brand));}
a,a:hover{color:#fff}
.main-wrap{max-width:1180px;margin:22px auto 48px;padding:0 18px;}

.card-dark{
  background: radial-gradient(circle at top, #222 0, #111 55%, #0b0b0b 100%);
  border:1px solid var(--line);
  border-radius:var(--r);
  box-shadow:var(--shadow);
  padding:18px 20px;
}
.header{
  border-radius:var(--r);
  border:1px solid var(--line);
  background:linear-gradient(135deg, rgba(179,0,0,.18), rgba(0,0,0,.65));
  box-shadow:var(--shadow);
  padding:18px 20px;
  margin-bottom:18px;
}
.header h2{margin:0 0 4px;font-weight:1000;font-size:1.25rem;}
.header p{margin:0;color:#d7d7d7;font-size:.92rem;}

.filters{
  display:grid;
  grid-template-columns: 1fr 1fr 1fr auto;
  gap:12px;
  align-items:end;
}
@media(max-width:991px){.filters{grid-template-columns:1fr;}}
.form-control,.custom-select{
  background:#121212;border:1px solid #2a2a2a;color:#eee;border-radius:12px;
}
.btn-pill{border-radius:999px;font-weight:900;letter-spacing:.04em;}
.btn-red{background:linear-gradient(120deg,var(--brand),var(--brand2));border:none;color:#fff;}
.btn-red:hover{filter:brightness(1.08);color:#fff;}
.pills{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}
.pill{
  display:inline-flex;align-items:center;gap:8px;
  padding:6px 12px;border-radius:999px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(0,0,0,.35);
  font-weight:900;font-size:.86rem;
}
.table-wrap{
  overflow:auto;
  border-radius:14px;
  border:1px solid rgba(255,255,255,.10);
}
.table{margin:0;color:#eee;background:transparent;}
.table thead th{
  position:sticky;top:0;background:#1a1a1a;
  border-bottom:1px solid rgba(255,255,255,.10);
  color:#cfcfcf;font-size:.78rem;text-transform:uppercase;letter-spacing:.10em;
}
.table td{border-top:1px solid rgba(255,255,255,.06);vertical-align:middle;}
.badge{border-radius:999px;padding:.35rem .6rem;}
.muted{color:var(--muted)}
.trainer-title{
  display:flex;justify-content:space-between;align-items:center;
  padding:10px 12px;margin:12px 0 8px;
  border:1px solid rgba(255,255,255,.12);
  border-radius:14px;
  background:#101010;
}
</style>
</head>

<body>
<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand" href="home.php">
    <img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness Admin
  </a>
  <div class="ml-auto">
    <a class="btn btn-sm btn-red btn-pill" href="home.php">Home</a>
    <a class="btn btn-sm btn-outline-light btn-pill" href="logout.php">Logout</a>
  </div>
</nav>

<main class="main-wrap">

  <section class="header">
    <h2>Admin Facilities — Trainer Schedules</h2>
    <p>View all trainers’ schedules and see who enrolled/applied (Pending / Approved / Rejected).</p>
  </section>

  <?php if (!$schemaOk): ?>
    <div class="card-dark mb-3">
      <h5 style="margin:0 0 8px;font-weight:1000;">System Notice</h5>
      <div class="muted"><?= h($schemaMsg) ?></div>
      <?php if ($bookTable): ?>
        <div class="muted mt-2">Detected table: <code><?= h($bookTable) ?></code></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <section class="card-dark mb-3">
    <h5 style="margin:0 0 12px;letter-spacing:.12em;text-transform:uppercase;color:#a9a9a9;">Filters</h5>

    <form class="filters" method="get">
      <div>
        <label class="muted mb-1">Date</label>
        <input type="date" name="date" class="form-control" value="<?= h($filterDate) ?>">
      </div>

      <div>
        <label class="muted mb-1">Trainer</label>
        <select name="trainer_id" class="custom-select">
          <option value="0" <?= $filterTrainer===0?'selected':''; ?>>All trainers</option>
          <?php foreach ($trainers as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $filterTrainer===(int)$t['id']?'selected':''; ?>>
              <?= h($t['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="muted mb-1">Status</label>
        <select name="status" class="custom-select">
          <option value="ALL" <?= $filterStatus==='ALL'?'selected':''; ?>>All</option>
          <option value="PENDING" <?= $filterStatus==='PENDING'?'selected':''; ?>>Pending</option>
          <option value="APPROVED" <?= $filterStatus==='APPROVED'?'selected':''; ?>>Approved</option>
          <option value="REJECTED" <?= $filterStatus==='REJECTED'?'selected':''; ?>>Rejected</option>
        </select>
      </div>

      <div>
        <button class="btn btn-red btn-pill" type="submit" style="height:38px;min-width:90px;">Go</button>
      </div>
    </form>

    <div class="pills">
      <div class="pill">Total: <b><?= (int)$counts['TOTAL'] ?></b></div>
      <div class="pill">Pending: <b><?= (int)$counts['PENDING'] ?></b></div>
      <div class="pill">Approved: <b><?= (int)$counts['APPROVED'] ?></b></div>
      <div class="pill">Rejected: <b><?= (int)$counts['REJECTED'] ?></b></div>
    </div>
  </section>

  <section class="card-dark">
    <h5 style="margin:0 0 10px;letter-spacing:.12em;text-transform:uppercase;color:#a9a9a9;">Schedules</h5>
    <div class="muted mb-2">Grouped by trainer</div>

    <?php if (!$schemaOk): ?>
      <div class="muted">Cannot show schedules because schema detection failed.</div>

    <?php elseif (empty($grouped)): ?>
      <div class="muted">No schedules found for this filter.</div>

    <?php else: ?>
      <?php foreach ($grouped as $trainerName => $items): ?>
        <div class="trainer-title">
          <div style="font-weight:1000;"><?= h($trainerName) ?></div>
          <div class="muted">Records: <?= count($items) ?></div>
        </div>

        <div class="table-wrap mb-3">
          <table class="table table-borderless">
            <thead>
              <tr>
                <th style="width:70px;">ID</th>
                <th style="width:140px;">Time</th>
                <th style="width:180px;">Facility</th>
                <th>Member</th>
                <th style="width:140px;">Status</th>
                <th style="width:260px;">Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $r): ?>
                <tr>
                  <td><?= (int)($r['sid'] ?? 0) ?></td>
                  <td><?= h($r['_time']) ?></td>
                  <td><?= h($r['_facility']) ?></td>
                  <td>
                    <div style="font-weight:900;"><?= h($r['_member']) ?></div>
                    <?php if (!empty($r['email'])): ?>
                      <div class="muted" style="font-size:.88rem;"><?= h($r['email']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge <?= h(status_badge_class($r['_status'])) ?>">
                      <?= h($r['_status']) ?>
                    </span>
                  </td>
                  <td class="muted" style="font-size:.9rem;">
                    <?= h($r['notes'] ?? '') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </section>

</main>
</body>
</html>