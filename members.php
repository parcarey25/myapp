<?php
session_start();
include 'db.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role']!='admin') {
  header("Location: index.php"); exit;
}
$rows = $conn->query("SELECT id,username,full_name,email,phone,role,created_at FROM users")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html><html><head><title>Members</title><link href="//maxcdn.bootstrapcdn.com/bootstrap/4.1.1/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4">
<a href="admin_dashboard.php" class="btn btn-sm btn-secondary mb-3">Back</a>
<table class="table table-bordered"><thead><tr><th>ID</th><th>Username</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Created</th></tr></thead>
<tbody>
<?php foreach($rows as $r): ?>
<tr>
  <td><?php echo $r['id'];?></td>
  <td><?php echo htmlspecialchars($r['username']);?></td>
  <td><?php echo htmlspecialchars($r['full_name']);?></td>
  <td><?php echo htmlspecialchars($r['email']);?></td>
  <td><?php echo htmlspecialchars($r['phone']);?></td>
  <td><?php echo $r['role'];?></td>
  <td><?php echo $r['created_at'];?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</body></html>