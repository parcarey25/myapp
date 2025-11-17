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
</html>