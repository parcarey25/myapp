<?php
require_once 'auth.php';
require_once 'wallet_helpers.php';
global $conn;

if (!in_array($_SESSION['role'] ?? '', ['staff','admin'])) {
    die("Access denied.");
}

$message = '';
$is_ok   = false;
$user    = null;

// load plans
$plans = [];
$res = $conn->query("SELECT id, name, price, duration_days FROM membership_plans ORDER BY price ASC");
while ($row = $res->fetch_assoc()) {
    $plans[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rfid_uid = trim($_POST['rfid_uid'] ?? '');
    $plan_id  = intval($_POST['plan_id'] ?? 0);

    if ($rfid_uid === '' || $plan_id <= 0) {
        $message = "Tap a card and choose a membership plan.";
    } else {
        $user = find_user_by_rfid($rfid_uid);
        if (!$user) {
            $message = "No member found for this card.";
        } else {
            // get plan
            $stmt = $conn->prepare("SELECT id, name, price, duration_days FROM membership_plans WHERE id = ?");
            $stmt->bind_param('i', $plan_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $plan = $res->fetch_assoc();
            $stmt->close();

            if (!$plan) {
                $message = "Selected plan not found.";
            } else {
                $price = floatval($plan['price']);
                if ($user['wallet_balance'] < $price) {
                    $message = "Insufficient balance. Need ₱" . number_format($price,2) .
                               ", wallet has ₱" . number_format($user['wallet_balance'],2);
                } else {
                    // deduct & log
                    $ok1 = update_wallet_balance($user['id'], -$price);
                    $ok2 = add_wallet_transaction(
                        $user['id'],
                        -$price,
                        'membership',
                        'RFID membership: ' . $plan['name']
                    );

                    // extend or create membership
                    $stmt = $conn->prepare("SELECT id, expires_at FROM memberships WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->bind_param('i', $user['id']);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $m   = $res->fetch_assoc();
                    $stmt->close();

                    $days = intval($plan['duration_days']);
                    $now  = new DateTime();

                    if ($m && $m['expires_at'] && $m['expires_at'] > $now->format('Y-m-d')) {
                        $start = new DateTime($m['expires_at']); // extend from expiry
                    } else {
                        $start = $now; // start today
                    }
                    $start->modify('+' . $days . ' days');
                    $new_expiry = $start->format('Y-m-d');

                    if ($m) {
                        $stmt = $conn->prepare("UPDATE memberships SET plan_id = ?, expires_at = ?, status = 'active' WHERE id = ?");
                        $stmt->bind_param('isi', $plan['id'], $new_expiry, $m['id']);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO memberships (user_id, plan_id, expires_at, status) VALUES (?,?,?,'active')");
                        $stmt->bind_param('iis', $user['id'], $plan['id'], $new_expiry);
                    }
                    $ok3 = $stmt->execute();
                    $stmt->close();

                    if ($ok1 && $ok2 && $ok3) {
                        $user = find_user_by_rfid($rfid_uid); // refresh
                        $message = "Membership renewed for " . htmlspecialchars($user['full_name']) .
                                   ". New expiry: " . htmlspecialchars($new_expiry) .
                                   ". Remaining balance: ₱" . number_format($user['wallet_balance'],2);
                        $is_ok = true;
                    } else {
                        $message = "Error processing membership renewal.";
                    }
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
    <title>RFID Membership Renewal</title>
    <!-- paste CSS here -->
</head>
<body>
<div class="page-wrapper">
    <div class="card">
        <div class="card-header">
            <div>
                <div class="tagline">RJL FITNESS · RFID WALLET</div>
                <h1>Membership Renewal</h1>
            </div>
            <span class="pill pill-red">Staff / Admin</span>
        </div>

        <?php if ($message): ?>
            <div class="status-message <?= $is_ok ? 'status-ok' : 'status-error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="flex-row">
                <div class="flex-1">
                    <label>RFID Card ID</label>
                    <input type="text" name="rfid_uid" id="rfid_uid" required autocomplete="off">
                    <div class="hint">Focus here, then tap the member’s card.</div>
                </div>
                <div class="flex-2">
                    <label>Membership Plan</label>
                    <select name="plan_id" required>
                        <option value="">-- select plan --</option>
                        <?php foreach ($plans as $p): ?>
                            <option value="<?= $p['id'] ?>">
                                <?= htmlspecialchars($p['name']) ?>
                                &nbsp;·&nbsp; ₱<?= number_format($p['price'],2) ?>
                                &nbsp;·&nbsp; <?= (int)$p['duration_days'] ?> days
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit">Pay with RFID Wallet</button>
        </form>
    </div>
</div>

<script>
document.getElementById('rfid_uid').focus();
</script>
</body>
</html>