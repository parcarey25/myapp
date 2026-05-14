<?php
// admin_dashboard.php

// UPDATED REVENUE LOGIC:
// Revenue Today / This Month / Year Total / Graph = GCash QR + RFID Wallet Load only.
// RFID Balance payment is NOT counted.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// IMPORTANT: db.php creates the $conn mysqli connection
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pos_records_lib.php';

// Safety check so the page gives a clear error if DB connection fails
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is missing. Please check db.php.");
}

$role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($role, ['admin', 'staff'], true)) {
    header('Location: home.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$yearStart = date('Y-01-01');
$yearEnd = date('Y-12-31');

$chartMode = $_GET['chart'] ?? 'month';
$debug = isset($_GET['debug']);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function peso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function table_exists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1";

    $st = $conn->prepare($sql);

    if (!$st) {
        return false;
    }

    $st->bind_param('s', $table);
    $st->execute();

    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;

    if ($res) {
        $res->free();
    }

    $st->close();

    return $ok;
}

function has_column(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";

    $st = $conn->prepare($sql);

    if (!$st) {
        return false;
    }

    $st->bind_param('ss', $table, $column);
    $st->execute();

    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;

    if ($res) {
        $res->free();
    }

    $st->close();

    return $ok;
}

function first_col(mysqli $conn, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (has_column($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function first_table(mysqli $conn, array $tables): ?string
{
    foreach ($tables as $table) {
        if (table_exists($conn, $table)) {
            return $table;
        }
    }

    return null;
}

function status_is_allowed(string $status): bool
{
    $status = strtolower(trim($status));

    if ($status === '') {
        return true;
    }

    return !in_array($status, [
        'cancelled',
        'canceled',
        'void',
        'refunded',
        'failed',
        'rejected',
        'declined'
    ], true);
}

function text_has_any(string $text, array $needles): bool
{
    $text = strtolower($text);

    foreach ($needles as $needle) {
        if ($needle !== '' && strpos($text, strtolower($needle)) !== false) {
            return true;
        }
    }

    return false;
}

function is_rfid_balance_text(string $text): bool
{
    $text = strtolower($text);

    return text_has_any($text, [
        'rfid balance',
        'wallet balance',
        'rfid wallet payment',
        'wallet payment',
        'paid using rfid',
        'paid by rfid',
        'deduct',
        'deduction',
        'debit',
        'purchase using rfid',
        'rfid payment'
    ]);
}

function is_gcash_text(string $text): bool
{
    return text_has_any($text, [
        'gcash qr',
        'gcash_qr',
        'gcash'
    ]);
}

function is_rfid_load_text(string $text): bool
{
    $text = strtolower($text);

    if (is_rfid_balance_text($text)) {
        return false;
    }

    return text_has_any($text, [
        'rfid wallet load',
        'rfid load',
        'wallet load',
        'cash load',
        'load rfid',
        'load wallet',
        'topup',
        'top-up',
        'cashin',
        'cash-in',
        'cash in'
    ]);
}

function get_amount_from_row(array $row, ?string $amountCol): float
{
    if (!$amountCol || !array_key_exists($amountCol, $row)) {
        return 0.0;
    }

    return (float)$row[$amountCol];
}

function sum_gcash_from_payments(mysqli $conn, string $from, string $to): array
{
    $total = 0.0;
    $count = 0;
    $details = [];

    if (!table_exists($conn, 'payments')) {
        return ['total' => 0.0, 'count' => 0, 'details' => ['payments table not found']];
    }

    $table = 'payments';

    $amountCol = first_col($conn, $table, ['amount', 'total', 'paid_amount', 'total_amount', 'price']);
    $dateCol = first_col($conn, $table, ['created_at', 'paid_at', 'payment_date', 'date_paid', 'transaction_date', 'date', 'datetime', 'date_time']);
    $methodCol = first_col($conn, $table, ['method', 'payment_method', 'mode_of_payment', 'payment_type', 'type']);
    $noteCol = first_col($conn, $table, ['note', 'description', 'remarks', 'details', 'reference']);
    $statusCol = first_col($conn, $table, ['status', 'payment_status']);

    $details[] = "payments amount={$amountCol} date={$dateCol} method={$methodCol} note={$noteCol} status={$statusCol}";

    if (!$amountCol || !$dateCol) {
        $details[] = 'payments missing amount/date column';
        return ['total' => 0.0, 'count' => 0, 'details' => $details];
    }

    $sql = "SELECT *
            FROM payments
            WHERE DATE(`{$dateCol}`) BETWEEN ? AND ?";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('ss', $from, $to);
        $st->execute();

        $res = $st->get_result();

        while ($row = $res->fetch_assoc()) {
            $method = $methodCol ? (string)($row[$methodCol] ?? '') : '';
            $note = $noteCol ? (string)($row[$noteCol] ?? '') : '';
            $status = $statusCol ? (string)($row[$statusCol] ?? '') : '';

            if (!status_is_allowed($status)) {
                continue;
            }

            $text = trim($method . ' ' . $note);

            if (is_rfid_balance_text($text)) {
                continue;
            }

            if (!is_gcash_text($text)) {
                continue;
            }

            $amount = get_amount_from_row($row, $amountCol);

            if ($amount <= 0) {
                continue;
            }

            $total += $amount;
            $count++;
        }

        if ($res) {
            $res->free();
        }

        $st->close();
    }

    return ['total' => $total, 'count' => $count, 'details' => $details];
}

function sum_load_from_payments(mysqli $conn, string $from, string $to): array
{
    $total = 0.0;
    $count = 0;
    $details = [];

    if (!table_exists($conn, 'payments')) {
        return ['total' => 0.0, 'count' => 0, 'details' => ['payments table not found']];
    }

    $table = 'payments';

    $amountCol = first_col($conn, $table, ['amount', 'total', 'paid_amount', 'total_amount', 'price']);
    $dateCol = first_col($conn, $table, ['created_at', 'paid_at', 'payment_date', 'date_paid', 'transaction_date', 'date', 'datetime', 'date_time']);
    $methodCol = first_col($conn, $table, ['method', 'payment_method', 'mode_of_payment', 'payment_type', 'type']);
    $noteCol = first_col($conn, $table, ['note', 'description', 'remarks', 'details', 'reference']);
    $statusCol = first_col($conn, $table, ['status', 'payment_status']);

    $details[] = "payments load amount={$amountCol} date={$dateCol} method={$methodCol} note={$noteCol} status={$statusCol}";

    if (!$amountCol || !$dateCol) {
        $details[] = 'payments missing amount/date column for load';
        return ['total' => 0.0, 'count' => 0, 'details' => $details];
    }

    $sql = "SELECT *
            FROM payments
            WHERE DATE(`{$dateCol}`) BETWEEN ? AND ?";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('ss', $from, $to);
        $st->execute();

        $res = $st->get_result();

        while ($row = $res->fetch_assoc()) {
            $method = $methodCol ? (string)($row[$methodCol] ?? '') : '';
            $note = $noteCol ? (string)($row[$noteCol] ?? '') : '';
            $status = $statusCol ? (string)($row[$statusCol] ?? '') : '';

            if (!status_is_allowed($status)) {
                continue;
            }

            $text = trim($method . ' ' . $note);

            if (!is_rfid_load_text($text)) {
                continue;
            }

            $amount = get_amount_from_row($row, $amountCol);

            if ($amount <= 0) {
                continue;
            }

            $total += $amount;
            $count++;
        }

        if ($res) {
            $res->free();
        }

        $st->close();
    }

    return ['total' => $total, 'count' => $count, 'details' => $details];
}

function sum_gcash_from_gcash_table(mysqli $conn, string $from, string $to): array
{
    $total = 0.0;
    $count = 0;
    $details = [];

    if (!table_exists($conn, 'gcash_payments')) {
        return ['total' => 0.0, 'count' => 0, 'details' => ['gcash_payments table not found']];
    }

    $table = 'gcash_payments';

    $amountCol = first_col($conn, $table, ['amount', 'total', 'paid_amount', 'total_amount', 'price']);
    $dateCol = first_col($conn, $table, ['created_at', 'paid_at', 'payment_date', 'date_paid', 'transaction_date', 'date', 'datetime', 'date_time']);
    $statusCol = first_col($conn, $table, ['status', 'payment_status']);

    $details[] = "gcash_payments amount={$amountCol} date={$dateCol} status={$statusCol}";

    if (!$amountCol || !$dateCol) {
        $details[] = 'gcash_payments missing amount/date column';
        return ['total' => 0.0, 'count' => 0, 'details' => $details];
    }

    $sql = "SELECT *
            FROM gcash_payments
            WHERE DATE(`{$dateCol}`) BETWEEN ? AND ?";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('ss', $from, $to);
        $st->execute();

        $res = $st->get_result();

        while ($row = $res->fetch_assoc()) {
            $status = $statusCol ? (string)($row[$statusCol] ?? '') : '';

            if (!status_is_allowed($status)) {
                continue;
            }

            $amount = get_amount_from_row($row, $amountCol);

            if ($amount <= 0) {
                continue;
            }

            $total += $amount;
            $count++;
        }

        if ($res) {
            $res->free();
        }

        $st->close();
    }

    return ['total' => $total, 'count' => $count, 'details' => $details];
}

function sum_wallet_load_from_wallet_table(mysqli $conn, string $from, string $to): array
{
    $total = 0.0;
    $count = 0;
    $details = [];

    $table = first_table($conn, [
        'wallet_transactions',
        'rfid_wallet_transactions',
        'rfid_wallet_transaction'
    ]);

    if (!$table) {
        return ['total' => 0.0, 'count' => 0, 'details' => ['wallet transaction table not found']];
    }

    $amountCol = first_col($conn, $table, ['amount', 'load_amount', 'credit', 'total', 'paid_amount']);
    $dateCol = first_col($conn, $table, ['created_at', 'date_created', 'transaction_date', 'date', 'paid_at', 'datetime', 'date_time']);
    $typeCol = first_col($conn, $table, ['type', 'transaction_type', 'txn_type', 'method']);
    $noteCol = first_col($conn, $table, ['note', 'description', 'remarks', 'details', 'reference']);
    $statusCol = first_col($conn, $table, ['status', 'payment_status']);

    $details[] = "{$table} amount={$amountCol} date={$dateCol} type={$typeCol} note={$noteCol} status={$statusCol}";

    if (!$amountCol || !$dateCol) {
        $details[] = "{$table} missing amount/date column";
        return ['total' => 0.0, 'count' => 0, 'details' => $details];
    }

    $sql = "SELECT *
            FROM `{$table}`
            WHERE DATE(`{$dateCol}`) BETWEEN ? AND ?";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('ss', $from, $to);
        $st->execute();

        $res = $st->get_result();

        while ($row = $res->fetch_assoc()) {
            $status = $statusCol ? (string)($row[$statusCol] ?? '') : '';

            if (!status_is_allowed($status)) {
                continue;
            }

            $type = $typeCol ? (string)($row[$typeCol] ?? '') : '';
            $note = $noteCol ? (string)($row[$noteCol] ?? '') : '';
            $text = trim($type . ' ' . $note);

            $amount = get_amount_from_row($row, $amountCol);

            if ($amount <= 0) {
                continue;
            }

            /*
             * For wallet table:
             * positive amount usually means load/top-up.
             * But we still exclude if text says payment/deduct/RFID balance.
             */
            if (is_rfid_balance_text($text)) {
                continue;
            }

            if ($text !== '' && !is_rfid_load_text($text)) {
                $lower = strtolower($text);

                if (!in_array($lower, ['load', 'topup', 'top-up', 'cashin', 'cash-in', 'credit'], true)) {
                    /*
                     * If type/note exists but not load-like, skip to avoid counting balance use.
                     */
                    continue;
                }
            }

            $total += $amount;
            $count++;
        }

        if ($res) {
            $res->free();
        }

        $st->close();
    }

    return ['total' => $total, 'count' => $count, 'details' => $details];
}

