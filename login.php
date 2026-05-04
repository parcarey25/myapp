<?php
// login.php — RJL Fitness separated login portal
// Portal 1: Member only
// Portal 2: Staff / Trainer / Admin only

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/db.php';

// Optional mail files. These will load only if they exist.
$possibleMailFiles = [
    __DIR__ . '/mailer.php',
    __DIR__ . '/mail.php',
    __DIR__ . '/mail_config.php',
    __DIR__ . '/email_helper.php',
    __DIR__ . '/registration_email.php',
];

foreach ($possibleMailFiles as $mailFile) {
    if (is_file($mailFile)) {
        require_once $mailFile;
    }
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function has_column(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;

    if ($result) {
        $result->free();
    }

    $stmt->close();

    return $exists;
}

function redirect_by_role(string $role): void
{
    $role = strtolower(trim($role));

    if ($role === 'admin') {
        header('Location: home_admin.php');
        exit;
    }

    if ($role === 'staff') {
        header('Location: home_staff.php');
        exit;
    }

    if ($role === 'trainer') {
        if (file_exists(__DIR__ . '/home_trainer.php')) {
            header('Location: home_trainer.php');
        } else {
            header('Location: home_staff.php');
        }
        exit;
    }

    if ($role === 'member') {
        if (file_exists(__DIR__ . '/home_member.php')) {
            header('Location: home_member.php');
        } else {
            header('Location: home.php');
        }
        exit;
    }

    header('Location: home.php');
    exit;
}

// Auto-expire old pending registrations if your database supports it.
if (
    has_column($conn, 'users', 'registration_expires_at') &&
    has_column($conn, 'users', 'status')
) {
    $conn->query("
        UPDATE users
        SET status = 'expired'
        WHERE status = 'pending'
        AND registration_expires_at IS NOT NULL
        AND registration_expires_at < NOW()
    ");
}

$errors = [];
$success = $_GET['success'] ?? '';
$activePortal = $_POST['login_portal'] ?? ($_GET['portal'] ?? 'member');

if (!in_array($activePortal, ['member', 'staff'], true)) {
    $activePortal = 'member';
}

$oldLoginId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginPortal = $_POST['login_portal'] ?? 'member';
    $loginId = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $oldLoginId = $loginId;

    if (!in_array($loginPortal, ['member', 'staff'], true)) {
        $loginPortal = 'member';
    }

    $activePortal = $loginPortal;

    if ($loginId === '' || $password === '') {
        $errors[] = 'Please enter your username/email and password.';
    } else {
        $sql = "
            SELECT id, username, email, password, role, status, full_name, id_number
            FROM users
            WHERE username = ? OR email = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $errors[] = 'Database error. Please try again.';
            $user = null;
        } else {
            $stmt->bind_param('ss', $loginId, $loginId);
            $stmt->execute();

            $result = $stmt->get_result();
            $user = $result ? $result->fetch_assoc() : null;

            if ($result) {
                $result->free();
            }

            $stmt->close();
        }

        if (!$errors && !$user) {
            $errors[] = 'Invalid username/email or password.';
        }

        if (!$errors && $user) {
            $storedPassword = (string)($user['password'] ?? '');
            $passwordOk = password_verify($password, $storedPassword);

            if (!$passwordOk) {
                $errors[] = 'Invalid username/email or password.';
            }
        }

        if (!$errors && $user) {
            $role = strtolower(trim($user['role'] ?? 'member'));

            if ($loginPortal === 'member' && $role !== 'member') {
                $errors[] = 'This login is for members only. Staff, trainers, and admins must use the Staff Login.';
            }

            if ($loginPortal === 'staff' && !in_array($role, ['staff', 'trainer', 'admin'], true)) {
                $errors[] = 'Members cannot login using the Staff / Trainer / Admin portal. Please use Member Login.';
            }
        }

        if (!$errors && $user) {
            $status = strtolower(trim($user['status'] ?? 'pending'));

            if ($status !== 'active') {
                if ($status === 'pending') {
                    $errors[] = 'Your account is pending approval. Please wait for staff to approve your registration.';
                } elseif ($status === 'pending_payment') {
                    $errors[] = 'Your account is pending payment. Please settle your registration or membership fee.';
                } elseif ($status === 'expired') {
                    $errors[] = 'Your registration has expired. Please contact staff to register again.';
                } elseif ($status === 'blocked') {
                    $errors[] = 'Your account is blocked. Please contact the administrator.';
                } else {
                    $errors[] = 'Your account is not active. Please contact the administrator.';
                }
            }
        }

        if (!$errors && $user) {
            $role = strtolower(trim($user['role'] ?? 'member'));

            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $role;
            $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
            $_SESSION['email'] = $user['email'] ?? null;
            $_SESSION['id_number'] = $user['id_number'] ?? null;

            // Optional login notice email
            if (!empty($user['email']) && function_exists('sendLoginNoticeEmail')) {
                $loginResult = sendLoginNoticeEmail(
                    $user['email'],
                    !empty($user['full_name']) ? $user['full_name'] : $user['username'],
                    $user['id_number'] ?? ''
                );

                if (is_array($loginResult) && empty($loginResult['ok'])) {
                    if (!is_dir(__DIR__ . '/logs')) {
                        @mkdir(__DIR__ . '/logs', 0777, true);
                    }

                    @file_put_contents(
                        __DIR__ . '/logs/mail.log',
                        '[' . date('Y-m-d H:i:s') . '] login email error: ' . ($loginResult['error'] ?? 'Unknown error') . PHP_EOL,
                        FILE_APPEND
                    );
                }
            }

            redirect_by_role($role);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Login | RJL Fitness</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --red: #ed1c24;
            --red-dark: #8b0000;
            --red-soft: rgba(237, 28, 36, 0.16);
            --bg: #050505;
            --panel: #141414;
            --panel-2: #1d1d1d;
            --border: rgba(255, 255, 255, 0.11);
            --border-strong: rgba(255, 255, 255, 0.20);
            --text: #ffffff;
            --muted: #b8b8b8;
            --green: #24c46b;
            --yellow: #f5bd3d;
            --danger: #ff4b5c;
            --shadow: 0 26px 70px rgba(0, 0, 0, 0.58);
            --radius: 28px;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            color: var(--text);
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(237, 28, 36, 0.20), transparent 35%),
                radial-gradient(circle at bottom right, rgba(237, 28, 36, 0.12), transparent 30%),
                linear-gradient(135deg, #020202, #111111 52%, #050505);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .login-shell {
            width: min(1240px, calc(100vw - 34px));
            min-height: 100vh;
            margin: 0 auto;
            padding: 34px 0;
            display: grid;
            grid-template-columns: 0.86fr 1.14fr;
            gap: 22px;
            align-items: stretch;
        }

        .brand-panel,
        .login-panel {
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .brand-panel {
            position: relative;
            padding: 34px;
            min-height: 680px;
            background:
                linear-gradient(145deg, rgba(237, 28, 36, 0.86), rgba(10, 10, 10, 0.96)),
                linear-gradient(135deg, #1b1b1b, #070707);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .brand-panel::before {
            content: "";
            position: absolute;
            width: 430px;
            height: 430px;
            right: -190px;
            top: -160px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
        }

        .brand-panel::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: -115px;
            bottom: 70px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.25);
        }

        .brand-content,
        .brand-footer {
            position: relative;
            z-index: 2;
        }

        .brand-block {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 42px;
        }

        .brand-block img {
            width: 76px;
            height: 76px;
            border-radius: 22px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.88);
            background: #111;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.34);
        }

        .brand-title {
            font-size: 1.72rem;
            font-weight: 950;
            letter-spacing: -0.04em;
            line-height: 1;
        }

        .brand-subtitle {
            margin-top: 7px;
            color: rgba(255, 255, 255, 0.82);
            font-size: 0.76rem;
            font-weight: 900;
            letter-spacing: 0.28em;
            text-transform: uppercase;
        }

        .hero-kicker {
            display: inline-flex;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            font-size: 0.72rem;
            font-weight: 950;
            letter-spacing: 0.10em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .brand-panel h1 {
            margin: 0;
            font-size: clamp(2.4rem, 5vw, 4.5rem);
            line-height: 0.95;
            letter-spacing: -0.075em;
            font-weight: 950;
        }

        .brand-panel p {
            margin: 18px 0 0;
            max-width: 490px;
            color: rgba(255, 255, 255, 0.84);
            line-height: 1.65;
            font-size: 1rem;
        }

        .portal-rules {
            display: grid;
            gap: 12px;
            margin-top: 30px;
        }

        .rule-card {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 14px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.14);
            backdrop-filter: blur(8px);
        }

        .rule-icon {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            background: rgba(0, 0, 0, 0.25);
            flex: 0 0 auto;
        }

        .rule-card strong {
            display: block;
            font-size: 0.95rem;
        }

        .rule-card span {
            display: block;
            margin-top: 3px;
            color: rgba(255, 255, 255, 0.72);
            font-size: 0.84rem;
        }

        .brand-footer {
            margin-top: 32px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.16);
            color: rgba(255, 255, 255, 0.78);
            font-size: 0.9rem;
            line-height: 1.55;
        }

        .login-panel {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.065), rgba(255, 255, 255, 0.025));
            backdrop-filter: blur(14px);
            padding: 26px;
            display: flex;
            flex-direction: column;
        }

        .login-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            margin-bottom: 20px;
        }

        .login-header h2 {
            margin: 0;
            font-size: clamp(1.7rem, 3vw, 2.35rem);
            font-weight: 950;
            letter-spacing: -0.055em;
        }

        .login-header p {
            margin: 8px 0 0;
            color: var(--muted);
            line-height: 1.5;
        }

        .register-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 10px 16px;
            border-radius: 999px;
            border: 1px solid var(--border-strong);
            background: rgba(0, 0, 0, 0.22);
            color: #fff;
            font-weight: 900;
            font-size: 0.82rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            white-space: nowrap;
            transition: 0.2s ease;
        }

        .register-pill:hover {
            background: var(--red);
            border-color: var(--red);
            transform: translateY(-1px);
        }

        .alert {
            border-radius: 18px;
            padding: 14px 16px;
            margin-bottom: 18px;
            border: 1px solid var(--border);
            line-height: 1.5;
        }

        .alert-error {
            background: rgba(255, 75, 92, 0.14);
            border-color: rgba(255, 75, 92, 0.35);
            color: #ffe1e4;
        }

        .alert-success {
            background: rgba(36, 196, 107, 0.14);
            border-color: rgba(36, 196, 107, 0.35);
            color: #d9ffe8;
        }

        .alert ul {
            margin: 0;
            padding-left: 20px;
        }

        .portal-tabs {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }

        .portal-tab {
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 15px;
            background: rgba(0, 0, 0, 0.22);
            color: var(--muted);
            cursor: pointer;
            text-align: left;
            transition: 0.2s ease;
        }

        .portal-tab.active {
            border-color: rgba(237, 28, 36, 0.76);
            background:
                linear-gradient(135deg, rgba(237, 28, 36, 0.26), rgba(255, 255, 255, 0.04)),
                rgba(0, 0, 0, 0.30);
            box-shadow: 0 16px 32px rgba(237, 28, 36, 0.16);
            color: #fff;
        }

        .portal-tab:hover {
            transform: translateY(-1px);
            border-color: rgba(237, 28, 36, 0.55);
        }

        .portal-tab strong {
            display: block;
            font-size: 1rem;
            font-weight: 950;
            margin-bottom: 6px;
        }

        .portal-tab span {
            display: block;
            font-size: 0.83rem;
            line-height: 1.45;
            color: inherit;
            opacity: 0.86;
        }

        .login-form-card {
            padding: 20px;
            border-radius: 24px;
            background: rgba(0, 0, 0, 0.24);
            border: 1px solid var(--border);
        }

        .portal-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--red-soft);
            color: #ffbdc1;
            font-size: 0.75rem;
            font-weight: 950;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        .field {
            display: grid;
            gap: 7px;
            margin-bottom: 15px;
        }

        .field label {
            color: #fff;
            font-size: 0.88rem;
            font-weight: 800;
        }

        .control {
            width: 100%;
            min-height: 48px;
            border-radius: 14px;
            border: 1px solid var(--border-strong);
            background: rgba(0, 0, 0, 0.30);
            color: #fff;
            outline: none;
            padding: 12px 13px;
            font-size: 0.98rem;
            transition: 0.2s ease;
        }

        .control:focus {
            border-color: rgba(237, 28, 36, 0.82);
            box-shadow: 0 0 0 4px rgba(237, 28, 36, 0.16);
            background: rgba(0, 0, 0, 0.46);
        }

        .control::placeholder {
            color: rgba(255, 255, 255, 0.35);
        }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus {
            -webkit-text-fill-color: #ffffff;
            -webkit-box-shadow: 0 0 0 1000px #151515 inset;
            transition: background-color 9999s ease-in-out 0s;
        }

        .password-wrap {
            display: flex;
            align-items: stretch;
        }

        .password-wrap .control {
            border-radius: 14px 0 0 14px;
        }

        .toggle-pass {
            min-width: 70px;
            border: 1px solid var(--border-strong);
            border-left: 0;
            border-radius: 0 14px 14px 0;
            background: rgba(255, 255, 255, 0.06);
            color: var(--muted);
            cursor: pointer;
            font-weight: 800;
        }

        .toggle-pass:hover {
            color: #fff;
            background: rgba(237, 28, 36, 0.22);
        }

        .portal-note {
            margin: 6px 0 18px;
            padding: 13px 14px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.055);
            border: 1px solid var(--border);
            color: var(--muted);
            font-size: 0.88rem;
            line-height: 1.5;
        }

        .btn-login {
            width: 100%;
            min-height: 50px;
            border: 0;
            border-radius: 999px;
            padding: 13px 26px;
            background: linear-gradient(135deg, var(--red), #ff444c);
            color: #fff;
            font-size: 0.9rem;
            font-weight: 950;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            cursor: pointer;
            box-shadow: 0 16px 35px rgba(237, 28, 36, 0.34);
            transition: 0.2s ease;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }

        .bottom-links {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .bottom-links a {
            color: #fff;
            font-weight: 850;
        }

        .bottom-links a:hover {
            color: #ffb4b8;
        }

        @media (max-width: 980px) {
            .login-shell {
                grid-template-columns: 1fr;
            }

            .brand-panel {
                min-height: auto;
            }
        }

        @media (max-width: 650px) {
            .login-shell {
                width: calc(100vw - 22px);
                padding: 18px 0;
            }

            .brand-panel,
            .login-panel {
                padding: 20px;
                border-radius: 22px;
            }

            .login-header {
                flex-direction: column;
            }

            .portal-tabs {
                grid-template-columns: 1fr;
            }

            .register-pill {
                width: 100%;
            }

            .bottom-links {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<main class="login-shell">

    <section class="brand-panel">
        <div class="brand-content">
            <div class="brand-block">
                <img src="photo/logo.jpg" alt="RJL Fitness Logo">
                <div>
                    <div class="brand-title">RJL Fitness</div>
                    <div class="brand-subtitle">Power Center</div>
                </div>
            </div>

            <div class="hero-kicker">Secure Login Portal</div>
            <h1>Choose the correct login.</h1>
            <p>
                Members and staff have separate access portals. This prevents member accounts from entering the staff panel and prevents staff accounts from using the member login.
            </p>

            <div class="portal-rules">
                <div class="rule-card">
                    <div class="rule-icon">👤</div>
                    <div>
                        <strong>Member Login</strong>
                        <span>Only accounts with member role can enter here.</span>
                    </div>
                </div>

                <div class="rule-card">
                    <div class="rule-icon">🛡️</div>
                    <div>
                        <strong>Staff / Trainer / Admin Login</strong>
                        <span>Only management and trainer accounts can enter here.</span>
                    </div>
                </div>

                <div class="rule-card">
                    <div class="rule-icon">✅</div>
                    <div>
                        <strong>Active accounts only</strong>
                        <span>Pending, expired, or blocked accounts cannot login.</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="brand-footer">
            Use the correct login portal based on your account role. Contact the administrator if your account role or status is incorrect.
        </div>
    </section>

    <section class="login-panel">
        <div class="login-header">
            <div>
                <h2>Welcome Back</h2>
                <p>Login with your approved RJL Fitness account.</p>
            </div>

            <a href="register.php" class="register-pill">Create Account</a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= h($success) ?>
            </div>
        <?php endif; ?>

        <div class="portal-tabs">
            <button
                type="button"
                class="portal-tab <?= $activePortal === 'member' ? 'active' : '' ?>"
                onclick="setPortal('member')"
            >
                <strong>Member Login</strong>
                <span>For gym members only.</span>
            </button>

            <button
                type="button"
                class="portal-tab <?= $activePortal === 'staff' ? 'active' : '' ?>"
                onclick="setPortal('staff')"
            >
                <strong>Staff / Trainer / Admin</strong>
                <span>For RJL personnel only.</span>
            </button>
        </div>

        <form method="post" action="login.php" class="login-form-card">
            <input type="hidden" name="login_portal" id="login_portal" value="<?= h($activePortal) ?>">

            <div class="portal-badge" id="portalBadge">
                <?= $activePortal === 'member' ? '👤 Member Portal' : '🛡️ Staff Portal' ?>
            </div>

            <div class="field">
                <label for="login_id">Username or Email</label>
                <input
                    type="text"
                    id="login_id"
                    name="login_id"
                    class="control"
                    value="<?= h($oldLoginId) ?>"
                    placeholder="Enter username or email"
                    required
                    autocomplete="username"
                >
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="password-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="control"
                        placeholder="Enter password"
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="toggle-pass" onclick="togglePassword()">
                        Show
                    </button>
                </div>
            </div>

            <div class="portal-note" id="portalNote">
                <?= $activePortal === 'member'
                    ? 'This portal accepts member accounts only. Staff, trainer, and admin accounts must use the staff portal.'
                    : 'This portal accepts staff, trainer, and admin accounts only. Member accounts must use the member portal.'
                ?>
            </div>

            <button type="submit" class="btn-login" id="loginButton">
                <?= $activePortal === 'member' ? 'Login as Member' : 'Login as Staff' ?>
            </button>
        </form>

        <div class="bottom-links">
            <span>Don’t have an account? <a href="register.php">Register here</a></span>
            <span>Need help? Contact RJL Fitness staff.</span>
        </div>
    </section>

</main>

<script>
    function setPortal(portal) {
        const portalInput = document.getElementById('login_portal');
        const tabs = document.querySelectorAll('.portal-tab');
        const badge = document.getElementById('portalBadge');
        const note = document.getElementById('portalNote');
        const button = document.getElementById('loginButton');

        portalInput.value = portal;

        tabs.forEach(tab => tab.classList.remove('active'));

        if (portal === 'member') {
            tabs[0].classList.add('active');
            badge.textContent = '👤 Member Portal';
            note.textContent = 'This portal accepts member accounts only. Staff, trainer, and admin accounts must use the staff portal.';
            button.textContent = 'Login as Member';
        } else {
            tabs[1].classList.add('active');
            badge.textContent = '🛡️ Staff Portal';
            note.textContent = 'This portal accepts staff, trainer, and admin accounts only. Member accounts must use the member portal.';
            button.textContent = 'Login as Staff';
        }
    }

    function togglePassword() {
        const input = document.getElementById('password');
        const button = document.querySelector('.toggle-pass');

        if (!input) return;

        if (input.type === 'password') {
            input.type = 'text';
            button.textContent = 'Hide';
        } else {
            input.type = 'password';
            button.textContent = 'Show';
        }
    }
</script>

</body>
</html>