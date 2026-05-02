<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($role, ['staff','admin'], true)) {
    header('Location: home.php');
    exit;
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$payments = [];
$sql = "SELECT gp.*, u.full_name, u.username
        FROM gcash_payments gp
        LEFT JOIN users u ON u.id = gp.user_id
        WHERE gp.status = 'pending'
        ORDER BY gp.submitted_at DESC";

if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $payments[] = $row;
    }
    $res->free();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Pending GCash Payments</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
body{background:#050505;color:#fff;font-family:Arial,sans-serif;}
.wrap{max-width:1300px;margin:30px auto;padding:0 15px;}
.card-box{background:#111;border:1px solid rgba(255,255,255,.08);border-radius:18px;box-shadow:0 18px 38px rgba(0,0,0,.35);overflow:hidden;}
.header{padding:22px;background:linear-gradient(90deg,#500000,#8e0000,#d10000);}
.header h1{margin:0;font-size:1.9rem;font-weight:800;}
.table-wrap{padding:18px;overflow:auto;}
.table{color:#fff;}
.table th,.table td{vertical-align:middle;border-color:rgba(255,255,255,.08);}
.badge-pending{background:#ffc107;color:#111;padding:6px 10px;border-radius:999px;font-weight:700;}
.btn-pill{border:none;border-radius:999px;padding:8px 16px;font-weight:700;}
.btn-approve{background:linear-gradient(120deg,#a40000,#ff2a2a);color:#fff;}
.btn-reject{background:#222;color:#fff;border:1px solid rgba(255,255,255,.14);}
.proof-thumb{width:90px;height:90px;object-fit:cover;border-radius:12px;border:1px solid rgba(255,255,255,.08);}
textarea.form-control{min-height:85px;background:#101010;color:#fff;border:1px solid rgba(255,255,255,.10);}
</style>
</head>
<body>
<div class="wrap">
    <div class="card-box">
        <div class="header">
            <h1>Pending GCash Payments</h1>
        </div>
        <div class="table-wrap">
            <?php if (empty($payments)): ?>
                <div class="alert alert-secondary mb-0">No pending GCash payments found.</div>
            <?php else: ?>
                <table class="table table-dark table-bordered table-hover">
                    <thead>
                    <tr>
                        <th>Member</th>
                        <th>Plan</th>
                        <th>Amount</th>
                        <th>Sender</th>
                        <th>Reference</th>
                        <th>Proof</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th style="min-width:260px;">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td>
                                <strong><?= h($p['full_name'] ?: $p['username'] ?: ('User #' . $p['user_id'])) ?></strong><br>
                                <small>User ID: <?= (int)$p['user_id'] ?></small>
                            </td>
                            <td><?= h($p['plan_name']) ?></td>
                            <td>PHP <?= number_format((float)$p['amount'], 2) ?></td>
                            <td>
                                <?= h($p['sender_name']) ?><br>
                                <small><?= h($p['sender_number']) ?></small>
                            </td>
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
                            <td><?= h(date('M d, Y g:ia', strtotime($p['submitted_at']))) ?></td>
                            <td><span class="badge-pending">PENDING</span></td>
                            <td>
                                <form action="gcash_approve.php" method="post" style="display:inline-block;margin-bottom:8px;">
                                    <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="btn btn-pill btn-approve">Approve</button>
                                </form>

                                <form action="gcash_reject.php" method="post">
                                    <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                    <textarea name="rejection_reason" class="form-control mb-2" placeholder="Reason for rejection" required></textarea>
                                    <button type="submit" class="btn btn-pill btn-reject">Reject</button>
                                </form>
                            </td>
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