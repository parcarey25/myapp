<<<<<<< HEAD
<?php
require __DIR__.'/admin_guard.php';

if (isset($_GET['approve'])) { $id=(int)$_GET['approve']; $conn->query("UPDATE users SET status='active' WHERE id=$id"); header('Location: admin_pending.php'); exit; }
if (isset($_GET['reject']))  { $id=(int)$_GET['reject'];  $conn->query("DELETE FROM users WHERE id=$id AND status='pending'"); header('Location: admin_pending.php'); exit; }

$res = $conn->query("SELECT id,username,email,full_name,role,created_at FROM users WHERE status='pending' ORDER BY created_at ASC");
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin • Pending</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>body{background:#111;color:#fff}.card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}</style>
</head><body>
<nav class="navbar navbar-dark" style="background:linear-gradient(90deg,#000,#b30000)">
  <a class="navbar-brand ml-3" href="admin_dashboard.php"><img src="photo/logo.jpg" height="32" class="mr-2">RJL Admin</a>
  <div class="ml-auto mr-3">
    <a class="btn btn-outline-light btn-sm" href="admin_users.php">Users</a>
    <a class="btn btn-outline-light btn-sm" href="admin_pos.php">POS</a>
    <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
  </div>
</nav>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Pending Registrations</h3>
    <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
  </div>

  <?php if($res->num_rows===0): ?>
    <div class="alert alert-secondary">No pending accounts.</div>
  <?php else: while($u=$res->fetch_assoc()): ?>
    <div class="list-group-item bg-secondary text-white border-0 mb-2">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <strong><?=htmlspecialchars($u['full_name'] ?: $u['username'])?></strong>
          <div><small><?=htmlspecialchars($u['email'])?> • <?=htmlspecialchars($u['role'])?></small></div>
          <div><small>Registered: <?=date('M d, Y g:ia', strtotime($u['created_at']))?></small></div>
        </div>
        <div>
          <a class="btn btn-danger btn-sm" href="?approve=<?=$u['id']?>" onclick="return confirm('Approve this account?')">Approve</a>
          <a class="btn btn-outline-light btn-sm" href="?reject=<?=$u['id']?>" onclick="return confirm('Reject and delete this account?')">Reject</a>
        </div>
      </div>
    </div>
  <?php endwhile; endif; ?>
</div>

</body></html>