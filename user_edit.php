<?php
require __DIR__.'/auth.php';
if (!in_array($_SESSION['role'] ?? 'member', ['staff','admin'], true)) { http_response_code(403); die('Forbidden'); }
require __DIR__.'/db.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Missing id');

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $full     = trim($_POST['full_name'] ?? '');
  $role_in  = trim($_POST['role'] ?? 'member');
  $status   = trim($_POST['status'] ?? 'active');
  $expiry   = $_POST['membership_expires_at'] !== '' ? $_POST['membership_expires_at'] : NULL;

  // staff cannot set admin here
  $role = in_array($role_in, ['member','trainer','staff'], true) ? $role_in : 'member';

  $sql = "UPDATE users SET username=?, email=?, full_name=?, role=?, status=?, membership_expires_at=?";
  $types = 'ssssss';
  $params = [$username, $email, $full, $role, $status, $expiry];

  if (!empty($_POST['new_password'])) {
    $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
    $sql .= ", password=?";
    $types .= 's';
    $params[] = $hash;
  }
  $sql .= " WHERE id=?";
  $types .= 'i';
  $params[] = $id;

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $msg = $stmt->execute() ? 'Saved' : ('Save failed: '.$conn->error);
  $stmt->close();
}

$u = $conn->query("SELECT id,username,email,full_name,role,status,membership_expires_at FROM users WHERE id=$id")->fetch_assoc();
if(!$u) die('User not found');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit User</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
</head><body class="bg-dark text-white">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>✏️ Edit User #<?=$u['id']?></h3>
    <a href="staff_users.php" class="btn btn-outline-light btn-sm">Back</a>
  </div>
  <?php if($msg): ?><div class="alert alert-info"><?=htmlspecialchars($msg)?></div><?php endif; ?>

  <form method="post">
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