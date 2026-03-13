<?php
// attendance.php – member view of their own attendance
// Reads from the same attendance table that all_attendance.php uses.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require __DIR__ . '/db.php';

$userId   = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Member');
$role     = strtolower($_SESSION['role'] ?? 'member');

/* ------------------------------------------------------------------
   Helpers to detect column names so this works with your existing DB
-------------------------------------------------------------------*/
function has_col(mysqli $conn, string $table, string $col): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    if (!$st = $conn->prepare($sql)) return false;
    $st->bind_param('ss', $table, $col);
    $st->execute();
    $res = $st->get_result();
    $ok  = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $st->close();
    return $ok;
}

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

function fmt_date($d) {
    if (!$d) return '—';
    $ts = strtotime($d);
    if ($ts === false) return $d;
    return date('M d, Y', $ts);
}

function fmt_time($t) {
    if (!$t) return '—';
    $ts = strtotime($t);
    if ($ts === false) return $t;
    return date('h:i A', $ts);
}

function fmt_duration($in, $out) {
    if (!$in || !$out) return '—';
    $ti = strtotime($in);
    $to = strtotime($out);
    if ($ti === false || $to === false || $to <= $ti) return '—';
    $mins = (int)(($to - $ti) / 60);
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return sprintf('%02dh %02dm', $h, $m);
}

/* ------------------------------------------------------------------
   Figure out which columns your attendance table uses
-------------------------------------------------------------------*/

$table = 'attendance';

// which column identifies the user? user_id or id_number?
$useUserId   = has_col($conn, $table, 'user_id');
$useIdNumber = !$useUserId && has_col($conn, $table, 'id_number');

// which column is the date?
if (has_col($conn, $table, 'attendance_date')) {
    $dateCol = 'attendance_date';
} elseif (has_col($conn, $table, 'att_date')) {
    $dateCol = 'att_date';
} else {
    // fallback
    $dateCol = 'date';
}

// time columns
$timeInCol  = has_col($conn, $table, 'time_in')  ? 'time_in'  : 'check_in';
$timeOutCol = has_col($conn, $table, 'time_out') ? 'time_out' : 'check_out';

// if table uses id_number, fetch the member's id_number
$idNumber = '';
if ($useIdNumber) {
    if ($st = $conn->prepare("SELECT id_number FROM users WHERE id = ? LIMIT 1")) {
        $st->bind_param('i', $userId);
        $st->execute();
        if ($res = $st->get_result()) {
            if ($row = $res->fetch_assoc()) {
                $idNumber = $row['id_number'] ?? '';
            }
            $res->free();
        }
        $st->close();
    }
}

/* ------------------------------------------------------------------
   Optional date filter (so member can filter by a specific day)
-------------------------------------------------------------------*/
$selectedDate = $_GET['date'] ?? '';
$selectedDateSql = null;
if ($selectedDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDateSql = $selectedDate;
}

/* ------------------------------------------------------------------
   Load this member's attendance rows
-------------------------------------------------------------------*/
$rows = [];
$where = " WHERE 1=1 ";
$params = [];
$types  = '';

if ($useUserId) {
    $where .= " AND user_id = ? ";
    $params[] = $userId;
    $types   .= 'i';
} elseif ($useIdNumber && $idNumber !== '') {
    $where .= " AND id_number = ? ";
    $params[] = $idNumber;
    $types   .= 's';
} else {
    // can't identify user; return empty
    $rows = [];
}

if ($selectedDateSql && ($useUserId || $useIdNumber)) {
    $where .= " AND {$dateCol} = ? ";
    $params[] = $selectedDateSql;
    $types   .= 's';
}

if ($useUserId || $useIdNumber) {
    $sql = "SELECT id, {$dateCol} AS att_date,
                   {$timeInCol} AS time_in,
                   {$timeOutCol} AS time_out
            FROM {$table}
            {$where}
            ORDER BY {$dateCol} DESC, {$timeInCol} ASC";

    if ($st = $conn->prepare($sql)) {
        if ($types !== '') {
            $st->bind_param($types, ...$params);
        }
        $st->execute();
        if ($res = $st->get_result()) {
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();
        }
        $st->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>My Attendance | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
:root{
  --bg:#101010;
  --panel:#171717;
  --line:#2a2a2a;
  --brand:#b30000;
}
body{
  background:var(--bg);
  color:#fff;
  font-family:'Poppins',sans-serif;
}
.navbar{
  background:linear-gradient(90deg,#000,var(--brand));
}
.card{
  background:var(--panel);
  border:1px solid var(--line);
  border-radius:14px;
}
.table-dark{
  background:#141414;
}
.table-dark th, .table-dark td{
  border-color:#262626;
}
.btn-danger{
  background:var(--brand);
  border:none;
}
.btn-danger:hover{
  background:#ff1a1a;
}
.form-control{
  background:#121212;
  border:1px solid #2a2a2a;
  color:#eee;
}
small.text-muted{
  color:#9aa0a6 !important;
}
</style>
</head>
<body>
<nav class="navbar navbar-dark">
  <a class="navbar-brand ml-3" href="home.php">
    <img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness
  </a>
  <div class="ml-auto mr-3">
    <span class="mr-3">Welcome, <?= h($username) ?></span>
    <a class="btn btn-outline-light btn-sm mr-2" href="home.php">Dashboard</a>
    <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
  </div>
</nav>

<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-0">My Attendance</h3>
      <small class="text-muted">
        This list only shows your own attendance records saved from the staff page.
      </small>
    </div>
    <form method="get" class="form-inline">
      <label class="mr-2 mb-1">Date</label>
      <input type="date" name="date" class="form-control mr-2 mb-1"
             value="<?= h($selectedDateSql ?? '') ?>">
      <button class="btn btn-danger mb-1">Filter</button>
    </form>
  </div>

  <div class="card p-3">
    <?php if (!$rows): ?>
      <div class="alert alert-secondary mb-0">
        No attendance records found yet.
        <br>
        Once staff log your time in/out in <strong>All Attendance</strong> and click
        <strong>Save</strong>, your logs will appear here.
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-dark table-striped table-hover table-sm mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Date</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th>Duration</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $idx => $r): ?>
              <tr>
                <td><?= $idx + 1 ?></td>
                <td><?= h(fmt_date($r['att_date'] ?? '')) ?></td>
                <td><?= h(fmt_time($r['time_in'] ?? '')) ?></td>
                <td><?= h(fmt_time($r['time_out'] ?? '')) ?></td>
                <td><?= h(fmt_duration($r['time_in'] ?? '', $r['time_out'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="mt-3">
    <a class="btn btn-outline-light" href="home.php">Back to Dashboard</a>
  </div>
</div>
</body>
</html>