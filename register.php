<?php 

if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/send_mail.php';

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Manila');
}

// ---------- helpers ----------
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function has_column(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $st->close();
    return $ok;
}

// 🔴 ID NUMBER GENERATOR (M-0225-08 → M-0225-09, etc.)
function generate_id_number(mysqli $conn): string {
    $prefix = 'M-0225-'; // your chosen prefix

    $lastSuffix = 0;

    // get the latest user with this prefix
    if ($st = $conn->prepare("
        SELECT id_number
        FROM users
        WHERE id_number LIKE CONCAT(?, '%')
        ORDER BY id DESC
        LIMIT 1
    ")) {
        $st->bind_param('s', $prefix);
        $st->execute();
        $res = $st->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $existing   = $row['id_number'] ?? '';
            $suffixPart = substr($existing, strlen($prefix));
            if ($suffixPart !== '' && ctype_digit($suffixPart)) {
                $lastSuffix = (int)$suffixPart;
            }
        }
        if ($res) $res->free();
        $st->close();
    }

    // next number (e.g. 08 → 09)
    $next = $lastSuffix + 1;

    // make sure it is unique
    while (true) {
        $candidate = $prefix . str_pad((string)$next, 2, '0', STR_PAD_LEFT); // M-0225-09
        if ($st2 = $conn->prepare("SELECT 1 FROM users WHERE id_number = ? LIMIT 1")) {
            $st2->bind_param('s', $candidate);
            $st2->execute();
            $res2 = $st2->get_result();
            $exists = $res2 && $res2->num_rows > 0;
            if ($res2) $res2->free();
            $st2->close();
            if (!$exists) {
                return $candidate;
            }
        }
        $next++;
    }
}

