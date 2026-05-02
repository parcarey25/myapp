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
if ($paymentId <= 0) {
    header('Location: gcash_pending.php');
    exit;
}

$conn->begin_transaction();

try {
    $sql = "UPDATE gcash_payments
            SET status = 'approved', approved_by = ?, approved_at = NOW(), rejection_reason = NULL
            WHERE id = ? AND status = 'pending'";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $approvedBy, $paymentId);
    $stmt->execute();
    $stmt->close();

    // OPTIONAL: update user membership here if your system uses a membership table or expires field.
    // Example only: fetch payment details and apply membership logic if needed.

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
}

header('Location: gcash_pending.php');
exit;