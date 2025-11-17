<?php
require_once 'auth.php';          // protect page (only staff/admin)
require_once 'wallet_helpers.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rfid_uid    = trim($_POST['rfid_uid'] ?? '');
    $amount      = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if ($rfid_uid === '' || $amount <= 0) {
        $message = "Please tap a card and enter a valid amount.";
    } else {
        $user = find_user_by_rfid($rfid_uid);
        if (!$user) {
            $message = "No user found for this card.";
        } else {
            if ($user['wallet_balance'] < $amount) {
                $message = "Insufficient balance. Need ₱" . number_format($amount,2) .
                           ", wallet has ₱" . number_format($user['wallet_balance'],2);
            } else {
                // deduct from wallet and save transaction
                $ok1 = update_wallet_balance($user['id'], -$amount);
                $ok2 = add_wallet_transaction(
                    $user['id'],
                    -$amount,
                    'product', // type (you can still use this for membership too)
                    $description ?: 'Gym payment'
                );

                if ($ok1 && $ok2) {
                    $user = find_user_by_rfid($rfid_uid); // refresh balance
                    $message = "Payment of ₱" . number_format($amount,2) .
                               " successful for " . htmlspecialchars($user['full_name']) .
                               ". Remaining wallet balance: ₱" .
                               number_format($user['wallet_balance'], 2);
                } else {
                    $message = "Error processing payment.";
                }
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>RFID Pay (Any Purpose)</title>
</head>
<body>
<h1>RFID Wallet Payment</h1>

<?php if ($message): ?>
    <p><strong><?= $message ?></strong></p>
<?php endif; ?>

<form method="post">
    <label>RFID Card ID:</label><br>
    <input type="text" name="rfid_uid" id="rfid_uid" required autocomplete="off">
    <small>Click here and tap the card.</small>
    <br><br>

    <label>Amount to pay (₱):</label><br>
    <input type="number" name="amount" step="0.01" min="0.01" required>
    <br><br>

    <label>Description (optional):</label><br>
    <input type="text" name="description" placeholder="e.g. Day pass, Renewal, Drink">
    <br><br>

    <button type="submit">Pay with Wallet</button>
</form>

<script>
document.getElementById('rfid_uid').focus();
</script>
</body>
</html>