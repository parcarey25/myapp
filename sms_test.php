<?php
require __DIR__ . '/sms_functions.php';

$message = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone   = trim($_POST['phone'] ?? '');
    $type    = trim($_POST['type'] ?? 'registration');
    $name    = trim($_POST['name'] ?? 'Test User');
    $amount  = (float)($_POST['amount'] ?? 0);
    $balance = ($_POST['balance'] ?? '') !== '' ? (float)$_POST['balance'] : null;

    if ($type === 'registration') {
        $result = send_registration_plan_sms($conn, null, $phone, $name);
    } elseif ($type === 'payment_success') {
        $result = send_payment_result_sms($conn, null, $phone, $name, $amount, true, 'TEST-001');
    } elseif ($type === 'payment_failed') {
        $result = send_payment_result_sms($conn, null, $phone, $name, $amount, false, 'TEST-002');
    } elseif ($type === 'rfid_load') {
        $result = send_rfid_load_sms($conn, null, $phone, $name, $amount, $balance);
    }

    $message = ($result && !empty($result['success']))
        ? 'SMS request sent successfully.'
        : 'SMS request failed: ' . ($result['error'] ?? 'Unknown error');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SMS Test</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-dark text-white">
<div class="container py-5" style="max-width:700px;">
    <h2 class="mb-4">SMS Test Page</h2>

    <?php if ($message): ?>
        <div class="alert <?= !empty($result['success']) ? 'alert-success' : 'alert-danger' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="post" class="card bg-secondary p-4">
        <div class="form-group">
            <label>Phone Number</label>
            <input type="text" name="phone" class="form-control" placeholder="09xxxxxxxxx" required>
        </div>

        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" class="form-control" value="Test User">
        </div>

        <div class="form-group">
            <label>SMS Type</label>
            <select name="type" class="form-control">
                <option value="registration">Registration Plans</option>
                <option value="payment_success">Payment Success</option>
                <option value="payment_failed">Payment Failed</option>
                <option value="rfid_load">RFID Load</option>
            </select>
        </div>

        <div class="form-group">
            <label>Amount</label>
            <input type="number" step="0.01" name="amount" class="form-control" value="500">
        </div>

        <div class="form-group">
            <label>RFID Balance</label>
            <input type="number" step="0.01" name="balance" class="form-control" value="1000">
        </div>

        <button type="submit" class="btn btn-danger btn-block">Send Test SMS</button>
    </form>
</div>
</body>
</html>