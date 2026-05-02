<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$role = strtolower(trim($_SESSION['role'] ?? ''));
$approvedBy = (int)($_SESSION['user_id'] ?? 0);

if (!in_array($role, ['staff','admin'], true)) {
    header('Location: home.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: gcash_pending.php');
    exit;
}

$paymentId = (int)($_POST['payment_id'] ?? 0);
$reason = trim($_POST['rejection_reason'] ?? '');

if ($paymentId <= 0 || $reason === '') {
    header('Location: gcash_pending.php');
    exit;
}

$sql = "UPDATE gcash_payments
        SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ?
        WHERE id = ? AND status = 'pending'";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('isi', $approvedBy, $reason, $paymentId);
    $stmt->execute();
    $stmt->close();
}

header('Location: gcash_pending.php');
exit;