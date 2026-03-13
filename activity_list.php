<?php
// activity_list.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/db.php';

// Only members can use this page
$role = strtolower($_SESSION['role'] ?? 'member');
if ($role !== 'member') {
    header('Location: home.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

/* ---------- Load basic user info (for avatar & header) ---------- */
$user = [
    'username'    => $_SESSION['username'] ?? 'member',
    'full_name'   => '',
    'avatar_path' => '',
];

if ($st = $conn->prepare("SELECT full_name, avatar_path FROM users WHERE id = ? LIMIT 1")) {
    $st->bind_param('i', $userId);
    $st->execute();
    if ($res = $st->get_result()) {
        if ($row = $res->fetch_assoc()) {
            $user['full_name']   = $row['full_name'] ?? '';
            $user['avatar_path'] = $row['avatar_path'] ?? '';
        }
        $res->free();
    }
    $st->close();
}

$displayName = $user['full_name'] ?: $user['username'];
$avatarPath  = $user['avatar_path'] ?: 'photo/logo.jpg';

/* ---------- Helpers ---------- */
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ---------- Date handling ---------- */
$today = new DateTime('today'); // server date
if (!empty($_GET['date'])) {
    $d = DateTime::createFromFormat('Y-m-d', $_GET['date']);
    if ($d) $today = $d;
}
$selectedDate = $today->format('Y-m-d');

/* ---------- Handle POST (meals + daily note) ---------- */
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Save meal (breakfast / lunch / dinner)
    if (isset($_POST['action']) && $_POST['action'] === 'save_meal') {
        $meal   = $_POST['meal'] ?? '';
        $notes  = trim($_POST['notes'] ?? '');
        $dateIn = $_POST['activity_date'] ?? $selectedDate;

        if (!in_array($meal, ['breakfast', 'lunch', 'dinner'], true)) {
            $flash = 'Invalid meal type.';
        } else {
            $actDate = DateTime::createFromFormat('Y-m-d', $dateIn);
            if (!$actDate) {
                $flash = 'Invalid date.';
            } else {
                $dateStr   = $actDate->format('Y-m-d');
                $photoPath = null;

                // Handle upload (optional)
                if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $allowed = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/gif'  => 'gif',
                        'image/webp' => 'webp'
                    ];
                    $fi   = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $fi->file($_FILES['photo']['tmp_name']) ?: '';
                    if (!isset($allowed[$mime])) {
                        $flash = 'Meal photo must be JPG/PNG/GIF/WEBP.';
                    } else {
                        $size = (int)$_FILES['photo']['size'];
                        if ($size > 4 * 1024 * 1024) {
                            $flash = 'Meal photo too large (max 4MB).';
                        } else {
                            $dirFs  = __DIR__ . '/uploads/activities';
                            $dirWeb = 'uploads/activities';
                            if (!is_dir($dirFs)) {
                                mkdir($dirFs, 0755, true);
                            }
                            $ext = $allowed[$mime];
                            $name = 'meal_' . $userId . '_' . $dateStr . '_' . $meal . '_' . time() . '.' . $ext;
                            $dstFs  = $dirFs . '/' . $name;
                            $dstWeb = $dirWeb . '/' . $name;

                            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dstFs)) {
                                $flash = 'Failed to save meal photo.';
                            } else {
                                $photoPath = $dstWeb;
                            }
                        }
                    }
                }

                if ($flash === '') {
                    // Upsert into member_activities
                    if ($st = $conn->prepare("SELECT id, photo_path FROM member_activities WHERE user_id=? AND activity_date=? AND meal_type=? LIMIT 1")) {
                        $st->bind_param('iss', $userId, $dateStr, $meal);
                        $st->execute();
                        $existingId = 0;
                        $oldPhoto   = null;
                        if ($res = $st->get_result()) {
                            if ($row = $res->fetch_assoc()) {
                                $existingId = (int)$row['id'];
                                $oldPhoto   = $row['photo_path'] ?? null;
                            }
                            $res->free();
                        }
                        $st->close();

                        if ($existingId > 0) {
                            // Update
                            if ($photoPath === null) {
                                $sql = "UPDATE member_activities SET notes=? WHERE id=?";
                                $st2 = $conn->prepare($sql);
                                $st2->bind_param('si', $notes, $existingId);
                            } else {
                                $sql = "UPDATE member_activities SET notes=?, photo_path=? WHERE id=?";
                                $st2 = $conn->prepare($sql);
                                $st2->bind_param('ssi', $notes, $photoPath, $existingId);

                                // optionally delete old file
                                if ($oldPhoto && strpos($oldPhoto, 'uploads/activities/') === 0) {
                                    $oldFs = __DIR__ . '/' . $oldPhoto;
                                    if (is_file($oldFs)) @unlink($oldFs);
                                }
                            }
                            $st2->execute();
                            $st2->close();
                        } else {
                            // Insert
                            $sql = "INSERT INTO member_activities (user_id, activity_date, meal_type, photo_path, notes)
                                    VALUES (?,?,?,?,?)";
                            $st2 = $conn->prepare($sql);
                            $st2->bind_param(
                                'issss',
                                $userId,
                                $dateStr,
                                $meal,
                                $photoPath,
                                $notes
                            );
                            $st2->execute();
                            $st2->close();
                        }
                        $flash = ucfirst($meal) . ' saved.';
                    }
                }
            }
        }
    }

    // Save daily note (List of all activity)
    if (isset($_POST['action']) && $_POST['action'] === 'save_daily_note') {
        $note   = trim($_POST['daily_note'] ?? '');
        $dateIn = $_POST['activity_date'] ?? $selectedDate;
        $actDate = DateTime::createFromFormat('Y-m-d', $dateIn);
        if ($actDate) {
            $dateStr = $actDate->format('Y-m-d');
            $sql = "INSERT INTO member_daily_notes (user_id, activity_date, note)
                    VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE note=VALUES(note), updated_at=CURRENT_TIMESTAMP";
            if ($st = $conn->prepare($sql)) {
                $st->bind_param('iss', $userId, $dateStr, $note);
                $st->execute();
                $st->close();
                $flash = 'Activity note saved.';
            }
        } else {
            $flash = 'Invalid date.';
        }
    }

    // After POST, keep the same selected date
    $selectedDate = $today->format('Y-m-d');
}

