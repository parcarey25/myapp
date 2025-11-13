<?php
include 'db.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location:index.php"); exit; }
if ($_SERVER['REQUEST_METHOD']=='POST') {
  $uid=intval($_POST['user_id']); $amt=floatval($_POST['amount']); $method=$conn->real_escape_string($_POST['method']);
  $conn->query("INSERT INTO payments (user_id,amount,method) VALUES ($uid,$amt,'$method')");
  header("Location: payments.php");
  exit;
}
$rows = $conn->query("SELECT p.*,u.username FROM payments p JOIN users u ON u.id=p.user_id ORDER BY paid_at DESC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html><html><head><link href="//maxcdn.bootstrapcdn.com/bootstrap/4.1.1/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4">
<h3>Payments</h3>
<form method="POST" class="form-inline mb-3"><input name="user_id" class="form-control mr-2" placeholder="User ID" required><input name="amount" class="form-control mr-2" placeholder="Amount" required><input name="method" class="form-control mr-2" placeholder="Method"><button class="btn btn-primary">Record</button></form>
<table class="table"><thead><tr><th>ID</th><th>User</th><th>Amount</th><th>Method</th><th>Paid at</th></tr></thead><tbody>
<?php foreach($rows as $r): ?>
<tr><td><?php echo $r['id']?></td><td><?php echo htmlspecialchars($r['username'])?></td><td><?php echo $r['amount']?></td><td><?php echo htmlspecialchars($r['method'])?></td><td><?php echo $r['paid_at']?></td></tr>
<?php endforeach;?>
</tbody></table>
</body></html>