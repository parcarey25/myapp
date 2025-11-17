<?php
// staff_pending.php — staff can approve pending accounts
session_start();
require __DIR__.'/db.php';
if (!in_array($_SESSION['role'] ?? 'member', ['staff','admin'], true)) { http_response_code(403); die('Forbidden'); }

if (isset($_GET['approve'])) { $id=(int)$_GET['approve']; $conn->query("UPDATE users SET status='active' WHERE id=$id"); header('Location: staff_pending.php'); exit; }

$res = $conn->query("SELECT id,username,email,role,created_at FROM users WHERE status='pending' ORDER BY created_at ASC");
?>
<!doctype html><html><head><meta charset="utf-8"><title>Staff • Pending</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css"></head>
<body class="bg-dark text-white">
<div class="container py-4">
  <h3>Pending Accounts</h3>
  <table class="table table-dark table-striped table-sm">
    <thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Registered</th><th></th></tr></thead>
    <tbody>
      <?php if($res->num_rows===0): ?><tr><td colspan="6">None</td></tr><?php endif; ?>
      <?php while($u=$res->fetch_assoc()): ?>
        <tr>
          <td><?= $u['id'] ?></td>
          <td><?= htmlspecialchars($u['username']) ?></td>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td><?= htmlspecialchars($u['role']) ?></td>
          <td><?= date('M d, Y g:ia', strtotime($u['created_at'])) ?></td>
          <td><a class="btn btn-danger btn-sm" href="?approve=<?= $u['id'] ?>" onclick="return confirm('Approve this account?')">Approve</a></td>
        </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>
</body></html>