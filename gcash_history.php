<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = strtolower(trim($_SESSION['role'] ?? 'member'));

if ($userId <= 0 || !in_array($role, ['member','staff','admin'], true)) {
    header('Location: login.php');
    exit;
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$payments = [];
$sql = "SELECT * FROM gcash_payments WHERE user_id = ? ORDER BY submitted_at DESC";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>My GCash Payment History</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
body{background:#050505;color:#fff;font-family:Arial,sans-serif;}
.wrap{max-width:1100px;margin:30px auto;padding:0 15px;}
.card-box{background:#111;border:1px solid rgba(255,255,255,.08);border-radius:18px;box-shadow:0 18px 38px rgba(0,0,0,.35);overflow:hidden;}
.header{padding:22px;background:linear-gradient(90deg,#500000,#8e0000,#d10000);}
.header h1{margin:0;font-size:1.9rem;font-weight:800;}
.table-wrap{padding:18px;overflow:auto;}
.table{color:#fff;}
.table th,.table td{vertical-align:middle;border-color:rgba(255,255,255,.08);}
.badge-pillx{padding:6px 10px;border-radius:999px;font-weight:700;display:inline-block;}
.badge-pending{background:#ffc107;color:#111;}
.badge-approved{background:#28a745;color:#fff;}
.badge-rejected{background:#dc3545;color:#fff;}
.proof-thumb{width:80px;height:80px;object-fit:cover;border-radius:10px;border:1
</style>
</head>
<body>
<div class="wrap">
    <div class="card-box">
        <div class="header">
            <h1>My GCash Payment History</h1>
        </div>
        <div class="table-wrap">
            <?php if (empty($payments)): ?>
                <div class="alert alert-secondary mb-0">No GCash payments found.</div>
            <?php else: ?>
                <table class="table table-dark table-bordered table-hover">
                    <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Amount</th>
                        <th>Reference</th>
                        <th>Proof</th>
                        <th>Status</th>
                        <th>Reason</th>
                        <th>Submitted</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <?php
                        $badgeClass = 'badge-pending';
                        if ($p['status'] === 'approved') $badgeClass = 'badge-approved';
                        if ($p['status'] === 'rejected') $badgeClass = 'badge-rejected';
                        ?>
                        <tr>
                            <td><?= h($p['plan_name']) ?></td>
                            <td>PHP <?= number_format((float)$p['amount'], 2) ?></td>
                            <td><?= h($p['reference_number']) ?></td>
                            <td>
                                <?php if (!empty($p['proof_image'])): ?>
                                    <a href="<?= h($p['proof_image']) ?>" target="_blank">
                                        <img src="<?= h($p['proof_image']) ?>" class="proof-thumb" alt="Proof">
                                    </a>
                                <?php else: ?>
                                    No proof
                                <?php endif; ?>
                            </td>
                            <td><span class="badge-pillx <?= $badgeClass ?>"><?= strtoupper(h($p['status'])) ?></span></td>
                            <td><?= h($p['rejection_reason'] ?: '-') ?></td>
                            <td><?= h(date('M d, Y g:ia', strtotime($p['submitted_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>