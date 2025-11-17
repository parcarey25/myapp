<?php
require __DIR__.'/auth.php';
if (!in_array($_SESSION['role'] ?? 'member', ['staff','admin'], true)) { http_response_code(403); die('Forbidden'); }
require __DIR__.'/db.php';

$q = trim($_GET['q'] ?? '');
$sql = "SELECT id, username, full_name, email, role, status, membership_expires_at FROM users";
if ($q !== '') {
  $q_ = '%'.$conn->real_escape_string($q).'%';
  $sql .= " WHERE username LIKE '$q_' OR email LIKE '$q_' OR full_name LIKE '$q_'";
}
$sql .= " ORDER BY id DESC LIMIT 200";
$res = $conn->query($sql);
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Staff • Users</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
</head><body class="bg-dark text-white">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>👥 Users</h3>
    <div>
      <a href="staff_pending.php" class="btn btn-outline-light btn-sm">Pending</a>
      <a href="pos.php" class="btn btn-danger btn-sm">POS</a>
      <a href="home.php" class="btn btn-outline-light btn-sm">Home</a>
    </div>
  </div>

  <form class="form-inline mb-3">
    <input class="form-control mr-2" name="q" placeholder="Search name, username, email" value="<?=htmlspecialchars($q)?>">
    <button class="btn btn-danger">Search</button>
  </form>

  <div class="table-responsive">
    <table class="table table-dark table-striped table-sm">
      <thead><tr><th>ID</th><th>Username</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Expires</th><th></th></tr></thead>
      <tbody>
        <?php while($u = $res->fetch_assoc()): ?>
          <tr>
            <td><?=$u['id']?></td>
            <td><?=htmlspecialchars($u['username'])?></td>
            <td><?=htmlspecialchars($u['full_name'])?></td>
            <td><?=htmlspecialchars($u['email'])?></td>
            <td><?=htmlspecialchars($u['role'])?></td>
            <td><?=htmlspecialchars($u['status'])?></td>
            <td><?= $u['membership_expires_at'] ? date('M d, Y', strtotime($u['membership_expires_at'])) : '—' ?></td>
            <td><a class="btn btn-outline-light btn-sm" href="user_edit.php?id=<?=$u['id']?>">Edit</a></td>
          </tr>
        <?php endwhile; $res->free(); ?>
      </tbody>
    </table>
  </div>
</div>
</body></html>