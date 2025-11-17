<<<<<<< HEAD
<?php
// upload_avatar.php — change profile picture (JPG/PNG/GIF/WEBP ≤ 2MB)
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__ . '/db.php';

$userId   = (int)$_SESSION['user_id'];
$maxBytes = 2 * 1024 * 1024;
$allowed  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];

$errors = [];
$success = false;

// Get current avatar (so we can delete it if replaced)
$currentAvatar = null;
if ($st = $conn->prepare("SELECT avatar_path FROM users WHERE id=? LIMIT 1")) {
  $st->bind_param('i', $userId);
  $st->execute();
  $st->bind_result($cur);
  if ($st->fetch()) $currentAvatar = $cur ?: null;
  $st->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = 'Please choose an image to upload.';
  } else {
    $f = $_FILES['avatar'];

    // Size check
    if ($f['size'] > $maxBytes) {
      $errors[] = 'File is too large (max 2MB).';
    }

    // Real MIME check
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($f['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) {
      $errors[] = 'Only JPG, PNG, GIF, or WEBP images are allowed.';
    }

    if (!$errors) {
      // Ensure destination folder
      $dirFs  = __DIR__ . '/uploads/avatars';
      $dirWeb = 'uploads/avatars';
      if (!is_dir($dirFs) && !mkdir($dirFs, 0755, true)) {
        $errors[] = 'Cannot create upload folder.';
      }

      if (!$errors) {
        // Unique file name
        $ext   = $allowed[$mime];
        $name  = 'u' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $pathFs  = $dirFs . '/' . $name;
        $pathWeb = $dirWeb . '/' . $name;

        if (!move_uploaded_file($f['tmp_name'], $pathFs)) {
          $errors[] = 'Failed to save uploaded file.';
        } else {
          // Delete previous avatar if it was in uploads/avatars
          if ($currentAvatar && strpos($currentAvatar, 'uploads/avatars/') === 0) {
            $oldFs = __DIR__ . '/' . $currentAvatar;
            if (is_file($oldFs)) @unlink($oldFs);
          }

          // Save path to DB
          if ($st = $conn->prepare("UPDATE users SET avatar_path=? WHERE id=?")) {
            $st->bind_param('si', $pathWeb, $userId);
            if ($st->execute()) {
              $success = true;
              $currentAvatar = $pathWeb;
              // Optional: session mirror
              $_SESSION['avatar_path'] = $pathWeb;
            } else {
              $errors[] = 'Database error updating avatar.';
            }
            $st->close();
          } else {
            $errors[] = 'Database error preparing update.';
          }
        }
      }
    }
  }
}

