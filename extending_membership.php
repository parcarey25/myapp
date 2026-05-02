<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'member';
$role     = strtolower(trim($_SESSION['role'] ?? 'member'));

if ($userId <= 0 || !in_array($role, ['member', 'staff', 'admin'], true)) {
    header('Location: login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_with_message(string $type, string $message): void
{
    $param = $type === 'success' ? 'success' : 'error';
    header('Location: extending_membership.php?' . $param . '=' . urlencode($message));
    exit;
}

function get_wallet_balance(mysqli $conn, int $userId): float
{
    $balance = 0.00;
    $sql = "SELECT balance FROM rfid_wallet_balances WHERE user_id = ? LIMIT 1";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();

        if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) {
                $balance = (float)($row['balance'] ?? 0);
            }
            $res->free();
        }

        $stmt->close();
    }

    return $balance;
}

function get_membership_expiry(mysqli $conn, int $userId): ?string
{
    $expiry = null;
    $sql = "SELECT membership_expires_at FROM users WHERE id = ? LIMIT 1";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();

        if ($res = $stmt->get_result()) {
            if ($row = $res->fetch_assoc()) {
                $expiry = $row['membership_expires_at'] ?? null;
            }
            $res->free();
        }

        $stmt->close();
    }

    return $expiry;
}

function apply_membership_extension(mysqli $conn, int $userId, int $days): bool
{
    $currentExpiry = get_membership_expiry($conn, $userId);
    $baseTime = time();

    if (!empty($currentExpiry)) {
        $expiryTs = strtotime($currentExpiry);
        if ($expiryTs !== false && $expiryTs > time()) {
            $baseTime = $expiryTs;
        }
    }

    $newExpiry = date('Y-m-d H:i:s', strtotime("+{$days} days", $baseTime));

    $sql = "UPDATE users SET membership_expires_at = ? WHERE id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('si', $newExpiry, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    return false;
}

