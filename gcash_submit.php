<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = strtolower(trim($_SESSION['role'] ?? 'member'));

if ($userId <= 0 || !in_array($role, ['member', 'staff', 'admin'], true)) {
    header('Location: login.php');
    exit;
}

function redirect_with_error(string $msg): void {
    header('Location: gcash_payment.php?error=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_error('Invalid request.');
}

$planName        = trim($_POST['plan_name'] ?? '');
$amount          = (float)($_POST['amount'] ?? 0);
$amountSent      = (float)($_POST['amount_sent'] ?? 0);
$senderName      = trim($_POST['sender_name'] ?? '');
$senderNumber    = trim($_POST['sender_number'] ?? '');
$referenceNumber = trim($_POST['reference_number'] ?? '');

if ($planName === '' || $amount <= 0 || $senderName === '' || $senderNumber === '' || $referenceNumber === '') {
    redirect_with_error('Please complete all required fields.');
}

if ($amountSent <= 0) {
    redirect_with_error('Invalid amount sent.');
}

if (abs($amountSent - $amount) > 0.009) {
    redirect_with_error('Amount sent must match the selected plan amount.');
}

/*
|--------------------------------------------------------------------------
| Upload folder
|--------------------------------------------------------------------------
*/
$uploadDir = __DIR__ . '/uploads/gcash_proofs/';

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        redirect_with_error('Failed to create upload folder.');
    }
}

/*
|--------------------------------------------------------------------------
| Check uploaded proof image
|--------------------------------------------------------------------------
*/
if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
    redirect_with_error('Please upload proof of payment.');
}

$file = $_FILES['proof_image'];

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp'
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowed[$mime])) {
    redirect_with_error('Only JPG, PNG, or WEBP images are allowed.');
}

$ext = $allowed[$mime];
$filename = 'gcash_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destination = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    redirect_with_error('Failed to upload proof image.');
}

$proofPath = 'uploads/gcash_proofs/' . $filename;

/*
|--------------------------------------------------------------------------
| Duplicate reference check
|--------------------------------------------------------------------------
*/
$sql = "SELECT id FROM gcash_payments WHERE reference_number = ? LIMIT 1";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('s', $referenceNumber);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->fetch_assoc()) {
        $stmt->close();
        @unlink($destination);
        redirect_with_error('Reference number already exists.');
    }

    if ($res) {
        $res->free();
    }
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Save payment request
|--------------------------------------------------------------------------
*/
$sql = "INSERT INTO gcash_payments (
            user_id,
            plan_name,
            amount,
            sender_name,
            sender_number,
            reference_number,
            proof_image,
            payment_method,
            status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'GCash', 'pending')";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param(
        'isdssss',
        $userId,
        $planName,
        $amount,
        $senderName,
        $senderNumber,
        $referenceNumber,
        $proofPath
    );

    if ($stmt->execute()) {
        $stmt->close();
        header('Location: gcash_payment.php?success=1');
        exit;
    }

    $stmt->close();
}

@unlink($destination);
redirect_with_error('Failed to save payment request.');