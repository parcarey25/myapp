<<<<<<< HEAD
<?php
// FILE: login.php
session_start();
require __DIR__.'/db.php';

$error = '';

// If already logged in, go straight to role router
if (isset($_SESSION['user_id'])) {
    header('Location: home.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        // Find user by username
        if ($st = $conn->prepare("SELECT id, username, full_name, email, role, password FROM users WHERE username = ? LIMIT 1")) {
            $st->bind_param('s', $username);
            $st->execute();
            $res  = $st->get_result();
            $user = $res->fetch_assoc();
            $res->free();
            $st->close();

            if ($user) {
                $hash = $user['password'];

                // Support hashed AND plain text passwords (for school projects)
                $okPassword = false;
                if (password_verify($password, $hash)) {
                    $okPassword = true;
                } elseif ($password === $hash) {
                    $okPassword = true;
                }

                if ($okPassword) {
                    // ✅ Set sessions
                    $_SESSION['user_id']   = (int)$user['id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'] ?? '';
                    $_SESSION['role']      = strtolower(trim($user['role'] ?? 'member'));

                    // ✅ Send email notification (best effort)
                    $displayName = $user['full_name'] ?: $user['username'];

                    // 1) where to send
                    // Option A: send to the email from database (recommended)
                    $toEmail = $user['email'];

                    // Option B (for testing): force send to your own Gmail
                    // $toEmail = 'yourgmail@gmail.com'; // TODO: change this to your Gmail

                    if (!empty($toEmail)) {
                        $subject   = 'RJL Fitness Login Notification';
                        $loginTime = date('Y-m-d H:i:s');
                        $body = "Hello {$displayName},\n\n"
                              . "You have successfully logged in to the RJL Fitness system on {$loginTime}.\n\n"
                              . "If this was not you, please contact the administrator.\n\n"
                              . "This is an automatic message. Please do not reply.";

                        // 👇 Change this to an email that your server is allowed to send from
                        $fromEmail = 'no-reply@rjl-fitness.local'; // or your domain email
                        $headers   = "From: RJL Fitness <{$fromEmail}>\r\n";

                        // @ = ignore warning if mail() is not configured
                        @mail($toEmail, $subject, $body, $headers);
                    }

                    // ✅ Go to home router (home.php decides: member / trainer / staff / admin)
                    header('Location: home.php');
                    exit;
                } else {
                    $error = 'Invalid username or password.';
                }
            } else {
                $error = 'Invalid username or password.';
            }
        } else {
            $error = 'Database error.';
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
<style>
:root{
  --bg:#000;--card:#181818;--accent:#e53935;--accent-dark:#b71c1c;
  --border:#2a2a33;--text:#f5f5f5;--muted:#a0a0a0;
}
*{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
body{
  margin:0;background:var(--bg);color:var(--text);
  display:flex;min-height:100vh;align-items:center;justify-content:center;
}
.card{
  background:var(--card);
  border-radius:16px;
  border:1px solid var(--border);
  padding:24px 22px;
  width:100%;
  max-width:360px;
  box-shadow:0 16px 40px rgba(0,0,0,.7);
}
.title{font-size:1.4rem;font-weight:600;margin-bottom:4px;}
.subtitle{font-size:.9rem;color:var(--muted);margin-bottom:16px;}
label{
  display:block;font-size:.78rem;color:var(--muted);
  text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px;margin-top:8px;
}
input[type="text"],input[type="password"]{
  width:100%;padding:8px 10px;border-radius:8px;border:1px solid var(--border);
  background:#101010;color:var(--text);outline:none;
}
input[type="text"]:focus,input[type="password"]:focus{
  border-color:var(--accent);box-shadow:0 0 0 1px rgba(229,57,53,.3);
}
button{
  width:100%;margin-top:16px;border:none;border-radius:999px;padding:9px 14px;
  background:var(--accent);color:#fff;font-size:.9rem;font-weight:600;
  text-transform:uppercase;letter-spacing:.1em;cursor:pointer;
}
button:hover{background:var(--accent-dark);}
.error{
  margin-top:8px;padding:8px 10px;border-radius:8px;
  border:1px solid var(--accent);background:rgba(229,57,53,.1);
  font-size:.85rem;color:#ffb3b3;
}
.logo{display:flex;align-items:center;margin-bottom:10px;}
.logo-circle{
  width:32px;height:32px;border-radius:50%;background:#000;border:1px solid var(--accent);
  display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:700;color:#fff;margin-right:8px;
}
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-circle">R</div>
    <div>RJL Fitness</div>
  </div>
  <div class="title">Sign in</div>
  <div class="subtitle">Use your RJL Fitness account.</div>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <label for="username">Username</label>
    <input type="text" name="username" id="username" autocomplete="username" required>

    <label for="password">Password</label>
    <input type="password" name="password" id="password" autocomplete="current-password" required>

    <button type="submit">Login</button>
  </form>
</div>

<script>
// focus username automatically
document.getElementById('username').focus();
</script>
</body>
=======
<?php
// login.php — login + PHPMailer email + SMTP debug -> logs/mail.log + role redirect + flash banner
if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

/* =========================
   Settings helper (works without mysqlnd)
   ========================= */
function get_setting(mysqli $conn, string $key): ?string {
  $sql = "SELECT `value` FROM settings WHERE `key`=? LIMIT 1";
  $st  = $conn->prepare($sql);
  if (!$st) return null;
  $st->bind_param('s', $key);
  if (!$st->execute()) { $st->close(); return null; }
  $st->bind_result($val);
  $out = null; if ($st->fetch()) $out = $val;
  $st->close();
  return $out;
}

/* =========================
   Load SMTP + meta from settings (optional)
   ========================= */
$smtp = [
  'host'     => get_setting($conn,'smtp_host')     ?: '',
  'user'     => get_setting($conn,'smtp_user')     ?: '',
  'pass'     => get_setting($conn,'smtp_pass')     ?: '',
  'port'     => (int)(get_setting($conn,'smtp_port') ?: 0),
  'secure'   => get_setting($conn,'smtp_secure')   ?: '', // '', 'tls', 'ssl'
  'from'     => get_setting($conn,'contact_email') ?: '', // fallback below
  'fromName' => get_setting($conn,'site_name')     ?: 'RJL Fitness',
];

/* =========================
   PHPMailer include (UNCOMMENT ONE)
   ========================= */
// A) Composer autoload (if installed via Composer):
// require __DIR__ . '/vendor/autoload.php';

// B) Manual includes (if you copied PHPMailer into /phpmailer):
// require __DIR__.'/phpmailer/src/Exception.php';
// require __DIR__.'/phpmailer/src/PHPMailer.php';
// require __DIR__.'/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* =========================
   Email helper with logging to logs/mail.log
   ========================= */
function sendLoginEmail(array $smtp, string $toEmail, string $toName, string $username): void {
  // Ensure log dir/file exists
  $logDir = __DIR__ . '/logs';
  if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
  $logFile = $logDir . '/mail.log';

  // Marker that function ran
  error_log("=== sendLoginEmail() called for {$toEmail} at ".date('c')." ===\n", 3, $logFile);

  // If SMTP not configured, log why and exit
  if (empty($smtp['host']) || empty($smtp['user'])) {
    error_log("SMTP skipped: host/user missing\n", 3, $logFile);
    return;
  }

  $when = date('M d, Y g:ia');
  $ip   = $_SERVER['REMOTE_ADDR']     ?? 'unknown';
  $ua   = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

  $html = '
    <div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:auto;padding:16px;background:#111;color:#fff">
      <div style="text-align:center;margin-bottom:16px">
        <img src="photo/logo.jpg" alt="RJL Fitness" style="height:48px">
      </div>
      <h2 style="color:#ff3333;margin:0 0 8px">Login successful</h2>
      <p style="margin:0 0 12px">Hi <strong>'.htmlspecialchars($toName?:$username).'</strong>, your account just signed in.</p>
      <table style="width:100%;border-collapse:collapse;color:#ddd">
        <tr><td style="padding:6px 0;width:140px;color:#aaa">Username</td><td>'.htmlspecialchars($username).'</td></tr>
        <tr><td style="padding:6px 0;color:#aaa">Time</td><td>'.$when.'</td></tr>
        <tr><td style="padding:6px 0;color:#aaa">IP</td><td>'.$ip.'</td></tr>
        <tr><td style="padding:6px 0;color:#aaa">Device</td><td>'.htmlspecialchars($ua).'</td></tr>
      </table>
      <p style="margin:12px 0 0;color:#bbb">If this wasn’t you, please change your password.</p>
    </div>';
  $text = "Login successful\nUser: ".($toName?:$username)."\nUsername: $username\nTime: $when\nIP: $ip\nUA: $ua\n";

  try {
    $mail = new PHPMailer(true);

    // Write SMTP conversation to logs/mail.log
    $mail->SMTPDebug  = 2; // 0=off, 2=client+server
    $mail->Debugoutput = function($str, $level) use ($logFile) {
      error_log("SMTP($level): ".$str.PHP_EOL, 3, $logFile);
    };

    $mail->isSMTP();
    $mail->Host       = $smtp['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtp['user'];
    $mail->Password   = $smtp['pass'];

    if (($smtp['secure'] ?? '') === 'ssl') {
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
      $mail->Port       = $smtp['port'] ?: 465;
    } elseif (($smtp['secure'] ?? '') === 'tls') {
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port       = $smtp['port'] ?: 587;
    } else {
      $mail->Port       = $smtp['port'] ?: 25;
    }

    // Helpful for local dev
    $mail->SMTPOptions = ['ssl'=>[
      'verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true
    ]];

    // Gmail usually requires From == Username
    $fromEmail = $smtp['from'] ?: $smtp['user'];
    $fromName  = $smtp['fromName'] ?: 'RJL Fitness';
    $mail->setFrom($fromEmail, $fromName);

    $mail->addAddress($toEmail, $toName ?: $username);
    $mail->Subject = 'RJL Fitness: Login successful';
    $mail->isHTML(true);
    $mail->Body    = $html;
    $mail->AltBody = $text;

    $mail->send();
    error_log("=== MAIL OK to {$toEmail} ===\n", 3, $logFile);
  } catch (\Throwable $e) {
    error_log("=== MAIL FAIL: ".$e->getMessage()." ===\n", 3, $logFile);
  }
}

/* =========================
   Handle POST (login)
   ========================= */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $login = trim($_POST['login'] ?? '');   // email OR username
  $pass  = $_POST['password'] ?? '';

  if ($login === '' || $pass === '') {
    $errors[] = 'Please enter your email/username and password.';
  } else {
    $sql = "SELECT id, username, email, password, role, status, full_name
            FROM users
            WHERE email = ? OR username = ?
            LIMIT 1";
    if ($st = $conn->prepare($sql)) {
      $st->bind_param('ss', $login, $login);
      $st->execute();
      $res  = $st->get_result();
      $user = $res ? $res->fetch_assoc() : null;
      if ($res) $res->free();
      $st->close();

      if (!$user || !password_verify($pass, $user['password'])) {
        $errors[] = 'Invalid credentials.';
      } else {
        $status = strtolower($user['status'] ?? 'pending');
        if ($status !== 'active') {
          $errors[] = 'Your account is not active yet. Please visit the gym for activation.';
        } else {
          // ✅ success: set session
          $_SESSION['user_id']  = (int)$user['id'];
          $_SESSION['username'] = $user['username'];
          $_SESSION['role']     = $user['role'];

          // flash banner on next page
          $_SESSION['flash_success'] = 'You’ve successfully logged in.';

          // send email (non-blocking)
          if (!empty($user['email'])) {
            sendLoginEmail($smtp, $user['email'], $user['full_name'] ?? '', $user['username']);
          }

          // redirect by role
          switch (strtolower($user['role'])) {
            case 'admin':  header('Location: home_admin.php');  break;
            case 'staff':  header('Location: home_staff.php');  break;
            default:       header('Location: home_member.php'); break;
          }
          exit;
        }
      }
    } else {
      $errors[] = 'Database error. Please try again.';
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sign in | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
:root{--brand:#b30000;--hover:#ff1a1a;--bg:#111;--panel:#1a1a1a;--line:#2a2a2a}
body{background:#111;color:#fff;font-family:'Poppins',sans-serif;min-height:100vh;display:grid;place-items:center}
.card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px;max-width:420px;width:100%}
.form-control{background:#121212;border:1px solid #2a2a2a;color:#eee}
.btn-danger{background:var(--brand);border:none}.btn-danger:hover{background:var(--hover)}
a,a:hover{color:#fff}
.logo{display:block;margin:14px auto 6px;height:48px}
</style>
</head>
<body>
<div class="card p-4">
  <img src="photo/logo.jpg" class="logo" alt="RJL Fitness">
  <h4 class="text-center mb-3">Sign in</h4>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0">
      <?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul></div>
  <?php endif; ?>

  <form method="post" novalidate>
    <div class="form-group">
      <label>Email or Username</label>
      <input class="form-control" name="login" required value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" class="form-control" name="password" required>
    </div>
    <button class="btn btn-danger btn-block">Sign in</button>
  </form>

  <div class="text-center mt-3">
    <small>Don’t have an account? <a href="register.php">Register</a></small>
  </div>
</div>
</body>
>>>>>>> b78dc527f4ca1b402224214aa4f78775c370647f
</html>