function revenue_for_range(mysqli $conn, string $from, string $to): array
{
    $gcashSeparate = sum_gcash_from_gcash_table($conn, $from, $to);
    $gcashPayments = sum_gcash_from_payments($conn, $from, $to);

    /*
     * Avoid double counting:
     * If gcash_payments has records, use that as GCash source.
     * Otherwise use payments table GCash records.
     */
    if ($gcashSeparate['count'] > 0) {
        $gcash = $gcashSeparate;
        $gcashSource = 'gcash_payments';
    } else {
        $gcash = $gcashPayments;
        $gcashSource = 'payments';
    }

    $walletTableLoad = sum_wallet_load_from_wallet_table($conn, $from, $to);
    $paymentLoad = sum_load_from_payments($conn, $from, $to);

    /*
     * Avoid double counting:
     * If wallet transaction table has load records, use that.
     * Otherwise use payments table load records.
     */
    if ($walletTableLoad['count'] > 0) {
        $load = $walletTableLoad;
        $loadSource = 'wallet_transactions';
    } else {
        $load = $paymentLoad;
        $loadSource = 'payments';
    }

    $total = (float)$gcash['total'] + (float)$load['total'];

    return [
        'total' => $total,
        'gcash' => (float)$gcash['total'],
        'load' => (float)$load['total'],
        'gcash_count' => (int)$gcash['count'],
        'load_count' => (int)$load['count'],
        'gcash_source' => $gcashSource,
        'load_source' => $loadSource,
        'debug' => array_merge(
            $gcashSeparate['details'] ?? [],
            $gcashPayments['details'] ?? [],
            $walletTableLoad['details'] ?? [],
            $paymentLoad['details'] ?? []
        ),
    ];
}