/* ---------- Load meals + daily note for selected date ---------- */

// default empty meals
$meals = [
    'breakfast' => ['notes' => '', 'photo' => null],
    'lunch'     => ['notes' => '', 'photo' => null],
    'dinner'    => ['notes' => '', 'photo' => null],
];

if ($st = $conn->prepare("SELECT meal_type, notes, photo_path FROM member_activities WHERE user_id=? AND activity_date=?")) {
    $st->bind_param('is', $userId, $selectedDate);
    $st->execute();
    if ($res = $st->get_result()) {
        while ($row = $res->fetch_assoc()) {
            $mt = $row['meal_type'];
            if (isset($meals[$mt])) {
                $meals[$mt]['notes'] = $row['notes'] ?? '';
                $meals[$mt]['photo'] = $row['photo_path'] ?? null;
            }
        }
        $res->free();
    }
    $st->close();
}

// daily note
$dailyNote = '';
if ($st = $conn->prepare("SELECT note FROM member_daily_notes WHERE user_id=? AND activity_date=? LIMIT 1")) {
    $st->bind_param('is', $userId, $selectedDate);
    $st->execute();
    if ($res = $st->get_result()) {
        if ($row = $res->fetch_assoc()) {
            $dailyNote = $row['note'] ?? '';
        }
        $res->free();
    }
    $st->close();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Activity List | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
:root{
  --brand:#b30000;
  --bg:#0b0b0b;
  --panel:#141414;
  --line:#2a2a2a;
}
body{
  background:#000;
  color:#f5f5f5;
  font-family:'Poppins',sans-serif;
}
.navbar{
  background:linear-gradient(90deg,#000,var(--brand));
}
.card-dark{
  background:var(--panel);
  border:1px solid var(--line);
  border-radius:18px;
}
.meal-photo-box{
  background:#050505;
  border-radius:12px;
  border:1px dashed #444;
  height:200px;
  display:flex;
  align-items:center;
  justify-content:center;
  text-align:center;
  color:#a0aec0;
  font-size:.9rem;
  overflow:hidden;
}
.meal-photo-box img{
  width:100%;
  height:100%;
  object-fit:cover;
}
.profile-circle{
  width:40px;
  height:40px;
  border-radius:50%;
  overflow:hidden;
  border:2px solid #ff4b4b;
}
.profile-circle img{
  width:100%;
  height:100%;
  object-fit:cover;
}
textarea.form-control{
  background:#111;
  color:#f5f5f5;
  border:1px solid #333;
}
.btn-brand{
  background:var(--brand);
  border:none;
}
.btn-brand:hover{
  background:#ff1a1a;
}
</style>
</head>
<body>

<nav class="navbar navbar-dark px-3">
  <a href="home.php" class="btn btn-outline-light btn-sm mr-3">&larr; Back</a>
  <span class="navbar-brand mb-0 h5">Activity List</span>
  <div class="ml-auto d-flex align-items-center">
    <span class="mr-2 small">Welcome, <?=h($displayName)?></span>
    <div class="profile-circle">
      <img src="<?=h($avatarPath)?>" alt="Avatar">
    </div>
  </div>
</nav>

<div class="container py-4">

  <?php if ($flash): ?>
    <div class="alert alert-info py-2"><?=h($flash)?></div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Meals</h4>
    <form method="get" class="form-inline">
      <label class="mr-2">Select Date</label>
      <input type="date" name="date" class="form-control form-control-sm"
             value="<?=h($selectedDate)?>">
      <button class="btn btn-sm btn-secondary ml-2">Go</button>
    </form>
  </div>

  <div class="row">
    <?php
    $labels = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];
    foreach ($labels as $key => $label):
      $meal = $meals[$key];
    ?>
    <div class="col-md-4 mb-4">
      <div class="card-dark p-3 h-100">
        <h5 class="mb-3"><?=h($label)?></h5>

        <div class="meal-photo-box mb-3">
          <?php if ($meal['photo']): ?>
            <img src="<?=h($meal['photo'])?>" alt="<?=h($label)?> photo">
          <?php else: ?>
            <div>
              <div>No photo uploaded.</div>
              <div>Upload your meal for this day.</div>
            </div>
          <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="save_meal">
          <input type="hidden" name="meal" value="<?=h($key)?>">
          <input type="hidden" name="activity_date" value="<?=h($selectedDate)?>">

          <div class="form-group">
            <label class="small mb-1">Upload photo for meal</label>
            <input type="file" name="photo" class="form-control-file" accept="image/*">
          </div>

          <div class="form-group">
            <label class="small mb-1">Notes</label>
            <textarea name="notes" rows="3"
                      class="form-control"
                      placeholder="What did you eat or how did you feel?"><?=h($meal['notes'])?></textarea>
          </div>

          <button class="btn btn-brand btn-block">
            Save <?=h($label)?>
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- List of all activity (only one note + save button) -->
  <div class="card-dark p-3 mt-3">
    <h4 class="mb-3">List of all activity</h4>
    <form method="post">
      <input type="hidden" name="action" value="save_daily_note">
      <input type="hidden" name="activity_date" value="<?=h($selectedDate)?>">
      <div class="form-group">
        <label class="small mb-1">Notes</label>
        <textarea name="daily_note" rows="6"
                  class="form-control"
                  placeholder="Write your overall activity, how your day went, or anything you want to remember."><?=h($dailyNote)?></textarea>
      </div>
      <button class="btn btn-brand">Save</button>
    </form>
  </div>

</div>

</body>
</html>