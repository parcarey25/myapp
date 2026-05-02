<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'member';
$role = strtolower(trim($_SESSION['role'] ?? 'member'));

if ($userId <= 0 || !in_array($role, ['member', 'staff', 'admin'], true)) {
    header('Location: login.php');
    exit;
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function has_column(mysqli $conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);

    if ($table === '') {
        return false;
    }

    $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'";
    if ($res = $conn->query($sql)) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }

    return false;
}

$fullName = $username;
$phone = '';

$possiblePhoneColumns = ['phone', 'contact_number', 'mobile_number', 'phone_number', 'cp_number'];
$existingPhoneColumns = [];

foreach ($possiblePhoneColumns as $col) {
    if (has_column($conn, 'users', $col)) {
        $existingPhoneColumns[] = $col;
    }
}

$selectColumns = ['full_name'];
foreach ($existingPhoneColumns as $col) {
    $selectColumns[] = $col;
}

$quotedColumns = array_map(function ($c) {
    return "`$c`";
}, $selectColumns);

$selectSql = "SELECT " . implode(', ', $quotedColumns) . " FROM `users` WHERE id = ? LIMIT 1";

if ($stmt = $conn->prepare($selectSql)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    if ($res = $stmt->get_result()) {
        if ($row = $res->fetch_assoc()) {
            $fullName = !empty($row['full_name']) ? $row['full_name'] : $username;

            foreach ($existingPhoneColumns as $col) {
                if (!empty($row[$col])) {
                    $phone = $row[$col];
                    break;
                }
            }
        }
        $res->free();
    }

    $stmt->close();
}

$plans = [
    ['name' => 'Body Building', 'amount' => 3000.00],
    ['name' => 'Boxing', 'amount' => 2850.00],
    ['name' => 'Muay Thai', 'amount' => 2850.00],
    ['name' => 'Zumba', 'amount' => 1000.00],
];

$selectedPlan = $_GET['plan'] ?? $plans[0]['name'];
$selectedAmount = $plans[0]['amount'];

