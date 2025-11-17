<?php
require __DIR__.'/admin_guard.php'; // requires auth.php + db.php and role=admin

/**
 * Check if a column exists using INFORMATION_SCHEMA (safe on MariaDB/MySQL)
 */
function has_column(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param('ss', $table, $column);
  $st->execute();
  $res = $st->get_result();
  $exists = ($res && $res->num_rows > 0);
  if ($res) $res->free();
  $st->close();
  return $exists;
}

/* ---- detect optional columns on payments ---- */
$hasPaymentTimestamp = has_column($conn, 'payments', 'created_at');
$hasReference        = has_column($conn, 'payments', 'reference');

/* ---- revenue stats ---- */
$today     = date('Y-m-d');
$monthFrom = date('Y-m-01');

$revTotal = 0.00;
if ($r = $conn->query("SELECT IFNULL(SUM(amount),0) AS s FROM payments")) {
  $revTotal = (float)($r->fetch_assoc()['s'] ?? 0);
  $r->free();
}

$revMonth = 0.00;
$revToday = 0.00;

if ($hasPaymentTimestamp) {
  $mf = $conn->real_escape_string($monthFrom);
  if ($r = $conn->query("SELECT IFNULL(SUM(amount),0) AS s FROM payments WHERE created_at >= '{$mf} 00:00:00'")) {
    $revMonth = (float)($r->fetch_assoc()['s'] ?? 0); $r->free();
  }
  $td = $conn->real_escape_string($today);
  if ($r = $conn->query("SELECT IFNULL(SUM(amount),0) AS s FROM payments WHERE DATE(created_at) = '{$td}'")) {
    $revToday = (float)($r->fetch_assoc()['s'] ?? 0); $r->free();
  }
}

/* ---- user counts ---- */
$counts = ['member'=>0,'trainer'=>0,'staff'=>0,'admin'=>0,'pending'=>0];
if ($r = $conn->query("SELECT role, COUNT(*) c FROM users GROUP BY role")) {
  while($x=$r->fetch_assoc()){ $counts[$x['role']] = (int)$x['c']; }
  $r->free();
}
if ($r = $conn->query("SELECT COUNT(*) c FROM users WHERE status='pending'")) {
  $counts['pending'] = (int)$r->fetch_assoc()['c']; $r->free();
}

/* ---- recent payments (build select depending on columns) ---- */
$select = "SELECT p.id, p.amount, p.method, ";
$select .= $hasReference ? "p.reference, " : "'' AS reference, ";
$select .= $hasPaymentTimestamp ? "p.created_at, " : "'1970-01-01 00:00:00' AS created_at, ";
$select .= "u.username AS member, su.username AS staff
            FROM payments p
            LEFT JOIN users u  ON u.id = p.user_id
            LEFT JOIN users su ON su.id = p.staff_id
            ORDER BY p.id DESC
            LIMIT 20";
$pay = $conn->query($select);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin • Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
  body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
  .card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}
  .btn-danger{background:#b30000;border:none}.btn-danger:hover{background:#ff1a1a}
  a, a:hover{color:#fff}
</style>
</head>
<body>
<nav class="navbar navbar-dark" style="background:linear-gradient(90deg,#000,#b30000)">
  <a class="navbar-brand ml-3" href="home.php">
    <img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness Admin
  </a>
  <div class="ml-auto mr-3">
    <a class="btn btn-outline-light btn-sm" href="admin_users.php">Users</a>
    <a class="btn btn-outline-light btn-sm" href="admin_pending.php">Pending</a>
    <a class="btn btn-outline-light btn-sm" href="admin_pos.php">POS</a>
    <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
  </div>
</nav>

<div class="container py-4">
  <div class="row">
    <div class="col-md-4 mb-3">
      <div class="card p-3">
        <h6 class="text-muted mb-1">Revenue Today</h6>
        <h3>₱<?= number_format($revToday, 2) ?></h3>
        <?php if(!$hasPaymentTimestamp): ?>
          <small class="text-muted">Add payments.created_at to enable daily stats.</small>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-md-4 mb-3">
      <div class="card p-3">
        <h6 class="text-muted mb-1">Revenue This Month</h6>
        <h3>₱<?= number_format($revMonth, 2) ?></h3>
      </div>
    </div>
    <div class="col-md-4 mb-3">
      <div class="card p-3">
        <h6 class="text-muted mb-1">Revenue Total</h6>
        <h3>₱<?= number_format($revTotal, 2) ?></h3>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-md-3 mb-3"><div class="card p-3"><strong>Members</strong><div><?= $counts['member'] ?></div></div></div>
    <div class="col-md-3 mb-3"><div class="card p-3"><strong>Trainers</strong><div><?= $counts['trainer'] ?></div></div></div>
    <div class="col-md-3 mb-3"><div class="card p-3"><strong>Staff</strong><div><?= $counts['staff'] ?></div></div></div>
    <div class="col-md-3 mb-3"><div class="card p-3"><strong>Pending</strong><div><?= $counts['pending'] ?></div></div></div>
  </div>

  <div class="card p-3">
    <h5 class="mb-3">Recent Payments</h5>
    <div class="table-responsive">
      <table class="table table-dark table-striped table-sm mb-0">
        <thead>
          <tr>
            <th>ID</th>
            <th>Member</th>
            <th>Amount</th>
            <th>Method</th>
            <th>Reference</th>
            <th>Staff</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
          <?php if($pay && $pay->num_rows): ?>
            <?php while($p = $pay->fetch_assoc()): ?>
              <tr>
                <td><?= $p['id'] ?></td>
                <td><?= htmlspecialchars($p['member'] ?? '—') ?></td>
                <td>₱<?= number_format($p['amount'], 2) ?></td>
                <td><?= htmlspecialchars($p['method']) ?></td>
                <td><?= $hasReference ? htmlspecialchars($p['reference'] ?? '') : '—' ?></td>
                <td><?= htmlspecialchars($p['staff'] ?? '—') ?></td>
                <td><?= $hasPaymentTimestamp ? date('M d, Y g:ia', strtotime($p['created_at'])) : '—' ?></td>
              </tr>
            <?php endwhile; $pay->free(); ?>
          <?php else: ?>
            <tr><td colspan="7" class="text-muted">No payments yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if(!$hasReference): ?>
      <small class="text-muted d-block mt-2">Tip: add payments.reference (VARCHAR(120)) to store OR/GCash refs.</small>
    <?php endif; ?>
  </div>
</div>
</body>
</html>