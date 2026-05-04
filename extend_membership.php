<?php
// extend_membership.php — Staff RFID membership extension with receipt + email

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

date_default_timezone_set('Asia/Manila');

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

if (!function_exists('sendMembershipReceiptEmail')) {
    function sendMembershipReceiptEmail(string $toEmail, string $toName, array $receipt): array
    {
        $subject = 'RJL Fitness Membership Purchase Receipt';

        $plainBody =
            "Hello {$toName},\n\n" .
            "You have successfully purchased a membership plan at RJL Fitness.\n\n" .
            "Receipt No.: {$receipt['receipt_no']}\n" .
            "Member Name: {$receipt['member_name']}\n" .
            "ID Number: {$receipt['id_number']}\n" .
            "Membership Type: {$receipt['membership_type_label']}\n" .
            "Plan: {$receipt['plan_label']}\n" .
            "Amount Paid: " . peso($receipt['amount']) . "\n" .
            "Payment Method: {$receipt['payment_method']}\n" .
            "Purchase Date: {$receipt['purchase_date_display']}\n" .
            "Old Expiry: {$receipt['old_expiry_display']}\n" .
            "New Expiry: {$receipt['new_expiry_display']}\n" .
            "Sessions Added: {$receipt['sessions_added']}\n\n" .
            "Thank you,\n" .
            "RJL Fitness";

        $htmlBody = '
            <div style="font-family:Arial,sans-serif;background:#f4f4f4;padding:24px;">
                <div style="max-width:620px;margin:auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #ddd;">
                    <div style="background:#970000;color:#ffffff;padding:20px;">
                        <h2 style="margin:0;">RJL Fitness</h2>
                        <p style="margin:6px 0 0;">Membership Purchase Receipt</p>
                    </div>

                    <div style="padding:22px;color:#111;">
                        <p>Hello <strong>' . h($toName) . '</strong>,</p>
                        <p>You have successfully purchased a membership plan.</p>

                        <table style="width:100%;border-collapse:collapse;margin-top:16px;">
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Receipt No.</td><td style="padding:8px;border-bottom:1px solid #eee;"><strong>' . h($receipt['receipt_no']) . '</strong></td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Member Name</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['member_name']) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">ID Number</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['id_number']) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Membership Type</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['membership_type_label']) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Plan</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['plan_label']) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Amount Paid</td><td style="padding:8px;border-bottom:1px solid #eee;"><strong>' . h(peso($receipt['amount'])) . '</strong></td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Payment Method</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['payment_method']) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Purchase Date</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['purchase_date_display']) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Old Expiry</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['old_expiry_display']) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">New Expiry</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['new_expiry_display']) . '</td></tr>
                            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Sessions Added</td><td style="padding:8px;border-bottom:1px solid #eee;">' . h($receipt['sessions_added']) . '</td></tr>
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

$HAS_RFID = has_column($conn, 'users', 'rfid_uid');
$HAS_WALLET = has_column($conn, 'users', 'wallet_balance');
$HAS_EXPIRY = has_column($conn, 'users', 'membership_expires_at');
$HAS_MEMBERSHIP_TYPE = has_column($conn, 'users', 'membership_type');

$SESSION_COLUMN = first_existing_column($conn, 'users', [
    'trainer_sessions_remaining',
    'sessions_remaining',
]);

$HAS_FULL_NAME = has_column($conn, 'users', 'full_name');
$HAS_EMAIL = has_column($conn, 'users', 'email');
$HAS_ID_NUMBER = has_column($conn, 'users', 'id_number');

$errors = [];
$success = '';
$emailNotice = '';
$showReceipt = false;
$receiptData = null;

$membershipTypes = [
    'bodybuilding' => 'Bodybuilding',
    'zumba' => 'Zumba',
    'boxing' => 'Boxing',
    'muay_thai' => 'Muay Thai',
];

$plansConfig = [
    'bodybuilding' => [
        'bb_day' => [
            'name' => '1 Day Pass',
            'label' => '1 Day Pass · ₱60.00 · 1 day',
            'amount' => 60.00,
            'days' => 1,
            'membership_type' => 'bodybuilding_without_trainer',
            'sessions_add' => 0,
            'badge' => 'Day Pass',
        ],
        'bb_month' => [
            'name' => '1 Month Pass',
            'label' => '1 Month Pass · ₱550.00 · 1 month',
            'amount' => 550.00,
            'days' => 30,
            'membership_type' => 'bodybuilding_without_trainer',
            'sessions_add' => 0,
            'badge' => 'Monthly',
        ],
        'bb_trainor' => [
            'name' => '1 Month Pass with Trainor',
            'label' => '1 Month Pass · ₱3,000.00 with trainor · 1 month',
            'amount' => 3000.00,
            'days' => 30,
            'membership_type' => 'bodybuilding_with_trainer',
            'sessions_add' => 10,
            'badge' => 'With Trainor',
        ],
    ],

    'zumba' => [
        'z_day' => [
            'name' => '1 Day Pass',
            'label' => '1 Day Pass · ₱100.00 · 1 day',
            'amount' => 100.00,
            'days' => 1,
            'membership_type' => 'zumba',
            'sessions_add' => 0,
            'badge' => 'Day Pass',
        ],
        'z_month' => [
            'name' => '1 Month Pass',
            'label' => '1 Month Pass · ₱1,000.00 · 1 month',
            'amount' => 1000.00,
            'days' => 30,
            'membership_type' => 'zumba',
            'sessions_add' => 0,
            'badge' => 'Monthly',
        ],
    ],

    'boxing' => [
        'box_all_trainor' => [
            'name' => 'All Access + 10 Sessions',
            'label' => '₱2,850.00 · 1 month all access + 10 sessions with personal trainor',
            'amount' => 2850.00,
            'days' => 30,
            'membership_type' => 'boxing_with_trainer',
            'sessions_add' => 10,
            'badge' => 'Best Package',
        ],
        'box_all' => [
            'name' => '1 Month All Access',
            'label' => '₱850.00 · 1 month all access',
            'amount' => 850.00,
            'days' => 30,
            'membership_type' => 'boxing_without_trainer',
            'sessions_add' => 0,
            'badge' => 'All Access',
        ],
        'box_sessions' => [
            'name' => '10 Sessions with Personal Trainor',
            'label' => '₱2,000.00 · 10 sessions with personal trainor',
            'amount' => 2000.00,
            'days' => 0,
            'membership_type' => 'boxing_with_trainer',
            'sessions_add' => 10,
            'badge' => 'Sessions',
        ],
    ],

    'muay_thai' => [
        'mt_all_trainor' => [
            'name' => 'All Access + 10 Sessions',
            'label' => '₱2,850.00 · 1 month all access + 10 sessions with personal trainor',
            'amount' => 2850.00,
            'days' => 30,
            'membership_type' => 'muaythai_with_trainer',
            'sessions_add' => 10,
            'badge' => 'Best Package',
        ],
        'mt_all' => [
            'name' => '1 Month All Access',
            'label' => '₱850.00 · 1 month all access',
            'amount' => 850.00,
            'days' => 30,
            'membership_type' => 'muaythai_without_trainer',
            'sessions_add' => 0,
            'badge' => 'All Access',
        ],
        'mt_sessions' => [
            'name' => '10 Sessions with Personal Trainor',
            'label' => '₱2,000.00 · 10 sessions with personal trainor',
            'amount' => 2000.00,
            'days' => 0,
            'membership_type' => 'muaythai_with_trainer',
            'sessions_add' => 10,
            'badge' => 'Sessions',
        ],
    ],
];

$selectedType = trim($_POST['membership_type'] ?? '');
$selectedPlanKey = trim($_POST['plan_key'] ?? '');
$rfidUid = trim($_POST['rfid_uid'] ?? '');

$setupError = '';

if (!$HAS_RFID || !$HAS_WALLET) {
    $setupError = 'Database setup missing. users table needs rfid_uid and wallet_balance columns.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setupError === '') {
    if ($selectedType === '' || !isset($plansConfig[$selectedType])) {
        $errors[] = 'Please choose a membership type first.';
    }

    if ($selectedPlanKey === '' || !isset($plansConfig[$selectedType][$selectedPlanKey])) {
        $errors[] = 'Please choose a membership plan first.';
    }

    if ($rfidUid === '') {
        $errors[] = 'Please scan or swipe the RFID card.';
    }

    if (empty($errors)) {
        $plan = $plansConfig[$selectedType][$selectedPlanKey];
        $membershipTypeLabel = $membershipTypes[$selectedType] ?? ucfirst(str_replace('_', ' ', $selectedType));
        $planLabel = $plan['label'];
        $amount = (float)$plan['amount'];
        $daysToAdd = (int)$plan['days'];
        $sessionsToAdd = (int)$plan['sessions_add'];
        $membershipTypeCode = (string)$plan['membership_type'];

        $fullNameSelect = $HAS_FULL_NAME ? 'full_name' : "'' AS full_name";
        $emailSelect = $HAS_EMAIL ? 'email' : "'' AS email";
        $idNumberSelect = $HAS_ID_NUMBER ? 'id_number' : "'' AS id_number";
        $expirySelect = $HAS_EXPIRY ? 'membership_expires_at' : "NULL AS membership_expires_at";

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                SELECT
                    id,
                    username,
                    {$fullNameSelect},
                    {$emailSelect},
                    {$idNumberSelect},
                    {$expirySelect},
                    wallet_balance
                FROM users
                WHERE rfid_uid = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception('Database error while looking up RFID card.');
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
            $oldExpiryRaw = $member['membership_expires_at'] ?? null;
            $walletBalance = (float)($member['wallet_balance'] ?? 0);

            if ($walletBalance < $amount) {
                throw new Exception('Insufficient RFID wallet balance. Needed ' . peso($amount) . ', current balance is ' . peso($walletBalance) . '.');
            }

            $newExpiry = null;

            if ($daysToAdd > 0 && $HAS_EXPIRY) {
                $now = new DateTime();
                $baseDate = clone $now;

                if (!empty($oldExpiryRaw)) {
                    try {
                        $oldExpiryDate = new DateTime($oldExpiryRaw);

                        if ($oldExpiryDate > $now) {
                            $baseDate = $oldExpiryDate;
                        }
                    } catch (Throwable $e) {
                        $baseDate = clone $now;
                    }
                }

                $baseDate->modify('+' . $daysToAdd . ' days');
                $newExpiry = $baseDate->format('Y-m-d H:i:s');
            }

            $stmt = $conn->prepare("
                UPDATE users
                SET wallet_balance = wallet_balance - ?
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception('Failed to prepare wallet deduction.');
            }

            $stmt->bind_param('di', $amount, $memberId);

            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to deduct RFID wallet balance.');
            }

            $stmt->close();

            if ($newExpiry !== null && $HAS_EXPIRY) {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET membership_expires_at = ?
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception('Failed to prepare expiry update.');
                }

                $stmt->bind_param('si', $newExpiry, $memberId);

                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new Exception('Failed to update membership expiry.');
                }

                $stmt->close();
            }

            if ($HAS_MEMBERSHIP_TYPE) {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET membership_type = ?
                    WHERE id = ?
                    LIMIT 1
                ");

                if ($stmt) {
                    $stmt->bind_param('si', $membershipTypeCode, $memberId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if ($sessionsToAdd > 0 && $SESSION_COLUMN) {
                $safeSessionColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $SESSION_COLUMN);

                $stmt = $conn->prepare("
                    UPDATE users
                    SET `{$safeSessionColumn}` = COALESCE(`{$safeSessionColumn}`, 0) + ?
                    WHERE id = ?
                    LIMIT 1
                ");

                if ($stmt) {
                    $stmt->bind_param('ii', $sessionsToAdd, $memberId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $purchaseDateSql = date('Y-m-d H:i:s');
            $receiptNo = 'RJL-' . date('Ymd-His') . '-' . $memberId;
            $paymentMethod = 'RFID Wallet';
            $reference = $receiptNo . ' | ' . $membershipTypeLabel . ' - ' . $planLabel;

            if (
                table_exists($conn, 'payments') &&
                has_column($conn, 'payments', 'user_id') &&
                has_column($conn, 'payments', 'staff_id') &&
                has_column($conn, 'payments', 'amount') &&
                has_column($conn, 'payments', 'method') &&
                has_column($conn, 'payments', 'reference')
            ) {
                $stmt = $conn->prepare("
                    INSERT INTO payments (user_id, staff_id, amount, method, reference)
                    VALUES (?, ?, ?, ?, ?)
                ");

                if ($stmt) {
                    $stmt->bind_param('iidss', $memberId, $currentUserId, $amount, $paymentMethod, $reference);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $conn->commit();

            $oldExpiryDisplay = 'Not set';

            if (!empty($oldExpiryRaw) && strtotime($oldExpiryRaw) !== false) {
                $oldExpiryDisplay = date('M d, Y h:i A', strtotime($oldExpiryRaw));
            }

            $newExpiryDisplay = 'No expiry change';

            if (!empty($newExpiry) && strtotime($newExpiry) !== false) {
                $newExpiryDisplay = date('M d, Y h:i A', strtotime($newExpiry));
            }

            $receiptData = [
                'receipt_no' => $receiptNo,
                'member_id' => $memberId,
                'member_name' => $memberName,
                'username' => $member['username'] ?? '',
                'email' => $memberEmail,
                'id_number' => $idNumber !== '' ? $idNumber : 'N/A',
                'membership_type_label' => $membershipTypeLabel,
                'plan_label' => $planLabel,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'purchase_date_sql' => $purchaseDateSql,
                'purchase_date_display' => date('M d, Y h:i:s A', strtotime($purchaseDateSql)),
                'old_expiry_display' => $oldExpiryDisplay,
                'new_expiry_display' => $newExpiryDisplay,
                'sessions_added' => $sessionsToAdd > 0 ? (string)$sessionsToAdd : '0',
                'remaining_balance' => $walletBalance - $amount,
            ];

            $showReceipt = true;
            $success = 'Membership purchase successful. Receipt generated.';

            if ($memberEmail !== '') {
                $mailResult = sendMembershipReceiptEmail($memberEmail, $memberName, $receiptData);

                if (!empty($mailResult['ok'])) {
                    $emailNotice = 'Email receipt sent to ' . $memberEmail . '.';
                } else {
                    $emailNotice = 'Purchase saved, but email was not sent: ' . ($mailResult['error'] ?? 'Unknown mail error.');

                    if (!is_dir(__DIR__ . '/logs')) {
                        @mkdir(__DIR__ . '/logs', 0777, true);
                    }

                    @file_put_contents(
                        __DIR__ . '/logs/mail.log',
                        '[' . date('Y-m-d H:i:s') . '] membership receipt error: ' . ($mailResult['error'] ?? 'Unknown error') . PHP_EOL,
                        FILE_APPEND
                    );
                }
            } else {
                $emailNotice = 'Purchase saved, but the member has no email address.';
            }

            $selectedType = '';
            $selectedPlanKey = '';
            $rfidUid = '';
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
    <title>Extend Membership | RJL Fitness</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --red: #ed1c24;
            --red-dark: #7a0000;
            --red-soft: rgba(237, 28, 36, 0.18);
            --bg: #050505;
            --panel: #141414;
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

        body {
            margin: 0;
            min-height: 100vh;
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
            width: min(1440px, calc(100vw - 36px));
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
            max-width: 780px;
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

        .workflow-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 18px;
            align-items: start;
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

        .type-tabs {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }

        .type-tab {
            border: 1px solid var(--border);
            border-radius: 16px;
            background: rgba(0,0,0,0.24);
            color: #fff;
            padding: 13px;
            cursor: pointer;
            font-weight: 950;
            transition: 0.18s ease;
        }

        .type-tab.active {
            border-color: rgba(237,28,36,0.84);
            background: rgba(237,28,36,0.22);
            box-shadow: 0 14px 30px rgba(237,28,36,0.16);
        }

        .plan-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .plan-card {
            min-height: 198px;
            border-radius: 22px;
            border: 1px solid var(--border);
            background: linear-gradient(145deg, #181818, #0e0e0e);
            padding: 18px;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: 0.18s ease;
        }

        .plan-card::after {
            content: "";
            position: absolute;
            width: 120px;
            height: 120px;
            right: -50px;
            top: -50px;
            border-radius: 50%;
            background: rgba(237,28,36,0.18);
        }

        .plan-card.hidden {
            display: none;
        }

        .plan-card.active {
            border-color: rgba(237,28,36,0.88);
            box-shadow: 0 0 0 2px rgba(237,28,36,0.22), 0 18px 38px rgba(237,28,36,0.16);
            transform: translateY(-2px);
        }

        .plan-badge {
            display: inline-flex;
            position: relative;
            z-index: 2;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(237,28,36,0.16);
            color: #ffb4b8;
            font-size: 0.70rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 13px;
        }

        .plan-card h3 {
            position: relative;
            z-index: 2;
            margin: 0 0 9px;
            font-size: 1.05rem;
            font-weight: 950;
        }

        .plan-card p {
            position: relative;
            z-index: 2;
            margin: 0 0 14px;
            color: var(--muted);
            line-height: 1.45;
            font-size: 0.90rem;
            min-height: 58px;
        }

        .price {
            position: relative;
            z-index: 2;
            font-size: 1.55rem;
            font-weight: 950;
        }

        .selected-summary {
            padding: 18px;
            border-radius: 22px;
            border: 1px solid var(--border);
            background: rgba(0,0,0,0.24);
            margin-bottom: 16px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            color: var(--muted);
            margin-bottom: 10px;
        }

        .summary-row:last-child {
            margin-bottom: 0;
        }

        .summary-row strong {
            color: #fff;
            text-align: right;
        }

        .rfid-box {
            border-radius: 24px;
            border: 1px solid rgba(237,28,36,0.30);
            background: rgba(237,28,36,0.10);
            padding: 18px;
        }

        .rfid-box label {
            display: block;
            font-size: 0.78rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: #ffb4b8;
            margin-bottom: 8px;
        }

        .rfid-input {
            width: 100%;
            height: 54px;
            border-radius: 16px;
            border: 1px solid var(--border-strong);
            background: rgba(0,0,0,0.42);
            color: #fff;
            outline: none;
            padding: 0 15px;
            font-size: 1.05rem;
            font-weight: 800;
        }

        .rfid-input:focus {
            border-color: rgba(237,28,36,0.86);
            box-shadow: 0 0 0 4px rgba(237,28,36,0.16);
        }

        .rfid-input:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .hint {
            margin-top: 10px;
            color: var(--muted);
            font-size: 0.88rem;
            line-height: 1.45;
        }

        .btn-submit {
            width: 100%;
            height: 52px;
            margin-top: 16px;
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

        .btn-submit:hover {
            transform: translateY(-1px);
            filter: brightness(1.04);
        }

        .btn-submit:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
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

        @media (max-width: 1050px) {
            .workflow-grid {
                grid-template-columns: 1fr;
            }

            .type-tabs {
                grid-template-columns: repeat(2, 1fr);
            }

            .plan-grid {
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

            .type-tabs,
            .plan-grid {
                grid-template-columns: 1fr;
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
            <div class="hero-kicker">RFID Wallet Payment</div>
            <h1>Extend Membership</h1>
            <p>
                Choose a membership plan first, then scan or swipe the member’s RFID card.
                After payment, the receipt will appear and an email receipt will be sent to the member.
            </p>
        </div>
    </section>

    <?php if ($setupError): ?>
        <div class="setup-box">
            <strong><?= h($setupError) ?></strong>
            <p>Please run this SQL in phpMyAdmin if needed:</p>
            <code>ALTER TABLE users
ADD COLUMN rfid_uid VARCHAR(100) NULL,
ADD COLUMN wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
ADD COLUMN membership_expires_at DATETIME NULL,
ADD COLUMN membership_type VARCHAR(80) NULL,
ADD COLUMN trainer_sessions_remaining INT NOT NULL DEFAULT 0;</code>
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
        <form method="post" action="extend_membership.php" id="purchaseForm">
            <input type="hidden" name="membership_type" id="membership_type" value="<?= h($selectedType) ?>">
            <input type="hidden" name="plan_key" id="plan_key" value="<?= h($selectedPlanKey) ?>">

            <section class="workflow-grid">
                <div class="panel">
                    <div class="panel-head">
                        <div>
                            <h2>Step 1: Choose Plan</h2>
                            <p>Select the membership type and exact plan before scanning RFID.</p>
                        </div>
                        <div class="step-badge">1</div>
                    </div>

                    <div class="panel-body">
                        <div class="type-tabs">
                            <?php foreach ($membershipTypes as $typeKey => $typeLabel): ?>
                                <button
                                    type="button"
                                    class="type-tab"
                                    data-type="<?= h($typeKey) ?>"
                                >
                                    <?= h($typeLabel) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <div class="plan-grid">
                            <?php foreach ($plansConfig as $typeKey => $plans): ?>
                                <?php foreach ($plans as $planKey => $plan): ?>
                                    <article
                                        class="plan-card hidden"
                                        data-type="<?= h($typeKey) ?>"
                                        data-plan="<?= h($planKey) ?>"
                                        data-type-label="<?= h($membershipTypes[$typeKey]) ?>"
                                        data-plan-label="<?= h($plan['label']) ?>"
                                        data-amount="<?= number_format((float)$plan['amount'], 2, '.', '') ?>"
                                    >
                                        <span class="plan-badge"><?= h($plan['badge']) ?></span>
                                        <h3><?= h($plan['name']) ?></h3>
                                        <p><?= h($plan['label']) ?></p>
                                        <div class="price"><?= peso($plan['amount']) ?></div>
                                    </article>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <div>
                            <h2>Step 2: Scan RFID</h2>
                            <p>Tap or swipe the RFID card after selecting a plan.</p>
                        </div>
                        <div class="step-badge">2</div>
                    </div>

                    <div class="panel-body">
                        <div class="selected-summary">
                            <div class="summary-row">
                                <span>Selected Type</span>
                                <strong id="selectedTypeText">None</strong>
                            </div>

                            <div class="summary-row">
                                <span>Selected Plan</span>
                                <strong id="selectedPlanText">None</strong>
                            </div>

                            <div class="summary-row">
                                <span>Amount</span>
                                <strong id="selectedAmountText">₱0.00</strong>
                            </div>
                        </div>

                        <div class="rfid-box">
                            <label for="rfid_uid">RFID UID</label>
                            <input
                                type="text"
                                id="rfid_uid"
                                name="rfid_uid"
                                class="rfid-input"
                                placeholder="Choose a plan first, then scan RFID"
                                value="<?= h($rfidUid) ?>"
                                disabled
                                autocomplete="off"
                            >

                            <div class="hint">
                                RFID scanners usually type the UID automatically and press Enter.
                                The payment will be charged from the member’s RFID wallet.
                            </div>
                        </div>

                        <button type="submit" class="btn-submit" id="submitButton" disabled>
                            Complete Purchase
                        </button>
                    </div>
                </div>
            </section>
        </form>
    <?php endif; ?>

</main>

<div class="modal-backdrop <?= $showReceipt && $receiptData ? 'show' : '' ?>" id="receiptModal">
    <?php if ($receiptData): ?>
        <div class="receipt-card">
            <div class="receipt-top">
                <div>
                    <h2>RJL Fitness</h2>
                    <p>Membership Receipt</p>
                </div>

                <button type="button" class="close-modal" onclick="closeReceipt()">×</button>
            </div>

            <div class="receipt-body">
                <div class="success-mark">✓</div>
                <div class="receipt-title">Successfully Purchased Membership Plan</div>

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
                    <span>Membership Type</span>
                    <strong><?= h($receiptData['membership_type_label']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Plan Bought</span>
                    <strong><?= h($receiptData['plan_label']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Amount Paid</span>
                    <strong><?= h(peso($receiptData['amount'])) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Payment Method</span>
                    <strong><?= h($receiptData['payment_method']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Exact Purchase Date</span>
                    <strong><?= h($receiptData['purchase_date_display']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Old Expiry</span>
                    <strong><?= h($receiptData['old_expiry_display']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>New Expiry</span>
                    <strong><?= h($receiptData['new_expiry_display']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Sessions Added</span>
                    <strong><?= h($receiptData['sessions_added']) ?></strong>
                </div>

                <div class="receipt-line">
                    <span>Remaining Wallet Balance</span>
                    <strong><?= h(peso($receiptData['remaining_balance'])) ?></strong>
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
    const initialType = <?= json_encode($selectedType) ?>;
    const initialPlan = <?= json_encode($selectedPlanKey) ?>;

    const typeTabs = document.querySelectorAll('.type-tab');
    const planCards = document.querySelectorAll('.plan-card');

    const membershipTypeInput = document.getElementById('membership_type');
    const planKeyInput = document.getElementById('plan_key');

    const selectedTypeText = document.getElementById('selectedTypeText');
    const selectedPlanText = document.getElementById('selectedPlanText');
    const selectedAmountText = document.getElementById('selectedAmountText');

    const rfidInput = document.getElementById('rfid_uid');
    const submitButton = document.getElementById('submitButton');

    function formatPeso(amount) {
        return '₱' + Number(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function chooseType(type) {
        typeTabs.forEach(tab => {
            tab.classList.toggle('active', tab.dataset.type === type);
        });

        planCards.forEach(card => {
            const shouldShow = card.dataset.type === type;
            card.classList.toggle('hidden', !shouldShow);
            card.classList.remove('active');
        });

        membershipTypeInput.value = type;
        planKeyInput.value = '';

        const activeTab = document.querySelector('.type-tab.active');
        selectedTypeText.textContent = activeTab ? activeTab.textContent.trim() : 'None';
        selectedPlanText.textContent = 'Choose a plan';
        selectedAmountText.textContent = '₱0.00';

        lockRFID();
    }

    function choosePlan(card) {
        planCards.forEach(item => item.classList.remove('active'));
        card.classList.add('active');

        membershipTypeInput.value = card.dataset.type;
        planKeyInput.value = card.dataset.plan;

        selectedTypeText.textContent = card.dataset.typeLabel;
        selectedPlanText.textContent = card.dataset.planLabel;
        selectedAmountText.textContent = formatPeso(card.dataset.amount);

        unlockRFID();
    }

    function lockRFID() {
        if (!rfidInput || !submitButton) return;

        rfidInput.disabled = true;
        rfidInput.placeholder = 'Choose a plan first, then scan RFID';
        submitButton.disabled = true;
    }

    function unlockRFID() {
        if (!rfidInput || !submitButton) return;

        rfidInput.disabled = false;
        rfidInput.placeholder = 'Scan or swipe RFID card now';
        submitButton.disabled = false;

        setTimeout(() => {
            rfidInput.focus();
            rfidInput.select();
        }, 120);
    }

    typeTabs.forEach(tab => {
        tab.addEventListener('click', () => chooseType(tab.dataset.type));
    });

    planCards.forEach(card => {
        card.addEventListener('click', () => choosePlan(card));
    });

    if (initialType) {
        chooseType(initialType);

        if (initialPlan) {
            const foundPlan = document.querySelector(`.plan-card[data-type="${initialType}"][data-plan="${initialPlan}"]`);

            if (foundPlan) {
                choosePlan(foundPlan);
            }
        }
    }

    const purchaseForm = document.getElementById('purchaseForm');

    if (purchaseForm) {
        purchaseForm.addEventListener('submit', function (event) {
            if (!membershipTypeInput.value || !planKeyInput.value) {
                alert('Please choose a membership type and plan first.');
                event.preventDefault();
                return;
            }

            if (!rfidInput.value.trim()) {
                alert('Please scan or swipe the RFID card.');
                rfidInput.focus();
                event.preventDefault();
                return;
            }

            const ok = confirm('Confirm RFID wallet membership purchase?');

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
</script>

</body>
</html>