/*
|--------------------------------------------------------------------------
| User Metrics
|--------------------------------------------------------------------------
*/

$members = 0;
$trainers = 0;
$staffs = 0;
$pending = 0;

if (table_exists($conn, 'users') && has_column($conn, 'users', 'role')) {
    $sql = "SELECT
              SUM(CASE WHEN LOWER(role)='member' THEN 1 ELSE 0 END) AS members,
              SUM(CASE WHEN LOWER(role)='trainer' THEN 1 ELSE 0 END) AS trainers,
              SUM(CASE WHEN LOWER(role)='staff' THEN 1 ELSE 0 END) AS staffs
            FROM users";

    if ($res = $conn->query($sql)) {
        $row = $res->fetch_assoc();

        $members = (int)($row['members'] ?? 0);
        $trainers = (int)($row['trainers'] ?? 0);
        $staffs = (int)($row['staffs'] ?? 0);

        $res->free();
    }
}

if (table_exists($conn, 'users') && has_column($conn, 'users', 'status')) {
    $sql = "SELECT COUNT(*) AS c
            FROM users
            WHERE LOWER(TRIM(status)) = 'pending'";

    if ($res = $conn->query($sql)) {
        $pending = (int)($res->fetch_assoc()['c'] ?? 0);
        $res->free();
    }
}

