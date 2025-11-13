<?php
require __DIR__.'/auth.php';
if (!in_array($_SESSION['role'] ?? 'member', ['staff','admin'], true)) { http_response_code(403); die('Forbidden'); }
require __DIR__.'/db.php';

$msg='';
function add_months_from($base_ts, $months){
  $d = new DateTime('@'.$base_ts); $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
  $d->modify("+{$months} month"); return $d->format('Y-m-d H:i:s');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  $identifier = trim($_POST['identifier'] ?? ''); // username or email
  $months = max(1, (int)($_POST['months'] ?? 1));
  $amount = (float)($_POST['amount'] ?? 0);
  $method = $_POST['method'] ?? 'cash';
  $reference = trim($_POST['reference'] ?? '');

  if ($identifier==='') {
    $msg='Enter username or email.';
  } else {
    $stmt=$conn->prepare("SELECT id, membership_expires_at FROM users WHERE (username=? OR email=?) AND status='active' LIMIT 1");
    $stmt->bind_param('ss',$identifier,$identifier); $stmt->execute(); $stmt->bind_result($uid,$exp);
    if($stmt->fetch()){
      $stmt->close();

      // record payment
      $staff_id=(int)$_SESSION['user_id'];
      $p=$conn->prepare("INSERT INTO payments (user_id,staff_id,amount,method,reference,months_added) VALUES (?,?,?,?,?,?)");
      $p->bind_param('iisssi',$uid,$staff_id,$amount,$method,$reference,$months); $p->execute(); $p->close();

      // extend expiry
      $base = $exp ? strtotime($exp) : time();
      if ($base < time()) $base = time();
      $new_exp = add_months_from($base, $months);

      $u=$conn->prepare("UPDATE users SET membership_expires_at=? WHERE id=?");
      $u->bind_param('si',$new_exp,$uid); $u->execute(); $u->close();

      $msg = 'Payment saved. New expiry: '.date('M d, Y g:ia', strtotime($new_exp));
    } else {
      $msg='User not found or not active.';
    }
  }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>POS</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
</head><body class="bg-dark text-white">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>💳 POS</h3>
    <div>
      <a href="staff_users.php" class="btn btn-outline-light btn-sm">All Users</a>
      <a href="home.php" class="btn btn-outline-light btn-sm">Home</a>
    </div>
  </div>

  <?php if($msg): ?><div class="alert alert-info"><?=htmlspecialchars($msg)?></div><?php endif; ?>

  <form method="post">
    <div class="form-group">
      <label>Username or Email</label>
      <input class="form-control" name="identifier" placeholder="member1 or member1@example.com" required>
    </div>

    <div class="form-row">
      <div class="form-group col-md-3">
        <label>Months</label>
        <input type="number" name="months" class="form-control" value="1" min="1">
      </div>
      <div class="form-group col-md-3">
        <label>Amount</label>
        <input type="number" step="0.01" name="amount" class="form-control" value="0">
      </div>
      <div class="form-group col-md-3">
        <label>Method</label>
        <select name="method" class="form-control"><option>cash</option><option>gcash</option><option>card</option><option>other</option></select>
      </div>
      <div class="form-group col-md-3">
        <label>Reference (optional)</label>
        <input name="reference" class="form-control" placeholder="Txn ID / Note">
      </div>
    </div>

    <button class="btn btn-danger">Save & Extend</button>
  </form>
</div>
</body></html>