function ensure_wallet_row(mysqli $conn, int $userId): void
{
    $sql = "INSERT IGNORE INTO rfid_wallet_balances (user_id, balance) VALUES (?, 0.00)";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

$plans = [
    [
        'key'    => 'monthly',
        'name'   => '30 Days Membership',
        'days'   => 30,
        'amount' => 1000.00,
    ],
    [
        'key'    => 'bimonthly',
        'name'   => '60 Days Membership',
        'days'   => 60,
        'amount' => 1800.00,
    ],
    [
        'key'    => 'quarterly',
        'name'   => '90 Days Membership',
        'days'   => 90,
        'amount' => 2500.00,
    ],
];

$plansByKey = [];
foreach ($plans as $plan) {
    $plansByKey[$plan['key']] = $plan;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $planKey = trim($_POST['plan_key'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? '');

    if (!isset($plansByKey[$planKey])) {
        redirect_with_message('error', 'Invalid membership plan selected.');
    }

    $plan = $plansByKey[$planKey];
    $planName = $plan['name'];
    $durationDays = (int)$plan['days'];
    $amount = (float)$plan['amount'];

    if ($paymentMethod === 'rfid') {
        $conn->begin_transaction();

        try {
            ensure_wallet_row($conn, $userId);

            $currentBalance = 0.00;
            $sql = "SELECT balance FROM rfid_wallet_balances WHERE user_id = ? FOR UPDATE";
            if (!($stmt = $conn->prepare($sql))) {
                throw new Exception('Failed to prepare wallet check.');
            }

            $stmt->bind_param('i', $userId);
            $stmt->execute();

            if ($res = $stmt->get_result()) {
                if ($row = $res->fetch_assoc()) {
                    $currentBalance = (float)($row['balance'] ?? 0);
                }
                $res->free();
            }
            $stmt->close();

            if ($currentBalance < $amount) {
                throw new Exception('Insufficient RFID wallet balance.');
            }

            $sql = "UPDATE rfid_wallet_balances SET balance = balance - ? WHERE user_id = ?";
            if (!($stmt = $conn->prepare($sql))) {
                throw new Exception('Failed to deduct RFID balance.');
            }

            $stmt->bind_param('di', $amount, $userId);
            $stmt->execute();
            $stmt->close();

            $referenceNo = 'RFID-EXT-' . $userId . '-' . time();
            $description = 'Membership extension: ' . $planName;
            $negativeAmount = -1 * abs($amount);

            $sql = "INSERT INTO rfid_wallet_transactions
                    (user_id, amount, transaction_type, reference_no, description)
                    VALUES (?, ?, 'deduct', ?, ?)";
            if (!($stmt = $conn->prepare($sql))) {
                throw new Exception('Failed to log RFID deduction.');
            }

            $stmt->bind_param('idss', $userId, $negativeAmount, $referenceNo, $description);
            $stmt->execute();
            $stmt->close();

            if (!apply_membership_extension($conn, $userId, $durationDays)) {
                throw new Exception('Failed to extend membership.');
            }

            $sql = "INSERT INTO membership_extension_requests
                    (user_id, plan_name, duration_days, amount, payment_method, wallet_deducted, status, approved_at)
                    VALUES (?, ?, ?, ?, 'rfid', ?, 'completed', NOW())";
            if (!($stmt = $conn->prepare($sql))) {
                throw new Exception('Failed to save extension record.');
            }

            $walletDeducted = $amount;
            $stmt->bind_param('isidd', $userId, $planName, $durationDays, $amount, $walletDeducted);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            redirect_with_message('success', 'RFID wallet payment successful. Membership updated.');
        } catch (Throwable $e) {
            $conn->rollback();
            redirect_with_message('error', $e->getMessage());
        }
    }

    if ($paymentMethod === 'gcash') {
        $senderName      = trim($_POST['sender_name'] ?? '');
        $senderNumber    = trim($_POST['sender_number'] ?? '');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $amountSent      = (float)($_POST['amount_sent'] ?? 0);

        if ($senderName === '' || $senderNumber === '' || $referenceNumber === '') {
            redirect_with_message('error', 'Please complete all GCash fields.');
        }

        if (abs($amountSent - $amount) > 0.009) {
            redirect_with_message('error', 'Amount sent must match the selected plan amount.');
        }

        $uploadDir = __DIR__ . '/uploads/extension_proofs/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                redirect_with_message('error', 'Failed to create upload folder.');
            }
        }

        if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
            redirect_with_message('error', 'Please upload proof of payment.');
        }

        $file = $_FILES['proof_image'];

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed[$mime])) {
            redirect_with_message('error', 'Only JPG, PNG, and WEBP are allowed.');
        }

        $sql = "SELECT id FROM membership_extension_requests WHERE reference_number = ? LIMIT 1";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('s', $referenceNumber);
            $stmt->execute();

            if ($res = $stmt->get_result()) {
                if ($res->fetch_assoc()) {
                    $res->free();
                    $stmt->close();
                    redirect_with_message('error', 'Reference number already exists.');
                }
                $res->free();
            }

            $stmt->close();
        }

        $ext = $allowed[$mime];
        $filename = 'extension_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destination = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            redirect_with_message('error', 'Failed to upload proof image.');
        }

        $proofPath = 'uploads/extension_proofs/' . $filename;

        $sql = "INSERT INTO membership_extension_requests
                (user_id, plan_name, duration_days, amount, payment_method, sender_name, sender_number, reference_number, proof_image, status)
                VALUES (?, ?, ?, ?, 'gcash', ?, ?, ?, ?, 'pending')";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param(
                'isidssss',
                $userId,
                $planName,
                $durationDays,
                $amount,
                $senderName,
                $senderNumber,
                $referenceNumber,
                $proofPath
            );

            if ($stmt->execute()) {
                $stmt->close();
                redirect_with_message('success', 'GCash proof submitted successfully. Please wait for staff approval.');
            }

            $stmt->close();
        }

        @unlink($destination);
        redirect_with_message('error', 'Failed to save GCash extension request.');
    }

    redirect_with_message('error', 'Invalid payment method.');
}

