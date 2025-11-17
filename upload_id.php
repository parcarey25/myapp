<<<<<<< HEAD
<?php
// upload_id.php — Member/Trainer upload their Valid ID for verification
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__ . '/db.php';

$uid  = (int)$_SESSION['user_id'];

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

// Load current user ID status
$st = $conn->prepare("SELECT username, full_name, valid_id_path, valid_id_status, valid_id_note, valid_id_uploaded_at FROM users WHERE id=? LIMIT 1");
$st->bind_param('i', $uid);
$st->execute();
$res = $st->get_result();
$user = $res ? $res->fetch_assoc() : null;
if ($res) $res->free();
$st->close();

$okMsg = $errMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $errMsg = 'Invalid CSRF token.';
  } elseif (!isset($_FILES['valid_id']) || $_FILES['valid_id']['error'] !== UPLOAD_ERR_OK) {
    $errMsg = 'Please choose a file to upload.';
  } else {
    $file = $_FILES['valid_id'];

    // Size limit: 5 MB
    if ($file['size'] > 5 * 1024 * 1024) {
      $errMsg = 'File too large (max 5 MB).';
    } else {
      // Validate mime type via finfo
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime  = $finfo->file($file['tmp_name']) ?: '';
      $allowed = [
        'image/jpeg' => '.jpg',
        'image/png'  => '.png',
        'application/pdf' => '.pdf'
      ];
      if (!isset($allowed[$mime])) {
        $errMsg = 'Only JPG, PNG, or PDF allowed.';
      } else {
        // Make destination
        $ext = $allowed[$mime];
        $safeBase = __DIR__ . '/uploads/ids/' . $uid;
        if (!is_dir($safeBase)) {
          @mkdir($safeBase, 0755, true);
        }
        $name = 'valid_id_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . $ext;
        $destAbs = $safeBase . '/' . $name;
        $destRel = 'uploads/ids/' . $uid . '/' . $name;

        if (move_uploaded_file($file['tmp_name'], $destAbs)) {
          // Update user record: set pending
          $now = date('Y-m-d H:i:s');
          $st = $conn->prepare("UPDATE users SET valid_id_path=?, valid_id_status='pending', valid_id_note=NULL, valid_id_uploaded_at=? WHERE id=?");
          $st->bind_param('ssi', $destRel, $now, $uid);
          if ($st->execute()) {
            $okMsg = 'Valid ID uploaded successfully. Status is now PENDING for review.';
            // refresh $user
            $user['valid_id_path'] = $destRel;
            $user['valid_id_status'] = 'pending';
            $user['valid_id_note'] = null;
            $user['valid_id_uploaded_at'] = $now;
          } else {
            $errMsg = 'Database error while saving.';
          }
          $st->close();
        } else {
          $errMsg = 'Failed to save uploaded file.';
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
<title>Upload Valid ID | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#101010;color:#fff;font-family:'Poppins',sans-serif}
  .navbar{background:linear-gradient(90deg,#000,#b30000)}
  .card{background:#171717;border:1px solid #2a2a2a;border-radius:14px}
  .form-control{background:#121212;border:1px solid #2a2a2a;color:#eee}
  .btn-danger{background:#b30000;border:none}.btn-danger:hover{background:#ff1a1a}
  .muted{color:#bbb}
  .thumb{max-width:320px; max-height:320px; border:1px solid #2a2a2a; border-radius:8px}
</style>
</head>
<body>
<nav class="navbar navbar-dark">
  <a class="navbar-brand ml-3" href="home.php"><img src="photo/logo.jpg" height="28" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto mr-3">
    <a class="btn btn-outline-light btn-sm" href="home.php">Home</a>
    <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
  </div>
</nav>

<div class="container py-4">
  <h3 class="mb-3">Upload Valid ID</h3>

  <?php if ($okMsg): ?><div class="alert alert-success"><?= htmlspecialchars($okMsg) ?></div><?php endif; ?>
  <?php if ($errMsg): ?><div class="alert alert-danger"><?= htmlspecialchars($errMsg) ?></div><?php endif; ?>

  <div class="card p-3 mb-3">
    <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($user['full_name'] ?? $_SESSION['username']) ?></p>
    <p class="mb-1"><strong>Status:</strong> <?= htmlspecialchars(strtoupper($user['valid_id_status'] ?? 'NONE')) ?></p>
    <?php if (!empty($user['valid_id_note'])): ?>
      <p class="text-warning mb-1"><strong>Note:</strong> <?= htmlspecialchars($user['valid_id_note']) ?></p>
    <?php endif; ?>
    <?php if (!empty($user['valid_id_uploaded_at'])): ?>
      <p class="muted mb-0">Last upload: <?= htmlspecialchars($user['valid_id_uploaded_at']) ?></p>
    <?php endif; ?>
  </div>

  <?php if (!empty($user['valid_id_path'])): ?>
    <div class="mb-3">
      <?php if (preg_match('/\.pdf$/i', $user['valid_id_path'])): ?>
        <a class="btn btn-outline-light" href="<?= htmlspecialchars($user['valid_id_path']) ?>" target="_blank">View current PDF</a>
      <?php else: ?>
        <img class="thumb" src="<?= htmlspecialchars($user['valid_id_path']) ?>" alt="Current ID">
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card p-3">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= $CSRF ?>">
      <div class="form-group">
        <label>Choose Valid ID (JPG/PNG/PDF, max 5MB)</label>
        <input type="file" class="form-control" name="valid_id" accept=".jpg,.jpeg,.png,.pdf" required>
      </div>
      <button class="btn btn-danger">Upload / Replace</button>
      <p class="muted mt-2 mb-0">Tip: Make sure details are clear and not blurry.</p>
    </form>
  </div>
</div>
</body>

</html>