// Auto-expire old pending registrations if registration_expires_at exists
if (has_column($conn, 'users', 'registration_expires_at')) {
    $conn->query("
        UPDATE users
        SET status='expired'
        WHERE status='pending'
          AND registration_expires_at IS NOT NULL
          AND registration_expires_at < NOW()
    ");
}

$errors  = [];
$success = null;

$old = [
    'full_name'   => '',
    'username'    => '',
    'email'       => '',
    'bday_day'    => '',
    'bday_month'  => '',
    'bday_year'   => '',
    'phone'       => '',
    'address'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $pass      = $_POST['password'] ?? '';
    $pass2     = $_POST['confirm_password'] ?? '';

    $bday_day   = trim($_POST['bday_day'] ?? '');
    $bday_month = trim($_POST['bday_month'] ?? '');
    $bday_year  = trim($_POST['bday_year'] ?? '');

    $phone      = trim($_POST['phone'] ?? '');
    $address    = trim($_POST['address'] ?? '');

    $old['full_name']   = $full_name;
    $old['username']    = $username;
    $old['email']       = $email;
    $old['bday_day']    = $bday_day;
    $old['bday_month']  = $bday_month;
    $old['bday_year']   = $bday_year;
    $old['phone']       = $phone;
    $old['address']     = $address;

    // Basic validation
    if ($full_name === '') $errors[] = 'Full name is required.';
    if ($username === '')  $errors[] = 'Username is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($pass !== $pass2)  $errors[] = 'Passwords do not match.';

    if ($phone === '')     $errors[] = 'Contact number is required.';
    if ($address === '')   $errors[] = 'Address is required.';

    // Birthday (optional)
    $birthdate = null;
    if ($bday_day !== '' && $bday_month !== '' && $bday_year !== '') {
        if (checkdate((int)$bday_month, (int)$bday_day, (int)$bday_year)) {
            $birthdate = sprintf('%04d-%02d-%02d', (int)$bday_year, (int)$bday_month, (int)$bday_day);
        } else {
            $errors[] = 'Birthday is not a valid date.';
        }
    }

    // Unique username/email
    if (!$errors) {
        if ($st = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1")) {
            $st->bind_param('s', $username);
            $st->execute();
            $res = $st->get_result();
            if ($res && $res->num_rows > 0) $errors[] = 'Username is already taken.';
            if ($res) $res->free();
            $st->close();
        }
        if ($st = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1")) {
            $st->bind_param('s', $email);
            $st->execute();
            $res = $st->get_result();
            if ($res && $res->num_rows > 0) $errors[] = 'Email is already registered.';
            if ($res) $res->free();
            $st->close();
        }
    }

    // Avatar upload (2x2)
    $avatarPath = 'photo/logo.jpg'; // default
    if (
        !$errors &&
        !empty($_FILES['avatar']['name']) &&
        $_FILES['avatar']['error'] === UPLOAD_ERR_OK
    ) {
        $tmpName = $_FILES['avatar']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif'], true)) {
            $errors[] = 'Profile photo must be JPG, PNG or GIF.';
        } else {
            $destDir = __DIR__.'/uploads/avatars/';
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0777, true);
            }
            $fileName = 'avatar_'.preg_replace('/[^a-z0-9]+/i','_',$username).'_'.time().'.'.$ext;
            $destFs = $destDir.$fileName;
            if (move_uploaded_file($tmpName, $destFs)) {
                $avatarPath = 'uploads/avatars/'.$fileName;
            } else {
                $errors[] = 'Failed to save profile picture.';
            }
        }
    }

    if (!$errors) {
        $passwordHash = password_hash($pass, PASSWORD_DEFAULT);
        $idNumber     = generate_id_number($conn);
        $status       = 'pending';   // shows up in pending_users.php
        $role         = 'member';

        // 48-hour deadline
        $deadline    = new DateTime('+2 days');
        $deadlineSql = $deadline->format('Y-m-d H:i:s');
        $deadlineTxt = $deadline->format('M d, Y g:ia');

        // check if columns exist
        $hasPhone   = has_column($conn,'users','phone');
        $hasAddress = has_column($conn,'users','address');

        // Build dynamic INSERT
        $cols         = 'full_name, username, email, password, role, status, id_number';
        $placeholders = '?,?,?,?,?,?,?';
        $types        = 'sssssss';
        $params       = [$full_name, $username, $email, $passwordHash, $role, $status, $idNumber];

        if ($hasPhone) {
            $cols         .= ', phone';
            $placeholders .= ',?';
            $types        .= 's';
            $params[]      = $phone;
        }
        if ($hasAddress) {
            $cols         .= ', address';
            $placeholders .= ',?';
            $types        .= 's';
            $params[]      = $address;
        }

        $sql = "INSERT INTO users ($cols) VALUES ($placeholders)";

        if ($st = $conn->prepare($sql)) {
            $st->bind_param($types, ...$params);
            $ok        = $st->execute();
            $newUserId = $ok ? $st->insert_id : 0;
            $st->close();

            if (!$ok || !$newUserId) {
                $errors[] = 'Could not create account (database error).';
            } else {
                // Optional: birthday
                if ($birthdate && has_column($conn,'users','birthday')) {
                    if ($st2 = $conn->prepare("UPDATE users SET birthday=? WHERE id=?")) {
                        $st2->bind_param('si',$birthdate,$newUserId);
                        $st2->execute();
                        $st2->close();
                    }
                }

                // Optional: avatar path
                $avatarCol = null;
                if (has_column($conn,'users','avatar_path'))      $avatarCol='avatar_path';
                elseif (has_column($conn,'users','avatar'))       $avatarCol='avatar';
                elseif (has_column($conn,'users','profile_pic'))  $avatarCol='profile_pic';

                if ($avatarCol && $avatarPath) {
                    $sql3 = "UPDATE users SET {$avatarCol}=? WHERE id=?";
                    if ($st3 = $conn->prepare($sql3)) {
                        $st3->bind_param('si',$avatarPath,$newUserId);
                        $st3->execute();
                        $st3->close();
                    }
                }

                // Optional: registration_expires_at
                if (has_column($conn,'users','registration_expires_at')) {
                    if ($st4 = $conn->prepare("UPDATE users SET registration_expires_at=? WHERE id=?")) {
                        $st4->bind_param('si',$deadlineSql,$newUserId);
                        $st4->execute();
                        $st4->close();
                    }
                }

                // Send registration payment email (best-effort)
                if (function_exists('sendRegistrationFeeEmail')) {
                    $emailResult = sendRegistrationFeeEmail($email,$full_name,$idNumber,$deadlineTxt);
                    if (!$emailResult['ok']) {
                        @file_put_contents(
                            __DIR__.'/logs/mail.log',
                            '['.date('Y-m-d H:i:s')."] register email error: ".$emailResult['error'].PHP_EOL,
                            FILE_APPEND
                        );
                    }
                }

                $success = "Registration successful. Your account is pending approval and payment.<br>
                    <strong>ID Number:</strong> ".h($idNumber)."<br>
                    Please check your email for instructions. You have 48 hours to settle the registration fee, otherwise your registration may be cancelled.";

                // Clear form
                $old = [
                    'full_name'   => '',
                    'username'    => '',
                    'email'       => '',
                    'bday_day'    => '',
                    'bday_month'  => '',
                    'bday_year'   => '',
                    'phone'       => '',
                    'address'     => '',
                ];
            }
        } else {
            $errors[] = 'Prepare failed when saving account.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Register | RJL Fitness</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
:root{
  --brand:#b30000;
  --brand-light:#ff4b4b;
  --bg:#050505;
  --panel:#151515;
  --border:#2a2a2a;
  --muted:#aaaaaa;
}
body{
  min-height:100vh;
  margin:0;
  background:radial-gradient(circle at top,#222 0,#050505 40%,#000 100%);
  color:#f5f5f5;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:16px;
}
.card-auth{
  width:100%;
  max-width:420px;
  background:var(--panel);
  border-radius:18px;
  border:1px solid var(--border);
  box-shadow:0 20px 40px rgba(0,0,0,0.7);
  padding:22px 22px 26px;
}
.logo-wrap{
  text-align:center;
  margin-bottom:10px;
}
.logo-wrap img{
  height:64px;
  border-radius:12px;
  margin-bottom:6px;
}
.logo-wrap h1{
  font-size:1.1rem;
  letter-spacing:.12em;
  text-transform:uppercase;
  margin:0;
}
.form-title{
  font-size:1.2rem;
  font-weight:600;
  text-align:center;
  margin-bottom:6px;
}
.form-sub{
  font-size:.9rem;
  text-align:center;
  color:var(--muted);
  margin-bottom:14px;
}
.form-control{
  background:#101010;
  border:1px solid #262626;
  color:#f9fafb;
  font-size:.9rem;
}
.form-control:focus{
  border-color:var(--brand-light);
  box-shadow:0 0 0 1px rgba(255,75,75,.4);
}
.input-group-text{
  background:#101010;
  border-color:#262626;
  color:#dddddd;
}
.small-muted{
  font-size:.8rem;
  color:var(--muted);
}
.btn-brand{
  background:linear-gradient(120deg,var(--brand),var(--brand-light));
  border:none;
  color:#fff;
  font-weight:600;
  letter-spacing:.06em;
  text-transform:uppercase;
  font-size:.85rem;
  border-radius:999px;
  padding:9px 18px;
}
.btn-brand:hover{
  background:linear-gradient(120deg,#ff4444,#ff7777);
}
.footer-text{
  margin-top:10px;
  text-align:center;
  font-size:.85rem;
}
.footer-text a{ color:#fff; text-decoration:underline; }
.bday-row .form-control{
  text-align:center;
}
</style>
</head>
<body>
<div class="card-auth">
  <div class="logo-wrap">
    <img src="photo/logo.jpg" alt="RJL Fitness">
    <h1>RJL FITNESS</h1>
  </div>

  <div class="form-title">Create Account</div>
  <div class="form-sub">
    Register as a member. Your account will be reviewed and activated by staff.
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success py-2 mb-2"><?= $success ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger py-2 mb-2">
      <ul class="mb-0 pl-3">
        <?php foreach ($errors as $e): ?>
          <li><?= h($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" autocomplete="off">
    <div class="form-group">
      <label>Full Name</label>
      <input type="text" name="full_name" class="form-control"
             value="<?= h($old['full_name']) ?>" required>
    </div>

    <div class="form-group">
      <label>Birthday</label>
      <div class="form-row bday-row">
        <div class="col-4">
          <input type="number" min="1" max="31" name="bday_day"
                 class="form-control" placeholder="DD"
                 value="<?= h($old['bday_day']) ?>">
        </div>
        <div class="col-4">
          <input type="number" min="1" max="12" name="bday_month"
                 class="form-control" placeholder="MM"
                 value="<?= h($old['bday_month']) ?>">
        </div>
        <div class="col-4">
          <input type="number" min="1900" max="2100" name="bday_year"
                 class="form-control" placeholder="YYYY"
                 value="<?= h($old['bday_year']) ?>">
        </div>
      </div>
      <small class="small-muted">Optional, but recommended.</small>
    </div>

    <div class="form-group">
      <label>Contact Number</label>
      <input type="text" name="phone" class="form-control"
             value="<?= h($old['phone']) ?>" required>
    </div>

    <div class="form-group">
      <label>Address</label>
      <textarea name="address" class="form-control" rows="2" required><?= h($old['address']) ?></textarea>
    </div>

    <div class="form-group">
      <label>Username</label>
      <input type="text" name="username" class="form-control"
             value="<?= h($old['username']) ?>" required>
    </div>

    <div class="form-group">
      <label>Email</label>
      <input type="email" name="email" class="form-control"
             value="<?= h($old['email']) ?>" required>
    </div>

    <div class="form-group">
      <label>Password</label>
      <div class="input-group">
        <input type="password" name="password" id="password"
               class="form-control" required>
        <div class="input-group-append">
          <button class="btn btn-sm btn-outline-secondary" type="button"
                  id="togglePassword">Show</button>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label>Confirm Password</label>
      <div class="input-group">
        <input type="password" name="confirm_password" id="confirm_password"
               class="form-control" required>
        <div class="input-group-append">
          <button class="btn btn-sm btn-outline-secondary" type="button"
                  id="togglePassword2">Show</button>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label>2x2 Profile Picture</label>
      <input type="file" name="avatar" class="form-control-file" accept="image/*">
      <small class="small-muted">Preferably a square 2x2 style photo.</small>
    </div>

    <button type="submit" class="btn btn-brand btn-block mt-2">Create Account</button>

    <div class="footer-text">
      Already have an account?
      <a href="login.php">Login</a>
    </div>
  </form>
</div>

<script>
// show/hide password
(function(){
  const pwd1 = document.getElementById('password');
  const pwd2 = document.getElementById('confirm_password');
  const btn1 = document.getElementById('togglePassword');
  const btn2 = document.getElementById('togglePassword2');

  function toggle(input, btn){
    if (!input || !btn) return;
    const isPass = input.type === 'password';
    input.type = isPass ? 'text' : 'password';
    btn.textContent = isPass ? 'Hide' : 'Show';
  }

  if (btn1 && pwd1) btn1.addEventListener('click', function(e){
    e.preventDefault();
    toggle(pwd1, btn1);
  });

  if (btn2 && pwd2) btn2.addEventListener('click', function(e){
    e.preventDefault();
    toggle(pwd2, btn2);
  });
})();
</script>
</body>
</html>