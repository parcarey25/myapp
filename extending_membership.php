<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'member';
$role = strtolower(trim($_SESSION['role'] ?? 'member'));

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function peso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function redirect_with_message(string $type, string $message): void
{
    $param = $type === 'success' ? 'success' : 'error';
    header('Location: extending_membership.php?' . $param . '=' . urlencode($message));
    exit;
}

function db_table_exists(mysqli $conn, string $table): bool
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

function db_column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    if ($result) {
        $result->free();
    }

    $stmt->close();

    return !empty($row) && (int)$row['total'] > 0;
}

function first_existing_column(mysqli $conn, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (db_column_exists($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function ensure_extension_request_table(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS membership_extension_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            plan_name VARCHAR(180) NOT NULL,
            membership_type VARCHAR(80) NULL,
            duration_days INT NOT NULL DEFAULT 0,
            trainer_sessions INT NOT NULL DEFAULT 0,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(30) NOT NULL DEFAULT 'gcash',
            wallet_deducted DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            sender_name VARCHAR(150) NULL,
            sender_number VARCHAR(30) NULL,
            reference_number VARCHAR(100) NULL,
            proof_image VARCHAR(255) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            approved_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = [
        'plan_name' => "ALTER TABLE membership_extension_requests ADD COLUMN plan_name VARCHAR(180) NOT NULL DEFAULT ''",
        'membership_type' => "ALTER TABLE membership_extension_requests ADD COLUMN membership_type VARCHAR(80) NULL",
        'duration_days' => "ALTER TABLE membership_extension_requests ADD COLUMN duration_days INT NOT NULL DEFAULT 0",
        'trainer_sessions' => "ALTER TABLE membership_extension_requests ADD COLUMN trainer_sessions INT NOT NULL DEFAULT 0",
        'amount' => "ALTER TABLE membership_extension_requests ADD COLUMN amount DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'payment_method' => "ALTER TABLE membership_extension_requests ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'gcash'",
        'wallet_deducted' => "ALTER TABLE membership_extension_requests ADD COLUMN wallet_deducted DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'sender_name' => "ALTER TABLE membership_extension_requests ADD COLUMN sender_name VARCHAR(150) NULL",
        'sender_number' => "ALTER TABLE membership_extension_requests ADD COLUMN sender_number VARCHAR(30) NULL",
        'reference_number' => "ALTER TABLE membership_extension_requests ADD COLUMN reference_number VARCHAR(100) NULL",
        'proof_image' => "ALTER TABLE membership_extension_requests ADD COLUMN proof_image VARCHAR(255) NULL",
        'status' => "ALTER TABLE membership_extension_requests ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending'",
        'approved_at' => "ALTER TABLE membership_extension_requests ADD COLUMN approved_at DATETIME NULL",
        'created_at' => "ALTER TABLE membership_extension_requests ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    ];

    foreach ($columns as $column => $alterSql) {
        if (!db_column_exists($conn, 'membership_extension_requests', $column)) {
            @$conn->query($alterSql);
        }
    }
}

function get_member(mysqli $conn, int $userId): array
{
    $hasFullName = db_column_exists($conn, 'users', 'full_name');
    $fullNameSelect = $hasFullName ? 'full_name,' : "'' AS full_name,";

    $sql = "
        SELECT id, username, {$fullNameSelect} membership_expires_at, wallet_balance
        FROM users
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $member = $result ? ($result->fetch_assoc() ?: []) : [];

    if ($result) {
        $result->free();
    }

    $stmt->close();

    return $member;
}

function get_new_expiry(?string $currentExpiry, int $days): string
{
    $baseTime = time();

    if (!empty($currentExpiry)) {
        $expiryTimestamp = strtotime($currentExpiry);

        if ($expiryTimestamp !== false && $expiryTimestamp > time()) {
            $baseTime = $expiryTimestamp;
        }
    }

    return date('Y-m-d H:i:s', strtotime("+{$days} days", $baseTime));
}

function reference_exists(mysqli $conn, string $referenceNumber): bool
{
    if (!db_table_exists($conn, 'membership_extension_requests')) {
        return false;
    }

    if (!db_column_exists($conn, 'membership_extension_requests', 'reference_number')) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM membership_extension_requests
        WHERE reference_number = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $referenceNumber);
    $stmt->execute();

    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;

    if ($result) {
        $result->free();
    }

    $stmt->close();

    return $exists;
}

function log_membership_extension(
    mysqli $conn,
    int $userId,
    string $planName,
    string $membershipType,
    int $durationDays,
    int $trainerSessions,
    float $amount,
    string $paymentMethod,
    float $walletDeducted = 0.00,
    string $status = 'completed',
    ?string $senderName = null,
    ?string $senderNumber = null,
    ?string $referenceNumber = null,
    ?string $proofImage = null
): void {
    ensure_extension_request_table($conn);

    if ($paymentMethod === 'rfid') {
        $sql = "
            INSERT INTO membership_extension_requests
                (user_id, plan_name, membership_type, duration_days, trainer_sessions, amount, payment_method, wallet_deducted, status, approved_at)
            VALUES (?, ?, ?, ?, ?, ?, 'rfid', ?, ?, NOW())
        ";

        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param(
                'issiidds',
                $userId,
                $planName,
                $membershipType,
                $durationDays,
                $trainerSessions,
                $amount,
                $walletDeducted,
                $status
            );

            $stmt->execute();
            $stmt->close();
        }

        return;
    }

    $sql = "
        INSERT INTO membership_extension_requests
            (user_id, plan_name, membership_type, duration_days, trainer_sessions, amount, payment_method, wallet_deducted, sender_name, sender_number, reference_number, proof_image, status)
        VALUES (?, ?, ?, ?, ?, ?, 'gcash', ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param(
            'issiiddsssss',
            $userId,
            $planName,
            $membershipType,
            $durationDays,
            $trainerSessions,
            $amount,
            $walletDeducted,
            $senderName,
            $senderNumber,
            $referenceNumber,
            $proofImage,
            $status
        );

        $stmt->execute();
        $stmt->close();
    }
}

function apply_membership_to_user(mysqli $conn, int $userId, array $plan): void
{
    $durationDays = (int)$plan['days'];
    $membershipType = (string)$plan['membership_type'];
    $trainerSessions = (int)$plan['sessions'];

    if ($durationDays > 0 && db_column_exists($conn, 'users', 'membership_expires_at')) {
        $stmt = $conn->prepare("
            SELECT membership_expires_at
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception('Failed to check current membership expiry.');
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result ? ($result->fetch_assoc() ?: []) : [];

        if ($result) {
            $result->free();
        }

        $stmt->close();

        $newExpiry = get_new_expiry($row['membership_expires_at'] ?? null, $durationDays);

        $stmt = $conn->prepare("
            UPDATE users
            SET membership_expires_at = ?
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception('Failed to update membership expiry.');
        }

        $stmt->bind_param('si', $newExpiry, $userId);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to save membership expiry.');
        }

        $stmt->close();
    }

    if (db_column_exists($conn, 'users', 'membership_type')) {
        $stmt = $conn->prepare("
            UPDATE users
            SET membership_type = ?
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('si', $membershipType, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($trainerSessions > 0) {
        $sessionsColumn = first_existing_column($conn, 'users', [
            'trainer_sessions_remaining',
            'sessions_remaining',
        ]);

        if ($sessionsColumn) {
            $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $sessionsColumn);

            $stmt = $conn->prepare("
                UPDATE users
                SET `{$safeColumn}` = COALESCE(`{$safeColumn}`, 0) + ?
                WHERE id = ?
                LIMIT 1
            ");

            if ($stmt) {
                $stmt->bind_param('ii', $trainerSessions, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

$membershipTypes = [
    'bodybuilding' => 'Bodybuilding',
    'zumba' => 'Zumba',
    'boxing' => 'Boxing',
    'muay_thai' => 'Muay Thai',
];

$plans = [
    [
        'key' => 'bodybuilding_1day',
        'type' => 'bodybuilding',
        'membership_type' => 'bodybuilding_without_trainer',
        'name' => '1 Day Pass',
        'label' => '1 Day Pass · ₱60.00 · 1 day',
        'days' => 1,
        'sessions' => 0,
        'amount' => 60.00,
        'badge' => 'Day Pass',
        'description' => 'Bodybuilding gym access for 1 day.',
    ],
    [
        'key' => 'bodybuilding_1month',
        'type' => 'bodybuilding',
        'membership_type' => 'bodybuilding_without_trainer',
        'name' => '1 Month Pass',
        'label' => '1 Month Pass · ₱550.00 · 1 month',
        'days' => 30,
        'sessions' => 0,
        'amount' => 550.00,
        'badge' => 'Monthly',
        'description' => 'Bodybuilding gym access for 1 month.',
    ],
    [
        'key' => 'bodybuilding_1month_trainer',
        'type' => 'bodybuilding',
        'membership_type' => 'bodybuilding_with_trainer',
        'name' => '1 Month Pass with Trainor',
        'label' => '1 Month Pass · ₱3000.00 with trainor · 1 month',
        'days' => 30,
        'sessions' => 10,
        'amount' => 3000.00,
        'badge' => 'With Trainor',
        'description' => 'Bodybuilding access with personal trainor sessions.',
    ],

    [
        'key' => 'zumba_1day',
        'type' => 'zumba',
        'membership_type' => 'zumba',
        'name' => '1 Day Pass',
        'label' => '1 Day Pass · ₱100.00 · 1 day',
        'days' => 1,
        'sessions' => 0,
        'amount' => 100.00,
        'badge' => 'Day Pass',
        'description' => 'Zumba access for 1 day.',
    ],
    [
        'key' => 'zumba_1month',
        'type' => 'zumba',
        'membership_type' => 'zumba',
        'name' => '1 Month Pass',
        'label' => '1 Month Pass · ₱1000.00 · 1 month',
        'days' => 30,
        'sessions' => 0,
        'amount' => 1000.00,
        'badge' => 'Monthly',
        'description' => 'Zumba access for 1 month.',
    ],

    [
        'key' => 'boxing_all_access_trainer',
        'type' => 'boxing',
        'membership_type' => 'boxing_with_trainer',
        'name' => 'All Access + 10 Sessions',
        'label' => '₱2850.00 · 1 month all access + 10 sessions with personal trainor',
        'days' => 30,
        'sessions' => 10,
        'amount' => 2850.00,
        'badge' => 'Best Package',
        'description' => 'Boxing 1 month all access plus 10 personal trainor sessions.',
    ],
    [
        'key' => 'boxing_all_access',
        'type' => 'boxing',
        'membership_type' => 'boxing_without_trainer',
        'name' => '1 Month All Access',
        'label' => '₱850.00 · 1 month all access',
        'days' => 30,
        'sessions' => 0,
        'amount' => 850.00,
        'badge' => 'All Access',
        'description' => 'Boxing 1 month all access without trainor sessions.',
    ],
    [
        'key' => 'boxing_10_sessions',
        'type' => 'boxing',
        'membership_type' => 'boxing_with_trainer',
        'name' => '10 Sessions with Personal Trainor',
        'label' => '₱2000.00 · 10 sessions with personal trainor',
        'days' => 0,
        'sessions' => 10,
        'amount' => 2000.00,
        'badge' => 'Sessions',
        'description' => 'Boxing 10 sessions with personal trainor.',
    ],

    [
        'key' => 'muaythai_all_access_trainer',
        'type' => 'muay_thai',
        'membership_type' => 'muaythai_with_trainer',
        'name' => 'All Access + 10 Sessions',
        'label' => '₱2850.00 · 1 month all access + 10 sessions with personal trainor',
        'days' => 30,
        'sessions' => 10,
        'amount' => 2850.00,
        'badge' => 'Best Package',
        'description' => 'Muay Thai 1 month all access plus 10 personal trainor sessions.',
    ],
    [
        'key' => 'muaythai_all_access',
        'type' => 'muay_thai',
        'membership_type' => 'muaythai_without_trainer',
        'name' => '1 Month All Access',
        'label' => '₱850.00 · 1 month all access',
        'days' => 30,
        'sessions' => 0,
        'amount' => 850.00,
        'badge' => 'All Access',
        'description' => 'Muay Thai 1 month all access without trainor sessions.',
    ],
    [
        'key' => 'muaythai_10_sessions',
        'type' => 'muay_thai',
        'membership_type' => 'muaythai_with_trainer',
        'name' => '10 Sessions with Personal Trainor',
        'label' => '₱2000.00 · 10 sessions with personal trainor',
        'days' => 0,
        'sessions' => 10,
        'amount' => 2000.00,
        'badge' => 'Sessions',
        'description' => 'Muay Thai 10 sessions with personal trainor.',
    ],
];

$plansByKey = [];

foreach ($plans as $plan) {
    $plansByKey[$plan['key']] = $plan;
}

$defaultPlan = $plans[0];

$gcashName = 'RJL Power Fitness Center';
$gcashNumber = '09942577283';

$gcashQrCandidates = [
    'photo/gcash_qr.jpg',
    'photo/gcash_qr.jpeg',
    'photo/gcash_qr.png',
    'photo/gcash_qr.webp',
];

$gcashQrImage = '';

foreach ($gcashQrCandidates as $candidate) {
    if (is_file(__DIR__ . '/' . $candidate)) {
        $gcashQrImage = $candidate;
        break;
    }
}

$qrExists = $gcashQrImage !== '';

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
$setupError = '';

$hasWalletBalanceColumn = db_column_exists($conn, 'users', 'wallet_balance');
$hasMembershipExpiryColumn = db_column_exists($conn, 'users', 'membership_expires_at');

if (!$hasWalletBalanceColumn || !$hasMembershipExpiryColumn) {
    $setupError = 'Database setup missing. Please make sure users table has wallet_balance and membership_expires_at columns.';
}

try {
    ensure_extension_request_table($conn);
} catch (Throwable $e) {
    $setupError = 'Could not create/check membership_extension_requests table: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setupError === '') {
    $planKey = trim($_POST['plan_key'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? '');

    if (!isset($plansByKey[$planKey])) {
        redirect_with_message('error', 'Invalid membership plan selected.');
    }

    $plan = $plansByKey[$planKey];

    $planName = $membershipTypes[$plan['type']] . ' - ' . $plan['label'];
    $membershipType = $plan['membership_type'];
    $durationDays = (int)$plan['days'];
    $trainerSessions = (int)$plan['sessions'];
    $amount = (float)$plan['amount'];

    if ($paymentMethod === 'rfid') {
        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                SELECT wallet_balance
                FROM users
                WHERE id = ?
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception('Failed to check RFID wallet.');
            }

            $stmt->bind_param('i', $userId);
            $stmt->execute();

            $result = $stmt->get_result();
            $walletRow = $result ? ($result->fetch_assoc() ?: null) : null;

            if ($result) {
                $result->free();
            }

            $stmt->close();

            if (!$walletRow) {
                throw new Exception('Member account not found.');
            }

            $currentBalance = (float)($walletRow['wallet_balance'] ?? 0);

            if ($currentBalance < $amount) {
                throw new Exception('Insufficient RFID wallet balance. Please load your RFID wallet first.');
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

            $stmt->bind_param('di', $amount, $userId);

            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to deduct RFID wallet balance.');
            }

            $stmt->close();

            apply_membership_to_user($conn, $userId, $plan);

            if (db_table_exists($conn, 'payments')) {
                $hasUserId = db_column_exists($conn, 'payments', 'user_id');
                $hasStaffId = db_column_exists($conn, 'payments', 'staff_id');
                $hasAmount = db_column_exists($conn, 'payments', 'amount');
                $hasMethod = db_column_exists($conn, 'payments', 'method');
                $hasReference = db_column_exists($conn, 'payments', 'reference');

                if ($hasUserId && $hasStaffId && $hasAmount && $hasMethod && $hasReference) {
                    $staffId = (int)($_SESSION['user_id'] ?? $userId);
                    $method = 'RFID Wallet';
                    $reference = 'Membership Extension - ' . $planName;

                    $stmt = $conn->prepare("
                        INSERT INTO payments (user_id, staff_id, amount, method, reference)
                        VALUES (?, ?, ?, ?, ?)
                    ");

                    if ($stmt) {
                        $stmt->bind_param('iidss', $userId, $staffId, $amount, $method, $reference);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            log_membership_extension(
                $conn,
                $userId,
                $planName,
                $membershipType,
                $durationDays,
                $trainerSessions,
                $amount,
                'rfid',
                $amount,
                'completed'
            );

            $conn->commit();

            redirect_with_message(
                'success',
                'RFID wallet payment successful. ' . $planName . ' has been applied to your account.'
            );
        } catch (Throwable $e) {
            $conn->rollback();
            redirect_with_message('error', $e->getMessage());
        }
    }

    if ($paymentMethod === 'gcash') {
        $senderName = trim($_POST['sender_name'] ?? '');
        $senderNumber = trim($_POST['sender_number'] ?? '');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $amountSent = (float)($_POST['amount_sent'] ?? 0);

        if ($senderName === '' || $senderNumber === '' || $referenceNumber === '') {
            redirect_with_message('error', 'Please complete all GCash fields.');
        }

        if (!preg_match('/^09\d{9}$/', $senderNumber)) {
            redirect_with_message('error', 'Please enter a valid 11-digit GCash number starting with 09.');
        }

        if (abs($amountSent - $amount) > 0.009) {
            redirect_with_message('error', 'Amount sent must match the selected plan amount exactly.');
        }

        if (reference_exists($conn, $referenceNumber)) {
            redirect_with_message('error', 'Reference number already exists.');
        }

        if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
            redirect_with_message('error', 'Please upload proof of payment.');
        }

        $file = $_FILES['proof_image'];

        if ($file['size'] > 5 * 1024 * 1024) {
            redirect_with_message('error', 'Proof image must be 5MB or smaller.');
        }

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';

        if ($finfo) {
            finfo_close($finfo);
        }

        if (!isset($allowed[$mime])) {
            redirect_with_message('error', 'Only JPG, PNG, and WEBP images are allowed.');
        }

        $uploadDir = __DIR__ . '/uploads/extension_proofs/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
            redirect_with_message('error', 'Failed to create upload folder.');
        }

        $extension = $allowed[$mime];
        $filename = 'extension_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
        $destination = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            redirect_with_message('error', 'Failed to upload proof image.');
        }

        $proofPath = 'uploads/extension_proofs/' . $filename;

        try {
            log_membership_extension(
                $conn,
                $userId,
                $planName,
                $membershipType,
                $durationDays,
                $trainerSessions,
                $amount,
                'gcash',
                0.00,
                'pending',
                $senderName,
                $senderNumber,
                $referenceNumber,
                $proofPath
            );
        } catch (Throwable $e) {
            @unlink($destination);
            redirect_with_message('error', 'Failed to save GCash request: ' . $e->getMessage());
        }

        redirect_with_message('success', 'GCash proof submitted. Please wait for staff/admin approval.');
    }

    redirect_with_message('error', 'Invalid payment method.');
}

$member = [];

if ($setupError === '') {
    $member = get_member($conn, $userId);
}

$currentExpiry = $member['membership_expires_at'] ?? null;
$walletBalance = (float)($member['wallet_balance'] ?? 0);
$displayName = !empty($member['full_name']) ? $member['full_name'] : ($member['username'] ?? $username);

$backLink = 'home.php';

if ($role === 'admin') {
    $backLink = 'home_admin.php';
} elseif ($role === 'staff') {
    $backLink = 'home_staff.php';
} elseif ($role === 'member') {
    $backLink = file_exists(__DIR__ . '/home_member.php') ? 'home_member.php' : 'home.php';
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
            --brand-red: #c4161c;
            --brand-red-soft: #ff3f4a;
            --bg: #070707;
            --panel: #141414;
            --panel-soft: #1d1d1d;
            --line: rgba(255, 255, 255, .10);
            --text: #ffffff;
            --muted: #a8a8a8;
            --gcash-blue: #007dfe;
            --gcash-blue-dark: #0359c7;
            --success: #18c27a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(196, 22, 28, .22), transparent 36%),
                radial-gradient(circle at bottom right, rgba(0, 125, 254, .16), transparent 32%),
                linear-gradient(135deg, #030303, #111111 55%, #050505);
            color: var(--text);
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .page-shell {
            width: min(1220px, calc(100% - 30px));
            margin: 0 auto;
            padding: 30px 0 44px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--brand-red), #6e0509);
            box-shadow: 0 18px 30px rgba(196, 22, 28, .28);
        }

        .back-link {
            padding: 10px 15px;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: #f4f4f4;
            text-decoration: none;
            background: rgba(255, 255, 255, .04);
        }

        .back-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, .08);
        }

        .hero {
            border: 1px solid var(--line);
            border-radius: 28px;
            padding: 30px;
            background:
                linear-gradient(135deg, rgba(196, 22, 28, .90), rgba(16, 16, 16, .94)),
                linear-gradient(145deg, #1a1a1a, #080808);
            box-shadow: 0 30px 70px rgba(0, 0, 0, .50);
            margin-bottom: 18px;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(2rem, 5vw, 3.4rem);
            font-weight: 950;
            letter-spacing: -.04em;
        }

        .hero p {
            max-width: 720px;
            margin: 10px 0 0;
            color: rgba(255, 255, 255, .86);
            font-size: 1.03rem;
        }

        .hero-kicker {
            display: inline-flex;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .14);
            font-size: .75rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .alert-clean {
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 15px 18px;
            margin-bottom: 18px;
        }

        .alert-success-clean {
            background: rgba(24, 194, 122, .14);
            border-color: rgba(24, 194, 122, .34);
            color: #d7ffec;
        }

        .alert-error-clean {
            background: rgba(255, 63, 74, .14);
            border-color: rgba(255, 63, 74, .36);
            color: #ffe1e4;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }

        .summary-card,
        .glass-card {
            border: 1px solid var(--line);
            border-radius: 22px;
            background: linear-gradient(145deg, rgba(255, 255, 255, .065), rgba(255, 255, 255, .025));
            box-shadow: 0 20px 45px rgba(0, 0, 0, .30);
        }

        .summary-card {
            padding: 18px;
        }

        .summary-card small {
            display: block;
            color: var(--muted);
            font-size: .72rem;
            font-weight: 850;
            letter-spacing: .09em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .summary-card strong {
            display: block;
            color: #fff;
            font-size: 1.12rem;
            line-height: 1.25;
        }

        .section {
            padding: 22px;
            margin-bottom: 18px;
        }

        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 16px;
        }

        .section-title h2 {
            margin: 0;
            font-size: 1.22rem;
            font-weight: 900;
        }

        .section-title span {
            color: var(--muted);
            font-size: .88rem;
        }

        .selected-mini {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 11px;
            border-radius: 999px;
            color: #fff;
            background: rgba(255, 255, 255, .08);
            font-weight: 800;
            font-size: .82rem;
        }

        .type-tabs {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }

        .type-tab {
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 13px 14px;
            background: rgba(0,0,0,.22);
            color: #fff;
            font-weight: 900;
            cursor: pointer;
            transition: .18s ease;
        }

        .type-tab.active {
            border-color: var(--brand-red-soft);
            background: rgba(196, 22, 28, .22);
            box-shadow: 0 12px 28px rgba(196, 22, 28, .14);
        }

        .plan-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }

        .plan-card {
            position: relative;
            overflow: hidden;
            min-height: 205px;
            padding: 20px;
            border-radius: 22px;
            border: 1px solid var(--line);
            background: linear-gradient(145deg, #181818, #101010);
            cursor: pointer;
            transition: .18s ease;
        }

        .plan-card::after {
            content: "";
            position: absolute;
            width: 132px;
            height: 132px;
            right: -52px;
            top: -52px;
            border-radius: 50%;
            background: rgba(196, 22, 28, .18);
        }

        .plan-card.hidden {
            display: none;
        }

        .plan-card.active {
            border-color: rgba(255, 63, 74, .88);
            box-shadow: 0 0 0 2px rgba(255, 63, 74, .24), 0 24px 48px rgba(196, 22, 28, .20);
            transform: translateY(-2px);
        }

        .plan-badge {
            position: relative;
            z-index: 2;
            display: inline-flex;
            margin-bottom: 14px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 63, 74, .15);
            color: #ffb8bd;
            font-size: .72rem;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .plan-card h3 {
            position: relative;
            z-index: 2;
            margin: 0 0 8px;
            font-size: 1.15rem;
            font-weight: 900;
        }

        .plan-card p {
            position: relative;
            z-index: 2;
            color: var(--muted);
            margin: 0 0 14px;
            min-height: 45px;
        }

        .plan-price {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .plan-price strong {
            font-size: 1.75rem;
            line-height: 1;
            font-weight: 950;
        }

        .plan-price span {
            color: var(--muted);
            font-size: .84rem;
        }

        .payment-grid {
            display: grid;
            grid-template-columns: .92fr 1.08fr;
            gap: 18px;
            align-items: start;
        }

        .payment-card {
            border: 1px solid var(--line);
            border-radius: 26px;
            overflow: hidden;
            background: linear-gradient(145deg, var(--panel), #0d0d0d);
            box-shadow: 0 20px 44px rgba(0, 0, 0, .32);
        }

        .payment-head {
            padding: 20px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .payment-head h3 {
            margin: 0;
            font-size: 1.08rem;
            font-weight: 900;
        }

        .payment-head p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: .88rem;
        }

        .icon-bubble {
            width: 48px;
            height: 48px;
            border-radius: 18px;
            display: grid;
            place-items: center;
            font-size: 1.45rem;
            background: rgba(255, 255, 255, .08);
        }

        .payment-body {
            padding: 20px;
        }

        .wallet-visual {
            padding: 20px;
            border-radius: 22px;
            background:
                linear-gradient(135deg, rgba(196, 22, 28, .24), rgba(255, 255, 255, .05)),
                linear-gradient(145deg, #191919, #111);
            border: 1px solid rgba(255, 255, 255, .08);
            margin-bottom: 16px;
        }

        .wallet-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
            color: var(--muted);
        }

        .wallet-row strong {
            color: #fff;
        }

        .wallet-balance {
            font-size: 2rem;
            font-weight: 950;
            color: #fff;
            margin: 8px 0 0;
        }

        .btn-main {
            width: 100%;
            border: 0;
            border-radius: 999px;
            padding: 14px 18px;
            background: linear-gradient(135deg, var(--brand-red), var(--brand-red-soft));
            color: #fff;
            font-weight: 950;
            letter-spacing: .04em;
            text-transform: uppercase;
            box-shadow: 0 18px 34px rgba(196, 22, 28, .32);
            cursor: pointer;
        }

        .note-list {
            margin: 14px 0 0;
            padding-left: 18px;
            color: var(--muted);
            font-size: .9rem;
        }

        .gcash-card {
            background: #eef5fb;
            color: #101828;
            border: none;
        }

        .gcash-header {
            padding: 20px;
            color: #fff;
            background:
                radial-gradient(circle at 10% 10%, rgba(255, 255, 255, .25), transparent 26%),
                linear-gradient(135deg, var(--gcash-blue), var(--gcash-blue-dark));
        }

        .gcash-brand-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .gcash-logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 950;
            font-size: 1.35rem;
        }

        .gcash-logo-mark {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: #fff;
            color: var(--gcash-blue);
            font-weight: 950;
        }

        .gcash-status {
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .18);
            font-size: .72rem;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .gcash-merchant {
            margin-top: 18px;
            padding: 16px;
            border-radius: 20px;
            background: rgba(255, 255, 255, .14);
        }

        .gcash-merchant small {
            display: block;
            opacity: .82;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-size: .70rem;
            font-weight: 850;
            margin-bottom: 6px;
        }

        .gcash-merchant strong {
            display: block;
            font-size: 1.05rem;
        }

        .gcash-body {
            padding: 24px 22px;
        }

        .qr-stage {
            width: min(330px, 100%);
            margin: 0 auto 18px;
            padding: 14px;
            border-radius: 28px;
            background: #fff;
            box-shadow: 0 16px 35px rgba(0, 97, 190, .16);
            border: 1px solid #d6ecff;
        }

        .qr-frame {
            border: 2px dashed #9bc9ff;
            border-radius: 22px;
            padding: 14px;
            background: #f5fbff;
        }

        .qr-inner {
            width: 100%;
            aspect-ratio: 1 / 1;
            min-height: 240px;
            border-radius: 16px;
            background: #e9f2fb;
            border: 1px solid #b7d7ff;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 14px;
        }

        .qr-inner img {
            width: 100%;
            height: 100%;
            max-width: 245px;
            max-height: 245px;
            object-fit: contain;
            display: block;
        }

        .qr-placeholder {
            text-align: center;
            color: #0057c8;
            font-weight: 900;
            line-height: 1.55;
        }

        .gcash-amount-pill {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 14px 16px;
            border-radius: 18px;
            background: #eaf5ff;
            border: 1px solid #d6ecff;
            color: #344054;
            margin-bottom: 18px;
        }

        .gcash-amount-pill strong {
            color: var(--gcash-blue-dark);
            font-size: 1.25rem;
            white-space: nowrap;
        }

        .form-panel {
            background: #fff;
            border: 1px solid #e7eef7;
            border-radius: 22px;
            padding: 18px;
            box-shadow: 0 14px 30px rgba(16, 24, 40, .08);
        }

        .field-label {
            display: block;
            color: #475467;
            font-size: .75rem;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 7px;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-control-modern {
            width: 100%;
            min-height: 46px;
            border: 1px solid #d0d5dd;
            border-radius: 14px;
            padding: 11px 13px;
            background: #fff;
            color: #101828;
            outline: none;
            font-size: 1rem;
        }

        .form-control-modern:focus {
            border-color: var(--gcash-blue);
            box-shadow: 0 0 0 4px rgba(0, 125, 254, .12);
        }

        .upload-box {
            position: relative;
            display: grid;
            place-items: center;
            min-height: 120px;
            border: 1.5px dashed #a7c7eb;
            border-radius: 18px;
            background: #f8fbff;
            color: #475467;
            text-align: center;
            cursor: pointer;
        }

        .upload-box input {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .upload-box strong {
            display: block;
            color: var(--gcash-blue-dark);
        }

        .preview-img {
            display: none;
            width: 100%;
            max-height: 240px;
            object-fit: contain;
            border-radius: 16px;
            margin-top: 12px;
            border: 1px solid #e7eef7;
            background: #f8fbff;
        }

        .btn-gcash {
            width: 100%;
            border: 0;
            border-radius: 999px;
            padding: 14px 18px;
            background: linear-gradient(135deg, var(--gcash-blue), var(--gcash-blue-dark));
            color: #fff;
            font-weight: 950;
            letter-spacing: .04em;
            text-transform: uppercase;
            box-shadow: 0 18px 34px rgba(0, 125, 254, .25);
            cursor: pointer;
        }

        .gcash-card .note-list {
            color: #475467;
        }

        .insufficient {
            color: #ffb8bd;
            font-size: .86rem;
            margin-top: 10px;
            display: none;
        }

        .setup-box {
            border: 1px solid rgba(255, 63, 74, .36);
            background: rgba(255, 63, 74, .12);
            color: #ffe1e4;
            padding: 20px;
            border-radius: 22px;
            margin-bottom: 18px;
        }

        .setup-box code {
            display: block;
            white-space: pre-wrap;
            margin-top: 12px;
            padding: 14px;
            border-radius: 14px;
            background: rgba(0, 0, 0, .32);
            color: #fff;
        }

        @media (max-width: 991.98px) {
            .summary-grid,
            .plan-grid,
            .payment-grid,
            .type-tabs {
                grid-template-columns: 1fr;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 575.98px) {
            .hero,
            .section,
            .payment-body,
            .gcash-body {
                padding: 18px;
            }

            .section-title {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>
<div class="page-shell">
    <div class="topbar">
        <div class="brand">
            <div class="brand-mark">R</div>
            <span>RJL Fitness</span>
        </div>

        <a class="back-link" href="<?= h($backLink) ?>">← Back to Home</a>
    </div>

    <section class="hero">
        <div>
            <div class="hero-kicker">Membership Payment Center</div>
            <h1>Extend Membership</h1>
            <p>Select your membership type and plan, then pay using RFID wallet or submit GCash QR proof for staff approval.</p>
        </div>
    </section>

    <?php if ($success): ?>
        <div class="alert-clean alert-success-clean"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert-clean alert-error-clean"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($setupError): ?>
        <div class="setup-box">
            <strong><?= h($setupError) ?></strong>
            <p>Please run this SQL in phpMyAdmin if those columns are missing:</p>
            <code>ALTER TABLE users
ADD COLUMN wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
ADD COLUMN membership_expires_at DATETIME NULL,
ADD COLUMN membership_type VARCHAR(80) NULL,
ADD COLUMN trainer_sessions_remaining INT NOT NULL DEFAULT 0;</code>
        </div>
    <?php else: ?>

        <section class="summary-grid">
            <div class="summary-card">
                <small>Member</small>
                <strong><?= h($displayName) ?></strong>
            </div>

            <div class="summary-card">
                <small>Current Expiry</small>
                <strong>
                    <?= $currentExpiry ? h(date('M d, Y · h:i A', strtotime($currentExpiry))) : 'Not Set' ?>
                </strong>
            </div>

            <div class="summary-card">
                <small>RFID Wallet Load</small>
                <strong><?= peso($walletBalance) ?></strong>
            </div>
        </section>

        <section class="glass-card section">
            <div class="section-title">
                <div>
                    <h2>Choose Membership Plan</h2>
                    <span>Select type first, then choose the plan you want.</span>
                </div>

                <div class="selected-mini">
                    Selected: <span id="selectedPlanName"><?= h($membershipTypes[$defaultPlan['type']] . ' - ' . $defaultPlan['label']) ?></span>
                </div>
            </div>

            <div class="type-tabs">
                <?php foreach ($membershipTypes as $typeKey => $typeLabel): ?>
                    <button
                        type="button"
                        class="type-tab <?= $typeKey === 'bodybuilding' ? 'active' : '' ?>"
                        data-type="<?= h($typeKey) ?>"
                    >
                        <?= h($typeLabel) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="plan-grid" id="planGrid">
                <?php foreach ($plans as $index => $plan): ?>
                    <article
                        class="plan-card <?= $plan['type'] !== 'bodybuilding' ? 'hidden' : '' ?> <?= $plan['key'] === $defaultPlan['key'] ? 'active' : '' ?>"
                        data-key="<?= h($plan['key']) ?>"
                        data-type="<?= h($plan['type']) ?>"
                        data-type-label="<?= h($membershipTypes[$plan['type']]) ?>"
                        data-name="<?= h($membershipTypes[$plan['type']] . ' - ' . $plan['label']) ?>"
                        data-amount="<?= number_format($plan['amount'], 2, '.', '') ?>"
                        data-days="<?= (int)$plan['days'] ?>"
                        data-sessions="<?= (int)$plan['sessions'] ?>"
                    >
                        <span class="plan-badge"><?= h($plan['badge']) ?></span>
                        <h3><?= h($plan['name']) ?></h3>
                        <p><?= h($plan['description']) ?></p>

                        <div class="plan-price">
                            <strong><?= peso($plan['amount']) ?></strong>
                            <span>
                                <?php if ((int)$plan['days'] > 0): ?>
                                    / <?= (int)$plan['days'] ?> day(s)
                                <?php endif; ?>

                                <?php if ((int)$plan['sessions'] > 0): ?>
                                    · <?= (int)$plan['sessions'] ?> session(s)
                                <?php endif; ?>
                            </span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="payment-grid">
            <div class="payment-card">
                <div class="payment-head">
                    <div>
                        <h3>Pay Using RFID Load</h3>
                        <p>Deducts directly from your loaded wallet balance.</p>
                    </div>

                    <div class="icon-bubble">💳</div>
                </div>

                <div class="payment-body">
                    <div class="wallet-visual">
                        <div class="wallet-row">
                            <span>Available Balance</span>
                            <strong><?= peso($walletBalance) ?></strong>
                        </div>

                        <div class="wallet-row">
                            <span>Selected Amount</span>
                            <strong id="selectedAmountWallet"><?= peso($defaultPlan['amount']) ?></strong>
                        </div>

                        <div class="wallet-row">
                            <span>Selected Plan</span>
                            <strong id="selectedPlanWallet"><?= h($defaultPlan['label']) ?></strong>
                        </div>

                        <div class="wallet-balance">RFID Wallet</div>

                        <div class="insufficient" id="insufficientNotice">
                            Your wallet balance is lower than the selected plan amount.
                        </div>
                    </div>

                    <form method="post" action="extending_membership.php" onsubmit="return confirm('Confirm RFID wallet payment for the selected membership plan?');">
                        <input type="hidden" name="payment_method" value="rfid">
                        <input type="hidden" name="plan_key" id="wallet_plan_key" value="<?= h($defaultPlan['key']) ?>">

                        <button type="submit" class="btn-main" id="rfidPayButton">
                            Pay with RFID Wallet
                        </button>
                    </form>

                    <ol class="note-list">
                        <li>Load your wallet first using the RFID load page.</li>
                        <li>Payment is completed immediately when balance is enough.</li>
                        <li>Your membership expiry or sessions update right away.</li>
                    </ol>
                </div>
            </div>

            <div class="payment-card gcash-card">
                <div class="gcash-header">
                    <div class="gcash-brand-row">
                        <div class="gcash-logo">
                            <span class="gcash-logo-mark">G</span>
                            <span>GCash</span>
                        </div>

                        <span class="gcash-status">QR Payment</span>
                    </div>

                    <div class="gcash-merchant">
                        <small>Send payment to</small>
                        <strong><?= h($gcashName) ?></strong>
                        <div><?= h($gcashNumber) ?></div>
                    </div>
                </div>

                <div class="gcash-body">
                    <div class="qr-stage">
                        <div class="qr-frame">
                            <div class="qr-inner">
                                <?php if ($qrExists): ?>
                                    <img src="<?= h($gcashQrImage) ?>?v=<?= time() ?>" alt="GCash QR Code">
                                <?php else: ?>
                                    <div class="qr-placeholder">
                                        QR IMAGE<br>
                                        Save your QR as<br>
                                        photo/gcash_qr.jpg<br>
                                        or photo/gcash_qr.png
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="gcash-amount-pill">
                        <span>Exact amount to send</span>
                        <strong id="selectedAmountGCash"><?= peso($defaultPlan['amount']) ?></strong>
                    </div>

                    <div class="form-panel">
                        <form method="post" action="extending_membership.php" enctype="multipart/form-data">
                            <input type="hidden" name="payment_method" value="gcash">
                            <input type="hidden" name="plan_key" id="gcash_plan_key" value="<?= h($defaultPlan['key']) ?>">

                            <div class="form-group">
                                <label class="field-label" for="sender_name">Your GCash Name</label>
                                <input
                                    type="text"
                                    id="sender_name"
                                    name="sender_name"
                                    class="form-control-modern"
                                    placeholder="Name shown in GCash receipt"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label class="field-label" for="sender_number">Your GCash Number</label>
                                <input
                                    type="text"
                                    id="sender_number"
                                    name="sender_number"
                                    class="form-control-modern"
                                    placeholder="09XXXXXXXXX"
                                    maxlength="11"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label class="field-label" for="reference_number">Reference Number</label>
                                <input
                                    type="text"
                                    id="reference_number"
                                    name="reference_number"
                                    class="form-control-modern"
                                    placeholder="GCash reference number"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label class="field-label" for="gcash_amount_sent">Amount Sent</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    id="gcash_amount_sent"
                                    name="amount_sent"
                                    class="form-control-modern"
                                    value="<?= number_format($defaultPlan['amount'], 2, '.', '') ?>"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label class="field-label">Proof of Payment</label>

                                <label class="upload-box" for="proof_image">
                                    <input
                                        type="file"
                                        id="proof_image"
                                        name="proof_image"
                                        accept="image/jpeg,image/png,image/webp"
                                        required
                                    >

                                    <span>
                                        <strong id="uploadText">Upload GCash Screenshot</strong>
                                        JPG, PNG, or WEBP only · Max 5MB
                                    </span>
                                </label>

                                <img id="previewImg" class="preview-img" alt="GCash proof preview">
                            </div>

                            <button type="submit" class="btn-gcash">
                                Submit GCash Proof
                            </button>
                        </form>
                    </div>

                    <ol class="note-list">
                        <li>Scan the QR using your GCash app.</li>
                        <li>Send the exact selected amount.</li>
                        <li>Upload the successful payment screenshot.</li>
                        <li>Staff/admin approval is required before membership is extended.</li>
                    </ol>
                </div>
            </div>
        </section>

    <?php endif; ?>
</div>

<script>
    const typeTabs = document.querySelectorAll('.type-tab');
    const planCards = document.querySelectorAll('.plan-card');

    const walletPlanKey = document.getElementById('wallet_plan_key');
    const gcashPlanKey = document.getElementById('gcash_plan_key');

    const selectedPlanName = document.getElementById('selectedPlanName');
    const selectedAmountWallet = document.getElementById('selectedAmountWallet');
    const selectedAmountGCash = document.getElementById('selectedAmountGCash');
    const selectedPlanWallet = document.getElementById('selectedPlanWallet');

    const gcashAmountSent = document.getElementById('gcash_amount_sent');
    const insufficientNotice = document.getElementById('insufficientNotice');
    const rfidPayButton = document.getElementById('rfidPayButton');

    const walletBalance = <?= json_encode($walletBalance) ?>;

    function formatPeso(amount) {
        return '₱' + Number(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function selectPlan(card) {
        const key = card.dataset.key;
        const name = card.dataset.name;
        const amount = parseFloat(card.dataset.amount || '0');

        planCards.forEach(item => item.classList.remove('active'));
        card.classList.add('active');

        walletPlanKey.value = key;
        gcashPlanKey.value = key;

        selectedPlanName.textContent = name;
        selectedAmountWallet.textContent = formatPeso(amount);
        selectedAmountGCash.textContent = formatPeso(amount);
        selectedPlanWallet.textContent = name;
        gcashAmountSent.value = amount.toFixed(2);

        if (walletBalance < amount) {
            insufficientNotice.style.display = 'block';
            rfidPayButton.disabled = true;
            rfidPayButton.style.opacity = '.55';
            rfidPayButton.style.cursor = 'not-allowed';
        } else {
            insufficientNotice.style.display = 'none';
            rfidPayButton.disabled = false;
            rfidPayButton.style.opacity = '1';
            rfidPayButton.style.cursor = 'pointer';
        }
    }

    function selectType(type) {
        typeTabs.forEach(tab => {
            tab.classList.toggle('active', tab.dataset.type === type);
        });

        let firstVisible = null;

        planCards.forEach(card => {
            const show = card.dataset.type === type;
            card.classList.toggle('hidden', !show);
            card.classList.remove('active');

            if (show && firstVisible === null) {
                firstVisible = card;
            }
        });

        if (firstVisible) {
            selectPlan(firstVisible);
        }
    }

    typeTabs.forEach(tab => {
        tab.addEventListener('click', () => selectType(tab.dataset.type));
    });

    planCards.forEach(card => {
        card.addEventListener('click', () => selectPlan(card));
    });

    const firstActivePlan = document.querySelector('.plan-card.active') || document.querySelector('.plan-card:not(.hidden)');
    if (firstActivePlan) {
        selectPlan(firstActivePlan);
    }

    const proofInput = document.getElementById('proof_image');
    const previewImg = document.getElementById('previewImg');
    const uploadText = document.getElementById('uploadText');

    if (proofInput) {
        proofInput.addEventListener('change', function () {
            const file = this.files && this.files[0];

            if (!file) {
                previewImg.style.display = 'none';
                uploadText.textContent = 'Upload GCash Screenshot';
                return;
            }

            uploadText.textContent = file.name;

            const reader = new FileReader();

            reader.onload = function (event) {
                previewImg.src = event.target.result;
                previewImg.style.display = 'block';
            };

            reader.readAsDataURL(file);
        });
    }
</script>
</body>
</html>