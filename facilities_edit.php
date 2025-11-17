<<<<<<< HEAD
<?php
// facilities_edit.php — edit a facility + upload image (staff/admin only)
session_start();
require __DIR__ . '/db.php';

$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['staff','admin'], true)) {
  http_response_code(403);
  echo "Forbidden (staff/admin only).";
  exit;
}

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

// Ensure table exists (safe guard)
$conn->query("CREATE TABLE IF NOT EXISTS facilities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(150) NOT NULL UNIQUE,
  visible_to ENUM('both','member','trainer') NOT NULL DEFAULT 'both',
  description TEXT NULL,
  image VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Load facility by id or slug
$fac = null;
if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
  $id = (int)$_GET['id'];
  $st = $conn->prepare("SELECT * FROM facilities WHERE id=?");
  $st->bind_param("i", $id);
  $st->execute(); $fac = $st->get_result()->fetch_assoc(); $st->close();
} elseif (!empty($_GET['slug'])) {
  $slug = trim($_GET['slug']);
  $st = $conn->prepare("SELECT * FROM facilities WHERE slug=?");
  $st->bind_param("s", $slug);
  $st->execute(); $fac = $st->get_result()->fetch_assoc(); $st->close();
}

if (!$fac) {
  echo "Facility not found. Pass ?id=123 or ?slug=boxing";
  exit;
}

$err = [];
$ok  = false;

// Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) $err[] = 'Invalid CSRF token.';

  $name        = trim($_POST['name'] ?? '');
  $slug        = strtolower(trim($_POST['slug'] ?? ''));
  $visible_to  = strtolower(trim($_POST['visible_to'] ?? 'both'));
  $description = trim($_POST['description'] ?? '');
  $is_active   = isset($_POST['is_active']) ? 1 : 0;

  if ($name === '') $err[] = 'Name is required.';
  if ($slug === '') $err[] = 'Slug is required.';
  if (!in_array($visible_to, ['both','member','trainer'], true)) $err[] = 'Invalid visibility.';

  // Handle optional image upload
  $imagePath = $fac['image']; // keep current by default
  if (!empty($_FILES['image']['name'])) {
    $uploadDir = __DIR__ . '/photo/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','gif'];
    if (!in_array($ext, $allowed, true)) {
      $err[] = 'Image must be JPG, PNG, WEBP, or GIF.';
    } else {
      $safeSlug = preg_replace('~[^a-z0-9\-]+~i', '-', $slug);
      $filename = 'facility_' . $safeSlug . '_' . time() . '.' . $ext;
      $destAbs  = $uploadDir . $filename;
      $destRel  = 'photo/' . $filename;

      if (!move_uploaded_file($_FILES['image']['tmp_name'], $destAbs)) {
        $err[] = 'Failed to save uploaded image.';
      } else {
        $imagePath = $destRel;
      }
    }
  }

  if (!$err) {
    $sql = "UPDATE facilities
            SET name=?, slug=?, visible_to=?, description=?, image=?, is_active=?
            WHERE id=?";
    $st = $conn->prepare($sql);
    $st->bind_param("ssssssi",
      $name, $slug, $visible_to, $description, $imagePath, $is_active, $fac['id']
    );
    if ($st->execute()) {
      $ok  = true;
      // reload latest row
      $fac = [
        'id' => $fac['id'],
        'name' => $name,
        'slug' => $slug,
        'visible_to' => $visible_to,
        'description' => $description,
        'image' => $imagePath,
        'is_active' => $is_active,
      ];
    } else {
      $err[] = 'DB error: ' . $conn->error;
    }
    $st->close();
  }
}

