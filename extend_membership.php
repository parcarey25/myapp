<?php
// FILE: extend_membership.php
// Staff extends membership using RFID + plan choice (1 day / 1 week / 1 month, etc.)

require_once 'auth.php';
require_once 'db.php';
require_once 'wallet_helpers.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['trainer','admin','staff'])) {
    die("Access denied.");
}

$message = '';
$is_ok   = false;
$user    = null;
$plan    = null;

// Load available membership plans (1 day, 1 week, 1 month, etc.)
$plans = [];
$res = $conn->query("SELECT id, name, price, duration_days FROM membership_plans ORDER BY price ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $plans[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rfid_uid = trim($_POST['rfid_uid'] ?? '');
    $plan_id  = intval($_POST['plan_id'] ?? 0);

    if ($rfid_uid === '' || $plan_id <= 0) {
        $message = "Please tap a card and select a membership plan.";
    } else {
        // 1) Find member by RFID
        $user = find_user_by_rfid($rfid_uid);
        if (!$user) {
            $message = "No member found for this RFID card.";
        } else {
            $user_id = (int)$user['id'];

            // 2) Get the selected plan
            $stmt = $conn->prepare("SELECT id, name, price, duration_days FROM membership_plans WHERE id = ?");
            $stmt->bind_param('i', $plan_id);
            $stmt->execute();
            $resPlan = $stmt->get_result();
            $plan = $resPlan->fetch_assoc();
            $stmt->close();

            if (!$plan) {
                $message = "Selected plan not found.";
            } else {
                $price   = (float)$plan['price'];
                $days    = (int)$plan['duration_days'];
                $plan_name = $plan['name'];

                // 3) Check wallet balance
                $wallet_balance = (float)$user['wallet_balance'];
                if ($wallet_balance < $price) {
                    $message = "Insufficient wallet balance. Plan '" . htmlspecialchars($plan_name) .
                               "' costs ₱" . number_format($price,2) .
                               ", but wallet has only ₱" . number_format($wallet_balance,2) . ".";
                } else {
                    // 4) Deduct from wallet and log wallet_transaction
                    $ok1 = update_wallet_balance($user_id, -$price);
                    $ok2 = add_wallet_transaction(
                        $user_id,
                        -$price,
                        'membership',
                        'RFID extend: ' . $plan_name
                    );

                    // Also create a payment record (for admin reports)
                    $method = 'RFID Wallet';
                    $note   = 'Membership extension: ' . $plan_name;
                    $stmt = $conn->prepare("
                        INSERT INTO payments (user_id, amount, method, note)
                        VALUES (?, ?, ?, ?)
                    ");
                    $amount_positive = $price; // store as positive amount paid
                    $stmt->bind_param('idss', $user_id, $amount_positive, $method, $note);
                    $ok3 = $stmt->execute();
                    $stmt->close();

                    // 5) Extend membership in memberships table
                    // Get latest membership (if exists)
                    $stmt = $conn->prepare("
                        SELECT id, start_date, end_date
                        FROM memberships
                        WHERE user_id = ?
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $stmt->bind_param('i', $user_id);
                    $stmt->execute();
                    $resMem = $stmt->get_result();
                    $currentMem = $resMem->fetch_assoc();
                    $stmt->close();

                    $now = new DateTime();

                    if ($currentMem) {
                        $membership_id = (int)$currentMem['id'];
                        $end_date      = new DateTime($currentMem['end_date']);

                        // Extend from later of (today, current end_date)
                        if ($end_date > $now) {
                            $start_from = $end_date;
                        } else {
                            $start_from = $now;
                        }
                        $start_from->modify('+' . $days . ' days');
                        $new_end_date = $start_from->format('Y-m-d');

                        // Update existing membership
                        $stmt = $conn->prepare("
                            UPDATE memberships
                            SET plan_id = ?, end_date = ?, status = 'active'
                            WHERE id = ?
                        ");
                        $stmt->bind_param('isi', $plan_id, $new_end_date, $membership_id);
                        $ok4 = $stmt->execute();
                        $stmt->close();
                    } else {
                        // No membership yet: create a new one starting today
                        $start_date   = $now->format('Y-m-d');
                        $now->modify('+' . $days . ' days');
                        $new_end_date = $now->format('Y-m-d');

                        $stmt = $conn->prepare("
                            INSERT INTO memberships (user_id, plan_id, start_date, end_date, status)
                            VALUES (?, ?, ?, ?, 'active')
                        ");
                        $stmt->bind_param('iiss', $user_id, $plan_id, $start_date, $new_end_date);
                        $ok4 = $stmt->execute();
                        $stmt->close();
                    }

                    if ($ok1 && $ok2 && $ok3 && $ok4) {
                        // Refresh user data
                        $user = find_user_by_rfid($rfid_uid);
                        $is_ok = true;
                        $message = "Membership extended for " . htmlspecialchars($user['full_name']) .
                                   " using plan '" . htmlspecialchars($plan_name) .
                                   "'. New end date: " . htmlspecialchars($new_end_date) .
                                   ". Remaining wallet: ₱" . number_format($user['wallet_balance'], 2);
                    } else {
                        $message = "Error processing membership extension.";
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
    <title>Extend Membership (RFID)</title>
    <style>
        :root {
            --bg-card:#181818; --accent-red:#e53935; --accent-red-dark:#b71c1c;
            --text-light:#f5f5f5; --text-muted:#aaaaaa; --border-soft:#2a2a33;
        }
        *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
        body{margin:0;background:#000;color:var(--text-light);}
        .wrap{max-width:900px;margin:40px auto;padding:0 16px;}
        .card{background:var(--bg-card);border:1px solid var(--border-soft);border-radius:16px;padding:24px;box-shadow:0 16px 40px rgba(0,0,0,.6);}
        .head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:16px;border-bottom:1px solid var(--border-soft);padding-bottom:8px;}
        .title{font-size:1.6rem;font-weight:600}
        .tag{font-size:.85rem;color:var(--accent-red);text-transform:uppercase;letter-spacing:.15em}
        .pill{background:rgba(229,57,53,.12);color:var(--accent-red);border-radius:999px;padding:3px 8px;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em}
        label{font-size:.8rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:4px}
        input,select{width:100%;padding:9px 11px;border-radius:8px;border:1px solid var(--border-soft);background:#101010;color:var(--text-light);outline:none;margin-bottom:8px}
        input:focus,select:focus{border-color:var(--accent-red);box-shadow:0 0 0 1px rgba(229,57,53,.3);}
        button{border:none;border-radius:999px;padding:9px 16px;font-size:.9rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;background:var(--accent-red);color:#fff;transition:background .15s,transform .1s}
        button:hover{background:var(--accent-red-dark);transform:translateY(-1px)}
        .hint{font-size:.8rem;color:var(--text-muted);margin-top:-4px;margin-bottom:12px}
        .status{margin-bottom:12px;padding:10px 12px;border-radius:8px;font-size:.9rem}
        .ok{background:rgba(76,175,80,.1);border:1px solid #4caf50;}
        .err{background:rgba(229,57,53,.12);border:1px solid var(--accent-red);}
        .snapshot{margin-top:12px;font-size:.9rem;}
        .snapshot span{color:var(--text-muted);}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <div>
                <div class="tag">RJL FITNESS · STAFF</div>
                <div class="title">Extend Membership (RFID)</div>
            </div>
            <span class="pill"><?= htmlspecialchars(strtoupper($role)) ?></span>
        </div>

        <?php if ($message): ?>
            <div class="status <?= $is_ok ? 'ok' : 'err' ?>">
                <?= $message ?>
            </div>
        <?php else: ?>
            <div class="status ok">
                Tap the member's RFID card, choose a plan (e.g. 1 day / 1 week / 1 month), then click Extend.
            </div>
        <?php endif; ?>

        <form method="post">
            <label for="rfid_uid">Tap RFID Card</label>
            <input type="text" name="rfid_uid" id="rfid_uid" autocomplete="off" required>
            <div class="hint">Click here, then let the member swipe/tap their card.</div>

            <label for="plan_id">Membership Plan</label>
            <select name="plan_id" id="plan_id" required>
                <option value="">-- Select plan (1 day, 1 week, 1 month, etc.) --</option>
                <?php foreach ($plans as $p): ?>
                    <option value="<?= $p['id'] ?>">
                        <?= htmlspecialchars($p['name']) ?> · ₱<?= number_format($p['price'],2) ?>
                        · <?= (int)$p['duration_days'] ?> day(s)
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Extend Membership</button>
        </form>

        <?php if ($user): ?>
            <div class="snapshot">
                <p><span>Member: </span><strong><?= htmlspecialchars($user['full_name']) ?></strong></p>
                <p><span>Email: </span><?= htmlspecialchars($user['email']) ?></p>
                <p><span>Wallet Balance: </span>₱<?= number_format($user['wallet_balance'], 2) ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('rfid_uid').focus();
</script>
</body>
</html>