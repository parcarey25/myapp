<?php
include 'db.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location:index.php"); exit; }
if ($_SERVER['REQUEST_METHOD']=='POST' && $_SESSION['role']=='admin') {
  $name=$conn->real_escape_string($_POST['name']);
  $price=floatval($_POST['price']);
  $dur=intval($_POST['duration']);
  $conn->query("INSERT INTO membership_plans (name,price,duration_days,description) VALUES ('$name',$price,$dur,'')");
  header("Location: plans.php");
  exit;
}
$plans = $conn->query("SELECT * FROM membership_plans")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html><html><head><title>Plans</title><link href="//maxcdn.bootstrapcdn.com/bootstrap/4.1.1/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4">
<h3>Plans</h3>
<table class="table"><thead><tr><th>ID</th><th>Name</th><th>Price</th><th>Days</th></tr></thead><tbody>
<?php foreach($plans as $p): ?>
<tr><td><?php echo $p['id']?></td><td><?php echo htmlspecialchars($p['name'])?></td><td><?php echo $p['price']?></td><td><?php echo $p['duration_days']?></td></tr>
<?php endforeach;?>
</tbody></table>

<?php if($_SESSION['role']=='admin'): ?>
<hr><h5>Add Plan</h5>
<form method="POST">
  <input name="name" class="form-control mb-2" placeholder="Plan name" required>
  <input name="price" class="form-control mb-2" placeholder="Price" required>
  <input name="duration" class="form-control mb-2" placeholder="Duration days" required>
  <button class="btn btn-primary">Add</button>
</form>
<?php endif; ?>

</body></html>