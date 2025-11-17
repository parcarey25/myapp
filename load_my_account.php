<?php
// FILE: load_my_account.php
// Show current wallet balance + instructions how to load account
// for ANY logged-in user (member, trainer, admin, etc.)

require_once 'auth.php';
require_once 'db.php';

$user_id  = $_SESSION['user_id'] ?? 0;
$role_raw = $_SESSION['role'] ?? '';
$role     = strtolower(trim($role_raw));

if ($user_id <= 0) {
    die("Access denied (no user logged in).");
}

// get user info + wallet
$stmt = $conn->prepare("SELECT full_name, email, wallet_balance, rfid_uid FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res  = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

$full_name      = $user['full_name'] ?? 'User';
$email          = $user['email'] ?? '';
$wallet_balance = isset($user['wallet_balance']) ? (float)$user['wallet_balance'] : 0.00;
$rfid_uid       = $user['rfid_uid'] ?: 'Not yet linked';
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Load My Account</title>
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
        .pill{background:rgba(76,175,80,.15);color:#4caf50;border-radius:999px;padding:3px 8px;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em}
        .back{display:inline-block;margin-bottom:12px;font-size:.85rem;color:#fff;text-decoration:none;border-radius:999px;border:1px solid var(--accent-red);padding:6px 12px}
        .back:hover{background:var(--accent-red);}
        .section{margin-top:10px;margin-bottom:16px;}
        .label{font-size:.8rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;}
        .value{font-size:1.1rem;margin:4px 0 8px;}
        .value-amount{font-size:1.6rem;font-weight:700;}
        ol{margin-left:20px;font-size:.95rem;}
        li{margin-bottom:6px;}
        .tip{font-size:.85rem;color:var(--text-muted);margin-top:8px;}
        .highlight{color:var(--accent-red);}
    </style>
</head>
<body>
<div class="wrap">
    <a href="home_member.php" class="back">&laquo; Back to Dashboard</a>

    <div class="card">
        <div class="head">
            <div>
                <div class="tag">RJL FITNESS · ACCOUNT</div>
                <div class="title">Load My Account</div>
            </div>
            <span class="pill"><?= htmlspecialchars(strtoupper($role)) ?> · RFID Wallet</span>
        </div>

        <div class="section">
            <div class="label">User</div>
            <div class="value"><?= htmlspecialchars($full_name) ?></div>
            <div class="label">Email</div>
            <div class="value"><?= htmlspecialchars($email) ?></div>
        </div>

        <div class="section">
            <div class="label">RFID Card ID</div>
            <div class="value"><?= htmlspecialchars($rfid_uid) ?></div>

            <div class="label">Current Wallet Balance</div>
            <div class="value value-amount">₱<?= number_format($wallet_balance, 2) ?></div>
        </div>

        <div class="section">
            <div class="label">How to load your account</div>
            <ol>
                <li>Go to the <span class="highlight">front desk / staff</span> and tell them you want to load your RFID account.</li>
                <li>Staff will open the <span class="highlight">RFID Load Wallet</span> screen on their system.</li>
                <li>They will click the RFID box and ask you to <span class="highlight">tap your card</span> on the scanner.</li>
                <li>Tell the staff how much you want to load (example: ₱100, ₱300, ₱500).</li>
                <li>The system will add the amount to your wallet balance and save the transaction.</li>
                <li>You can then use your RFID wallet to pay for <span class="highlight">membership</span> or other services without cash.</li>
            </ol>
            <p class="tip">
                For security, only authorized staff can actually load your account.
                This page is for you to see your balance and understand the loading process.
            </p>
        </div>
    </div>
</div>
</body>
</html>