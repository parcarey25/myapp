<?php
require __DIR__.'/admin_guard.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Missing id');

$msg='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $username = trim($_POST['username'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $full     = trim($_POST['full_name'] ?? '');
  $role     = trim($_POST['role'] ?? 'member');   // admin allowed here
  $status   = trim($_POST['status'] ?? 'active');
  $expiry   = $_POST['membership_expires_at'] !== '' ? $_POST['membership_expires_at'] : NULL;

  $sql   = "UPDATE users SET username=?, email=?, full_name=?, role=?, status=?, membership_expires_at=?";
  $types = 'ssssss';
  $params= [$username,$email,$full,$role,$status,$expiry];

  if (!empty($_POST['new_password'])) {
    $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
    $sql .= ", password=?";
    $types .= 's';
    $params[] = $hash;
  }
  $sql .= " WHERE id=?";
  $types .= 'i';
  $params[] = $id;

  $st=$conn->prepare($sql);
  $st->bind_param($types, ...$params);
  $msg = $st->execute() ? 'Saved' : ('Save failed: '.$conn->error);
  $st->close();
}

$u = $conn->query("SELECT id,username,email,full_name,role,status,membership_expires_at FROM users WHERE id=$id")->fetch_assoc();
if(!$u) die('User not found');
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin • Edit User #<?=$u['id']?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>body{background:#111;color:#fff}.card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}</style>
</head><body>
<nav class="navbar navbar-dark" style="background:linear-gradient(90deg,#000,#b30000)">
  <a class="navbar-brand ml-3" href="admin_dashboard.php"><img src="photo/logo.jpg" height="32" class="mr-2">RJL Admin</a>
  <div class="ml-auto mr-3">
    <a class="btn btn-outline-light btn-sm" href="admin_users.php">Users</a>
    <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
  </div>
</nav>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>✏️ Edit User #<?=$u['id']?></h3>
    <a href="admin_users.php" class="btn btn-outline-light btn-sm">Back</a>
  </div>

  <?php if($msg): ?><div class="alert alert-info"><?=htmlspecialchars($msg)?></div><?php endif; ?>

  <form method="post" class="card p-3">
    <div class="form-row">
      <div class="form-group col-md-4"><label>Username</label><input class="form-control" name="username" value="<?=htmlspecialchars($u['username'])?>" required></div>
      <div class="form-group col-md-4"><label>Email</label><input type="email" class="form-control" name="email" value="<?=htmlspecialchars($u['email'])?>" required></div>
      <div class="form-group col-md-4"><label>Full Name</label><input class="form-control" name="full_name" value="<?=htmlspecialchars($u['full_name'])?>"></div>
    </div>

    <div class="form-row">
      <div class="form-group col-md-3">
        <label>Role</label>
        <select name="role" class="form-control">
          <option value="member"  <?=$u['role']==='member'?'selected':''?>>member</option>
          <option value="trainer" <?=$u['role']==='trainer'?'selected':''?>>trainer</option>
          <option value="staff"   <?=$u['role']==='staff'?'selected':''?>>staff</option>
          <option value="admin"   <?=$u['role']==='admin'?'selected':''?>>admin</option>
        </select>
      </div>
      <div class="form-group col-md-3">
        <label>Status</label>
        <select name="status" class="form-control">
          <option value="pending"   <?=$u['status']==='pending'?'selected':''?>>pending</option>
          <option value="active"    <?=$u['status']==='active'?'selected':''?>>active</option>
          <option value="suspended" <?=$u['status']==='suspended'?'selected':''?>>suspended</option>
        </select>
      </div>
      <div class="form-group col-md-3">
        <label>Membership Expires</label>
        <input type="datetime-local" name="membership_expires_at" class="form-control"
               value="<?= $u['membership_expires_at'] ? date('Y-m-d\TH:i', strtotime($u['membership_expires_at'])) : '' ?>">
      </div>
      <div class="form-group col-md-3">
        <label>New Password (optional)</label>
        <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep">
      </div>
    </div>

    <button class="btn btn-danger">Save</button>
  </form>
</div>
</body></html>