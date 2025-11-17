<?php
require_once 'auth.php';
require_once 'db.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['staff','admin'])) {
    die("Access denied.");
}

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

$start_date = sprintf('%04d-%02d-01', $year, $month);
$end_date   = date('Y-m-t', strtotime($start_date));

$stmt = $conn->prepare("
    SELECT u.id, u.full_name, u.email, COUNT(a.id) AS visit_count
    FROM users u
    LEFT JOIN attendance a
      ON a.user_id = u.id
      AND DATE(a.check_in) BETWEEN ? AND ?
    WHERE u.role = 'member'
    GROUP BY u.id, u.full_name, u.email
    ORDER BY visit_count DESC, u.full_name ASC
");
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Attendance Report</title>
    <!-- paste CSS here -->
</head>
<body>
<div class="page-wrapper">
    <div class="card">
        <div class="card-header">
            <div>
                <div class="tagline">RJL FITNESS · ANALYTICS</div>
                <h1>Attendance Report</h1>
            </div>
            <span class="pill pill-red">Staff / Admin</span>
        </div>

        <form method="get" class="flex-row">
            <div class="flex-1">
                <label>Month</label>
                <select name="month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>>
                            <?= date('F', mktime(0,0,0,$m,1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex-1">
                <label>Year</label>
                <select name="year">
                    <?php
                    $currentYear = (int)date('Y');
                    for ($y = $currentYear - 3; $y <= $currentYear; $y++): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>>
                            <?= $y ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex-1" style="display:flex;align-items:flex-end;">
                <button type="submit">View</button>
            </div>
        </form>

        <p style="margin-top:10px;font-size:0.85rem;color:var(--text-muted);">
            Visits counted from <strong><?= htmlspecialchars($start_date) ?></strong>
            to <strong><?= htmlspecialchars($end_date) ?></strong>.
        </p>

        <table>
            <tr>
                <th>Member</th>
                <th>Email</th>
                <th>Number of Visits</th>
            </tr>
            <?php if (!$rows): ?>
                <tr><td colspan="3">No data for this period.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                        <td><?= htmlspecialchars($r['email']) ?></td>
                        <td><?= (int)$r['visit_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>
</div>
</body>
</html>