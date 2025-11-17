<?php
require __DIR__.'/admin_guard.php';

$q = trim($_GET['q'] ?? '');
$sql = "SELECT id,username,full_name,email,role,status,membership_expires_at FROM users";
if ($q !== '') {
  $q_ = '%'.$conn->real_escape_string($q).'%';
  $sql .= " WHERE username LIKE '$q_' OR email LIKE '$q_' OR full_name LIKE '$q_'";
}
$sql .= " ORDER BY id DESC LIMIT 300";
$res = $conn->query($sql);
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin • Users</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>body{background:#111;color:#fff}.card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}</style>
</head><body>
<nav class="navbar navbar-dark" style="background:linear-gradient(90deg,#000,#b30000)">
  <a class="navbar-brand ml-3" href="admin_dashboard.php"><img src="photo/logo.jpg" height="32" class="mr-2">RJL Admin</a>
  <div class="ml-auto mr-3">
    <a class="btn btn-outline-light btn-sm" href="admin_pending.php">Pending</a>
    <a class="btn btn-outline-light btn-sm" href="admin_pos.php">POS</a>
    <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
  </div>
</nav>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>All Users</h3>
    <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
  </div>

  <form class="form-inline mb-3">
    <input class="form-control mr-2" name="q" placeholder="Search name, username, email" value="<?=htmlspecialchars($q)?>">
    <button class="btn btn-danger">Search</button>
  </form>

  <div class="table-responsive">
    <table class="table table-dark table-striped table-sm">
      <thead><tr><th>ID</th><th>Username</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Expires</th><th></th></tr></thead>
      <tbody>
        <?php while($u=$res->fetch_assoc()): ?>
          <tr>
            <td><?=$u['id']?></td>
            <td><?=htmlspecialchars($u['username'])?></td>
            <td><?=htmlspecialchars($u['full_name'])?></td>
            <td><?=htmlspecialchars($u['email'])?></td>
            <td><?=htmlspecialchars($u['role'])?></td>
            <td><?=htmlspecialchars($u['status'])?></td>
            <td><?= $u['membership_expires_at'] ? date('M d, Y', strtotime($u['membership_expires_at'])) : '—' ?></td>
            <td><a class="btn btn-outline-light btn-sm" href="admin_user_edit.php?id=<?=$u['id']?>">Edit</a></td>
          </tr>
        <?php endwhile; $res->free(); ?>
      </tbody>
    </table>
  </div>
</div>
</body></html>