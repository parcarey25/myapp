<?php
require_once 'auth.php';
require_once 'db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$role    = $_SESSION['role'] ?? '';

if ($user_id <= 0 || !in_array($role, ['member','trainer'])) {
    die("Access denied.");
}

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

$start_date = sprintf('%04d-%02d-01', $year, $month);
$end_date   = date('Y-m-t', strtotime($start_date));

$stmt = $conn->prepare("
    SELECT check_in, check_out
    FROM attendance
    WHERE user_id = ?
      AND DATE(check_in) BETWEEN ? AND ?
    ORDER BY check_in ASC
");
$stmt->bind_param('iss', $user_id, $start_date, $end_date);
$stmt->execute();
$res = $stmt->get_result();
$logs = [];
while ($row = $res->fetch_assoc()) {
    $logs[] = $row;
}
$stmt->close();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>My Attendance</title>
    <!-- paste CSS here -->
</head>
<body>
<div class="page-wrapper">
    <div class="card">
        <div class="card-header">
            <div>
                <div class="tagline">RJL FITNESS · MEMBER</div>
                <h1>My Attendance</h1>
            </div>
            <span class="pill pill-green">Logged In</span>
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
            Showing logs from <strong><?= htmlspecialchars($start_date) ?></strong>
            to <strong><?= htmlspecialchars($end_date) ?></strong>.
        </p>

        <table>
            <tr>
                <th>Date</th>
                <th>Time-in</th>
                <th>Time-out</th>
            </tr>
            <?php if (!$logs): ?>
                <tr><td colspan="3">No attendance records for this period.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $date    = date('Y-m-d', strtotime($log['check_in']));
                    $time_in = date('H:i', strtotime($log['check_in']));
                    $time_out = $log['check_out'] ? date('H:i', strtotime($log['check_out'])) : '-';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($date) ?></td>
                        <td><?= htmlspecialchars($time_in) ?></td>
                        <td><?= htmlspecialchars($time_out) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>
</div>
</body>
</html>