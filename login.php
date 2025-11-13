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
</html>