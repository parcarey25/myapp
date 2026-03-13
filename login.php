<?php
// login.php – only ACTIVE users can login

if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/send_mail.php';

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Manila');
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Auto-expire old pending registrations if we have registration_expires_at
function has_column(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param('ss',$table,$column);
    $st->execute();
    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $st->close();
    return $ok;
}

if (has_column($conn,'users','registration_expires_at')) {
    $conn->query("
        UPDATE users
        SET status='expired'
        WHERE status='pending'
          AND registration_expires_at IS NOT NULL
          AND registration_expires_at < NOW()
    ");
}

$errors = [];
$usernameOrEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = trim($_POST['login_id'] ?? '');
    $password        = $_POST['password'] ?? '';

    if ($usernameOrEmail === '' || $password === '') {
        $errors[] = 'Please enter your username/email and password.';
    } else {
        // Find user by username OR email
        if ($st = $conn->prepare("
            SELECT id, username, email, password, role, status, full_name, id_number
            FROM users
            WHERE username = ? OR email = ?
            LIMIT 1
        ")) {
            $st->bind_param('ss',$usernameOrEmail,$usernameOrEmail);
            $st->execute();
            $res = $st->get_result();
            $user = $res ? $res->fetch_assoc() : null;
            if ($res) $res->free();
            $st->close();
        } else {
            $user = null;
            $errors[] = 'Database error (prepare failed).';
        }

        if (!$errors && !$user) {
            $errors[] = 'Invalid username/email or password.';
        } elseif (!$errors && $user) {
            if (!password_verify($password, $user['password'])) {
                $errors[] = 'Invalid username/email or password.';
            } else {
                $status = strtolower($user['status'] ?? 'pending');

                if ($status !== 'active') {
                    if ($status === 'pending') {
                        $errors[] = 'Your account is pending approval. Please wait for staff to approve your registration.';
                    } elseif ($status === 'pending_payment') {
                        $errors[] = 'Your account is pending payment. Please settle your registration / membership fee.';
                    } elseif ($status === 'expired') {
                        $errors[] = 'Your registration has expired. Please contact staff to register again.';
                    } elseif ($status === 'blocked') {
                        $errors[] = 'Your account is blocked. Please contact the administrator.';
                    } else {
                        $errors[] = 'Your account is not active. Please contact the administrator.';
                    }
                } else {
                    // All good: login
                    $_SESSION['user_id']   = (int)$user['id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['role']      = strtolower($user['role'] ?? 'member');
                    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                    $_SESSION['email']     = $user['email'] ?? null;
                    $_SESSION['id_number'] = $user['id_number'] ?? null;

                    // Optional: send login notice email
                    if (!empty($user['email']) && function_exists('sendLoginNoticeEmail')) {
                        $loginResult = sendLoginNoticeEmail(
                            $user['email'],
                            $user['full_name'] ?: $user['username'],
                            $user['id_number'] ?? ''
                        );
                        if (!$loginResult['ok']) {
                            @file_put_contents(
                                __DIR__.'/logs/mail.log',
                                '['.date('Y-m-d H:i:s')."] login email error: ".$loginResult['error'].PHP_EOL,
                                FILE_APPEND
                            );
                        }
                    }

                    header('Location: home.php');
                    exit;
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
<title>Login | RJL Fitness</title>
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
  max-width:380px;
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
.small-muted{
  font-size:.8rem;
  color:var(--muted);
}
</style>
</head>
<body>
<div class="card-auth">
  <div class="logo-wrap">
    <img src="photo/logo.jpg" alt="RJL Fitness">
    <h1>RJL FITNESS</h1>
  </div>

  <div class="form-title">Welcome Back</div>
  <div class="form-sub">Login with your approved RJL Fitness account.</div>

  <?php if ($errors): ?>
    <div class="alert alert-danger py-2 mb-2">
      <ul class="mb-0 pl-3">
        <?php foreach ($errors as $e): ?>
          <li><?= h($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <div class="form-group">
      <label>Username or Email</label>
      <input type="text" name="login_id" class="form-control"
             value="<?= h($usernameOrEmail) ?>" required>
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
      <small class="small-muted">
        Only accounts marked as <strong>Active</strong> by staff can login.
      </small>
    </div>

    <button type="submit" class="btn btn-brand btn-block mt-2">Login</button>

    <div class="footer-text">
      Don’t have an account?
      <a href="register.php">Register</a>
    </div>
  </form>
</div>

<script>
// show/hide password
(function(){
  const pwd = document.getElementById('password');
  const btn = document.getElementById('togglePassword');

  function toggle(){
    if (!pwd || !btn) return;
    const isPass = pwd.type === 'password';
    pwd.type = isPass ? 'text' : 'password';
    btn.textContent = isPass ? 'Hide' : 'Show';
  }

  if (btn && pwd) {
    btn.addEventListener('click', function(e){
      e.preventDefault();
      toggle();
    });
  }
})();
</script>
</body>
</html>