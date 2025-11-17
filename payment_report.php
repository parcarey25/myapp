<?php
// FILE: payment_report.php
require_once 'auth.php';
require_once 'db.php';

$role = $_SESSION['role'] ?? '';
if ($role !== 'admin') {
    die("Access denied.");
}

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

$start_date = sprintf('%04d-%02d-01', $year, $month);
$end_date   = date('Y-m-t', strtotime($start_date));

// Get payments in this month
$stmt = $conn->prepare("
    SELECT p.amount, p.method, p.note, p.paid_at, u.full_name, u.username
    FROM payments p
    JOIN users u ON u.id = p.user_id
    WHERE DATE(p.paid_at) BETWEEN ? AND ?
    ORDER BY p.paid_at DESC
");
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Totals
$total_amount = 0;
foreach ($rows as $r) {
    $total_amount += (float)$r['amount'];
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Report</title>
    <style>
        :root { --bg-dark:#0c0c0f;--bg-card:#18181f;--accent-red:#e53935;--accent-red-dark:#b71c1c;--text-light:#f5f5f5;--text-muted:#aaaaaa;--border-soft:#2a2a33;}
        *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
        body{margin:0;background:radial-gradient(circle at top,#1b1b22 0,#050509 50%,#000 100%);color:var(--text-light);}
        .page-wrapper{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 12px;}
        .card{width:100%;max-width:1100px;background:var(--bg-card);border-radius:16px;border:1px solid var(--border-soft);padding:24px 28px;box-shadow:0 16px 40px rgba(0,0,0,0.6);}
        .card-header{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:18px;border-bottom:1px solid var(--border-soft);padding-bottom:10px;}
        h1{margin:0;font-size:1.6rem;letter-spacing:0.03em;text-transform:uppercase;}
        .tagline{font-size:0.85rem;color:var(--accent-red);text-transform:uppercase;letter-spacing:0.15em;}
        .pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.08em;}
        .pill-red{background:rgba(229,57,53,0.12);color:var(--accent-red);}
        label{font-size:0.9rem;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);}
        select{padding:7px 10px;border-radius:8px;border:1px solid var(--border-soft);background:#101018;color:var(--text-light);outline:none;margin-right:8px;}
        select:focus{border-color:var(--accent-red);box-shadow:0 0 0 1px rgba(229,57,53,0.3);}
        button{border:none;border-radius:999px;padding:8px 15px;font-size:0.9rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;cursor:pointer;background:var(--accent-red);color:#fff;transition:background 0.15s ease,transform 0.1s ease;}
        button:hover{background:var(--accent-red-dark);transform:translateY(-1px);}
        table{width:100%;border-collapse:collapse;margin-top:10px;font-size:0.9rem;}
        th,td{padding:8px 10px;border-bottom:1px solid var(--border-soft);text-align:left;}
        th{text-transform:uppercase;letter-spacing:0.08em;font-size:0.78rem;color:var(--text-muted);}
        .summary{margin-top:10px;font-size:0.9rem;}
        .summary strong{color:var(--accent-red);}
    </style>
</head>
<body>
<div class="page-wrapper">
    <div class="card">
        <div class="card-header">
            <div>
                <div class="tagline">RJL FITNESS · ADMIN</div>
                <h1>Payment Report</h1>
            </div>
            <span class="pill pill-red">Admin</span>
        </div>

        <form method="get">
            <label>Filter by Month & Year</label>
            <select name="month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>>
                        <?= date('F', mktime(0,0,0,$m,1)) ?>
                    </option>
                <?php endfor; ?>
            </select>

            <select name="year">
                <?php
                $currentYear = (int)date('Y');
                for ($y = $currentYear - 3; $y <= $currentYear; $y++): ?>
                    <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>>
                        <?= $y ?>
                    </option>
                <?php endfor; ?>
            </select>

            <button type="submit">View</button>
        </form>

        <p class="summary">
            Showing payments from <strong><?= htmlspecialchars($start_date) ?></strong>
            to <strong><?= htmlspecialchars($end_date) ?></strong>.
            Total amount: <strong>₱<?= number_format($total_amount, 2) ?></strong>
        </p>

        <table>
            <tr>
                <th>Date/Time</th>
                <th>Member</th>
                <th>Username</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Reason / Note</th>
            </tr>
            <?php if (!$rows): ?>
                <tr><td colspan="6">No payments found for this period.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['paid_at']) ?></td>
                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                        <td><?= htmlspecialchars($r['username']) ?></td>
                        <td>₱<?= number_format($r['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($r['method'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['note'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>
</div>
</body>
</html>