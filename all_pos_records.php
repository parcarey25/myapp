<?php
// all_pos_records.php
// Shows all POS payments split into RFID Wallet Load, GCash QR, and RFID Balance.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/db.php';
require __DIR__ . '/pos_records_lib.php';

$role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($role, ['staff', 'admin'], true)) {
    header('Location: home.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$today = date('Y-m-d');
$dateFrom = $_GET['from'] ?? $_POST['from'] ?? $today;
$dateTo = $_GET['to'] ?? $_POST['to'] ?? $dateFrom;
$search = trim($_GET['q'] ?? $_POST['q'] ?? '');

if (!pos_valid_date($dateFrom)) {
    $dateFrom = $today;
}

if (!pos_valid_date($dateTo)) {
    $dateTo = $dateFrom;
}

$flash = $_SESSION['pos_records_flash'] ?? '';
$flashType = $_SESSION['pos_records_flash_type'] ?? 'info';
$flashFileUrl = $_SESSION['pos_records_file_url'] ?? '';

unset($_SESSION['pos_records_flash']);
unset($_SESSION['pos_records_flash_type']);
unset($_SESSION['pos_records_file_url']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pos_report'])) {
    $saveDate = $_POST['save_date'] ?? $dateFrom;
    $result = pos_save_report($conn, $saveDate);

    $_SESSION['pos_records_flash'] = $result['message'] ?? 'Save completed.';
    $_SESSION['pos_records_flash_type'] = !empty($result['ok']) ? 'success' : 'danger';
    $_SESSION['pos_records_file_url'] = $result['file_url'] ?? '';

    $qs = http_build_query([
        'from' => $dateFrom,
        'to' => $dateTo,
        'q' => $search,
    ]);

    header('Location: all_pos_records.php?' . $qs);
    exit;
}

$data = pos_get_records($conn, $dateFrom, $dateTo, $search);

$records = $data['records'];
$totals = $data['totals'];
$counts = $data['counts'];

$grouped = [
    'rfid_load' => [],
    'gcash_qr' => [],
    'rfid_balance' => [],
];

foreach ($records as $record) {
    $grouped[$record['category']][] = $record;
}

$grandTotal = (float)$totals['rfid_load'] + (float)$totals['gcash_qr'] + (float)$totals['rfid_balance'];

function render_pos_table(array $items): void
{
    ?>
    <div class="table-responsive">
        <table class="table table-dark table-sm pos-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date/Time</th>
                    <th>Name</th>
                    <th>ID Number</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Reference</th>
                    <th>Status</th>
                    <th>Source</th>
                    <th>Note</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$items): ?>
                    <tr>
                        <td colspan="10" class="text-center muted">No records found.</td>
                    </tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($items as $r): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= pos_h($r['date_time']) ?></td>
                            <td><?= pos_h($r['name'] ?: '—') ?></td>
                            <td><?= pos_h($r['id_number'] ?: '—') ?></td>
                            <td><?= pos_h($r['method'] ?: $r['category_label']) ?></td>
                            <td><strong><?= pos_h(pos_money($r['amount'])) ?></strong></td>
                            <td><?= pos_h($r['reference'] ?: '—') ?></td>
                            <td><?= pos_h($r['status'] ?: '—') ?></td>
                            <td><?= pos_h($r['source'] ?: '—') ?></td>
                            <td><?= pos_h($r['note'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>All POS Records | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root {
    --bg: #101010;
    --panel: #171717;
    --line: #2a2a2a;
    --brand: #b30000;
    --brand2: #ff3333;
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
    border-color: var(--brand2);
}

.page-card,
.stat-card,
.section-card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 18px;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
}

.muted {
    color: var(--muted);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}

@media(max-width: 992px) {
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 576px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}

.stat-label {
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .12em;
    font-size: .78rem;
    font-weight: 800;
}

.stat-value {
    font-size: 1.8rem;
    font-weight: 900;
    margin-top: 8px;
}

.section-title {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.section-title h4 {
    margin: 0;
}

.table-dark {
    background: #101010;
}

.pos-table th {
    color: #ccc;
    border-bottom: 1px solid var(--line);
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    white-space: nowrap;
}

.pos-table td {
    border-top: 1px solid #242424;
    font-size: .85rem;
    vertical-align: middle;
}

.form-control {
    background: #121212;
    border: 1px solid #2a2a2a;
    color: #eee;
}

.form-control:focus {
    background: #121212;
    color: #fff;
    border-color: var(--brand2);
    box-shadow: 0 0 0 0.2rem rgba(179,0,0,.25);
}
</style>
</head>

<body>

<nav class="navbar navbar-dark">
    <a class="navbar-brand ml-3" href="home_staff.php">
        <img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness POS Records
    </a>

    <div class="ml-auto mr-3">
        <a href="home_staff.php" class="btn btn-outline-light btn-sm">Staff Home</a>
        <a href="pos_storage.php" class="btn btn-outline-light btn-sm">POS Storage</a>
        <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
    </div>
</nav>

<div class="container-fluid py-4">

    <div class="page-card mb-3">
        <div class="d-flex justify-content-between flex-wrap align-items-center">
            <div>
                <h3 class="mb-1">All POS Records</h3>
                <div class="muted">
                    Split into RFID Wallet Load, GCash QR, and RFID Balance.
                </div>
            </div>

            <form method="post" class="form-inline mt-3 mt-md-0">
                <input type="hidden" name="save_pos_report" value="1">
                <input type="hidden" name="from" value="<?= pos_h($dateFrom) ?>">
                <input type="hidden" name="to" value="<?= pos_h($dateTo) ?>">
                <input type="hidden" name="q" value="<?= pos_h($search) ?>">

                <label class="mr-2 muted">Save date:</label>
                <input type="date" name="save_date" class="form-control form-control-sm mr-2" value="<?= pos_h($dateFrom) ?>">

                <button class="btn btn-danger btn-sm" type="submit">
                    Save POS Report
                </button>
            </form>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= pos_h($flashType) ?>">
            <?= pos_h($flash) ?>

            <?php if ($flashFileUrl): ?>
                <br>
                <a href="<?= pos_h($flashFileUrl) ?>" target="_blank">Open saved POS report</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="get" class="page-card mb-3">
        <div class="form-row">
            <div class="col-md-3 mb-2">
                <label class="muted">From</label>
                <input type="date" name="from" class="form-control" value="<?= pos_h($dateFrom) ?>">
            </div>

            <div class="col-md-3 mb-2">
                <label class="muted">To</label>
                <input type="date" name="to" class="form-control" value="<?= pos_h($dateTo) ?>">
            </div>

            <div class="col-md-4 mb-2">
                <label class="muted">Search</label>
                <input type="text" name="q" class="form-control"
                       placeholder="Name, ID, reference, method..."
                       value="<?= pos_h($search) ?>">
            </div>

            <div class="col-md-2 mb-2 d-flex align-items-end">
                <button class="btn btn-danger mr-2" type="submit">Search</button>
                <a href="all_pos_records.php" class="btn btn-outline-light">Clear</a>
            </div>
        </div>
    </form>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">RFID Wallet Load</div>
            <div class="stat-value"><?= pos_h(pos_money($totals['rfid_load'])) ?></div>
            <div class="muted"><?= (int)$counts['rfid_load'] ?> record(s)</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">GCash QR</div>
            <div class="stat-value"><?= pos_h(pos_money($totals['gcash_qr'])) ?></div>
            <div class="muted"><?= (int)$counts['gcash_qr'] ?> record(s)</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">RFID Balance</div>
            <div class="stat-value"><?= pos_h(pos_money($totals['rfid_balance'])) ?></div>
            <div class="muted"><?= (int)$counts['rfid_balance'] ?> record(s)</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Grand Total</div>
            <div class="stat-value"><?= pos_h(pos_money($grandTotal)) ?></div>
            <div class="muted"><?= count($records) ?> total record(s)</div>
        </div>
    </div>

    <div class="section-card mb-3">
        <div class="section-title">
            <h4>RFID Wallet Load</h4>
            <span class="muted"><?= pos_h(pos_money($totals['rfid_load'])) ?> · <?= (int)$counts['rfid_load'] ?> record(s)</span>
        </div>
        <?php render_pos_table($grouped['rfid_load']); ?>
    </div>

    <div class="section-card mb-3">
        <div class="section-title">
            <h4>GCash QR</h4>
            <span class="muted"><?= pos_h(pos_money($totals['gcash_qr'])) ?> · <?= (int)$counts['gcash_qr'] ?> record(s)</span>
        </div>
        <?php render_pos_table($grouped['gcash_qr']); ?>
    </div>

    <div class="section-card mb-3">
        <div class="section-title">
            <h4>RFID Balance</h4>
            <span class="muted"><?= pos_h(pos_money($totals['rfid_balance'])) ?> · <?= (int)$counts['rfid_balance'] ?> record(s)</span>
        </div>
        <?php render_pos_table($grouped['rfid_balance']); ?>
    </div>

</div>

</body>
</html>
