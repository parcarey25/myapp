<?php
require __DIR__.'/auth.php';
require __DIR__.'/db.php';

$id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'member';

$stmt = $conn->prepare("SELECT username, email, full_name, membership_expires_at FROM users WHERE id=?");
$stmt->bind_param('i',$id);
$stmt->execute();
$stmt->bind_result($username,$email,$full_name,$expires);
$stmt->fetch();
$stmt->close();

$expiryText = '—';
if ($role === 'member') {
  if ($expires) {
    $ts = strtotime($expires);
    $days = floor(($ts - time()) / 86400);
    $expiryText = $days >= 0
      ? "Active — expires in {$days} day(s) on ".date('M d, Y g:ia',$ts)
      : "Expired on ".date('M d, Y g:ia',$ts);
  } else {
    $expiryText = "Not set";
  }
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><title>Account Info</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-dark text-white">
<div class="container py-4">
  <h3>👤 Account Info</h3>
  <div class="card bg-secondary border-0">
    <div class="card-body">
      <p><strong>Full Name:</strong> <?=htmlspecialchars($full_name ?: $username)?></p>
      <p><strong>Email:</strong> <?=htmlspecialchars($email ?: '—')?></p>
      <p><strong>Role:</strong> <?=htmlspecialchars($role)?></p>
      <?php if ($role === 'member'): ?>
        <p><strong>Membership:</strong> <?=$expiryText?></p>
      <?php endif; ?>
      <a href="home.php" class="btn btn-outline-light">⬅ Back</a>
      <a href="change_password.php" class="btn btn-danger">Change Password</a>
    </div>
  </div>
</div>
</body></html>