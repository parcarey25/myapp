<?php
// schedules.php — Save booking (if POST from facilities.php) + List bookings (GET) + Edit/Delete actions.
// Permissions: staff/admin can manage all bookings; member/trainer only their own.
// CSRF protected edit/delete.

session_start();
require __DIR__ . '/db.php';

// ---------- Ensure bookings table exists (safe every time) ----------
$conn->query("
  CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facility_slug VARCHAR(100) NOT NULL,
    facility_name VARCHAR(150) NOT NULL,
    date DATE NOT NULL,
    time VARCHAR(20) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ---------- Helpers ----------
function clean($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function is_admin_like($role){ return in_array(strtolower($role), ['staff','admin'], true); }
$role   = strtolower($_SESSION['role'] ?? 'member');
$uid    = $_SESSION['user_id'] ?? null;
$uemail = $_SESSION['email'] ?? null;

// CSRF token
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['csrf'];

// Flash message helpers
function flash_set($msg,$type='success'){ $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }
function flash_get(){ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

// ---------- If POST booking from facilities.php: save then show confirmation ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['facility_slug']) && !isset($_GET['action'])) {
  $facility_slug = trim($_POST['facility_slug'] ?? '');
  $facility_name = trim($_POST['facility_name'] ?? '');
  $date          = trim($_POST['date'] ?? '');
  $time          = trim($_POST['time'] ?? '');
  $full_name     = trim($_POST['full_name'] ?? '');
  $email         = trim($_POST['email'] ?? '');
  $notes         = trim($_POST['notes'] ?? '');

  $errors = [];
  if ($facility_slug === '' || $facility_name === '') $errors[] = 'Missing facility information.';
  if ($date === '') $errors[] = 'Please choose a date.';
  if ($time === '') $errors[] = 'Please choose a time.';
  if ($full_name === '') $errors[] = 'Full name is required.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

  $booking_id = null;
  if (!$errors) {
    if ($st = $conn->prepare("
      INSERT INTO bookings (facility_slug, facility_name, date, time, full_name, email, notes, user_id)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")) {
      $uid_i = $uid ?: null;
      $st->bind_param("sssssssi", $facility_slug, $facility_name, $date, $time, $full_name, $email, $notes, $uid_i);
      if ($st->execute()) { $booking_id = $st->insert_id; }
      else { $errors[] = 'Could not save booking (DB error).'; }
      $st->close();
    } else { $errors[] = 'Database error (prepare failed).'; }
  }
  ?>
  <!doctype html>
  <html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Booking <?= $errors ? 'Error' : 'Confirmation' ?> | RJL Fitness</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
    <style>
      body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
      .card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}
      .ok{color:#9cff9c}.bad{color:#ff9b9b}
      .btn-danger{background:#b30000;border:none}.btn-danger:hover{background:#ff1a1a}
      a,a:hover{color:#fff}.navbar{background:linear-gradient(90deg,#000,#b30000)}
    </style>
  </head>
  <body>
    <nav class="navbar navbar-dark">
      <a class="navbar-brand ml-3" href="home.php"><img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness</a>
      <div class="ml-auto mr-3">
        <a class="btn btn-outline-light btn-sm" href="facilities.php">Facilities</a>
        <a class="btn btn-danger btn-sm" href="schedules.php">View Bookings</a>
      </div>
    </nav>
    <div class="container my-4">
      <div class="card p-4 mx-auto" style="max-width:780px">
        <?php if ($errors): ?>
          <h4 class="bad mb-3">Booking not saved</h4>
          <ul class="mb-3"><?php foreach ($errors as $e): ?><li><?= clean($e) ?></li><?php endforeach; ?></ul>
          <a class="btn btn-danger" href="javascript:history.back()">Go Back</a>
        <?php else: ?>
          <h4 class="ok mb-1">Booking received ✅</h4>
          <p class="text-muted">Thanks! Here are your details:</p>
          <div class="table-responsive">
            <table class="table table-dark table-sm">
              <tbody>
                <?php if ($booking_id): ?><tr><th>ID</th><td>#<?= (int)$booking_id ?></td></tr><?php endif; ?>
                <tr><th>Facility</th><td><?= clean($facility_name) ?> <span class="text-muted">(<?= clean($facility_slug) ?>)</span></td></tr>
                <tr><th>Date</th><td><?= clean($date) ?></td></tr>
                <tr><th>Time</th><td><?= clean($time) ?></td></tr>
                <tr><th>Name</th><td><?= clean($full_name) ?></td></tr>
                <tr><th>Email</th><td><?= clean($email) ?></td></tr>
                <?php if ($notes !== ''): ?><tr><th>Notes</th><td><?= nl2br(clean($notes)) ?></td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex justify-content-between">
            <a class="btn btn-outline-light" href="facilities.php">Back to Facilities</a>
            <a class="btn btn-danger" href="schedules.php">View My Bookings</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </body></html>
  <?php
  exit;
}

// ---------- EDIT / DELETE actions (GET/POST) ----------
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Load a booking row helper (for edit/delete)
function load_booking(mysqli $conn, int $id) {
  $st = $conn->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");
  $st->bind_param('i', $id);
  $st->execute(); $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  if ($res) $res->free(); $st->close();
  return $row;
}

// Permission check helper
function can_manage($row, $role, $uid, $uemail) {
  if (is_admin_like($role)) return true;
  if (!$row) return false;
  if ($uid && $row['user_id'] && (int)$row['user_id'] === (int)$uid) return true;
  if ($uemail && strcasecmp($row['email'], $uemail) === 0) return true;
  return false;
}

// Handle Delete POST
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) { http_response_code(400); die('CSRF token invalid.'); }
  $row = load_booking($conn, $id);
  if (!$row || !can_manage($row,$role,$uid,$uemail)) { http_response_code(403); die('Not allowed.'); }
  $st = $conn->prepare("DELETE FROM bookings WHERE id=?");
  $st->bind_param('i', $id);
  $ok = $st->execute();
  $st->close();
  flash_set($ok ? 'Booking deleted.' : 'Delete failed.','danger');
  header('Location: schedules.php'); exit;
}

// Handle Edit POST (update date/time/notes; name/email optional toggle)
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) { http_response_code(400); die('CSRF token invalid.'); }
  $row = load_booking($conn, $id);
  if (!$row || !can_manage($row,$role,$uid,$uemail)) { http_response_code(403); die('Not allowed.'); }

  $date  = trim($_POST['date'] ?? '');
  $time  = trim($_POST['time'] ?? '');
  $notes = trim($_POST['notes'] ?? '');
  $full_name = trim($_POST['full_name'] ?? $row['full_name']);
  $email     = trim($_POST['email'] ?? $row['email']);

  $errs = [];
  if ($date==='') $errs[]='Date required.';
  if ($time==='') $errs[]='Time required.';
  if ($full_name==='') $errs[]='Name required.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[]='Valid email required.';

  if ($errs) {
    flash_set(implode(' ', $errs),'danger');
    header("Location: schedules.php?action=edit&id={$id}");
    exit;
  }

  $st = $conn->prepare("UPDATE bookings SET date=?, time=?, notes=?, full_name=?, email=? WHERE id=?");
  $st->bind_param('sssssi', $date, $time, $notes, $full_name, $email, $id);
  $ok = $st->execute();
  $st->close();

  flash_set($ok ? 'Booking updated.' : 'Update failed.','success');
  header('Location: schedules.php'); exit;
}

// If GET action=edit: render edit form for that row
if ($action === 'edit' && $id > 0) {
  $row = load_booking($conn, $id);
  if (!$row || !can_manage($row,$role,$uid,$uemail)) { http_response_code(403); die('Not allowed.'); }
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Booking #<?= (int)$row['id'] ?> | RJL Fitness</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
    <style>
      body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
      .navbar{background:linear-gradient(90deg,#000,#b30000)}
      .card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}
      .btn-danger{background:#b30000;border:none}.btn-danger:hover{background:#ff1a1a}
      .form-control{background:#121212;border:1px solid #2a2a2a;color:#eee}
      a,a:hover{color:#fff}
    </style>
  </head>
  <body>
    <nav class="navbar navbar-dark">
      <a class="navbar-brand ml-3" href="home.php"><img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness</a>
      <div class="ml-auto mr-3">
        <a class="btn btn-outline-light btn-sm" href="schedules.php">Back to Bookings</a>
      </div>
    </nav>
    <div class="container my-4">
      <div class="card p-4 mx-auto" style="max-width:760px">
        <h4 class="mb-3">Edit Booking #<?= (int)$row['id'] ?></h4>
        <p class="text-muted mb-2">
          <strong><?= clean($row['facility_name']) ?></strong>
          <span class="text-muted">(<?= clean($row['facility_slug']) ?>)</span>
        </p>
        <?php if ($f = flash_get()): ?>
          <div class="alert alert-<?= $f['type']==='danger'?'danger':'success' ?>"><?= clean($f['msg']) ?></div>
        <?php endif; ?>
        <form method="post" action="schedules.php?action=edit&id=<?= (int)$row['id'] ?>">
          <input type="hidden" name="csrf" value="<?= $CSRF ?>">
          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Date</label>
              <input type="date" name="date" class="form-control" value="<?= clean($row['date']) ?>" required>
            </div>
            <div class="form-group col-md-6">
              <label>Time</label>
              <input type="text" name="time" class="form-control" value="<?= clean($row['time']) ?>" placeholder="e.g., 18:00" required>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Full name</label>
              <input name="full_name" class="form-control" value="<?= clean($row['full_name']) ?>" required>
            </div>
            <div class="form-group col-md-6">
              <label>Email</label>
              <input type="email" name="email" class="form-control" value="<?= clean($row['email']) ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" rows="3" class="form-control" placeholder="Optional"><?= clean($row['notes']) ?></textarea>
          </div>
          <div class="d-flex justify-content-between">
            <a class="btn btn-outline-light" href="schedules.php">Cancel</a>
            <button class="btn btn-danger">Save Changes</button>
          </div>
        </form>
        <hr>
        <form method="post" action="schedules.php?action=delete&id=<?= (int)$row['id'] ?>" onsubmit="return confirm('Delete this booking?');">
          <input type="hidden" name="csrf" value="<?= $CSRF ?>">
          <button class="btn btn-outline-danger">Delete Booking</button>
        </form>
      </div>
    </div>
  </body></html>
  <?php
  exit;
}

// If GET action=delete (confirm screen) — optional, skip and rely on button confirm in list.
// We’ll manage delete via POST from the list row confirmation dialog.

// ---------- If GET: list bookings with Edit/Delete buttons ----------
$isAdmin = is_admin_like($role);

// Filters for staff/admin
$filter_fac = ''; $filter_date = '';
if ($isAdmin) {
  $filter_fac = trim($_GET['facility'] ?? '');
  $filter_date = trim($_GET['date'] ?? '');
}

$rows = [];
if ($isAdmin) {
  $sql = "SELECT id, facility_name, facility_slug, date, time, full_name, email, notes, user_id, created_at
          FROM bookings WHERE 1=1";
  $params = []; $types = '';
  if ($filter_fac !== '') { $sql .= " AND facility_slug = ?"; $params[] = $filter_fac; $types .= 's'; }
  if ($filter_date !== '') { $sql .= " AND date = ?"; $params[] = $filter_date; $types .= 's'; }
  $sql .= " ORDER BY created_at DESC";
  if ($types) { $st = $conn->prepare($sql); $st->bind_param($types, ...$params); $st->execute(); $res = $st->get_result(); }
  else { $res = $conn->query($sql); }
  if ($res) { $rows = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }
  if (isset($st)) $st->close();
} else {
  if ($uid) {
    $st = $conn->prepare("SELECT id, facility_name, facility_slug, date, time, full_name, email, notes, user_id, created_at
                          FROM bookings WHERE user_id=? ORDER BY created_at DESC");
    $st->bind_param('i', $uid);
    $st->execute(); $res = $st->get_result();
    if ($res) { $rows = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }
    $st->close();
  } elseif ($uemail) {
    $st = $conn->prepare("SELECT id, facility_name, facility_slug, date, time, full_name, email, notes, user_id, created_at
                          FROM bookings WHERE email=? ORDER BY created_at DESC");
    $st->bind_param('s', $uemail);
    $st->execute(); $res = $st->get_result();
    if ($res) { $rows = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }
    $st->close();
  }
}

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $isAdmin ? 'All' : 'My' ?> Bookings | RJL Fitness</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
  body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
  .navbar{background:linear-gradient(90deg,#000,#b30000)}
  .card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}
  .btn-danger{background:#b30000;border:none}.btn-danger:hover{background:#ff1a1a}
  .btn-ghost{background:transparent;border:1px solid #444;color:#eee}
  .btn-ghost:hover{background:#1e1e1e}
  .muted{color:#9aa0a6} a,a:hover{color:#fff}
  .filter-bar .form-control{background:#121212;border:1px solid #2a2a2a;color:#eee}
  .actions{white-space:nowrap}
  .actions form{display:inline}
</style>
</head>
<body>
<nav class="navbar navbar-dark">
  <a class="navbar-brand ml-3" href="home.php"><img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto mr-3">
    <a class="btn btn-outline-light btn-sm" href="facilities.php">Facilities</a>
    <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
  </div>
</nav>

<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3><?= $isAdmin ? 'All Bookings' : 'My Bookings' ?></h3>
    <?php if (!$isAdmin): ?><small class="muted">Showing bookings for your account.</small><?php endif; ?>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type']==='danger'?'danger':'success' ?>"><?= clean($flash['msg']) ?></div>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
    <form method="get" class="card p-3 mb-3 filter-bar">
      <div class="form-row">
        <div class="col-md-5">
          <label class="mb-1">Facility</label>
          <input class="form-control" name="facility" value="<?= clean($filter_fac) ?>" placeholder="e.g., boxing, muay-thai">
        </div>
        <div class="col-md-5">
          <label class="mb-1">Date</label>
          <input type="date" class="form-control" name="date" value="<?= clean($filter_date) ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-danger btn-block">Filter</button>
        </div>
      </div>
    </form>
  <?php endif; ?>

  <div class="card p-3">
    <?php if (!$rows): ?>
      <div class="alert alert-secondary mb-0">No bookings found.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-dark table-striped table-hover table-sm mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Facility</th>
              <th>Date</th>
              <th>Time</th>
              <?php if ($isAdmin): ?><th>Name</th><th>Email</th><?php endif; ?>
              <th>Created</th>
              <?php if ($isAdmin): ?><th>User ID</th><?php endif; ?>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= clean($r['facility_name']) ?> <span class="text-muted">(<?= clean($r['facility_slug']) ?>)</span></td>
                <td><?= clean($r['date']) ?></td>
                <td><?= clean($r['time']) ?></td>
                <?php if ($isAdmin): ?>
                  <td><?= clean($r['full_name']) ?></td>
                  <td><?= clean($r['email']) ?></td>
                <?php endif; ?>
                <td><?= clean($r['created_at']) ?></td>
                <?php if ($isAdmin): ?>
                  <td><?= $r['user_id'] !== null ? (int)$r['user_id'] : '—' ?></td>
                <?php endif; ?>
                <td class="text-right actions">
                  <a class="btn btn-ghost btn-sm" href="schedules.php?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                  <form method="post" action="schedules.php?action=delete&id=<?= (int)$r['id'] ?>" onsubmit="return confirm('Delete booking #<?= (int)$r['id'] ?>?');">
                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="mt-3 d-flex justify-content-between">
    <a class="btn btn-outline-light" href="facilities.php">Back to Facilities</a>
    <a class="btn btn-danger" href="home.php">Go to Dashboard</a>
  </div>
</div>
</body>
</html>