/*
|--------------------------------------------------------------------------
| Revenue Metrics
|--------------------------------------------------------------------------
*/

$todayRevenueData = revenue_for_range($conn, $today, $today);
$monthRevenueData = revenue_for_range($conn, $monthStart, $monthEnd);
$totalRevenueData = revenue_for_range($conn, $yearStart, $yearEnd);

$revToday = $todayRevenueData['total'];
$revMonth = $monthRevenueData['total'];
$revTotal = $totalRevenueData['total'];

/*
|--------------------------------------------------------------------------
| Revenue Graph
|--------------------------------------------------------------------------
*/

$labels = [];
$values = [];

if ($chartMode === 'month') {
    $chartTitle = 'Daily revenue for ' . date('F Y') . ' (line graph)';

    $start = new DateTime($monthStart);
    $end = new DateTime($monthEnd);
    $end->setTime(0, 0, 0);

    $period = new DatePeriod(
        $start,
        new DateInterval('P1D'),
        (clone $end)->modify('+1 day')
    );

    foreach ($period as $d) {
        $day = $d->format('Y-m-d');
        $dayData = revenue_for_range($conn, $day, $day);

        $labels[] = $d->format('M d');
        $values[] = $dayData['total'];
    }
} else {
    $chartTitle = 'Monthly revenue for ' . date('Y') . ' (line graph)';

    for ($m = 1; $m <= 12; $m++) {
        $monthStartLoop = date('Y') . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . '-01';
        $monthEndLoop = date('Y-m-t', strtotime($monthStartLoop));
        $monthLoopData = revenue_for_range($conn, $monthStartLoop, $monthEndLoop);

        $labels[] = date('M', strtotime($monthStartLoop));
        $values[] = $monthLoopData['total'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>RJL Fitness Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">

<style>
:root {
    --brand: #b30000;
    --brand2: #ff3333;
    --bg: #0b0b0b;
    --line: #2a2a2a;
    --muted: #a9a9a9;
    --shadow: 0 18px 35px rgba(0,0,0,.55);
    --r: 18px;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    background: radial-gradient(circle at top, #111 0, #000 55%, #000 100%);
    color: #f5f5f5;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.topbar {
    position: sticky;
    top: 0;
    z-index: 1000;
    min-height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 10px 22px;
    background: linear-gradient(90deg, #000, var(--brand));
    box-shadow: 0 10px 25px rgba(0,0,0,.5);
}

.brand {
    display: flex;
    align-items: center;
    color: #fff;
    text-decoration: none;
    font-weight: 900;
    letter-spacing: .04em;
    white-space: nowrap;
}

.brand:hover {
    color: #fff;
    text-decoration: none;
}

.brand img {
    height: 34px;
    border-radius: 8px;
    margin-right: 10px;
}

.navbtn {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
}

.navbtn a {
    color: #fff;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.18);
    padding: 6px 10px;
    border-radius: 10px;
    font-weight: 800;
    font-size: .85rem;
}

.navbtn a:hover {
    background: rgba(255,255,255,.08);
}

.main-wrap {
    max-width: 1180px;
    margin: 22px auto 48px;
    padding: 0 18px;
}

.card-dark {
    background: radial-gradient(circle at top, #222 0, #111 55%, #0b0b0b 100%);
    border: 1px solid var(--line);
    border-radius: var(--r);
    box-shadow: var(--shadow);
    padding: 16px 18px;
}

.card-dark h5 {
    margin: 0 0 10px;
    color: var(--muted);
    font-size: .9rem;
    text-transform: uppercase;
    letter-spacing: .12em;
}

.grid-top {
    display: grid;
    grid-template-columns: repeat(3, minmax(0,1fr));
    gap: 14px;
    margin-bottom: 14px;
}

.grid-mid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.big-value {
    font-size: 1.65rem;
    font-weight: 1000;
    margin: 0;
}

.small-muted {
    color: var(--muted);
    font-size: .88rem;
    margin-top: 4px;
}

.split-note {
    margin-top: 8px;
    color: #cfcfcf;
    font-size: .8rem;
    line-height: 1.55;
}

.chart-card {
    padding: 16px 18px 18px;
}

.chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.chart-title {
    font-weight: 1000;
    margin: 0;
}

.chart-sub {
    color: var(--muted);
    font-size: .9rem;
    margin: 0;
}

.chart-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.chart-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,.18);
    color: #fff;
    text-decoration: none;
    font-weight: 900;
    font-size: .82rem;
    letter-spacing: .06em;
    text-transform: uppercase;
    background: rgba(255,255,255,.04);
}

.chart-toggle:hover {
    color: #fff;
    text-decoration: none;
    background: rgba(255,255,255,.08);
}

.chart-toggle.active {
    background: linear-gradient(120deg, var(--brand), var(--brand2));
    border-color: transparent;
}

.chart-box {
    height: 500px;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,.10);
    background: #0f0f0f;
    padding: 10px;
}

.debug-box {
    margin-top: 18px;
    background: #111;
    border: 1px solid #333;
    border-radius: 14px;
    padding: 14px;
    color: #eee;
    font-size: .85rem;
}

.debug-box pre {
    color: #ddd;
    white-space: pre-wrap;
    margin: 0;
}

@media(max-width: 991px) {
    .grid-top {
        grid-template-columns: 1fr;
    }

    .grid-mid {
        grid-template-columns: repeat(2, minmax(0,1fr));
    }
}

@media(max-width: 575px) {
    .grid-mid {
        grid-template-columns: 1fr;
    }

    .topbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .navbtn {
        justify-content: flex-start;
    }
}
</style>
</head>

<body>

<header class="topbar">
    <a class="brand" href="home.php">
        <img src="photo/logo.jpg" alt="RJL">
        <span>RJL Fitness Admin</span>
    </a>

    <div class="navbtn">
        <a href="admin_users.php">Users</a>
        <a href="admin_pending.php">Pending</a>
        <a href="admin_pos.php">POS</a>
        <a href="all_pos_records.php">POS Records</a>
        <a href="pos_storage.php">POS Storage</a>
        <a href="admin_dashboard.php?debug=1">Debug Revenue</a>
        <a href="logout.php" style="border:none;background:linear-gradient(120deg,var(--brand),var(--brand2));">
            Logout
        </a>
    </div>
</header>

<main class="main-wrap">

    <div class="grid-top">
        <div class="card-dark">
            <h5>Revenue Today</h5>
            <p class="big-value"><?= h(peso($revToday)) ?></p>
            <div class="small-muted"><?= h(date('M d, Y')) ?></div>
            <div class="split-note">
                GCash QR: <?= h(peso($todayRevenueData['gcash'])) ?><br>
                RFID Load: <?= h(peso($todayRevenueData['load'])) ?>
            </div>
        </div>

        <div class="card-dark">
            <h5>Revenue This Month</h5>
            <p class="big-value"><?= h(peso($revMonth)) ?></p>
            <div class="small-muted"><?= h(date('F Y')) ?></div>
            <div class="split-note">
                GCash QR: <?= h(peso($monthRevenueData['gcash'])) ?><br>
                RFID Load: <?= h(peso($monthRevenueData['load'])) ?>
            </div>
        </div>

        <div class="card-dark">
            <h5>Revenue Total</h5>
            <p class="big-value"><?= h(peso($revTotal)) ?></p>
            <div class="small-muted">Year total for <?= h(date('Y')) ?> only</div>
            <div class="split-note">
                GCash QR: <?= h(peso($totalRevenueData['gcash'])) ?><br>
                RFID Load: <?= h(peso($totalRevenueData['load'])) ?>
            </div>
        </div>
    </div>

    <div class="grid-mid">
        <div class="card-dark">
            <h5>Members</h5>
            <p class="big-value"><?= (int)$members ?></p>
        </div>

        <div class="card-dark">
            <h5>Trainers</h5>
            <p class="big-value"><?= (int)$trainers ?></p>
        </div>

        <div class="card-dark">
            <h5>Staff</h5>
            <p class="big-value"><?= (int)$staffs ?></p>
        </div>

        <div class="card-dark">
            <h5>Pending</h5>
            <p class="big-value"><?= (int)$pending ?></p>
            <div class="small-muted">Users pending if status exists</div>
        </div>
    </div>

    <section class="card-dark chart-card">
        <div class="chart-header">
            <div>
                <h3 class="chart-title">Revenue Report</h3>
                <p class="chart-sub"><?= h($chartTitle) ?></p>
            </div>

            <div class="chart-actions">
                <a
                    href="admin_dashboard.php?chart=month"
                    class="chart-toggle <?= $chartMode === 'month' ? 'active' : '' ?>"
                >
                    Month
                </a>

                <a
                    href="admin_dashboard.php?chart=year"
                    class="chart-toggle <?= $chartMode === 'year' ? 'active' : '' ?>"
                >
                    Year
                </a>

                <span class="small-muted">
                    RFID balance not counted
                </span>
            </div>
        </div>

        <div class="chart-box">
            <canvas id="revenueChart"></canvas>
        </div>
    </section>

    <?php if ($debug): ?>
        <div class="debug-box">
            <h5>Revenue Debug</h5>

            <p>
                <strong>Today total:</strong> <?= h(peso($todayRevenueData['total'])) ?><br>
                <strong>Today GCash source:</strong> <?= h($todayRevenueData['gcash_source']) ?>,
                records: <?= (int)$todayRevenueData['gcash_count'] ?>,
                amount: <?= h(peso($todayRevenueData['gcash'])) ?><br>
                <strong>Today RFID Load source:</strong> <?= h($todayRevenueData['load_source']) ?>,
                records: <?= (int)$todayRevenueData['load_count'] ?>,
                amount: <?= h(peso($todayRevenueData['load'])) ?>
            </p>

            <p>
                <strong>Month total:</strong> <?= h(peso($monthRevenueData['total'])) ?><br>
                <strong>Month GCash source:</strong> <?= h($monthRevenueData['gcash_source']) ?>,
                records: <?= (int)$monthRevenueData['gcash_count'] ?>,
                amount: <?= h(peso($monthRevenueData['gcash'])) ?><br>
                <strong>Month RFID Load source:</strong> <?= h($monthRevenueData['load_source']) ?>,
                records: <?= (int)$monthRevenueData['load_count'] ?>,
                amount: <?= h(peso($monthRevenueData['load'])) ?>
            </p>

            <pre><?php
                echo h(implode("\n", array_unique(array_merge(
                    $todayRevenueData['debug'],
                    $monthRevenueData['debug'],
                    $totalRevenueData['debug']
                ))));
            ?></pre>
        </div>
    <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
const labels = <?= json_encode($labels) ?>;
const values = <?= json_encode($values) ?>;
const chartMode = <?= json_encode($chartMode) ?>;

const ctx = document.getElementById('revenueChart');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: chartMode === 'year' ? 'Monthly Revenue (₱)' : 'Daily Revenue (₱)',
            data: values,
            tension: 0.35,
            fill: true,
            pointRadius: 3,
            pointHoverRadius: 6,
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                labels: {
                    color: '#ddd'
                }
            },
            tooltip: {
                callbacks: {
                    label: (ctx) => {
                        return ' ₱' + Number(ctx.parsed.y || 0).toLocaleString(undefined, {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    color: '#bbb'
                },
                grid: {
                    color: 'rgba(255,255,255,0.06)'
                }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    color: '#bbb',
                    callback: (v) => '₱' + Number(v).toLocaleString()
                },
                grid: {
                    color: 'rgba(255,255,255,0.06)'
                }
            }
        }
    }
});
</script>

</body>
</html>
