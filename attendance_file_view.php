<?php
// attendance_file_view.php
// View saved CSV attendance file as a clean table.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = strtolower($_SESSION['role'] ?? '');

if (!in_array($role, ['staff', 'admin'], true)) {
    header('Location: home.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$monthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

$year = $_GET['year'] ?? '';
$month = $_GET['month'] ?? '';
$file = $_GET['file'] ?? '';

if (!preg_match('/^\d{4}$/', $year)) {
    exit('Invalid year.');
}

if (!in_array($month, $monthNames, true)) {
    exit('Invalid month.');
}

if (!preg_match('/^daily_attendance_\d{4}-\d{2}-\d{2}\.csv$/', $file)) {
    exit('Invalid file.');
}

$baseDir = realpath(__DIR__ . '/attendance_storage');

if (!$baseDir) {
    exit('Attendance storage folder not found.');
}

$filePath = __DIR__ . '/attendance_storage/' . $year . '/' . $month . '/' . $file;
$realFilePath = realpath($filePath);

if (!$realFilePath || strpos($realFilePath, $baseDir) !== 0 || !is_file($realFilePath)) {
    exit('File not found.');
}

$rows = [];

$handle = fopen($realFilePath, 'r');

if ($handle) {
    while (($data = fgetcsv($handle)) !== false) {
        $rows[] = $data;
    }

    fclose($handle);
}

$headers = $rows[0] ?? [];
$dataRows = array_slice($rows, 1);

$downloadUrl = 'attendance_storage/'
    . rawurlencode($year) . '/'
    . rawurlencode($month) . '/'
    . rawurlencode($file);

$backUrl = 'attendance_storage.php?view=month&year=' . urlencode($year) . '&month=' . urlencode($month);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>View Attendance File | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root {
    --bg: #101010;
    --panel: #171717;
    --line: #2a2a2a;
    --brand: #b30000;
    --muted: #a9a9a9;
}

body {
    background: var(--bg);
    color: #fff;
    font-family: 'Poppins', Arial, sans-serif;
}

.navbar {
    background: linear-gradient(90deg, #000, var(--brand));
}

.btn-danger {
    background: var(--brand);
    border: none;
}

.btn-danger:hover {
    background: #e60000;
}

.btn-outline-light {
    border-color: #ff4d4d;
}

.page-card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 18px;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
}

.muted {
    color: var(--muted);
}

.table-wrap {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 14px;
    overflow: auto;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
}

.table {
    margin-bottom: 0;
    color: #fff;
    min-width: 1100px;
}

.table thead th {
    background: #111;
    color: #ddd;
    border-bottom: 1px solid var(--line);
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    white-space: nowrap;
}

.table td {
    border-top: 1px solid #252525;
    font-size: .88rem;
    vertical-align: middle;
    white-space: nowrap;
}

.table tbody tr:hover {
    background: #1f1f1f;
}

.empty-box {
    background: var(--panel);
    border: 1px dashed rgba(255,255,255,.18);
    border-radius: 14px;
    padding: 30px;
    text-align: center;
    color: var(--muted);
}

@media print {
    body {
        background: #fff;
        color: #000;
    }

    .navbar,
    .action-buttons {
        display: none;
    }

    .page-card,
    .table-wrap {
        box-shadow: none;
        border: none;
    }

    .table {
        color: #000;
    }

    .table thead th {
        background: #eee !important;
        color: #000;
    }
}
</style>
</head>

<body>

<nav class="navbar navbar-dark">
    <a class="navbar-brand ml-3" href="home_staff.php">
        <img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness Staff
    </a>

    <div class="ml-auto mr-3">
        <a href="<?= h($backUrl) ?>" class="btn btn-outline-light btn-sm">Back</a>
        <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
    </div>
</nav>

<div class="container-fluid py-4">

    <div class="page-card mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h3 class="mb-1">Attendance File</h3>
                <div class="muted">
                    <?= h($year) ?> / <?= h($month) ?> / <?= h($file) ?>
                </div>
                <div class="muted mt-1">
                    Total records: <?= count($dataRows) ?>
                </div>
            </div>

            <div class="action-buttons mt-3 mt-md-0">
                <a href="<?= h($backUrl) ?>" class="btn btn-outline-light btn-sm">
                    ← Back
                </a>

                <a href="<?= h($downloadUrl) ?>" download class="btn btn-danger btn-sm">
                    Download CSV
                </a>

                <button onclick="window.print()" class="btn btn-outline-light btn-sm">
                    Print
                </button>
            </div>
        </div>
    </div>

    <?php if (!$headers): ?>
        <div class="empty-box">
            This CSV file is empty.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-dark table-sm">
                <thead>
                    <tr>
                        <?php foreach ($headers as $header): ?>
                            <th><?= h($header) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$dataRows): ?>
                        <tr>
                            <td colspan="<?= count($headers) ?>" class="text-center muted">
                                No attendance records inside this file.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dataRows as $row): ?>
                            <tr>
                                <?php for ($i = 0; $i < count($headers); $i++): ?>
                                    <td><?= h($row[$i] ?? '') ?></td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

</body>
</html>