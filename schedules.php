<?php
// schedules.php - Save booking, list bookings, edit/delete actions.
// Design updated to match the dark RJL Fitness facility pages.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/db.php';

$conn->query("CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facility_slug VARCHAR(100) NOT NULL,
    facility_name VARCHAR(150) NOT NULL,
    date DATE NOT NULL,
    time VARCHAR(20) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    status ENUM('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function clean($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function is_admin_like($role) {
    return in_array(strtolower((string)$role), ['staff', 'admin'], true);
}

function bookings_has_column(mysqli $conn, string $column): bool {
    $sql = "SELECT COUNT(*) AS c
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'bookings'
              AND COLUMN_NAME = ?";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('s', $column);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    if ($res) $res->free();
    $st->close();
    return !empty($row['c']);
}

function flash_set($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function flash_get() {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function load_booking(mysqli $conn, int $id) {
    $st = $conn->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('i', $id);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    if ($res) $res->free();
    $st->close();
    return $row;
}

function can_manage($row, $role, $uid, $uemail) {
    if (is_admin_like($role)) return true;
    if (!$row) return false;
    if ($uid && isset($row['user_id']) && $row['user_id'] && (int)$row['user_id'] === (int)$uid) return true;
    if ($uemail && isset($row['email']) && strcasecmp((string)$row['email'], (string)$uemail) === 0) return true;
    return false;
}

function home_link_for_role(string $role): string {
    if ($role === 'admin') return 'home_admin.php';
    if ($role === 'staff') return 'home_staff.php';
    if ($role === 'trainer') return 'home_trainer.php';
    return 'home.php';
}

function status_badge_class(string $status): string {
    $status = strtolower($status);
    if ($status === 'approved') return 'success';
    if ($status === 'disapproved' || $status === 'rejected') return 'danger';
    return 'warning';
}

function format_booking_time($time): string {
    $time = trim((string)$time);
    if ($time === '') return '';

    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
        return date('g:i A', strtotime($time));
    }

    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        return date('g:i A', strtotime($time));
    }

    return $time;
}

$role = strtolower((string)($_SESSION['role'] ?? 'member'));
$uid = $_SESSION['user_id'] ?? null;
$uemail = $_SESSION['email'] ?? null;
$homeLink = home_link_for_role($role);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

$hasTrainerId = bookings_has_column($conn, 'trainer_id');
$hasApprovalStatus = bookings_has_column($conn, 'approval_status');
$hasTrainerRemarks = bookings_has_column($conn, 'trainer_remarks');

/* --------------------------------------------------------------------------
   Save booking from facilities.php
---------------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['facility_slug']) && !isset($_GET['action'])) {
    $facility_slug = trim($_POST['facility_slug'] ?? '');
    $facility_name = trim($_POST['facility_name'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $time = trim($_POST['time'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $trainer_id = isset($_POST['trainer_id']) && $_POST['trainer_id'] !== '' ? (int)$_POST['trainer_id'] : null;

    $errors = [];
    if ($facility_slug === '' || $facility_name === '') $errors[] = 'Missing facility information.';
    if ($date === '') $errors[] = 'Please choose a date.';
    if ($time === '') $errors[] = 'Please choose a time.';
    if ($full_name === '') $errors[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

    $idNumber = '';
    if ($uid) {
        if ($stId = $conn->prepare("SELECT id_number FROM users WHERE id = ? LIMIT 1")) {
            $stId->bind_param('i', $uid);
            $stId->execute();
            if ($resId = $stId->get_result()) {
                if ($rowId = $resId->fetch_assoc()) {
                    $idNumber = $rowId['id_number'] ?? '';
                }
                $resId->free();
            }
            $stId->close();
        }
    }

    $trainerName = '';
    if ($hasTrainerId && $trainer_id) {
        if ($stT = $conn->prepare("SELECT COALESCE(NULLIF(full_name,''), username) AS trainer_name FROM users WHERE id = ? LIMIT 1")) {
            $stT->bind_param('i', $trainer_id);
            $stT->execute();
            if ($resT = $stT->get_result()) {
                if ($rowT = $resT->fetch_assoc()) {
                    $trainerName = $rowT['trainer_name'] ?? '';
                }
                $resT->free();
            }
            $stT->close();
        }
    }

    $booking_id = null;
    if (!$errors) {
        $uid_i = $uid ?: null;

        if ($hasTrainerId) {
            $sql = "INSERT INTO bookings
                    (facility_slug, facility_name, date, time, full_name, email, notes, user_id, trainer_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            if ($st = $conn->prepare($sql)) {
                $st->bind_param('sssssssii', $facility_slug, $facility_name, $date, $time, $full_name, $email, $notes, $uid_i, $trainer_id);
                if ($st->execute()) {
                    $booking_id = $st->insert_id;
                } else {
                    $errors[] = 'Could not save booking. Please try again.';
                }
                $st->close();
            } else {
                $errors[] = 'Database error while saving booking.';
            }
        } else {
            $sql = "INSERT INTO bookings
                    (facility_slug, facility_name, date, time, full_name, email, notes, user_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            if ($st = $conn->prepare($sql)) {
                $st->bind_param('sssssssi', $facility_slug, $facility_name, $date, $time, $full_name, $email, $notes, $uid_i);
                if ($st->execute()) {
                    $booking_id = $st->insert_id;
                } else {
                    $errors[] = 'Could not save booking. Please try again.';
                }
                $st->close();
            } else {
                $errors[] = 'Database error while saving booking.';
            }
        }
    }
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Booking <?= $errors ? 'Error' : 'Confirmation' ?> | RJL Fitness</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--bg:#090909;--panel:#111;--panel2:#171717;--line:#2a2a2a;--text:#fff;--muted:#b8c0cc;--red:#dc0000;--red2:#ef3340;--green:#22c55e;--yellow:#facc15;}
        *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font-family:'Poppins',Arial,sans-serif;min-height:100vh}
        .topbar{height:66px;background:linear-gradient(90deg,#070707 0%,#1a0000 48%,#d00000 100%);border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:20}
        .brand{display:flex;align-items:center;gap:12px;color:#fff;text-decoration:none;font-weight:800;font-size:23px}.brand:hover{color:#fff;text-decoration:none}.brand img{width:58px;height:42px;object-fit:contain}
        .nav-actions{display:flex;align-items:center;gap:10px}.btn-rjl{border-radius:7px;font-weight:700;padding:8px 14px;border:1px solid rgba(255,255,255,.7);color:#fff;background:transparent}.btn-rjl:hover{color:#fff;text-decoration:none;background:rgba(255,255,255,.08)}.btn-rjl.red{border-color:var(--red2);background:var(--red2)}.btn-rjl.red:hover{background:#ff4050}.btn-rjl.dark{border-color:#444;background:#151515}.btn-rjl.dark:hover{background:#202020}.btn-rjl.green{border-color:var(--green);background:var(--green);color:#08110a}.btn-rjl.green:hover{color:#08110a;filter:brightness(1.05)}
        .page{max-width:1120px;margin:0 auto;padding:30px 18px 48px}.hero{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:20px}.hero h1{font-size:34px;line-height:1.1;font-weight:800;margin:0}.hero p{margin:8px 0 0;color:var(--muted)}
        .panel{background:linear-gradient(180deg,#151515,#101010);border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 50px rgba(0,0,0,.35);overflow:hidden}.panel-pad{padding:24px}.alert-dark-rjl{background:#171717;border:1px solid #303030;color:#fff;border-radius:12px}.alert-success-rjl{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#c8ffd6;border-radius:12px}.alert-danger-rjl{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#ffd0d0;border-radius:12px}
        .receipt-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:20px 0}.receipt-item{background:#0d0d0d;border:1px solid #2c2c2c;border-radius:14px;padding:14px}.receipt-item span{display:block;color:#95a0ad;font-size:12px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px}.receipt-item strong{font-size:16px;word-break:break-word}.receipt-item.wide{grid-column:1/-1}
        .form-control{background:#0d0d0d!important;border:1px solid #333!important;color:#fff!important;border-radius:10px;height:44px}.form-control:focus{box-shadow:0 0 0 .18rem rgba(239,51,64,.18)!important;border-color:var(--red2)!important}.form-control::placeholder{color:#7e8793}.textarea.form-control, textarea.form-control{height:auto}.muted{color:var(--muted)}
        .table-wrap{border:1px solid #2c2c2c;border-radius:16px;overflow:hidden;background:#0f0f0f}.rjl-table{width:100%;margin:0;color:#fff;border-collapse:separate;border-spacing:0}.rjl-table thead th{background:#161616;color:#f2f2f2;font-size:13px;text-transform:uppercase;letter-spacing:.04em;border:none;padding:15px 14px;white-space:nowrap}.rjl-table tbody td{background:#101010;border-top:1px solid #292929;padding:15px 14px;vertical-align:middle}.rjl-table tbody tr:hover td{background:#171717}.facility-name{font-weight:700}.slug{color:#747d8a;font-weight:500}.actions{white-space:nowrap;text-align:right}.badge-rjl{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 10px;font-weight:800;font-size:12px;text-transform:capitalize}.badge-warning{background:var(--yellow);color:#1c1200}.badge-success{background:var(--green);color:#041207}.badge-danger{background:#ef4444;color:#fff}.badge-secondary{background:#6b7280;color:#fff}
        .filter-card{padding:16px;margin-bottom:18px}.empty{padding:38px;text-align:center;color:var(--muted)}.bottom-actions{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:20px}.small-note{font-size:13px;color:var(--muted)}
        @media (max-width:768px){.topbar{padding:0 14px}.brand{font-size:19px}.brand img{width:46px}.hero{align-items:flex-start;flex-direction:column}.hero h1{font-size:29px}.receipt-grid{grid-template-columns:1fr}.table-wrap{overflow-x:auto}.page{padding:24px 12px 40px}.panel-pad{padding:18px}.nav-actions{gap:6px}.btn-rjl{padding:7px 10px;font-size:13px}}
    </style>
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= clean($homeLink) ?>">
        <img src="photo/logo.jpg" alt="RJL Fitness">
        <span>RJL Fitness</span>
    </a>
    <div class="nav-actions">
        <a class="btn-rjl" href="facilities.php">Facilities</a>
        <a class="btn-rjl red" href="schedules.php">Bookings</a>
    </div>
</header>

<main class="page">
    <section class="hero">
        <div>
            <h1><?= $errors ? 'Booking Error' : 'Booking Received' ?></h1>
            <p><?= $errors ? 'Please check the details below.' : 'Your request was saved and is waiting for trainer approval.' ?></p>
        </div>
    </section>

    <section class="panel panel-pad" style="max-width:820px;margin:0 auto;">
        <?php if ($errors): ?>
            <div class="alert-danger-rjl p-3 mb-3">
                <strong>Booking not saved.</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $e): ?>
                        <li><?= clean($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <a class="btn-rjl red d-inline-block" href="javascript:history.back()">Go Back</a>
        <?php else: ?>
            <div class="alert-success-rjl p-3 mb-3">
                <strong>Booking received.</strong>
                <div class="small-note mt-1">Status: Pending trainer approval.</div>
            </div>

            <div class="receipt-grid">
                <div class="receipt-item"><span>Booking ID</span><strong>#<?= (int)$booking_id ?></strong></div>
                <div class="receipt-item"><span>ID Number</span><strong><?= clean($idNumber !== '' ? $idNumber : 'Not set') ?></strong></div>
                <div class="receipt-item wide"><span>Facility</span><strong><?= clean($facility_name) ?> <small class="slug">(<?= clean($facility_slug) ?>)</small></strong></div>
                <?php if ($trainerName !== ''): ?>
                    <div class="receipt-item wide"><span>Trainer</span><strong><?= clean($trainerName) ?></strong></div>
                <?php endif; ?>
                <div class="receipt-item"><span>Date</span><strong><?= clean($date) ?></strong></div>
                <div class="receipt-item"><span>Time</span><strong><?= clean(format_booking_time($time)) ?></strong></div>
                <div class="receipt-item"><span>Name</span><strong><?= clean($full_name) ?></strong></div>
                <div class="receipt-item"><span>Email</span><strong><?= clean($email) ?></strong></div>
                <?php if ($notes !== ''): ?>
                    <div class="receipt-item wide"><span>Notes</span><strong><?= nl2br(clean($notes)) ?></strong></div>
                <?php endif; ?>
            </div>

            <div class="bottom-actions">
                <a class="btn-rjl" href="facilities.php">Back to Facilities</a>
                <a class="btn-rjl red" href="schedules.php">View My Bookings</a>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
    <?php
    exit;
}

/* --------------------------------------------------------------------------
   Edit / delete actions
---------------------------------------------------------------------------*/
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        http_response_code(400);
        die('CSRF token invalid.');
    }

    $row = load_booking($conn, $id);
    if (!$row || !can_manage($row, $role, $uid, $uemail)) {
        http_response_code(403);
        die('Not allowed.');
    }

    $st = $conn->prepare("DELETE FROM bookings WHERE id = ?");
    $st->bind_param('i', $id);
    $ok = $st->execute();
    $st->close();

    flash_set($ok ? 'Booking deleted.' : 'Delete failed.', $ok ? 'success' : 'danger');
    header('Location: schedules.php');
    exit;
}

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        http_response_code(400);
        die('CSRF token invalid.');
    }

    $row = load_booking($conn, $id);
    if (!$row || !can_manage($row, $role, $uid, $uemail)) {
        http_response_code(403);
        die('Not allowed.');
    }

    $date = trim($_POST['date'] ?? '');
    $time = trim($_POST['time'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $full_name = trim($_POST['full_name'] ?? $row['full_name']);
    $email = trim($_POST['email'] ?? $row['email']);

    $errs = [];
    if ($date === '') $errs[] = 'Date required.';
    if ($time === '') $errs[] = 'Time required.';
    if ($full_name === '') $errs[] = 'Name required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Valid email required.';

    if ($errs) {
        flash_set(implode(' ', $errs), 'danger');
        header("Location: schedules.php?action=edit&id={$id}");
        exit;
    }

    $st = $conn->prepare("UPDATE bookings SET date = ?, time = ?, notes = ?, full_name = ?, email = ? WHERE id = ?");
    $st->bind_param('sssssi', $date, $time, $notes, $full_name, $email, $id);
    $ok = $st->execute();
    $st->close();

    flash_set($ok ? 'Booking updated.' : 'Update failed.', $ok ? 'success' : 'danger');
    header('Location: schedules.php');
    exit;
}

/* --------------------------------------------------------------------------
   Edit page
---------------------------------------------------------------------------*/
if ($action === 'edit' && $id > 0) {
    $row = load_booking($conn, $id);
    if (!$row || !can_manage($row, $role, $uid, $uemail)) {
        http_response_code(403);
        die('Not allowed.');
    }
    $flash = flash_get();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Edit Booking #<?= (int)$row['id'] ?> | RJL Fitness</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--bg:#090909;--panel:#111;--line:#2a2a2a;--text:#fff;--muted:#b8c0cc;--red:#dc0000;--red2:#ef3340;}
        *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font-family:'Poppins',Arial,sans-serif;min-height:100vh}.topbar{height:66px;background:linear-gradient(90deg,#070707 0%,#1a0000 48%,#d00000 100%);border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:space-between;padding:0 28px}.brand{display:flex;align-items:center;gap:12px;color:#fff;text-decoration:none;font-weight:800;font-size:23px}.brand:hover{color:#fff;text-decoration:none}.brand img{width:58px;height:42px;object-fit:contain}.nav-actions{display:flex;gap:10px}.btn-rjl{border-radius:7px;font-weight:700;padding:8px 14px;border:1px solid rgba(255,255,255,.7);color:#fff;background:transparent}.btn-rjl:hover{color:#fff;text-decoration:none;background:rgba(255,255,255,.08)}.btn-rjl.red{border-color:var(--red2);background:var(--red2)}.btn-rjl.red:hover{background:#ff4050}.page{max-width:900px;margin:0 auto;padding:30px 18px 48px}.hero h1{font-size:34px;font-weight:800;margin:0 0 8px}.hero p{color:var(--muted);margin:0 0 20px}.panel{background:linear-gradient(180deg,#151515,#101010);border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 50px rgba(0,0,0,.35);padding:24px}.form-control{background:#0d0d0d!important;border:1px solid #333!important;color:#fff!important;border-radius:10px;height:44px}.form-control:focus{box-shadow:0 0 0 .18rem rgba(239,51,64,.18)!important;border-color:var(--red2)!important}textarea.form-control{height:auto}.muted{color:var(--muted)}label{font-weight:700}.danger-zone{margin-top:22px;padding-top:20px;border-top:1px solid #2d2d2d}.alert-success-rjl{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#c8ffd6;border-radius:12px}.alert-danger-rjl{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#ffd0d0;border-radius:12px}@media(max-width:768px){.topbar{padding:0 14px}.brand{font-size:19px}.brand img{width:46px}.page{padding:24px 12px 40px}.panel{padding:18px}}
    </style>
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= clean($homeLink) ?>">
        <img src="photo/logo.jpg" alt="RJL Fitness">
        <span>RJL Fitness</span>
    </a>
    <div class="nav-actions">
        <a class="btn-rjl" href="schedules.php">Back</a>
        <a class="btn-rjl red" href="logout.php">Logout</a>
    </div>
</header>

<main class="page">
    <section class="hero">
        <h1>Edit Booking #<?= (int)$row['id'] ?></h1>
        <p><strong><?= clean($row['facility_name']) ?></strong> <span class="muted">(<?= clean($row['facility_slug']) ?>)</span></p>
    </section>

    <section class="panel">
        <?php if ($flash): ?>
            <div class="<?= $flash['type'] === 'danger' ? 'alert-danger-rjl' : 'alert-success-rjl' ?> p-3 mb-3"><?= clean($flash['msg']) ?></div>
        <?php endif; ?>

        <form method="post" action="schedules.php?action=edit&id=<?= (int)$row['id'] ?>">
            <input type="hidden" name="csrf" value="<?= clean($CSRF) ?>">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Date</label>
                    <input type="date" name="date" class="form-control" value="<?= clean($row['date']) ?>" required>
                </div>
                <div class="form-group col-md-6">
                    <label>Time</label>
                    <input type="text" name="time" class="form-control" value="<?= clean($row['time']) ?>" required>
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
            <div class="d-flex justify-content-between flex-wrap" style="gap:12px;">
                <a class="btn-rjl" href="schedules.php">Cancel</a>
                <button class="btn-rjl red" type="submit">Save Changes</button>
            </div>
        </form>

        <div class="danger-zone">
            <form method="post" action="schedules.php?action=delete&id=<?= (int)$row['id'] ?>" onsubmit="return confirm('Delete this booking?');">
                <input type="hidden" name="csrf" value="<?= clean($CSRF) ?>">
                <button class="btn btn-outline-danger" type="submit">Delete Booking</button>
            </form>
        </div>
    </section>
</main>
</body>
</html>
    <?php
    exit;
}

/* --------------------------------------------------------------------------
   List bookings
---------------------------------------------------------------------------*/
$isAdmin = is_admin_like($role);
$filter_fac = '';
$filter_date = '';

if ($isAdmin) {
    $filter_fac = trim($_GET['facility'] ?? '');
    $filter_date = trim($_GET['date'] ?? '');
}

$rows = [];
$selectTrainer = '';
$joinTrainer = '';
if ($hasTrainerId) {
    $selectTrainer = ", b.trainer_id, COALESCE(NULLIF(t.full_name,''), t.username) AS trainer_name";
    $joinTrainer = " LEFT JOIN users t ON t.id = b.trainer_id";
}

if ($isAdmin) {
    $sql = "SELECT b.id, b.facility_name, b.facility_slug, b.date, b.time,
                   b.full_name, b.email, b.status, b.notes, b.user_id, b.created_at
                   {$selectTrainer}
            FROM bookings b
            {$joinTrainer}
            WHERE 1=1";
    $params = [];
    $types = '';

    if ($filter_fac !== '') {
        $sql .= " AND b.facility_slug = ?";
        $params[] = $filter_fac;
        $types .= 's';
    }

    if ($filter_date !== '') {
        $sql .= " AND b.date = ?";
        $params[] = $filter_date;
        $types .= 's';
    }

    $sql .= " ORDER BY b.created_at DESC";

    if ($types) {
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
    } else {
        $res = $conn->query($sql);
    }

    if ($res) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
    if (isset($st)) $st->close();
} else {
    if ($uid) {
        $sql = "SELECT b.id, b.facility_name, b.facility_slug, b.date, b.time,
                       b.full_name, b.email, b.status, b.notes, b.user_id, b.created_at
                       {$selectTrainer}
                FROM bookings b
                {$joinTrainer}
                WHERE b.user_id = ?
                ORDER BY b.created_at DESC";
        $st = $conn->prepare($sql);
        $st->bind_param('i', $uid);
        $st->execute();
        $res = $st->get_result();
        if ($res) {
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();
        }
        $st->close();
    } elseif ($uemail) {
        $sql = "SELECT b.id, b.facility_name, b.facility_slug, b.date, b.time,
                       b.full_name, b.email, b.status, b.notes, b.user_id, b.created_at
                       {$selectTrainer}
                FROM bookings b
                {$joinTrainer}
                WHERE b.email = ?
                ORDER BY b.created_at DESC";
        $st = $conn->prepare($sql);
        $st->bind_param('s', $uemail);
        $st->execute();
        $res = $st->get_result();
        if ($res) {
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();
        }
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--bg:#090909;--panel:#111;--panel2:#171717;--line:#2a2a2a;--text:#fff;--muted:#b8c0cc;--red:#dc0000;--red2:#ef3340;--green:#22c55e;--yellow:#facc15;}
        *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font-family:'Poppins',Arial,sans-serif;min-height:100vh}
        .topbar{height:66px;background:linear-gradient(90deg,#070707 0%,#1a0000 48%,#d00000 100%);border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:20}
        .brand{display:flex;align-items:center;gap:12px;color:#fff;text-decoration:none;font-weight:800;font-size:23px}.brand:hover{color:#fff;text-decoration:none}.brand img{width:58px;height:42px;object-fit:contain}
        .nav-actions{display:flex;align-items:center;gap:10px}.btn-rjl{border-radius:7px;font-weight:700;padding:8px 14px;border:1px solid rgba(255,255,255,.7);color:#fff;background:transparent;display:inline-flex;align-items:center;justify-content:center}.btn-rjl:hover{color:#fff;text-decoration:none;background:rgba(255,255,255,.08)}.btn-rjl.red{border-color:var(--red2);background:var(--red2)}.btn-rjl.red:hover{background:#ff4050}.btn-rjl.dark{border-color:#444;background:#151515}.btn-rjl.dark:hover{background:#202020}
        .page{max-width:1280px;margin:0 auto;padding:30px 18px 48px}.hero{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:20px}.hero h1{font-size:34px;line-height:1.1;font-weight:800;margin:0}.hero p{margin:8px 0 0;color:var(--muted)}.stats{display:flex;gap:12px;flex-wrap:wrap}.stat{background:#111;border:1px solid #303030;border-radius:14px;padding:12px 16px;min-width:110px}.stat span{display:block;color:#9aa3af;font-size:12px;text-transform:uppercase;letter-spacing:.06em}.stat strong{font-size:22px}
        .panel{background:linear-gradient(180deg,#151515,#101010);border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 50px rgba(0,0,0,.35);overflow:hidden}.panel-pad{padding:18px}.filter-card{padding:16px;margin-bottom:18px}.form-control{background:#0d0d0d!important;border:1px solid #333!important;color:#fff!important;border-radius:10px;height:44px}.form-control:focus{box-shadow:0 0 0 .18rem rgba(239,51,64,.18)!important;border-color:var(--red2)!important}.muted{color:var(--muted)}label{font-weight:700}.alert-success-rjl{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#c8ffd6;border-radius:12px}.alert-danger-rjl{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#ffd0d0;border-radius:12px}
        .table-wrap{border:1px solid #2c2c2c;border-radius:16px;overflow:hidden;background:#0f0f0f}.rjl-table{width:100%;margin:0;color:#fff;border-collapse:separate;border-spacing:0}.rjl-table thead th{background:#161616;color:#f2f2f2;font-size:13px;text-transform:uppercase;letter-spacing:.04em;border:none;padding:15px 14px;white-space:nowrap}.rjl-table tbody td{background:#101010;border-top:1px solid #292929;padding:15px 14px;vertical-align:middle}.rjl-table tbody tr:hover td{background:#171717}.facility-name{font-weight:800}.slug{color:#7f8794;font-weight:500}.subtext{color:#8d96a3;font-size:13px}.actions{white-space:nowrap;text-align:right}.actions form{display:inline}.badge-rjl{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 10px;font-weight:800;font-size:12px;text-transform:capitalize}.badge-warning{background:var(--yellow);color:#1c1200}.badge-success{background:var(--green);color:#041207}.badge-danger{background:#ef4444;color:#fff}.badge-secondary{background:#6b7280;color:#fff}.empty{padding:48px 18px;text-align:center;color:var(--muted)}.empty h4{color:#fff;font-weight:800}.bottom-actions{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:20px}
        @media (max-width:992px){.table-wrap{overflow-x:auto}.rjl-table{min-width:860px}}@media (max-width:768px){.topbar{padding:0 14px}.brand{font-size:19px}.brand img{width:46px}.hero{align-items:flex-start;flex-direction:column}.hero h1{font-size:29px}.page{padding:24px 12px 40px}.nav-actions{gap:6px}.btn-rjl{padding:7px 10px;font-size:13px}.stats{width:100%}.stat{flex:1}}
    </style>
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= clean($homeLink) ?>">
        <img src="photo/logo.jpg" alt="RJL Fitness">
        <span>RJL Fitness</span>
    </a>
    <div class="nav-actions">
        <a class="btn-rjl" href="facilities.php">Facilities</a>
        <a class="btn-rjl red" href="logout.php">Logout</a>
    </div>
</header>

<main class="page">
    <section class="hero">
        <div>
            <h1><?= $isAdmin ? 'All Bookings' : 'My Bookings' ?></h1>
            <p><?= $isAdmin ? 'Manage all facility reservations.' : 'Showing bookings for your account.' ?></p>
        </div>
        <div class="stats">
            <div class="stat"><span>Total</span><strong><?= count($rows) ?></strong></div>
            <div class="stat"><span>Pending</span><strong><?= count(array_filter($rows, fn($r) => strtolower($r['status'] ?? 'pending') === 'pending')) ?></strong></div>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="<?= $flash['type'] === 'danger' ? 'alert-danger-rjl' : 'alert-success-rjl' ?> p-3 mb-3"><?= clean($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <form method="get" class="panel filter-card">
            <div class="form-row">
                <div class="form-group col-md-5 mb-md-0">
                    <label>Facility</label>
                    <input class="form-control" name="facility" value="<?= clean($filter_fac) ?>" placeholder="e.g. boxing, bodybuilding">
                </div>
                <div class="form-group col-md-5 mb-md-0">
                    <label>Date</label>
                    <input type="date" class="form-control" name="date" value="<?= clean($filter_date) ?>">
                </div>
                <div class="form-group col-md-2 mb-0 d-flex align-items-end">
                    <button class="btn-rjl red w-100" type="submit">Filter</button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <section class="panel panel-pad">
        <?php if (!$rows): ?>
            <div class="empty">
                <h4>No bookings found</h4>
                <p class="mb-0">Your facility reservations will appear here.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="rjl-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Facility</th>
                        <th>Date</th>
                        <th>Time</th>
                        <?php if ($hasTrainerId): ?><th>Trainer</th><?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <th>Name</th>
                            <th>Email</th>
                        <?php endif; ?>
                        <th>Status</th>
                        <th>Created</th>
                        <?php if ($isAdmin): ?><th>User ID</th><?php endif; ?>
                        <th class="actions">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $status = strtolower($r['status'] ?? 'pending');
                        $badgeClass = status_badge_class($status);
                        ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td>
                                <div class="facility-name"><?= clean($r['facility_name']) ?></div>
                                <div class="slug"><?= clean($r['facility_slug']) ?></div>
                            </td>
                            <td><?= clean($r['date']) ?></td>
                            <td><?= clean(format_booking_time($r['time'])) ?></td>
                            <?php if ($hasTrainerId): ?>
                                <td><?= clean($r['trainer_name'] ?? 'Not assigned') ?></td>
                            <?php endif; ?>
                            <?php if ($isAdmin): ?>
                                <td><?= clean($r['full_name']) ?></td>
                                <td><span class="subtext"><?= clean($r['email']) ?></span></td>
                            <?php endif; ?>
                            <td><span class="badge-rjl badge-<?= clean($badgeClass) ?>"><?= clean($status) ?></span></td>
                            <td><span class="subtext"><?= clean($r['created_at']) ?></span></td>
                            <?php if ($isAdmin): ?><td><?= $r['user_id'] !== null ? (int)$r['user_id'] : '-' ?></td><?php endif; ?>
                            <td class="actions">
                                <a class="btn-rjl dark" href="schedules.php?action=edit&id=<?= (int)$r['id'] ?>">Edit</a>
                                <form method="post" action="schedules.php?action=delete&id=<?= (int)$r['id'] ?>" onsubmit="return confirm('Delete booking #<?= (int)$r['id'] ?>?');">
                                    <input type="hidden" name="csrf" value="<?= clean($CSRF) ?>">
                                    <button class="btn btn-outline-danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div class="bottom-actions">
        <a class="btn-rjl" href="facilities.php">Back to Facilities</a>
        <a class="btn-rjl red" href="<?= clean($homeLink) ?>">Go to Dashboard</a>
    </div>
</main>
</body>
</html>