$currentExpiry = get_membership_expiry($conn, $userId);
$walletBalance = get_wallet_balance($conn, $userId);
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Extend Membership | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
:root{
    --red:#c40000;
    --red2:#ff2a2a;
    --black:#060606;
    --panel:#111111;
    --line:rgba(255,255,255,.10);
    --text:#ffffff;
}
body{
    background:linear-gradient(180deg,#000,#090909);
    color:var(--text);
    font-family:Arial,sans-serif;
}
.page-wrap{max-width:1180px;margin:30px auto;padding:0 15px;}
.hero{
    padding:24px;
    border-radius:18px;
    margin-bottom:18px;
    background:linear-gradient(90deg,#450000,#8f0000,#d60000);
}
.hero h1{margin:0;font-size:2rem;font-weight:800;}
.hero p{margin:8px 0 0;color:#f3f3f3;}
.card-dark{
    background:linear-gradient(180deg,#141414,#0d0d0d);
    border:1px solid var(--line);
    border-radius:18px;
    box-shadow:0 18px 38px rgba(0,0,0,.35);
}
.section-body{padding:22px;}
.summary-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:14px;
    margin-bottom:18px;
}
.summary-card{
    background:#151515;
    border:1px solid var(--line);
    border-radius:14px;
    padding:16px;
}
.summary-card small{
    display:block;
    color:#cfcfcf;
    text-transform:uppercase;
    margin-bottom:8px;
    letter-spacing:.08em;
}
.summary-card strong{font-size:1.1rem;}
.plan-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:12px;
}
.plan-card{
    background:#171717;
    border:1px solid var(--line);
    border-radius:14px;
    padding:16px;
    cursor:pointer;
    transition:.15s ease;
}
.plan-card.active{
    border-color:#ff4d4d;
    box-shadow:0 0 0 1px #ff4d4d inset;
}
.plan-card h5{margin:0 0 6px;font-size:1rem;}
.plan-card p{margin:0;color:#ddd;}
.content-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
}
.method-card{
    background:#151515;
    border:1px solid var(--line);
    border-radius:16px;
    padding:18px;
    margin-bottom:14px;
}
.method-card h4{margin:0 0 10px;font-size:1.1rem;}
.amount-big{
    font-size:1.8rem;
    font-weight:800;
    color:#fff;
}
.qr-box{
    background:#fff;
    border-radius:16px;
    padding:18px;
    max-width:300px;
    margin:auto;
}
.qr-box img{
    width:100%;
    height:auto;
    display:block;
}
.label-muted{
    font-size:.84rem;
    color:#ddd;
    text-transform:uppercase;
    letter-spacing:.08em;
}
.form-control,.custom-file-label{
    background:#101010;
    border:1px solid var(--line);
    color:#fff;
    min-height:46px;
    border-radius:12px;
}
.form-control:focus{
    background:#101010;
    color:#fff;
    border-color:#ff4d4d;
    box-shadow:none;
}
.custom-file-label::after{
    height:44px;
    line-height:32px;
    border:none;
    background:#222;
    color:#fff;
}
.btn-red{
    background:linear-gradient(120deg,var(--red),var(--red2));
    color:#fff;
    border:none;
    border-radius:999px;
    padding:11px 20px;
    font-weight:700;
}
.btn-red:hover{
    color:#fff;
    opacity:.95;
}
.note-box{
    background:#171717;
    border:1px solid var(--line);
    border-radius:14px;
    padding:14px;
    color:#eee;
}
.preview-img{
    max-width:100%;
    border-radius:12px;
    margin-top:10px;
    display:none;
}
@media (max-width: 991.98px){
    .summary-grid,.plan-grid,.content-grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>
<body>
<div class="page-wrap">
    <div class="hero">
        <h1>Extend Membership</h1>
        <p>Choose your plan and pay using RFID wallet balance or GCash QR.</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <small>Member</small>
            <strong><?= h($username) ?></strong>
        </div>
        <div class="summary-card">
            <small>Current Expiry</small>
            <strong><?= $currentExpiry ? h(date('M d, Y', strtotime($currentExpiry))) : 'Not set' ?></strong>
        </div>
        <div class="summary-card">
            <small>RFID Wallet Balance</small>
            <strong>PHP <?= number_format($walletBalance, 2) ?></strong>
        </div>
    </div>

    <div class="card-dark section-body mb-3">
        <h4 class="mb-3">Choose Membership Plan</h4>
        <div class="plan-grid" id="planGrid">
            <?php foreach ($plans as $index => $plan): ?>
                <div class="plan-card<?= $index === 0 ? ' active' : '' ?>"
                     data-key="<?= h($plan['key']) ?>"
                     data-name="<?= h($plan['name']) ?>"
                     data-days="<?= (int)$plan['days'] ?>"
                     data-amount="<?= number_format($plan['amount'], 2, '.', '') ?>">
                    <h5><?= h($plan['name']) ?></h5>
                    <p><?= (int)$plan['days'] ?> days</p>
                    <p><strong>PHP <?= number_format($plan['amount'], 2) ?></strong></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="content-grid">
        <div class="card-dark section-body">
            <div class="method-card">
                <h4>Pay using RFID Wallet Balance</h4>
                <p class="mb-2">Available balance: <strong>PHP <?= number_format($walletBalance, 2) ?></strong></p>
                <p class="mb-3">Selected amount: <span class="amount-big" id="selectedAmountWallet">PHP <?= number_format($plans[0]['amount'], 2) ?></span></p>

                <form method="post" action="extending_membership.php">
                    <input type="hidden" name="payment_method" value="rfid">
                    <input type="hidden" name="plan_key" id="wallet_plan_key" value="<?= h($plans[0]['key']) ?>">
                    <button type="submit" class="btn btn-red btn-block">Pay with RFID Wallet</button>
                </form>
            </div>

            <div class="note-box">
                <strong>RFID Wallet:</strong>
                <ol class="mb-0 mt-2">
                    <li>Select a membership plan.</li>
                    <li>Click <strong>Pay with RFID Wallet</strong>.</li>
                    <li>If the balance is enough, the amount is deducted immediately.</li>
                    <li>Your membership is extended or activated right away.</li>
                </ol>
            </div>
        </div>

        <div class="card-dark section-body">
            <div class="method-card">
                <h4>Pay using GCash QR</h4>
                <div class="text-center mb-3">
                    <div class="qr-box">
                        <img src="photo/gcash_qr.jpg" alt="GCash QR Code">
                    </div>
                </div>

                <p class="mb-2"><strong>GCash Name:</strong> RJL Power Fitness Center</p>
                <p class="mb-2"><strong>GCash Number:</strong> 09XXXXXXXXX</p>
                <p class="mb-3"><strong>Exact Amount:</strong> <span class="amount-big" id="selectedAmountGCash">PHP <?= number_format($plans[0]['amount'], 2) ?></span></p>

                <form method="post" action="extending_membership.php" enctype="multipart/form-data">
                    <input type="hidden" name="payment_method" value="gcash">
                    <input type="hidden" name="plan_key" id="gcash_plan_key" value="<?= h($plans[0]['key']) ?>">

                    <div class="form-group">
                        <label class="label-muted">Your GCash Name</label>
                        <input type="text" name="sender_name" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="label-muted">Your GCash Number</label>
                        <input type="text" name="sender_number" class="form-control" placeholder="09XXXXXXXXX" required>
                    </div>

                    <div class="form-group">
                        <label class="label-muted">Reference Number</label>
                        <input type="text" name="reference_number" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="label-muted">Amount Sent</label>
                        <input type="number" step="0.01" name="amount_sent" id="gcash_amount_sent" class="form-control" value="<?= number_format($plans[0]['amount'], 2, '.', '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="label-muted">Proof of Payment</label>
                        <div class="custom-file">
                            <input type="file" class="custom-file-input" id="proof_image" name="proof_image" accept="image/*" required>
                            <label class="custom-file-label" for="proof_image">Choose screenshot</label>
                        </div>
                        <img id="previewImg" class="preview-img" alt="Preview">
                    </div>

                    <button type="submit" class="btn btn-red btn-block">Submit GCash Proof</button>
                </form>
            </div>

            <div class="note-box">
                <strong>GCash QR:</strong>
                <ol class="mb-0 mt-2">
                    <li>Pay using the QR code.</li>
                    <li>Upload your proof and reference number.</li>
                    <li>The request is saved as <strong>pending</strong>.</li>
                    <li>Staff/admin will approve or reject it.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<script>
const planCards = document.querySelectorAll('.plan-card');
const walletPlanKey = document.getElementById('wallet_plan_key');
const selectedAmountWallet = document.getElementById('selectedAmountWallet');

const gcashPlanKey = document.getElementById('gcash_plan_key');
const gcashAmountSent = document.getElementById('gcash_amount_sent');
const selectedAmountGCash = document.getElementById('selectedAmountGCash');

planCards.forEach(card => {
    card.addEventListener('click', () => {
        planCards.forEach(c => c.classList.remove('active'));
        card.classList.add('active');

        const key = card.dataset.key;
        const amount = card.dataset.amount;
        const amountText = 'PHP ' + parseFloat(amount).toFixed(2);

        walletPlanKey.value = key;
        selectedAmountWallet.textContent = amountText;

        gcashPlanKey.value = key;
        gcashAmountSent.value = amount;
        selectedAmountGCash.textContent = amountText;
    });
});

const proofInput = document.getElementById('proof_image');
const previewImg = document.getElementById('previewImg');
const proofLabel = document.querySelector('.custom-file-label');

proofInput?.addEventListener('change', function() {
    if (this.files && this.files[0]) {
        proofLabel.textContent = this.files[0].name;
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
        };
        reader.readAsDataURL(this.files[0]);
    }
});
</script>
</body>
</html>
"""

files = {
    'extend_membership_module.sql': sql,
    'extending_membership.php': extend_membership_php,
    'extend_membership_submit.php': extend_membership_submit_php,
    'extend_membership_pending.php': extend_membership_pending_php,
    'extend_membership_approve.php': extend_membership_approve_php,
    'extend_membership_reject.php': extend_membership_reject_php,
    'extend_membership_history.php': extend_membership_history_php,
}

for name, content in files.items():
    (base / name).write_text(content, encoding='utf-8')

zip_path = Path('/mnt/data/extending_membership_full_code.zip')
with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as zf:
    for p in base.iterdir():
        zf.write(p, arcname=p.name)

print(f"Created {zip_path}")
{Jsiiassistant to=python_user_visible.exec code ఎంత?”