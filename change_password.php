<?php
// change_password.php — verifies current password, updates to a new one (secure)
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__ . '/db.php';

$userId = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';

// CSRF token
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Basic CSRF check
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
    $errors[] = 'Security check failed. Please try again.';
  } else {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // Validate inputs
    if ($current === '' || $new === '' || $confirm === '') {
      $errors[] = 'Please complete all fields.';
    }
    if ($new !== $confirm) {
      $errors[] = 'New password and confirmation do not match.';
    }
    if (strlen($new) < 8) {
      $errors[] = 'New password must be at least 8 characters.';
    }
    if (!$errors) {
      // Fetch current hashed password
      if ($st = $conn->prepare("SELECT password FROM users WHERE id=? LIMIT 1")) {
        $st->bind_param('i', $userId);
        $st->execute();
        $st->bind_result($hash);
        if ($st->fetch()) {
          // Verify current password
          if (!password_verify($current, $hash)) {
            $errors[] = 'Your current password is incorrect.';
          }
        } else {
          $errors[] = 'Account not found.';
        }
        $st->close();
      } else {
        $errors[] = 'Database error (lookup).';
      }

      // Prevent reusing the exact same password
      if (!$errors && password_verify($new, $hash)) {
        $errors[] = 'New password must be different from your current password.';
      }

      // Update to new password
      if (!$errors) {
        $newHash = password_hash($new, PASSWORD_BCRYPT);

        if ($st = $conn->prepare("UPDATE users SET password=? WHERE id=?")) {
          $st->bind_param('si', $newHash, $userId);
          if ($st->execute()) {
            $success = true;

            // Optional: rotate session ID to be extra safe
            session_regenerate_id(true);

            // OPTIONAL: if you have a `password_changed_at` column
            // $conn->query("UPDATE users SET password_changed_at=NOW() WHERE id={$userId}");
          } else {
            $errors[] = 'Failed to update password.';
          }
          $st->close();
        } else {
          $errors[] = 'Database error (update).';
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Change Password | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{ --brand:#b30000; --brand-hover:#ff1a1a; --bg:#111; --panel:#1a1a1a; --line:#2a2a2a; }
  body{ background:#111; color:#fff; font-family:'Poppins',sans-serif; min-height:100vh; }
  .navbar{ background:linear-gradient(90deg,#000,var(--brand)); }
  .card{ background:#1a1a1a; border:1px solid #2a2a2a; border-radius:14px; max-width:520px; margin:40px auto; }
  .form-control{ background:#121212; border:1px solid #2a2a2a; color:#eee; }
  .btn-danger{ background:var(--brand); border:none; } .btn-danger:hover{ background:var(--brand-hover); }
  a, a:hover{ color:#fff; }
</style>
</head>
<body>
<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand" href="home.php"><img src="photo/logo.jpg" height="30" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto">
    <a class="btn btn-outline-light btn-sm" href="home.php">Back to Dashboard</a>
  </div>
</nav>

<div class="card p-4">
  <h4 class="mb-3">Change Password</h4>
  <p class="text-muted mb-4">Signed in as <strong><?= htmlspecialchars($username) ?></strong></p>

  <?php if ($success): ?>
    <div class="alert alert-success">Your password has been updated successfully.</div>
    <div class="d-flex" style="gap:10px;">
      <a class="btn btn-danger" href="home.php">Return to Dashboard</a>
      <a class="btn btn-outline-light" href="logout.php">Sign out now</a>
    </div>
  <?php else: ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate>
      <input type="hidden" name="csrf" value="<?= $CSRF ?>">
      <div class="form-group">
        <label>Current Password</label>
        <input type="password" name="current_password" class="form-control" required>
      </div>
      <div class="form-group">
        <label>New Password <small class="text-muted">(min 8 characters)</small></label>
        <input type="password" name="new_password" class="form-control" minlength="8" required>
      </div>
      <div class="form-group">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" class="form-control" minlength="8" required>
      </div>
      <button class="btn btn-danger btn-block">Update Password</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>