<?php
include 'db.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location:index.php"); exit; }
if ($_SERVER['REQUEST_METHOD']=='POST') {
  $uid = intval($_POST['user_id']);
  $conn->query("INSERT INTO attendance (user_id) VALUES ($uid)");
  header("Location: attendance.php?msg=checked");
  exit;
}
$logs = $conn->query("SELECT a.*, u.username FROM attendance a JOIN users u ON u.id=a.user_id ORDER BY a.check_in DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html><html><head><link href="//maxcdn.bootstrapcdn.com/bootstrap/4.1.1/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4">
<h3>Attendance</h3>
<form method="POST" class="form-inline mb-3">
  <input name="user_id" class="form-control mr-2" placeholder="Member ID" required>
  <button class="btn btn-success">Check-in</button>
</form>
<table class="table"><thead><tr><th>ID</th><th>User</th><th>Check-in</th></tr></thead><tbody>
<?php foreach($logs as $l): ?>
<tr><td><?php echo $l['id']?></td><td><?php echo htmlspecialchars($l['username'])?></td><td><?php echo $l['check_in']?></td></tr>
<?php endforeach;?>
</tbody></table>
</body></html>