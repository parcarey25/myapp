<?php
require __DIR__.'/auth.php'; require_role('member');
require __DIR__.'/db.php';

$id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT membership_expires_at FROM users WHERE id=?");
$stmt->bind_param('i',$id);
$stmt->execute();
$stmt->bind_result($expires);
$stmt->fetch();
$stmt->close();

$status = 'Not set';
if ($expires) {
  $ts = strtotime($expires);
  $days = floor(($ts - time()) / 86400);
  $status = $days >= 0
    ? "Active — expires in {$days} day(s) on ".date('M d, Y g:ia',$ts)
    : "Expired on ".date('M d, Y g:ia',$ts);
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><title>Membership Status</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-dark text-white">
<div class="container py-4">
  <h3>🗓 Membership Status</h3>
  <div class="card bg-secondary border-0">
    <div class="card-body">
      <p><strong>Status:</strong> <?=$status?></p>
      <a href="home.php" class="btn btn-outline-light">⬅ Back</a>
      <!-- optional: link to renew/plan selection -->
    </div>
  </div>
</div>
</body></html>