<?php
// rfid_load.php — Load RFID wallet + receipt + email

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

date_default_timezone_set('Asia/Manila');

/* Optional mail files */
$possibleMailFiles = [
    __DIR__ . '/mailer.php',
    __DIR__ . '/mail.php',
    __DIR__ . '/mail_config.php',
    __DIR__ . '/email_helper.php',
    __DIR__ . '/vendor/autoload.php',
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

function peso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function table_exists(mysqli $conn, string $table): bool
{
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");

    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();

    return $exists;
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

function first_existing_column(mysqli $conn, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (has_column($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function get_mail_const(string $primary, string $fallback = '', string $default = ''): string
{
    if (defined($primary)) {
        return (string)constant($primary);
    }

    if ($fallback !== '' && defined($fallback)) {
        return (string)constant($fallback);
    }

    return $default;
}

function get_mail_port(): int
{
    if (defined('SMTP_PORT')) {
        return (int)SMTP_PORT;
    }

    if (defined('MAIL_PORT')) {
        return (int)MAIL_PORT;
    }

    return 587;
}

function ensure_wallet_load_logs_table(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS wallet_load_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            staff_id INT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            old_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            new_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            rfid_uid VARCHAR(150) NULL,
            receipt_no VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

if (!function_exists('sendWalletLoadReceiptEmail')) {
    function sendWalletLoadReceiptEmail(string $toEmail, string $toName, array $receipt): array
    {
        $subject = 'RJL Fitness RFID Wallet Load Receipt';

        $plainBody =
            "Hello {$toName},\n\n" .
            "Your RFID wallet has been successfully loaded.\n\n" .
            "Receipt No.: {$receipt['receipt_no']}\n" .
            "Member Name: {$receipt['member_name']}\n" .
            "ID Number: {$receipt['id_number']}\n" .
            "Amount Loaded: " . peso($receipt['amount']) . "\n" .
            "Old Balance: " . peso($receipt['old_balance']) . "\n" .
            "New Balance: " . peso($receipt['new_balance']) . "\n" .
            "Date Loaded: {$receipt['load_date_display']}\n" .
            "Processed By: {$receipt['staff_name']}\n\n" .
            "Thank you,\n" .
            "RJL Fitness";

        $htmlBody = '
            <div style="font-family:Arial,sans-serif;background:#f4f4f4;padding:24px;">
                <div style="max-width:620px;margin:auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #ddd;">
                    <div style="background:#970000;color:#ffffff;padding:20px;">
                        <h2 style="margin:0;">RJL Fitness</h2>
                        <p style="margin:6px 0 0;">RFID Wallet Load Receipt</p>
                    </div>

                    <div style="padding:22px;color:#111;">
                        <p>Hello <strong>' . h($toName) . '</strong>,</p>
                        <p>Your RFID wallet has been successfully loaded.</p>

                        <table style="width:100%;border-collapse:collapse;margin-top:16px;">
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Receipt No.</td><td style="padding:8px;border-bottom:1px solid #eee;"><strong>' . h($receipt['receipt_no']) . '</strong></td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Member Name</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['member_name']) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">ID Number</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['id_number']) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Amount Loaded</td><td style="padding:8px;border-bottom:1px solid #eee;"><strong>' . h(peso($receipt['amount'])) . '</strong></td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Old Balance</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h(peso($receipt['old_balance'])) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">New Balance</td><td style="padding:8px;border-bottom:1px solid #eee;"><strong>' . h(peso($receipt['new_balance'])) . '</strong></td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Date Loaded</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['load_date_display']) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Processed By</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['staff_name']) . '</td></tr>
                        </table>

                        <p style="margin-top:20px;">Thank you,<br><strong>RJL Fitness</strong></p>
                    </div>
                </div>
            </div>
        ';

        $smtpHost = get_mail_const('SMTP_HOST', 'MAIL_HOST');
        $smtpUser = get_mail_const('SMTP_USERNAME', 'MAIL_USERNAME', get_mail_const('SMTP_USER', 'MAIL_USER'));
        $smtpPass = get_mail_const('SMTP_PASSWORD', 'MAIL_PASSWORD', get_mail_const('SMTP_PASS', 'MAIL_PASS'));
        $fromEmail = get_mail_const('SMTP_FROM_EMAIL', 'MAIL_FROM_EMAIL', $smtpUser);
        $fromName = get_mail_const('SMTP_FROM_NAME', 'MAIL_FROM_NAME', 'RJL Fitness');

        if (
            class_exists('\PHPMailer\PHPMailer\PHPMailer') &&
            $smtpHost !== '' &&
            $smtpUser !== '' &&
            $smtpPass !== '' &&
            $fromEmail !== ''
        ) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
                $mail->Port = get_mail_port();
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($toEmail, $toName);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $htmlBody;
                $mail->AltBody = $plainBody;
                $mail->send();

                return ['ok' => true, 'error' => ''];
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: RJL Fitness <' . ($fromEmail !== '' ? $fromEmail : 'no-reply@rjlfitness.local') . '>',
        ];

        $sent = @mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));

        if ($sent) {
            return ['ok' => true, 'error' => ''];
        }

        return ['ok' => false, 'error' => 'Email sending is not configured. Set up Gmail SMTP in mailer.php or mail_config.php.'];
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUsername = $_SESSION['username'] ?? 'staff';
$currentRole = strtolower(trim($_SESSION['role'] ?? 'staff'));

if ($currentUserId <= 0) {
    header('Location: login.php');
    exit;
}

$homeLink = 'home.php';

if ($currentRole === 'admin') {
    $homeLink = 'home_admin.php';
} elseif ($currentRole === 'staff') {
    $homeLink = 'home_staff.php';
} elseif ($currentRole === 'trainer') {
    $homeLink = file_exists(__DIR__ . '/home_trainer.php') ? 'home_trainer.php' : 'home_staff.php';
}

$RFID_COLUMN = first_existing_column($conn, 'users', [
    'rfid_uid',
    'rfid',
    'rfid_code',
    'card_uid',
]);

$WALLET_COLUMN = first_existing_column($conn, 'users', [
    'wallet_balance',
    'rfid_balance',
    'balance',
]);

$HAS_FULL_NAME = has_column($conn, 'users', 'full_name');
$HAS_EMAIL = has_column($conn, 'users', 'email');
$HAS_ID_NUMBER = has_column($conn, 'users', 'id_number');

$setupError = '';

if (!$RFID_COLUMN || !$WALLET_COLUMN) {
    $setupError = 'Database setup missing. users table needs rfid_uid and wallet_balance columns.';
}

$errors = [];
$success = '';
$emailNotice = '';
$showReceipt = false;
$receiptData = null;

$rfidUid = trim($_POST['rfid_uid'] ?? '');
$amountInput = trim($_POST['amount'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setupError === '') {
    if ($rfidUid === '') {
        $errors[] = 'Please scan or enter the RFID UID.';
    }

    if ($amountInput === '' || !is_numeric($amountInput)) {
        $errors[] = 'Please enter a valid amount to load.';
    }

    $amount = (float)$amountInput;

    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    }

    if ($amount > 100000) {
        $errors[] = 'Amount is too large. Please check the value.';
    }

    if (empty($errors)) {
        $safeRfidColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $RFID_COLUMN);
        $safeWalletColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $WALLET_COLUMN);

        $fullNameSelect = $HAS_FULL_NAME ? 'full_name' : "'' AS full_name";
        $emailSelect = $HAS_EMAIL ? 'email' : "'' AS email";
        $idNumberSelect = $HAS_ID_NUMBER ? 'id_number' : "'' AS id_number";

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                SELECT
                    id,
                    username,
                    {$fullNameSelect},
                    {$emailSelect},
                    {$idNumberSelect},
                    `{$safeWalletColumn}` AS wallet_balance
                FROM users
                WHERE `{$safeRfidColumn}` = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception('Database error while checking RFID card.');
            }

            $stmt->bind_param('s', $rfidUid);
            $stmt->execute();

            $result = $stmt->get_result();
            $member = $result ? ($result->fetch_assoc() ?: null) : null;

            if ($result) {
                $result->free();
            }

            $stmt->close();

            if (!$member) {
                throw new Exception('No member found for this RFID card.');
            }

            $memberId = (int)$member['id'];
            $memberName = trim((string)($member['full_name'] ?? ''));

            if ($memberName === '') {
                $memberName = $member['username'] ?? 'Member';
            }

            $memberEmail = trim((string)($member['email'] ?? ''));
            $idNumber = trim((string)($member['id_number'] ?? ''));
            $oldBalance = (float)($member['wallet_balance'] ?? 0);
            $newBalance = $oldBalance + $amount;

            $stmt = $conn->prepare("
                UPDATE users
                SET `{$safeWalletColumn}` = COALESCE(`{$safeWalletColumn}`, 0) + ?
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception('Failed to prepare wallet update.');
            }

            $stmt->bind_param('di', $amount, $memberId);

            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to load RFID wallet.');
            }

            $stmt->close();

            $receiptNo = 'LOAD-' . date('Ymd-His') . '-' . $memberId;
            $loadDateSql = date('Y-m-d H:i:s');

            ensure_wallet_load_logs_table($conn);

            $stmt = $conn->prepare("
                INSERT INTO wallet_load_logs
                    (user_id, staff_id, amount, old_balance, new_balance, rfid_uid, receipt_no, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'iidddsss',
                    $memberId,
                    $currentUserId,
                    $amount,
                    $oldBalance,
                    $newBalance,
                    $rfidUid,
                    $receiptNo,
                    $loadDateSql
                );

                $stmt->execute();
                $stmt->close();
            }

            if (
                table_exists($conn, 'payments') &&
                has_column($conn, 'payments', 'user_id') &&
                has_column($conn, 'payments', 'staff_id') &&
                has_column($conn, 'payments', 'amount') &&
                has_column($conn, 'payments', 'method') &&
                has_column($conn, 'payments', 'reference')
            ) {
                $method = 'RFID Wallet Load';
                $reference = $receiptNo . ' | Wallet load for ' . $memberName;

                $stmt = $conn->prepare("
                    INSERT INTO payments (user_id, staff_id, amount, method, reference)
                    VALUES (?, ?, ?, ?, ?)
                ");

                if ($stmt) {
                    $stmt->bind_param('iidss', $memberId, $currentUserId, $amount, $method, $reference);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $conn->commit();

            $receiptData = [
                'receipt_no' => $receiptNo,
                'member_id' => $memberId,
                'member_name' => $memberName,
                'username' => $member['username'] ?? '',
                'email' => $memberEmail,
                'id_number' => $idNumber !== '' ? $idNumber : 'N/A',
                'rfid_uid' => $rfidUid,
                'amount' => $amount,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'load_date_sql' => $loadDateSql,
                'load_date_display' => date('M d, Y h:i:s A', strtotime($loadDateSql)),
                'staff_name' => $currentUsername,
            ];

            $showReceipt = true;
            $success = 'RFID wallet loaded successfully. Receipt generated.';

            if ($memberEmail !== '') {
                $mailResult = sendWalletLoadReceiptEmail($memberEmail, $memberName, $receiptData);

                if (!empty($mailResult['ok'])) {
                    $emailNotice = 'Email receipt sent to ' . $memberEmail . '.';
                } else {
                    $emailNotice = 'Wallet loaded, but email was not sent: ' . ($mailResult['error'] ?? 'Unknown mail error.');

                    if (!is_dir(__DIR__ . '/logs')) {
                        @mkdir(__DIR__ . '/logs', 0777, true);
                    }

                    @file_put_contents(
                        __DIR__ . '/logs/mail.log',
                        '[' . date('Y-m-d H:i:s') . '] RFID load receipt error: ' . ($mailResult['error'] ?? 'Unknown error') . PHP_EOL,
                        FILE_APPEND
                    );
                }
            } else {
                $emailNotice = 'Wallet loaded, but the member has no email address.';
            }

            $rfidUid = '';
            $amountInput = '';
        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = $e->getMessage();
            $showReceipt = false;
            $receiptData = null;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Load RFID Wallet | RJL Fitness</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --red: #ed1c24;
            --red-dark: #780000;
            --red-soft: rgba(237, 28, 36, 0.18);
            --bg: #050505;
            --panel: #141414;
            --border: rgba(255,255,255,0.11);
            --border-strong: rgba(255,255,255,0.18);
            --text: #ffffff;
            --muted: #b8b8b8;
            --green: #24c46b;
            --danger: #ff4b5c;
            --shadow: 0 26px 70px rgba(0,0,0,0.55);
            --radius: 28px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(237, 28, 36, 0.20), transparent 35%),
                radial-gradient(circle at bottom right, rgba(237, 28, 36, 0.10), transparent 28%),
                linear-gradient(135deg, #020202, #101010 52%, #050505);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .topbar {
            min-height: 78px;
            background: linear-gradient(90deg, #240000, #8f0000 55%, #c00000);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 14px 30px rgba(0,0,0,0.40);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 950;
            font-size: 1.12rem;
        }

        .brand img {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            object-fit: cover;
        }

        .top-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .top-btn {
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.20);
            background: rgba(255,255,255,0.07);
            font-size: 0.86rem;
            font-weight: 850;
        }

        .page-shell {
            width: min(1180px, calc(100vw - 34px));
            margin: 0 auto;
            padding: 30px 0 50px;
        }

        .hero {
            border-radius: 30px;
            border: 1px solid var(--border);
            padding: 30px;
            margin-bottom: 18px;
            background:
                linear-gradient(135deg, rgba(237, 28, 36, 0.86), rgba(17, 17, 17, 0.96)),
                linear-gradient(145deg, #1b1b1b, #080808);
            box-shadow: var(--shadow);
            overflow: hidden;
            position: relative;
        }

        .hero::after {
            content: "";
            position: absolute;
            width: 360px;
            height: 360px;
            right: -120px;
            top: -150px;
            border-radius: 50%;
            background: rgba(255,255,255,0.10);
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-kicker {
            display: inline-flex;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.14);
            font-size: 0.75rem;
            font-weight: 950;
            letter-spacing: 0.10em;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(2rem, 4vw, 3.3rem);
            line-height: 1;
            font-weight: 950;
            letter-spacing: -0.06em;
        }

        .hero p {
            margin: 12px 0 0;
            max-width: 760px;
            color: rgba(255,255,255,0.84);
            line-height: 1.55;
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

        .setup-box {
            border-radius: 22px;
            padding: 18px;
            background: rgba(255, 75, 92, 0.14);
            border: 1px solid rgba(255, 75, 92, 0.35);
            color: #ffe1e4;
            margin-bottom: 18px;
        }

        .setup-box code {
            display: block;
            margin-top: 12px;
            padding: 14px;
            border-radius: 14px;
            background: rgba(0,0,0,0.32);
            color: #fff;
            white-space: pre-wrap;
        }

        .wallet-grid {
            display: grid;
            grid-template-columns: 0.95fr 1.05fr;
            gap: 18px;
            align-items: stretch;
        }

        .panel {
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: linear-gradient(145deg, rgba(255,255,255,0.065), rgba(255,255,255,0.025));
            box-shadow: 0 20px 48px rgba(0,0,0,0.35);
            overflow: hidden;
        }

        .panel-head {
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
        }

        .panel-head h2 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 950;
        }

        .panel-head p {
            margin: 6px 0 0;
            color: var(--muted);
            line-height: 1.45;
        }

        .step-badge {
            width: 42px;
            height: 42px;
            border-radius: 15px;
            background: var(--red-soft);
            color: #ffb4b8;
            display: grid;
            place-items: center;
            font-weight: 950;
            flex: 0 0 auto;
        }

        .panel-body {
            padding: 22px;
        }

        .wallet-visual {
            height: 100%;
            min-height: 430px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background:
                radial-gradient(circle at top right, rgba(237,28,36,0.24), transparent 35%),
                linear-gradient(145deg, #191919, #0e0e0e);
        }

        .wallet-card {
            border-radius: 28px;
            border: 1px solid rgba(255,255,255,0.14);
            background:
                linear-gradient(135deg, rgba(237,28,36,0.45), rgba(255,255,255,0.07)),
                linear-gradient(145deg, #181818, #111111);
            padding: 24px;
            min-height: 230px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 22px 48px rgba(0,0,0,0.35);
        }

        .wallet-card-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            color: rgba(255,255,255,0.78);
            font-weight: 850;
        }

        .wallet-title {
            font-size: 2.2rem;
            font-weight: 950;
            letter-spacing: -0.06em;
        }

        .wallet-chip {
            width: 54px;
            height: 42px;
            border-radius: 14px;
            background: linear-gradient(135deg, #d7b46a, #8e6a25);
            box-shadow: inset 0 0 0 2px rgba(255,255,255,0.18);
        }

        .wallet-note {
            margin-top: 18px;
            color: var(--muted);
            line-height: 1.55;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            color: #fff;
            font-size: 0.86rem;
            font-weight: 900;
            margin-bottom: 8px;
        }

        .input-modern {
            width: 100%;
            min-height: 52px;
            border-radius: 16px;
            border: 1px solid var(--border-strong);
            background: rgba(0,0,0,0.38);
            color: #fff;
            outline: none;
            padding: 0 15px;
            font-size: 1rem;
            font-weight: 750;
        }

        .input-modern:focus {
            border-color: rgba(237,28,36,0.86);
            box-shadow: 0 0 0 4px rgba(237,28,36,0.16);
        }

        .hint {
            color: var(--muted);
            font-size: 0.86rem;
            line-height: 1.45;
            margin-top: 8px;
        }

        .quick-amounts {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 9px;
            margin-top: 10px;
        }

        .quick-btn {
            min-height: 40px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.055);
            color: #fff;
            border-radius: 13px;
            cursor: pointer;
            font-weight: 900;
        }

        .quick-btn:hover {
            border-color: rgba(237,28,36,0.72);
            background: rgba(237,28,36,0.18);
        }

        .btn-load {
            width: 100%;
            min-height: 54px;
            margin-top: 6px;
            border: 0;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--red), #ff444c);
            color: #fff;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            cursor: pointer;
            box-shadow: 0 16px 35px rgba(237,28,36,0.32);
            transition: 0.18s ease;
        }

        .btn-load:hover {
            transform: translateY(-1px);
            filter: brightness(1.04);
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.72);
            backdrop-filter: blur(8px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-backdrop.show {
            display: flex;
        }

        .receipt-card {
            width: min(560px, 100%);
            background: #fff;
            color: #111;
            border-radius: 26px;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0,0,0,0.55);
        }

        .receipt-top {
            background: linear-gradient(135deg, #970000, #ed1c24);
            color: #fff;
            padding: 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
        }

        .receipt-top h2 {
            margin: 0;
            font-size: 1.45rem;
            font-weight: 950;
        }

        .receipt-top p {
            margin: 6px 0 0;
            opacity: 0.88;
        }

        .close-modal {
            border: 0;
            background: rgba(255,255,255,0.18);
            color: #fff;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            font-size: 1.3rem;
            cursor: pointer;
        }

        .receipt-body {
            padding: 22px;
        }

        .success-mark {
            width: 62px;
            height: 62px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: rgba(36,196,107,0.14);
            color: #14a85d;
            display: grid;
            place-items: center;
            font-size: 2rem;
            font-weight: 950;
        }

        .receipt-title {
            text-align: center;
            font-weight: 950;
            font-size: 1.18rem;
            margin-bottom: 16px;
        }

        .receipt-line {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            padding: 10px 0;
            border-bottom: 1px solid #ececec;
            font-size: 0.94rem;
        }

        .receipt-line span {
            color: #667085;
        }

        .receipt-line strong {
            text-align: right;
            color: #111;
        }

        .receipt-actions {
            padding: 18px 22px 22px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .receipt-btn {
            flex: 1;
            min-height: 44px;
            border: 0;
            border-radius: 999px;
            cursor: pointer;
            font-weight: 950;
            text-transform: uppercase;
        }

        .receipt-btn.print {
            background: #111;
            color: #fff;
        }

        .receipt-btn.done {
            background: linear-gradient(135deg, var(--red), #ff444c);
            color: #fff;
        }

        @media print {
            body * {
                visibility: hidden;
            }

            .receipt-card,
            .receipt-card * {
                visibility: visible;
            }

            .receipt-card {
                position: absolute;
                inset: 0;
                margin: auto;
                box-shadow: none;
            }

            .close-modal,
            .receipt-actions {
                display: none;
            }
        }

        @media (max-width: 900px) {
            .wallet-grid {
                grid-template-columns: 1fr;
            }

            .quick-amounts {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 650px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
                padding: 16px;
            }

            .page-shell {
                width: calc(100vw - 22px);
                padding-top: 20px;
            }

            .panel-head {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <a href="<?= h($homeLink) ?>" class="brand">
        <img src="photo/logo.jpg" alt="RJL Fitness">
        <span>RJL Fitness</span>
    </a>

    <div class="top-actions">
        <a href="<?= h($homeLink) ?>" class="top-btn">Dashboard</a>
        <a href="logout.php" class="top-btn">Logout</a>
    </div>
</header>

<main class="page-shell">

    <section class="hero">
        <div class="hero-content">
            <div class="hero-kicker">RFID Wallet Center</div>
            <h1>Load Wallet via RFID</h1>
            <p>
                Scan or swipe the member’s RFID card, enter the amount, then load the wallet.
                A receipt will appear and an email receipt will be sent to the member.
            </p>
        </div>
    </section>

    <?php if ($setupError): ?>
        <div class="setup-box">
            <strong><?= h($setupError) ?></strong>
            <p>Please run this SQL in phpMyAdmin if needed:</p>
            <code>ALTER TABLE users
ADD COLUMN rfid_uid VARCHAR(100) NULL,
ADD COLUMN wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00;</code>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?= h($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= h($success) ?>
            <?php if ($emailNotice): ?>
                <br><?= h($emailNotice) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$setupError): ?>
        <section class="wallet-grid">
            <div class="panel wallet-visual">
                <div class="panel-head">
                    <div>
                        <h2>RFID Wallet</h2>
                        <p>Cashless payment balance for membership purchases.</p>
                    </div>
                    <div class="step-badge">₱</div>
                </div>

                <div class="panel-body">
                    <div class="wallet-card">
                        <div class="wallet-card-top">
                            <span>RJL FITNESS</span>
                            <div class="wallet-chip"></div>
                        </div>

                        <div>
                            <div class="wallet-title">RFID Load</div>
                            <div style="color:rgba(255,255,255,.76);font-weight:800;">
                                Staff Wallet Loading Panel
                            </div>
                        </div>
                    </div>

                    <div class="wallet-note">
                        Make sure the correct member RFID card is scanned before loading. After loading, the amount will be added directly to the member’s RFID wallet balance.
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Load Member Wallet</h2>
                        <p>Enter RFID UID and amount to load.</p>
                    </div>
                    <div class="step-badge">1</div>
                </div>

                <div class="panel-body">
                    <form method="post" action="rfid_load.php" id="loadForm">
                        <div class="form-group">
                            <label class="form-label" for="rfid_uid">RFID UID</label>
                            <input
                                type="text"
                                id="rfid_uid"
                                name="rfid_uid"
                                class="input-modern"
                                value="<?= h($rfidUid) ?>"
                                placeholder="Tap card or enter RFID UID"
                                autocomplete="off"
                                required
                            >
                            <div class="hint">
                                Swiping the RFID card should fill this automatically depending on your reader setup.
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="amount">Amount to Load</label>
                            <input
                                type="number"
                                step="0.01"
                                min="1"
                                id="amount"
                                name="amount"
                                class="input-modern"
                                value="<?= h($amountInput) ?>"
                                placeholder="Enter amount to load"
                                required
                            >

                            <div class="quick-amounts">
                                <button type="button" class="quick-btn" onclick="setAmount(100)">₱100</button>
                                <button type="button" class="quick-btn" onclick="setAmount(500)">₱500</button>
                                <button type="button" class="quick-btn" onclick="setAmount(1000)">₱1,000</button>
                                <button type="button" class="quick-btn" onclick="setAmount(2000)">₱2,000</button>
                            </div>
                        </div>

                        <button type="submit" class="btn-load">
                            Load Wallet
                        </button>
                    </form>
                </div>
            </div>
        </section>
    <?php endif; ?>

</main>

<div class="modal-backdrop <?= $showReceipt && $receiptData ? 'show' : '' ?>" id="receiptModal">
    <?php if ($receiptData): ?>
        <div class="receipt-card">
            <div class="receipt-top">
                <div>
                    <h2>RJL Fitness</h2>
                    <p>RFID Wallet Load Receipt</p>
                </div>

                <button type="button" class="close-modal" onclick="closeReceipt()">×</button>
            </div>

            <div class="receipt-body">
                <div class="success-mark">✓</div>
                <div class="receipt-title">Wallet Successfully Loaded</div>

                <div class="receipt-line">
                    <span>Receipt No.</span>
                    <strong><?= h($receiptData['receipt_no']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Member Name</span>
                    <strong><?= h($receiptData['member_name']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>ID Number</span>
                    <strong><?= h($receiptData['id_number']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Email</span>
                    <strong><?= h($receiptData['email'] !== '' ? $receiptData['email'] : 'No email') ?></strong>
                </div>

                <div class="receipt-line">
                    <span>RFID UID</span>
                    <strong><?= h($receiptData['rfid_uid']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Amount Loaded</span>
                    <strong><?= h(peso($receiptData['amount'])) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Old Balance</span>
                    <strong><?= h(peso($receiptData['old_balance'])) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>New Balance</span>
                    <strong><?= h(peso($receiptData['new_balance'])) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Exact Load Date</span>
                    <strong><?= h($receiptData['load_date_display']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Processed By</span>
                    <strong><?= h($receiptData['staff_name']) ?></strong>
                </div>
            </div>

            <div class="receipt-actions">
                <button type="button" class="receipt-btn print" onclick="window.print()">Print Receipt</button>
                <button type="button" class="receipt-btn done" onclick="closeReceipt()">Done</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    function setAmount(amount) {
        const input = document.getElementById('amount');
        if (input) {
            input.value = Number(amount).toFixed(2);
            input.focus();
        }
    }

    const loadForm = document.getElementById('loadForm');

    if (loadForm) {
        loadForm.addEventListener('submit', function (event) {
            const rfid = document.getElementById('rfid_uid');
            const amount = document.getElementById('amount');

            if (!rfid.value.trim()) {
                alert('Please scan or enter RFID UID.');
                rfid.focus();
                event.preventDefault();
                return;
            }

            if (!amount.value || Number(amount.value) <= 0) {
                alert('Please enter a valid amount.');
                amount.focus();
                event.preventDefault();
                return;
            }

            const ok = confirm('Confirm RFID wallet load?');

            if (!ok) {
                event.preventDefault();
            }
        });
    }

    function closeReceipt() {
        const modal = document.getElementById('receiptModal');

        if (modal) {
            modal.classList.remove('show');
        }
    }

    window.addEventListener('load', function () {
        const rfid = document.getElementById('rfid_uid');
        if (rfid) {
            rfid.focus();
        }
    });
</script>

</body>
</html>