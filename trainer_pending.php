<?php
// trainer_pending.php — trainer/admin can approve or decline bookings assigned to them.

if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__.'/db.php';

$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'trainer' && $role !== 'admin') {
    header('Location: home.php'); exit;
}
$trainerId = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function flash_set($m,$t='success'){ $_SESSION['flash']=['msg'=>$m,'type'=>$t]; }
function flash_get(){ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

// Handle approve/decline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'], $_POST['decision'])) {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        http_response_code(400); die('CSRF failed');
    }
    $id = (int)$_POST['booking_id'];
    $decision = $_POST['decision'] === 'approve' ? 'approved' : 'declined';

    if ($role === 'admin') {
        $st = $conn->prepare("UPDATE bookings SET status=? WHERE id=?");
        $st->bind_param('si', $decision, $id);
    } else {
        // trainer: only bookings assigned to them
        $st = $conn->prepare("UPDATE bookings SET status=? WHERE id=? AND trainer_id=?");
        $st->bind_param('sii', $decision, $id, $trainerId);
    }
    $st->execute();
    $affected = $st->affected_rows;
    $st->close();

    if ($affected > 0) {
        flash_set($decision === 'approved' ? 'Booking approved.' : 'Booking declined.', 'success');
    } else {
        flash_set('Could not update this booking (maybe not assigned to you?).','danger');
    }
    header('Location: trainer_pending.php'); exit;
}

// Load pending bookings
$rows = [];
if ($role === 'trainer') {
    $st = $conn->prepare("
      SELECT b.*, u.full_name AS member_name, u.id_number, u.role AS member_role
      FROM bookings b
      LEFT JOIN users u ON b.user_id = u.id
      WHERE b.trainer_id = ? AND b.status = 'pending'
      ORDER BY b.date, b.time
    ");
    $st->bind_param('i', $trainerId);
    $st->execute();
    if ($res = $st->get_result()) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
    $st->close();
} else { // admin: see all pending
    $sql = "
      SELECT b.*, u.full_name AS member_name, u.id_number, u.role AS member_role
      FROM bookings b
      LEFT JOIN users u ON b.user_id = u.id
      WHERE b.status = 'pending'
      ORDER BY b.date, b.time
    ";
    if ($res = $conn->query($sql)) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
}

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Trainer Pending Bookings | RJL Fitness</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
  body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
  .navbar{background:linear-gradient(90deg,#000,#b30000)}
  .card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}
  .btn-danger{background:#b30000;border:none}.btn-danger:hover{background:#ff1a1a}
  .btn-ghost{background:transparent;border:1px solid #444;color:#eee}
  .btn-ghost:hover{background:#1e1e1e}
  .muted{color:#9aa0a6} a,a:hover{color:#fff}
  .actions{white-space:nowrap}
  .actions form{display:inline}
</style>
</head>
<body>
<nav class="navbar navbar-dark">
  <a class="navbar-brand ml-3" href="home_trainer.php"><img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto mr-3">
    <a class="btn btn-outline-light btn-sm" href="home_trainer.php">Trainer Dashboard</a>
    <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
  </div>
</nav>

<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Pending Bookings</h3>
    <small class="muted">
      <?= $role === 'trainer' ? 'Bookings assigned to you.' : 'All pending bookings.' ?>
    </small>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type']==='danger' ? 'danger' : 'success' ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="card p-3">
    <?php if (!$rows): ?>
      <div class="alert alert-secondary mb-0">No pending bookings.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-dark table-striped table-hover table-sm mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Facility</th>
              <th>Date</th>
              <th>Time</th>
              <th>Member</th>
              <th>ID Number</th>
              <th>Role</th>
              <th>Notes</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= h($r['facility_name']) ?> <span class="text-muted">(<?= h($r['facility_slug']) ?>)</span></td>
                <td><?= h($r['date']) ?></td>
                <td><?= h($r['time']) ?></td>
                <td><?= h($r['member_name'] ?: $r['full_name']) ?></td>
                <td><?= h($r['id_number'] ?: '—') ?></td>
                <td><?= h($r['member_role'] ?: '—') ?></td>
                <td><?= $r['notes'] ? nl2br(h($r['notes'])) : '—' ?></td>
                <td class="text-right actions">
                  <?php if (!empty($r['user_id'])): ?>
                    <a class="btn btn-ghost btn-sm mb-1"
                       href="user_info.php?user_id=<?= (int)$r['user_id'] ?>">View User</a>
                  <?php endif; ?>
                  <form method="post" style="display:inline-block;">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="booking_id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-outline-success btn-sm mb-1" name="decision" value="approve">
                      Approve
                    </button>
                    <button class="btn btn-outline-danger btn-sm" name="decision" value="decline">
                      Decline
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="mt-3">
    <a class="btn btn-outline-light" href="home_trainer.php">Back to Trainer Dashboard</a>
  </div>
</div>
</body>
</html>