$fallback = 'photo/logo.jpg';
$preview  = $currentAvatar ?: $fallback;
$username = $_SESSION['username'] ?? 'User';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Change Profile Picture | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
:root{ --brand:#b30000; --hover:#ff1a1a; --bg:#111; --panel:#1a1a1a; --line:#2a2a2a; }
body{ background:#111; color:#fff; font-family:'Poppins',sans-serif; min-height:100vh; }
.navbar{ background:linear-gradient(90deg,#000,var(--brand)); }
.card{ background:#1a1a1a; border:1px solid #2a2a2a; border-radius:14px; max-width:560px; margin:40px auto; }
.avatar{ width:120px; height:120px; border-radius:50%; overflow:hidden; border:2px solid #ff3333; margin:0 auto 12px; }
.avatar img{ width:100%; height:100%; object-fit:cover; }
.btn-danger{ background:var(--brand); border:none; } .btn-danger:hover{ background:var(--hover); }
.form-control{ background:#121212; border:1px solid #2a2a2a; color:#eee; }
</style>
</head>
<body>
<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand" href="home.php"><img src="photo/logo.jpg" height="30" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto"><a class="btn btn-outline-light btn-sm" href="home.php">Back to Dashboard</a></div>
</nav>

<div class="card p-4">
  <h4 class="mb-1">Change Profile Picture</h4>
  <small class="text-muted">Signed in as <strong><?= htmlspecialchars($username) ?></strong></small>

  <?php if ($success): ?>
    <div class="alert alert-success mt-3">Your profile picture was updated.</div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger mt-3"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="avatar mt-3"><img src="<?= htmlspecialchars($preview) ?>" alt="Current avatar"></div>
  <form method="post" enctype="multipart/form-data" class="mt-3">
    <div class="form-group">
      <label>Choose image (JPG/PNG/GIF/WEBP ≤ 2MB)</label>
      <input type="file" name="avatar" class="form-control-file" accept="image/*" required>
    </div>
    <button class="btn btn-danger btn-block">Upload</button>
  </form>
</div>
</body>
=======
<?php
// upload_avatar.php — change profile picture (JPG/PNG/GIF/WEBP ≤ 2MB)
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__ . '/db.php';

$userId   = (int)$_SESSION['user_id'];
$maxBytes = 2 * 1024 * 1024;
$allowed  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];

$errors = [];
$success = false;

// Get current avatar (so we can delete it if replaced)
$currentAvatar = null;
if ($st = $conn->prepare("SELECT avatar_path FROM users WHERE id=? LIMIT 1")) {
  $st->bind_param('i', $userId);
  $st->execute();
  $st->bind_result($cur);
  if ($st->fetch()) $currentAvatar = $cur ?: null;
  $st->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = 'Please choose an image to upload.';
  } else {
    $f = $_FILES['avatar'];

    // Size check
    if ($f['size'] > $maxBytes) {
      $errors[] = 'File is too large (max 2MB).';
    }

    // Real MIME check
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($f['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) {
      $errors[] = 'Only JPG, PNG, GIF, or WEBP images are allowed.';
    }

    if (!$errors) {
      // Ensure destination folder
      $dirFs  = __DIR__ . '/uploads/avatars';
      $dirWeb = 'uploads/avatars';
      if (!is_dir($dirFs) && !mkdir($dirFs, 0755, true)) {
        $errors[] = 'Cannot create upload folder.';
      }

      if (!$errors) {
        // Unique file name
        $ext   = $allowed[$mime];
        $name  = 'u' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $pathFs  = $dirFs . '/' . $name;
        $pathWeb = $dirWeb . '/' . $name;

        if (!move_uploaded_file($f['tmp_name'], $pathFs)) {
          $errors[] = 'Failed to save uploaded file.';
        } else {
          // Delete previous avatar if it was in uploads/avatars
          if ($currentAvatar && strpos($currentAvatar, 'uploads/avatars/') === 0) {
            $oldFs = __DIR__ . '/' . $currentAvatar;
            if (is_file($oldFs)) @unlink($oldFs);
          }

          // Save path to DB
          if ($st = $conn->prepare("UPDATE users SET avatar_path=? WHERE id=?")) {
            $st->bind_param('si', $pathWeb, $userId);
            if ($st->execute()) {
              $success = true;
              $currentAvatar = $pathWeb;
              // Optional: session mirror
              $_SESSION['avatar_path'] = $pathWeb;
            } else {
              $errors[] = 'Database error updating avatar.';
            }
            $st->close();
          } else {
            $errors[] = 'Database error preparing update.';
          }
        }
      }
    }
  }
}

$fallback = 'photo/logo.jpg';
$preview  = $currentAvatar ?: $fallback;
$username = $_SESSION['username'] ?? 'User';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Change Profile Picture | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
:root{ --brand:#b30000; --hover:#ff1a1a; --bg:#111; --panel:#1a1a1a; --line:#2a2a2a; }
body{ background:#111; color:#fff; font-family:'Poppins',sans-serif; min-height:100vh; }
.navbar{ background:linear-gradient(90deg,#000,var(--brand)); }
.card{ background:#1a1a1a; border:1px solid #2a2a2a; border-radius:14px; max-width:560px; margin:40px auto; }
.avatar{ width:120px; height:120px; border-radius:50%; overflow:hidden; border:2px solid #ff3333; margin:0 auto 12px; }
.avatar img{ width:100%; height:100%; object-fit:cover; }
.btn-danger{ background:var(--brand); border:none; } .btn-danger:hover{ background:var(--hover); }
.form-control{ background:#121212; border:1px solid #2a2a2a; color:#eee; }
</style>
</head>
<body>
<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand" href="home.php"><img src="photo/logo.jpg" height="30" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto"><a class="btn btn-outline-light btn-sm" href="home.php">Back to Dashboard</a></div>
</nav>

<div class="card p-4">
  <h4 class="mb-1">Change Profile Picture</h4>
  <small class="text-muted">Signed in as <strong><?= htmlspecialchars($username) ?></strong></small>

  <?php if ($success): ?>
    <div class="alert alert-success mt-3">Your profile picture was updated.</div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger mt-3"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="avatar mt-3"><img src="<?= htmlspecialchars($preview) ?>" alt="Current avatar"></div>
  <form method="post" enctype="multipart/form-data" class="mt-3">
    <div class="form-group">
      <label>Choose image (JPG/PNG/GIF/WEBP ≤ 2MB)</label>
      <input type="file" name="avatar" class="form-control-file" accept="image/*" required>
    </div>
    <button class="btn btn-danger btn-block">Upload</button>
  </form>
</div>
</body>
>>>>>>> b78dc527f4ca1b402224214aa4f78775c370647f
</html>