<?php
// FILE: rfid_load.php
// STAFF PAGE: load money into a member's RFID wallet.
// Member gives cash to staff, staff credits e-money.

require_once 'auth.php';
require_once 'db.php';

$user_id  = $_SESSION['user_id'] ?? 0;
$role_raw = $_SESSION['role'] ?? '';
$role     = strtolower(trim($role_raw));

if ($user_id <= 0) {
    die("Access denied (not logged in).");
}

// allow only staff-like roles
if (!in_array($role, ['trainer','admin','staff'])) {
    die("Access denied (staff only).");
}

$message = '';
$is_ok   = false;
$member  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rfid_uid = trim($_POST['rfid_uid'] ?? '');
    $amount   = trim($_POST['amount'] ?? '');

    if ($rfid_uid === '' || $amount === '') {
        $message = "Please tap a card and enter an amount.";
    } elseif (!is_numeric($amount) || (float)$amount <= 0) {
        $message = "Invalid amount. Please enter a positive number (e.g. 100, 250.50).";
    } else {
        $amount_val = (float)$amount;

        // 1) Find member by RFID
        $stmt = $conn->prepare("
            SELECT id, full_name, email, wallet_balance
            FROM users
            WHERE rfid_uid = ?
        ");
        $stmt->bind_param('s', $rfid_uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $member = $res->fetch_assoc();
        $stmt->close();

        if (!$member) {
            $message = "No member found for RFID card: " . htmlspecialchars($rfid_uid);
        } else {
            $member_id   = (int)$member['id'];
            $old_balance = isset($member['wallet_balance']) ? (float)$member['wallet_balance'] : 0.00;
            $new_balance = $old_balance + $amount_val;

            // 2) Update wallet_balance
            $stmt = $conn->prepare("
                UPDATE users
                SET wallet_balance = wallet_balance + ?
                WHERE id = ?
            ");
            $stmt->bind_param('di', $amount_val, $member_id);
            $ok1 = $stmt->execute();
            $stmt->close();

            // 3) Record in payments table as cash load
            $method = 'Cash (Load)';
            $note   = 'RFID wallet load by staff';
            $stmt = $conn->prepare("
                INSERT INTO payments (user_id, amount, method, note)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param('idss', $member_id, $amount_val, $method, $note);
            $ok2 = $stmt->execute();
            $stmt->close();

            // Reload member to show updated balance
            $stmt = $conn->prepare("
                SELECT id, full_name, email, wallet_balance
                FROM users
                WHERE id = ?
            ");
            $stmt->bind_param('i', $member_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $member = $res->fetch_assoc();
            $stmt->close();

            if ($ok1 && $ok2 && $member) {
                $is_ok   = true;
                $message = "Loaded ₱" . number_format($amount_val, 2) . " to "
                         . htmlspecialchars($member['full_name'])
                         . ". New balance: ₱" . number_format($member['wallet_balance'], 2) . ".";
            } else {
                $message = "Error while loading wallet.";
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>RFID Load Wallet (Staff)</title>
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
        input{width:100%;padding:9px 11px;border-radius:8px;border:1px solid var(--border-soft);background:#101010;color:var(--text-light);outline:none;margin-bottom:8px}
        input:focus{border-color:var(--accent-red);box-shadow:0 0 0 1px rgba(229,57,53,.3);}
        button{border:none;border-radius:999px;padding:9px 16px;font-size:.9rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;background:var(--accent-red);color:#fff;transition:background .15s,transform .1s}
        button:hover{background:var(--accent-red-dark);transform:translateY(-1px);}
        .hint{font-size:.8rem;color:var(--text-muted);margin-top:-4px;margin-bottom:12px}
        .status{margin-bottom:12px;padding:10px 12px;border-radius:8px;font-size:.9rem}
        .ok{background:rgba(76,175,80,.1);border:1px solid #4caf50;}
        .err{background:rgba(229,57,53,.12);border:1px solid var(--accent-red);}
        .snapshot{margin-top:12px;font-size:.9rem;}
        .snapshot span{color:var(--text-muted);}

        /* New back button */
        .back-link{
            display:inline-block;
            margin-bottom:12px;
            padding:6px 12px;
            border-radius:999px;
            border:1px solid var(--accent-red);
            color:#fff;
            text-decoration:none;
            font-size:.85rem;
        }
        .back-link:hover{
            background:var(--accent-red);
        }
    </style>
</head>
<body>
<div class="wrap">
    <!-- BACK BUTTON -->
    <a href="home_staff.php" class="back-link">&laquo; Back to Staff Dashboard</a>

    <div class="card">
        <div class="head">
            <div>
                <div class="tag">RJL FITNESS · STAFF</div>
                <div class="title">RFID Load Wallet</div>
            </div>
            <span class="pill"><?= htmlspecialchars(strtoupper($role)) ?></span>
        </div>

        <?php if ($message): ?>
            <div class="status <?= $is_ok ? 'ok' : 'err' ?>">
                <?= $message ?>
            </div>
        <?php else: ?>
            <div class="status ok">
                Ask the member for cash, then:
                tap their RFID card, enter the load amount, and click <strong>Load Wallet</strong>.
            </div>
        <?php endif; ?>

        <form method="post">
            <label for="rfid_uid">Tap RFID Card</label>
            <input type="text" name="rfid_uid" id="rfid_uid" autocomplete="off" required>
            <div class="hint">Click here, then let the member tap/swipe their card on the scanner.</div>

            <label for="amount">Amount to Load (₱)</label>
            <input type="text" name="amount" id="amount" placeholder="e.g. 100, 300, 500" required>

            <button type="submit">Load Wallet</button>
        </form>

        <?php if ($member): ?>
            <div class="snapshot">
                <p><span>Member: </span><strong><?= htmlspecialchars($member['full_name']) ?></strong></p>
                <p><span>Email: </span><?= htmlspecialchars($member['email']) ?></p>
                <p><span>Current Wallet Balance: </span>₱<?= number_format($member['wallet_balance'], 2) ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Auto-focus card field on load
document.getElementById('rfid_uid').focus();
</script>
</body>
</html>