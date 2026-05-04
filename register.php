<?php
// register.php — Clean wide registration page for RJL Fitness

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/db.php';

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

function bind_params(mysqli_stmt $stmt, string $types, array &$params): bool
{
    $refs = [];
    $refs[] = $types;

    foreach ($params as &$value) {
        $refs[] = &$value;
    }

    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

function generate_id_number(mysqli $conn): string
{
    if (!has_column($conn, 'users', 'id_number')) {
        return '';
    }

    $prefix = 'M-0225-';
    $lastSuffix = 0;

    $stmt = $conn->prepare("
        SELECT id_number
        FROM users
        WHERE id_number LIKE CONCAT(?, '%')
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('s', $prefix);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result && ($row = $result->fetch_assoc())) {
            $existing = $row['id_number'] ?? '';
            $suffixPart = substr($existing, strlen($prefix));

            if ($suffixPart !== '' && ctype_digit($suffixPart)) {
                $lastSuffix = (int)$suffixPart;
            }
        }

        if ($result) {
            $result->free();
        }

        $stmt->close();
    }

    $next = $lastSuffix + 1;

    while (true) {
        $candidate = $prefix . str_pad((string)$next, 2, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare("SELECT id FROM users WHERE id_number = ? LIMIT 1");

        if (!$stmt) {
            return $candidate;
        }

        $stmt->bind_param('s', $candidate);
        $stmt->execute();

        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;

        if ($result) {
            $result->free();
        }

        $stmt->close();

        if (!$exists) {
            return $candidate;
        }

        $next++;
    }
}

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
$success = '';

$old = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'birthday' => '',
    'phone' => '',
    'address' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $birthdayInput = trim($_POST['birthday'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    $old = [
        'full_name' => $fullName,
        'username' => $username,
        'email' => $email,
        'birthday' => $birthdayInput,
        'phone' => $phone,
        'address' => $address,
    ];

    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    }

    if ($username === '') {
        $errors[] = 'Username is required.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required.';
    }

    if ($phone === '') {
        $errors[] = 'Contact number is required.';
    }

    if ($address === '') {
        $errors[] = 'Address is required.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    $birthdate = null;

    if ($birthdayInput !== '') {
        $birthdayDate = DateTime::createFromFormat('Y-m-d', $birthdayInput);

        if (!$birthdayDate || $birthdayDate->format('Y-m-d') !== $birthdayInput) {
            $errors[] = 'Birthday is not a valid date.';
        } else {
            $birthdate = $birthdayInput;
        }
    }

    if (!$errors && has_column($conn, 'users', 'username')) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");

        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $errors[] = 'Username is already taken.';
            }

            if ($result) {
                $result->free();
            }

            $stmt->close();
        }
    }

    if (!$errors && has_column($conn, 'users', 'email')) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");

        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $errors[] = 'Email is already registered.';
            }

            if ($result) {
                $result->free();
            }

            $stmt->close();
        }
    }

    $avatarPath = 'photo/logo.jpg';

    if (
        !$errors &&
        isset($_FILES['avatar']) &&
        !empty($_FILES['avatar']['name']) &&
        $_FILES['avatar']['error'] === UPLOAD_ERR_OK
    ) {
        $file = $_FILES['avatar'];

        if ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Profile picture must be 5MB or smaller.';
        } else {
            $allowedMime = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];

            $mime = '';

            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';

                if ($finfo) {
                    finfo_close($finfo);
                }
            }

            if (!isset($allowedMime[$mime])) {
                $errors[] = 'Profile picture must be JPG, PNG, WEBP, or GIF.';
            } else {
                $uploadDir = __DIR__ . '/uploads/avatars/';

                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }

                $safeUsername = preg_replace('/[^a-zA-Z0-9_]/', '_', $username);
                $extension = $allowedMime[$mime];
                $fileName = 'avatar_' . $safeUsername . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                $destination = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $avatarPath = 'uploads/avatars/' . $fileName;
                } else {
                    $errors[] = 'Failed to save profile picture.';
                }
            }
        }
    }

    if (!$errors) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $idNumber = generate_id_number($conn);
        $role = 'member';
        $status = 'pending';

        $deadline = new DateTime('+2 days');
        $deadlineSql = $deadline->format('Y-m-d H:i:s');
        $deadlineText = $deadline->format('M d, Y g:ia');

        $columns = [];
        $placeholders = [];
        $types = '';
        $params = [];

        if (has_column($conn, 'users', 'full_name')) {
            $columns[] = 'full_name';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $fullName;
        }

        if (has_column($conn, 'users', 'username')) {
            $columns[] = 'username';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $username;
        }

        if (has_column($conn, 'users', 'email')) {
            $columns[] = 'email';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $email;
        }

        if (has_column($conn, 'users', 'password')) {
            $columns[] = 'password';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $passwordHash;
        }

        if (has_column($conn, 'users', 'role')) {
            $columns[] = 'role';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $role;
        }

        if (has_column($conn, 'users', 'status')) {
            $columns[] = 'status';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $status;
        }

        if ($idNumber !== '' && has_column($conn, 'users', 'id_number')) {
            $columns[] = 'id_number';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $idNumber;
        }

        if (has_column($conn, 'users', 'phone')) {
            $columns[] = 'phone';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $phone;
        }

        if (has_column($conn, 'users', 'contact_number')) {
            $columns[] = 'contact_number';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $phone;
        }

        if (has_column($conn, 'users', 'address')) {
            $columns[] = 'address';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $address;
        }

        if ($birthdate && has_column($conn, 'users', 'birthday')) {
            $columns[] = 'birthday';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $birthdate;
        }

        if ($birthdate && has_column($conn, 'users', 'birthdate')) {
            $columns[] = 'birthdate';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $birthdate;
        }

        $avatarColumn = null;

        if (has_column($conn, 'users', 'avatar_path')) {
            $avatarColumn = 'avatar_path';
        } elseif (has_column($conn, 'users', 'avatar')) {
            $avatarColumn = 'avatar';
        } elseif (has_column($conn, 'users', 'profile_pic')) {
            $avatarColumn = 'profile_pic';
        }

        if ($avatarColumn) {
            $columns[] = $avatarColumn;
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $avatarPath;
        }

        if (has_column($conn, 'users', 'registration_expires_at')) {
            $columns[] = 'registration_expires_at';
            $placeholders[] = '?';
            $types .= 's';
            $params[] = $deadlineSql;
        }

        if (empty($columns)) {
            $errors[] = 'Users table has no supported columns.';
        } else {
            $sql = "INSERT INTO users (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                $errors[] = 'Prepare failed when saving account: ' . $conn->error;
            } else {
                bind_params($stmt, $types, $params);
                $ok = $stmt->execute();
                $newUserId = $ok ? $stmt->insert_id : 0;
                $stmtError = $stmt->error;
                $stmt->close();

                if (!$ok || $newUserId <= 0) {
                    $errors[] = 'Could not create account: ' . $stmtError;
                } else {
                    if (function_exists('sendRegistrationFeeEmail')) {
                        $emailResult = sendRegistrationFeeEmail($email, $fullName, $idNumber, $deadlineText);

                        if (is_array($emailResult) && empty($emailResult['ok'])) {
                            if (!is_dir(__DIR__ . '/logs')) {
                                @mkdir(__DIR__ . '/logs', 0777, true);
                            }

                            @file_put_contents(
                                __DIR__ . '/logs/mail.log',
                                '[' . date('Y-m-d H:i:s') . '] register email error: ' . ($emailResult['error'] ?? 'Unknown error') . PHP_EOL,
                                FILE_APPEND
                            );
                        }
                    }

                    $success = "Registration successful. Your account is pending approval and payment.";

                    if ($idNumber !== '') {
                        $success .= " Your ID Number is {$idNumber}.";
                    }

                    $success .= " Please check your email for instructions. You have 48 hours to settle the registration fee.";

                    $old = [
                        'full_name' => '',
                        'username' => '',
                        'email' => '',
                        'birthday' => '',
                        'phone' => '',
                        'address' => '',
                    ];
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
    <title>Register | RJL Fitness</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --red: #ed1c24;
            --red-dark: #850000;
            --red-soft: rgba(237, 28, 36, 0.18);
            --bg: #050505;
            --panel: #141414;
            --panel-2: #1e1e1e;
            --border: rgba(255, 255, 255, 0.11);
            --border-strong: rgba(255, 255, 255, 0.18);
            --text: #ffffff;
            --muted: #b8b8b8;
            --green: #24c46b;
            --danger: #ff4b5c;
            --shadow: 0 26px 70px rgba(0, 0, 0, 0.55);
            --radius: 26px;
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
                radial-gradient(circle at top left, rgba(237, 28, 36, 0.18), transparent 35%),
                radial-gradient(circle at bottom right, rgba(237, 28, 36, 0.10), transparent 28%),
                linear-gradient(135deg, #020202, #101010 52%, #050505);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .register-shell {
            width: min(1240px, calc(100vw - 34px));
            min-height: 100vh;
            margin: 0 auto;
            padding: 34px 0;
            display: grid;
            grid-template-columns: 0.82fr 1.18fr;
            gap: 22px;
            align-items: stretch;
        }

        .hero-panel,
        .form-panel {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .hero-panel {
            position: relative;
            background:
                linear-gradient(145deg, rgba(237, 28, 36, 0.88), rgba(12, 12, 12, 0.96)),
                linear-gradient(135deg, #1c1c1c, #080808);
            padding: 34px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 720px;
        }

        .hero-panel::before {
            content: "";
            position: absolute;
            width: 420px;
            height: 420px;
            right: -180px;
            top: -150px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
        }

        .hero-panel::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: -120px;
            bottom: 70px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.22);
        }

        .hero-content,
        .hero-footer {
            position: relative;
            z-index: 2;
        }

        .brand-block {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 40px;
        }

        .brand-block img {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.85);
            background: #111;
            box-shadow: 0 15px 35px rgba(0,0,0,0.32);
        }

        .brand-title {
            font-size: 1.65rem;
            font-weight: 950;
            line-height: 1;
            letter-spacing: -0.04em;
        }

        .brand-subtitle {
            margin-top: 6px;
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

        .hero-panel h1 {
            margin: 0;
            font-size: clamp(2.3rem, 5vw, 4.4rem);
            line-height: 0.95;
            letter-spacing: -0.07em;
            font-weight: 950;
        }

        .hero-panel p {
            margin: 18px 0 0;
            max-width: 470px;
            color: rgba(255, 255, 255, 0.84);
            line-height: 1.65;
            font-size: 1rem;
        }

        .benefit-grid {
            display: grid;
            gap: 12px;
            margin-top: 28px;
        }

        .benefit-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.13);
            backdrop-filter: blur(8px);
        }

        .benefit-icon {
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            border-radius: 14px;
            background: rgba(0, 0, 0, 0.23);
        }

        .benefit-item strong {
            display: block;
            font-size: 0.95rem;
        }

        .benefit-item span {
            display: block;
            margin-top: 3px;
            color: rgba(255, 255, 255, 0.72);
            font-size: 0.84rem;
        }

        .hero-footer {
            margin-top: 32px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.16);
            color: rgba(255, 255, 255, 0.78);
            font-size: 0.9rem;
            line-height: 1.55;
        }

        .form-panel {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.065), rgba(255, 255, 255, 0.025));
            backdrop-filter: blur(14px);
            padding: 26px;
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            margin-bottom: 20px;
        }

        .form-header h2 {
            margin: 0;
            font-size: clamp(1.6rem, 3vw, 2.2rem);
            font-weight: 950;
            letter-spacing: -0.05em;
        }

        .form-header p {
            margin: 8px 0 0;
            color: var(--muted);
            line-height: 1.5;
        }

        .login-pill {
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

        .login-pill:hover {
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

        .register-form {
            display: grid;
            gap: 18px;
        }

        .form-section {
            padding: 18px;
            border-radius: 22px;
            background: rgba(0, 0, 0, 0.22);
            border: 1px solid var(--border);
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            font-weight: 950;
            letter-spacing: 0.02em;
        }

        .section-title span {
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            background: var(--red-soft);
            color: #ffb4b8;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .field {
            display: grid;
            gap: 7px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field label {
            color: #fff;
            font-size: 0.88rem;
            font-weight: 800;
        }

        .field small {
            color: var(--muted);
            font-size: 0.82rem;
            line-height: 1.45;
        }

        .control,
        textarea {
            width: 100%;
            min-height: 46px;
            border-radius: 14px;
            border: 1px solid var(--border-strong);
            background: rgba(0, 0, 0, 0.30);
            color: #fff;
            outline: none;
            padding: 11px 13px;
            font-size: 0.96rem;
            transition: 0.2s ease;
        }

        textarea {
            min-height: 96px;
            resize: vertical;
            font-family: inherit;
        }

        .control:focus,
        textarea:focus {
            border-color: rgba(237, 28, 36, 0.82);
            box-shadow: 0 0 0 4px rgba(237, 28, 36, 0.16);
            background: rgba(0, 0, 0, 0.46);
        }

        .control::placeholder,
        textarea::placeholder {
            color: rgba(255, 255, 255, 0.35);
        }

        .birthday-date-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .birthday-date-row label {
            margin: 0;
            color: #ffffff;
            font-size: 0.95rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .birthday-date-input {
            width: 190px;
            height: 42px;
            border-radius: 8px;
            border: 1px solid var(--border-strong);
            background: #ffffff;
            color: #111111;
            padding: 0 10px;
            font-size: 0.95rem;
            outline: none;
        }

        .birthday-date-input:focus {
            border-color: rgba(237, 28, 36, 0.82);
            box-shadow: 0 0 0 4px rgba(237, 28, 36, 0.16);
        }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        textarea:-webkit-autofill {
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
            min-width: 64px;
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

        .upload-box {
            position: relative;
            min-height: 150px;
            display: grid;
            place-items: center;
            text-align: center;
            border: 1.5px dashed rgba(255, 255, 255, 0.24);
            border-radius: 18px;
            background: rgba(0, 0, 0, 0.24);
            cursor: pointer;
            overflow: hidden;
        }

        .upload-box input {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .upload-box strong {
            display: block;
            color: #fff;
            margin-bottom: 5px;
        }

        .upload-box span {
            color: var(--muted);
            font-size: 0.88rem;
        }

        .avatar-preview {
            display: none;
            width: 100%;
            max-height: 260px;
            object-fit: contain;
            border-radius: 16px;
            margin-top: 12px;
            background: rgba(0, 0, 0, 0.40);
            border: 1px solid var(--border);
        }

        .submit-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            padding: 2px 4px 0;
        }

        .submit-note {
            color: var(--muted);
            font-size: 0.86rem;
            line-height: 1.45;
            max-width: 420px;
        }

        .btn-submit {
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

        .btn-submit:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }

        @media (max-width: 980px) {
            .register-shell {
                grid-template-columns: 1fr;
            }

            .hero-panel {
                min-height: auto;
            }
        }

        @media (max-width: 650px) {
            .register-shell {
                width: calc(100vw - 22px);
                padding: 18px 0;
            }

            .hero-panel,
            .form-panel {
                padding: 20px;
                border-radius: 22px;
            }

            .form-header {
                flex-direction: column;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .birthday-date-row {
                align-items: flex-start;
                flex-direction: column;
            }

            .birthday-date-input {
                width: 100%;
            }

            .submit-row {
                flex-direction: column;
                align-items: stretch;
            }

            .btn-submit,
            .login-pill {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<main class="register-shell">

    <section class="hero-panel">
        <div class="hero-content">
            <div class="brand-block">
                <img src="photo/logo.jpg" alt="RJL Fitness Logo">
                <div>
                    <div class="brand-title">RJL Fitness</div>
                    <div class="brand-subtitle">Power Center</div>
                </div>
            </div>

            <div class="hero-kicker">Member Registration</div>
            <h1>Start your fitness journey.</h1>
            <p>
                Create your member account and submit your details.
                Your registration will be reviewed and activated by RJL Fitness staff after verification.
            </p>

            <div class="benefit-grid">
                <div class="benefit-item">
                    <div class="benefit-icon">📝</div>
                    <div>
                        <strong>Easy registration</strong>
                        <span>Fill out your member details in one clean form.</span>
                    </div>
                </div>

                <div class="benefit-item">
                    <div class="benefit-icon">✅</div>
                    <div>
                        <strong>Staff approval</strong>
                        <span>Your account will be reviewed before activation.</span>
                    </div>
                </div>

                <div class="benefit-item">
                    <div class="benefit-icon">🏋️</div>
                    <div>
                        <strong>Member access</strong>
                        <span>Use your account for membership, attendance, and gym services.</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="hero-footer">
            Already registered? Use the login button on the form panel to access your account.
        </div>
    </section>

    <section class="form-panel">
        <div class="form-header">
            <div>
                <h2>Create Account</h2>
                <p>Complete the information below.</p>
            </div>

            <a href="login.php" class="login-pill">Login</a>
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

        <form class="register-form" method="post" action="register.php" enctype="multipart/form-data">

            <div class="form-section">
                <div class="section-title">
                    <span>1</span>
                    Personal Information
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            class="control"
                            value="<?= h($old['full_name']) ?>"
                            placeholder="Enter your full name"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="phone">Contact Number</label>
                        <input
                            type="text"
                            id="phone"
                            name="phone"
                            class="control"
                            value="<?= h($old['phone']) ?>"
                            placeholder="09XXXXXXXXX"
                            required
                        >
                    </div>

                    <div class="field full">
                        <div class="birthday-date-row">
                            <label for="birthday">Birthday</label>

                            <input
                                type="date"
                                id="birthday"
                                name="birthday"
                                class="birthday-date-input"
                                value="<?= h($old['birthday']) ?>"
                            >
                        </div>

                        
                    </div>

                    <div class="field full">
                        <label for="address">Address</label>
                        <textarea
                            id="address"
                            name="address"
                            placeholder="Enter your complete address"
                            required
                        ><?= h($old['address']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="section-title">
                    <span>2</span>
                    Account Details
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="username">Username</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="control"
                            value="<?= h($old['username']) ?>"
                            placeholder="Choose a username"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="email">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="control"
                            value="<?= h($old['email']) ?>"
                            placeholder="example@email.com"
                            required
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
                                placeholder="Minimum 6 characters"
                                required
                            >
                            <button type="button" class="toggle-pass" onclick="togglePassword('password', this)">
                                Show
                            </button>
                        </div>
                    </div>

                    <div class="field">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-wrap">
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="control"
                                placeholder="Repeat password"
                                required
                            >
                            <button type="button" class="toggle-pass" onclick="togglePassword('confirm_password', this)">
                                Show
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="section-title">
                    <span>3</span>
                    Profile Picture
                </div>

                <div class="field">
                    <label>2x2 Profile Picture</label>

                    <label class="upload-box" for="avatar">
                        <input
                            type="file"
                            id="avatar"
                            name="avatar"
                            accept="image/jpeg,image/png,image/webp,image/gif"
                        >

                        <div>
                            <strong id="uploadText">Upload Profile Photo</strong>
                            <span>Preferably square 2x2 style photo. JPG, PNG, WEBP, or GIF. Max 5MB.</span>
                        </div>
                    </label>

                    <img id="avatarPreview" class="avatar-preview" alt="Profile preview">
                </div>
            </div>

            <div class="submit-row">
                <div class="submit-note">
                    Your account will be marked as pending until reviewed and activated by staff.
                </div>

                <button type="submit" class="btn-submit">
                    Create Account
                </button>
            </div>

        </form>
    </section>

</main>

<script>
    function togglePassword(inputId, button) {
        const input = document.getElementById(inputId);

        if (!input) return;

        if (input.type === 'password') {
            input.type = 'text';
            button.textContent = 'Hide';
        } else {
            input.type = 'password';
            button.textContent = 'Show';
        }
    }

    const avatarInput = document.getElementById('avatar');
    const avatarPreview = document.getElementById('avatarPreview');
    const uploadText = document.getElementById('uploadText');

    if (avatarInput) {
        avatarInput.addEventListener('change', function () {
            const file = this.files && this.files[0];

            if (!file) {
                avatarPreview.style.display = 'none';
                uploadText.textContent = 'Upload Profile Photo';
                return;
            }

            uploadText.textContent = file.name;

            const reader = new FileReader();

            reader.onload = function (event) {
                avatarPreview.src = event.target.result;
                avatarPreview.style.display = 'block';
            };

            reader.readAsDataURL(file);
        });
    }
</script>

</body>
</html>