// Resolve current image to show
$imgRel = trim($fac['image'] ?? '');
if ($imgRel === '') $imgRel = 'photo/logo.jpg';
$abs = __DIR__ . '/' . str_replace(['\\','//'], '/', $imgRel);
if (!is_file($abs)) $imgRel = 'photo/logo.jpg';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit Facility | RJL Fitness</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
  .navbar{background:linear-gradient(90deg,#000,#b30000)}
  .card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}
  .form-control, .custom-file-input, .custom-select{background:#121212;border:1px solid #2a2a2a;color:#eee}
  .custom-file-label{background:#121212;border:1px solid #2a2a2a;color:#aaa}
  .btn-danger{background:#b30000;border:none}.btn-danger:hover{background:#ff1a1a}
  a,a:hover{color:#fff}
  .img-preview{border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;background:#0e0e0e}
  .img-preview img{width:100%;height:100%;object-fit:cover;display:block;min-height:240px}
</style>
</head>
<body>
<nav class="navbar navbar-dark">
  <a class="navbar-brand ml-3" href="home.php"><img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto mr-3">
    <a class="btn btn-outline-light btn-sm" href="facilities.php">Back to Facilities</a>
  </div>
</nav>

<div class="container my-4">
  <div class="card p-4 mx-auto" style="max-width:950px">
    <h4 class="mb-3">Edit Facility</h4>

    <?php if ($ok): ?>
      <div class="alert alert-success">Saved.</div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($err as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">

      <div class="form-row">
        <div class="col-md-7 mb-3">
          <label>Name</label>
          <input class="form-control" name="name" required value="<?= htmlspecialchars($fac['name']) ?>">
        </div>
        <div class="col-md-5 mb-3">
          <label>Slug</label>
          <input class="form-control" name="slug" required value="<?= htmlspecialchars($fac['slug']) ?>">
          <small class="text-muted">Lowercase, use dashes (e.g., boxing, muay-thai).</small>
        </div>
      </div>

      <div class="form-row">
        <div class="col-md-4 mb-3">
          <label>Visible To</label>
          <select class="custom-select" name="visible_to">
            <?php
              $vt = $fac['visible_to'] ?? 'both';
              $opts = ['both' => 'Member & Trainer', 'member' => 'Member only', 'trainer' => 'Trainer only'];
              foreach ($opts as $v=>$label) {
                $sel = $vt === $v ? 'selected' : '';
                echo "<option value=\"$v\" $sel>$label</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-md-4 mb-3 d-flex align-items-center">
          <div class="custom-control custom-switch mt-3">
            <input type="checkbox" class="custom-control-input" id="isActive" name="is_active" <?= !empty($fac['is_active']) ? 'checked' : '' ?>>
            <label class="custom-control-label" for="isActive">Active</label>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label>Description</label>
        <textarea class="form-control" rows="3" name="description"><?= htmlspecialchars($fac['description'] ?? '') ?></textarea>
      </div>

      <div class="form-row">
        <div class="col-md-6 mb-3">
          <label>Image (optional)</label>
          <div class="custom-file">
            <input type="file" class="custom-file-input" id="image" name="image" accept=".jpg,.jpeg,.png,.webp,.gif">
            <label class="custom-file-label" for="image">Choose image...</label>
          </div>
          <small class="form-text text-muted">Saved to /photo/ and referenced in the database.</small>
        </div>
        <div class="col-md-6 mb-3">
          <label>Current Image</label>
          <div class="img-preview"><img id="previewImg" src="<?= htmlspecialchars($imgRel) ?>?v=<?= time() ?>" alt=""></div>
        </div>
      </div>

      <button class="btn btn-danger">Save Changes</button>
      <a class="btn btn-outline-light ml-2" href="facilities.php">Cancel</a>
    </form>
  </div>
</div>

<script>
document.querySelector('.custom-file-input')?.addEventListener('change', function(){
  const label = this.nextElementSibling;
  if (label) label.textContent = this.files[0]?.name || 'Choose image...';

  // show live preview
  const file = this.files[0];
  if (!file) return;
  const img = document.getElementById('previewImg');
  const reader = new FileReader();
  reader.onload = () => { img.src = reader.result; };
  reader.readAsDataURL(file);
});
</script>
</body>
=======
<?php
// facilities_edit.php — edit a facility + upload image (staff/admin only)
session_start();
require __DIR__ . '/db.php';

$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['staff','admin'], true)) {
  http_response_code(403);
  echo "Forbidden (staff/admin only).";
  exit;
}

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

// Ensure table exists (safe guard)
$conn->query("CREATE TABLE IF NOT EXISTS facilities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(150) NOT NULL UNIQUE,
  visible_to ENUM('both','member','trainer') NOT NULL DEFAULT 'both',
  description TEXT NULL,
  image VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Load facility by id or slug
$fac = null;
if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
  $id = (int)$_GET['id'];
  $st = $conn->prepare("SELECT * FROM facilities WHERE id=?");
  $st->bind_param("i", $id);
  $st->execute(); $fac = $st->get_result()->fetch_assoc(); $st->close();
} elseif (!empty($_GET['slug'])) {
  $slug = trim($_GET['slug']);
  $st = $conn->prepare("SELECT * FROM facilities WHERE slug=?");
  $st->bind_param("s", $slug);
  $st->execute(); $fac = $st->get_result()->fetch_assoc(); $st->close();
}

if (!$fac) {
  echo "Facility not found. Pass ?id=123 or ?slug=boxing";
  exit;
}

$err = [];
$ok  = false;

// Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) $err[] = 'Invalid CSRF token.';

  $name        = trim($_POST['name'] ?? '');
  $slug        = strtolower(trim($_POST['slug'] ?? ''));
  $visible_to  = strtolower(trim($_POST['visible_to'] ?? 'both'));
  $description = trim($_POST['description'] ?? '');
  $is_active   = isset($_POST['is_active']) ? 1 : 0;

  if ($name === '') $err[] = 'Name is required.';
  if ($slug === '') $err[] = 'Slug is required.';
  if (!in_array($visible_to, ['both','member','trainer'], true)) $err[] = 'Invalid visibility.';

  // Handle optional image upload
  $imagePath = $fac['image']; // keep current by default
  if (!empty($_FILES['image']['name'])) {
    $uploadDir = __DIR__ . '/photo/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','gif'];
    if (!in_array($ext, $allowed, true)) {
      $err[] = 'Image must be JPG, PNG, WEBP, or GIF.';
    } else {
      $safeSlug = preg_replace('~[^a-z0-9\-]+~i', '-', $slug);
      $filename = 'facility_' . $safeSlug . '_' . time() . '.' . $ext;
      $destAbs  = $uploadDir . $filename;
      $destRel  = 'photo/' . $filename;

      if (!move_uploaded_file($_FILES['image']['tmp_name'], $destAbs)) {
        $err[] = 'Failed to save uploaded image.';
      } else {
        $imagePath = $destRel;
      }
    }
  }

  if (!$err) {
    $sql = "UPDATE facilities
            SET name=?, slug=?, visible_to=?, description=?, image=?, is_active=?
            WHERE id=?";
    $st = $conn->prepare($sql);
    $st->bind_param("ssssssi",
      $name, $slug, $visible_to, $description, $imagePath, $is_active, $fac['id']
    );
    if ($st->execute()) {
      $ok  = true;
      // reload latest row
      $fac = [
        'id' => $fac['id'],
        'name' => $name,
        'slug' => $slug,
        'visible_to' => $visible_to,
        'description' => $description,
        'image' => $imagePath,
        'is_active' => $is_active,
      ];
    } else {
      $err[] = 'DB error: ' . $conn->error;
    }
    $st->close();
  }
}

// Resolve current image to show
$imgRel = trim($fac['image'] ?? '');
if ($imgRel === '') $imgRel = 'photo/logo.jpg';
$abs = __DIR__ . '/' . str_replace(['\\','//'], '/', $imgRel);
if (!is_file($abs)) $imgRel = 'photo/logo.jpg';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit Facility | RJL Fitness</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
  .navbar{background:linear-gradient(90deg,#000,#b30000)}
  .card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}
  .form-control, .custom-file-input, .custom-select{background:#121212;border:1px solid #2a2a2a;color:#eee}
  .custom-file-label{background:#121212;border:1px solid #2a2a2a;color:#aaa}
  .btn-danger{background:#b30000;border:none}.btn-danger:hover{background:#ff1a1a}
  a,a:hover{color:#fff}
  .img-preview{border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;background:#0e0e0e}
  .img-preview img{width:100%;height:100%;object-fit:cover;display:block;min-height:240px}
</style>
</head>
<body>
<nav class="navbar navbar-dark">
  <a class="navbar-brand ml-3" href="home.php"><img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto mr-3">
    <a class="btn btn-outline-light btn-sm" href="facilities.php">Back to Facilities</a>
  </div>
</nav>

<div class="container my-4">
  <div class="card p-4 mx-auto" style="max-width:950px">
    <h4 class="mb-3">Edit Facility</h4>

    <?php if ($ok): ?>
      <div class="alert alert-success">Saved.</div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($err as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">

      <div class="form-row">
        <div class="col-md-7 mb-3">
          <label>Name</label>
          <input class="form-control" name="name" required value="<?= htmlspecialchars($fac['name']) ?>">
        </div>
        <div class="col-md-5 mb-3">
          <label>Slug</label>
          <input class="form-control" name="slug" required value="<?= htmlspecialchars($fac['slug']) ?>">
          <small class="text-muted">Lowercase, use dashes (e.g., boxing, muay-thai).</small>
        </div>
      </div>

      <div class="form-row">
        <div class="col-md-4 mb-3">
          <label>Visible To</label>
          <select class="custom-select" name="visible_to">
            <?php
              $vt = $fac['visible_to'] ?? 'both';
              $opts = ['both' => 'Member & Trainer', 'member' => 'Member only', 'trainer' => 'Trainer only'];
              foreach ($opts as $v=>$label) {
                $sel = $vt === $v ? 'selected' : '';
                echo "<option value=\"$v\" $sel>$label</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-md-4 mb-3 d-flex align-items-center">
          <div class="custom-control custom-switch mt-3">
            <input type="checkbox" class="custom-control-input" id="isActive" name="is_active" <?= !empty($fac['is_active']) ? 'checked' : '' ?>>
            <label class="custom-control-label" for="isActive">Active</label>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label>Description</label>
        <textarea class="form-control" rows="3" name="description"><?= htmlspecialchars($fac['description'] ?? '') ?></textarea>
      </div>

      <div class="form-row">
        <div class="col-md-6 mb-3">
          <label>Image (optional)</label>
          <div class="custom-file">
            <input type="file" class="custom-file-input" id="image" name="image" accept=".jpg,.jpeg,.png,.webp,.gif">
            <label class="custom-file-label" for="image">Choose image...</label>
          </div>
          <small class="form-text text-muted">Saved to /photo/ and referenced in the database.</small>
        </div>
        <div class="col-md-6 mb-3">
          <label>Current Image</label>
          <div class="img-preview"><img id="previewImg" src="<?= htmlspecialchars($imgRel) ?>?v=<?= time() ?>" alt=""></div>
        </div>
      </div>

      <button class="btn btn-danger">Save Changes</button>
      <a class="btn btn-outline-light ml-2" href="facilities.php">Cancel</a>
    </form>
  </div>
</div>

<script>
document.querySelector('.custom-file-input')?.addEventListener('change', function(){
  const label = this.nextElementSibling;
  if (label) label.textContent = this.files[0]?.name || 'Choose image...';

  // show live preview
  const file = this.files[0];
  if (!file) return;
  const img = document.getElementById('previewImg');
  const reader = new FileReader();
  reader.onload = () => { img.src = reader.result; };
  reader.readAsDataURL(file);
});
</script>
</body>
>>>>>>> b78dc527f4ca1b402224214aa4f78775c370647f
</html>