foreach ($plans as $p) {
    if ($p['name'] === $selectedPlan) {
        $selectedAmount = $p['amount'];
        break;
    }
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>GCash Payment | RJL Fitness</title>
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
    --muted:#cfcfcf;
}
body{
    background:linear-gradient(180deg,#000,#090909);
    color:var(--text);
    font-family:Arial,sans-serif;
}
.page-wrap{max-width:1100px;margin:30px auto;padding:0 15px;}
.card-dark{
    background:linear-gradient(180deg,#141414,#0d0d0d);
    border:1px solid var(--line);
    border-radius:18px;
    box-shadow:0 18px 38px rgba(0,0,0,.35);
}
.header-box{
    padding:24px;
    margin-bottom:18px;
    background:linear-gradient(90deg,#450000,#8f0000,#d60000);
    border-radius:18px;
}
.header-box h1{margin:0;font-size:2rem;font-weight:800;}
.header-box p{margin:8px 0 0;color:#f3f3f3;}
.plan-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.plan-card{
    background:#171717;
    border:1px solid var(--line);
    border-radius:14px;
    padding:14px;
    cursor:pointer;
    transition:.15s ease;
}
.plan-card.active{border-color:#ff4d4d;box-shadow:0 0 0 1px #ff4d4d inset;}
.plan-card h5{margin:0 0 6px;font-size:1rem;}
.plan-card p{margin:0;color:#ddd;font-size:.9rem;}
.content-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px;}
.section-body{padding:22px;}
.qr-box{
    background:#fff;
    border-radius:16px;
    padding:18px;
    max-width:320px;
    margin:auto;
}
.qr-box img{width:100%;height:auto;display:block;}
.label-muted{font-size:.84rem;color:#ddd;text-transform:uppercase;letter-spacing:.08em;}
.amount-big{font-size:2rem;font-weight:800;color:#fff;}
.info-list{list-style:none;padding:0;margin:0;display:grid;gap:10px;}
.info-list li{background:#151515;border:1px solid var(--line);border-radius:12px;padding:12px 14px;}
.form-control, .custom-file-label{
    background:#101010;
    border:1px solid var(--line);
    color:#fff;
    min-height:46px;
    border-radius:12px;
}
.form-control:focus{
    background:#101010;color:#fff;border-color:#ff4d4d;box-shadow:none;
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
.btn-red:hover{color:#fff;opacity:.95;}
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
    .plan-grid{grid-template-columns:repeat(2,1fr);}
    .content-grid{grid-template-columns:1fr;}
}
@media (max-width: 575.98px){
    .plan-grid{grid-template-columns:1fr;}
}
</style>
</head>
<body>
<div class="page-wrap">
    <div class="header-box">
        <h1>GCash Payment</h1>
        <p>Pay manually using GCash, then submit your reference number and proof of payment for staff approval.</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">Payment request submitted successfully. Please wait for staff approval.</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card-dark section-body mb-3">
        <h4 class="mb-3">Choose Plan</h4>
        <div class="plan-grid" id="planGrid">
            <?php foreach ($plans as $p): ?>
                <div class="plan-card<?= $p['name'] === $selectedPlan ? ' active' : '' ?>"
                     data-name="<?= h($p['name']) ?>"
                     data-amount="<?= number_format($p['amount'], 2, '.', '') ?>">
                    <h5><?= h($p['name']) ?></h5>
                    <p>PHP <?= number_format($p['amount'], 2) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="content-grid">
        <div class="card-dark section-body">
            <h4 class="mb-3">Payment Instructions</h4>
            <div class="text-center mb-3">
                <div class="qr-box">
                    <img src="photo/gcash_qr.jpg" alt="GCash QR Code">
                </div>
            </div>

            <ul class="info-list mb-3">
                <li><strong>GCash Name:</strong> RJL Power Fitness Center</li>
                <li><strong>GCash Number:</strong> 09XXXXXXXXX</li>
                <li><strong>Selected Plan:</strong> <span id="selectedPlanText"><?= h($selectedPlan) ?></span></li>
                <li><strong>Exact Amount:</strong> <span class="amount-big" id="selectedAmountText">PHP <?= number_format($selectedAmount, 2) ?></span></li>
            </ul>

            <div class="note-box">
                <strong>Important:</strong>
                <ol class="mb-0 mt-2">
                    <li>Scan the QR code using GCash.</li>
                    <li>Send the exact amount shown.</li>
                    <li>Take a screenshot of your payment confirmation.</li>
                    <li>Submit the form on the right side.</li>
                    <li>Wait for staff verification before your plan is activated.</li>
                </ol>
            </div>
        </div>

        <div class="card-dark section-body">
            <h4 class="mb-3">Submit Payment Proof</h4>
            <form action="gcash_submit.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="plan_name" id="plan_name" value="<?= h($selectedPlan) ?>">
                <input type="hidden" name="amount" id="amount" value="<?= number_format($selectedAmount, 2, '.', '') ?>">

                <div class="form-group">
                    <label class="label-muted">Member Name</label>
                    <input type="text" class="form-control" value="<?= h($fullName) ?>" readonly>
                </div>

                <div class="form-group">
                    <label class="label-muted">Saved Phone Number</label>
                    <input type="text" class="form-control" value="<?= h($phone ?: 'No phone found in users table') ?>" readonly>
                </div>

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
                    <input type="number" step="0.01" name="amount_sent" id="amount_sent" class="form-control" value="<?= number_format($selectedAmount, 2, '.', '') ?>" required>
                </div>

                <div class="form-group">
                    <label class="label-muted">Proof of Payment</label>
                    <div class="custom-file">
                        <input type="file" class="custom-file-input" id="proof_image" name="proof_image" accept="image/*" required>
                        <label class="custom-file-label" for="proof_image">Choose screenshot</label>
                    </div>
                    <img id="previewImg" class="preview-img" alt="Preview">
                </div>

                <button type="submit" class="btn btn-red btn-block">Submit GCash Payment</button>
            </form>
        </div>
    </div>
</div>

<script>
const planCards = document.querySelectorAll('.plan-card');
const planNameInput = document.getElementById('plan_name');
const amountInput = document.getElementById('amount');
const amountSentInput = document.getElementById('amount_sent');
const selectedPlanText = document.getElementById('selectedPlanText');
const selectedAmountText = document.getElementById('selectedAmountText');

planCards.forEach(card => {
    card.addEventListener('click', () => {
        planCards.forEach(c => c.classList.remove('active'));
        card.classList.add('active');

        const name = card.dataset.name;
        const amount = card.dataset.amount;

        planNameInput.value = name;
        amountInput.value = amount;
        amountSentInput.value = amount;
        selectedPlanText.textContent = name;
        selectedAmountText.textContent = 'PHP ' + parseFloat(amount).toFixed(2);
    });
});

const proofInput = document.getElementById('proof_image');
const previewImg = document.getElementById('previewImg');
const proofLabel = document.querySelector('.custom-file-label');

proofInput.addEventListener('change', function(){
    if (this.files && this.files[0]) {
        proofLabel.textContent = this.files[0].name;
        const reader = new FileReader();
        reader.onload = function(e){
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
        };
        reader.readAsDataURL(this.files[0]);
    }
});
</script>
</body>
</html>