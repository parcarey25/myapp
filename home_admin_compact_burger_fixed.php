<?php
// home_admin.php
// Admin Control Panel
// Revenue logic:
// Today Revenue = GCash QR + RFID Wallet Load today
// Total Revenue = GCash QR + RFID Wallet Load this year
// RFID Balance / wallet balance payments are NOT counted.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/db.php';

$role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($role, ['admin'], true)) {
    header('Location: home.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$yearStart = date('Y-01-01');
$yearEnd = date('Y-12-31');

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

    if (!table_exists($conn, 'payments')) {
        return ['total' => 0.0, 'count' => 0];
    }

    $table = 'payments';

    $amountCol = first_col($conn, $table, ['amount', 'total', 'paid_amount', 'total_amount', 'price']);
    $dateCol = first_col($conn, $table, ['created_at', 'paid_at', 'payment_date', 'date_paid', 'transaction_date', 'date', 'datetime', 'date_time']);
    $methodCol = first_col($conn, $table, ['method', 'payment_method', 'mode_of_payment', 'payment_type', 'type']);
    $noteCol = first_col($conn, $table, ['note', 'description', 'remarks', 'details', 'reference']);
    $statusCol = first_col($conn, $table, ['status', 'payment_status']);

    if (!$amountCol || !$dateCol) {
        return ['total' => 0.0, 'count' => 0];
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

    return ['total' => $total, 'count' => $count];
}

function sum_load_from_payments(mysqli $conn, string $from, string $to): array
{
    $total = 0.0;
    $count = 0;

    if (!table_exists($conn, 'payments')) {
        return ['total' => 0.0, 'count' => 0];
    }

    $table = 'payments';

    $amountCol = first_col($conn, $table, ['amount', 'total', 'paid_amount', 'total_amount', 'price']);
    $dateCol = first_col($conn, $table, ['created_at', 'paid_at', 'payment_date', 'date_paid', 'transaction_date', 'date', 'datetime', 'date_time']);
    $methodCol = first_col($conn, $table, ['method', 'payment_method', 'mode_of_payment', 'payment_type', 'type']);
    $noteCol = first_col($conn, $table, ['note', 'description', 'remarks', 'details', 'reference']);
    $statusCol = first_col($conn, $table, ['status', 'payment_status']);

    if (!$amountCol || !$dateCol) {
        return ['total' => 0.0, 'count' => 0];
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

    return ['total' => $total, 'count' => $count];
}

function sum_gcash_from_gcash_table(mysqli $conn, string $from, string $to): array
{
    $total = 0.0;
    $count = 0;

    if (!table_exists($conn, 'gcash_payments')) {
        return ['total' => 0.0, 'count' => 0];
    }

    $table = 'gcash_payments';

    $amountCol = first_col($conn, $table, ['amount', 'total', 'paid_amount', 'total_amount', 'price']);
    $dateCol = first_col($conn, $table, ['created_at', 'paid_at', 'payment_date', 'date_paid', 'transaction_date', 'date', 'datetime', 'date_time']);
    $statusCol = first_col($conn, $table, ['status', 'payment_status']);

    if (!$amountCol || !$dateCol) {
        return ['total' => 0.0, 'count' => 0];
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

    return ['total' => $total, 'count' => $count];
}

function sum_wallet_load_from_wallet_table(mysqli $conn, string $from, string $to): array
{
    $total = 0.0;
    $count = 0;

    $table = first_table($conn, [
        'wallet_transactions',
        'rfid_wallet_transactions',
        'rfid_wallet_transaction'
    ]);

    if (!$table) {
        return ['total' => 0.0, 'count' => 0];
    }

    $amountCol = first_col($conn, $table, ['amount', 'load_amount', 'credit', 'total', 'paid_amount']);
    $dateCol = first_col($conn, $table, ['created_at', 'date_created', 'transaction_date', 'date', 'paid_at', 'datetime', 'date_time']);
    $typeCol = first_col($conn, $table, ['type', 'transaction_type', 'txn_type', 'method']);
    $noteCol = first_col($conn, $table, ['note', 'description', 'remarks', 'details', 'reference']);
    $statusCol = first_col($conn, $table, ['status', 'payment_status']);

    if (!$amountCol || !$dateCol) {
        return ['total' => 0.0, 'count' => 0];
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

            if (is_rfid_balance_text($text)) {
                continue;
            }

            if ($text !== '' && !is_rfid_load_text($text)) {
                $lower = strtolower($text);

                if (!in_array($lower, ['load', 'topup', 'top-up', 'cashin', 'cash-in', 'credit'], true)) {
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

    return ['total' => $total, 'count' => $count];
}

function revenue_for_range(mysqli $conn, string $from, string $to): array
{
    $gcashSeparate = sum_gcash_from_gcash_table($conn, $from, $to);
    $gcashPayments = sum_gcash_from_payments($conn, $from, $to);

    if ($gcashSeparate['count'] > 0) {
        $gcash = $gcashSeparate;
    } else {
        $gcash = $gcashPayments;
    }

    $walletTableLoad = sum_wallet_load_from_wallet_table($conn, $from, $to);
    $paymentLoad = sum_load_from_payments($conn, $from, $to);

    if ($walletTableLoad['count'] > 0) {
        $load = $walletTableLoad;
    } else {
        $load = $paymentLoad;
    }

    return [
        'gcash' => (float)$gcash['total'],
        'load' => (float)$load['total'],
        'total' => (float)$gcash['total'] + (float)$load['total'],
    ];
}

/*
|--------------------------------------------------------------------------
| Current user
|--------------------------------------------------------------------------
*/

$username = $_SESSION['username'] ?? 'admin';
$displayName = $username;
$avatarPath = 'photo/logo.jpg';

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId > 0 && table_exists($conn, 'users')) {
    $nameCol = has_column($conn, 'users', 'full_name') ? 'full_name' : null;
    $avatarCol = first_col($conn, 'users', ['avatar_path', 'avatar', 'profile_pic', 'photo']);

    $select = "username";

    if ($nameCol) {
        $select .= ", `{$nameCol}` AS full_name";
    } else {
        $select .= ", '' AS full_name";
    }

    if ($avatarCol) {
        $select .= ", `{$avatarCol}` AS avatar_path";
    } else {
        $select .= ", '' AS avatar_path";
    }

    $sql = "SELECT {$select}
            FROM users
            WHERE id = ?
            LIMIT 1";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('i', $userId);
        $st->execute();

        $res = $st->get_result();

        if ($row = $res->fetch_assoc()) {
            $displayName = trim((string)($row['full_name'] ?? ''));
            if ($displayName === '') {
                $displayName = trim((string)($row['username'] ?? $username));
            }

            if (!empty($row['avatar_path'])) {
                $avatarPath = $row['avatar_path'];
            }
        }

        if ($res) {
            $res->free();
        }

        $st->close();
    }
}

/*
|--------------------------------------------------------------------------
| Metrics
|--------------------------------------------------------------------------
*/

$totalMembers = 0;
$activeMembers = 0;
$pendingMembers = 0;
$expiringSoon = 0;
$expiredMembers = 0;
$pendingValidIds = 0;

if (table_exists($conn, 'users')) {
    if (has_column($conn, 'users', 'role')) {
        $sql = "SELECT COUNT(*) AS c
                FROM users
                WHERE LOWER(TRIM(role)) = 'member'";

        if ($res = $conn->query($sql)) {
            $totalMembers = (int)($res->fetch_assoc()['c'] ?? 0);
            $res->free();
        }

        if (has_column($conn, 'users', 'status')) {
            $sql = "SELECT COUNT(*) AS c
                    FROM users
                    WHERE LOWER(TRIM(role)) = 'member'
                      AND LOWER(TRIM(status)) = 'active'";

            if ($res = $conn->query($sql)) {
                $activeMembers = (int)($res->fetch_assoc()['c'] ?? 0);
                $res->free();
            }

            $sql = "SELECT COUNT(*) AS c
                    FROM users
                    WHERE LOWER(TRIM(role)) = 'member'
                      AND LOWER(TRIM(status)) = 'pending'";

            if ($res = $conn->query($sql)) {
                $pendingMembers = (int)($res->fetch_assoc()['c'] ?? 0);
                $res->free();
            }

            $sql = "SELECT COUNT(*) AS c
                    FROM users
                    WHERE LOWER(TRIM(role)) = 'member'
                      AND LOWER(TRIM(status)) = 'expired'";

            if ($res = $conn->query($sql)) {
                $expiredMembers = (int)($res->fetch_assoc()['c'] ?? 0);
                $res->free();
            }
        } else {
            $activeMembers = $totalMembers;
        }
    }

    if (has_column($conn, 'users', 'valid_id_status')) {
        $sql = "SELECT COUNT(*) AS c
                FROM users
                WHERE LOWER(TRIM(valid_id_status)) = 'pending'";

        if ($res = $conn->query($sql)) {
            $pendingValidIds = (int)($res->fetch_assoc()['c'] ?? 0);
            $res->free();
        }
    }
}

$expiryCol = null;

if (table_exists($conn, 'users')) {
    $expiryCol = first_col($conn, 'users', [
        'membership_end',
        'membership_end_date',
        'membership_expiry',
        'membership_expiry_date',
        'expires_at',
        'valid_until'
    ]);
}

if ($expiryCol) {
    $sql = "SELECT COUNT(*) AS c
            FROM users
            WHERE LOWER(TRIM(role)) = 'member'
              AND `{$expiryCol}` IS NOT NULL
              AND DATE(`{$expiryCol}`) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";

    if ($res = $conn->query($sql)) {
        $expiringSoon = (int)($res->fetch_assoc()['c'] ?? 0);
        $res->free();
    }
}

$todayRevenueData = revenue_for_range($conn, $today, $today);
$yearRevenueData = revenue_for_range($conn, $yearStart, $yearEnd);

$todayRevenue = $todayRevenueData['total'];
$totalRevenue = $yearRevenueData['total'];

/*
|--------------------------------------------------------------------------
| Chart: daily revenue for current month
|--------------------------------------------------------------------------
*/

$chartLabels = [];
$chartValues = [];

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

    $chartLabels[] = $d->format('M d');
    $chartValues[] = $dayData['total'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Control Panel | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">

<style>
:root {
    --brand: #b30000;
    --brand2: #ff2525;
    --dark: #050505;
    --panel: #151515;
    --panel2: #1f1f1f;
    --line: rgba(255,255,255,.12);
    --text: #f5f5f5;
    --muted: #c2c7d0;
    --gold: #f3d1a1;
    --radius: 24px;
    --shadow: 0 18px 45px rgba(0,0,0,.55);
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    background:
        radial-gradient(circle at top left, rgba(179,0,0,.26), transparent 32%),
        radial-gradient(circle at bottom right, rgba(179,0,0,.18), transparent 30%),
        #030303;
    color: var(--text);
    font-family: "Segoe UI", Arial, sans-serif;
}

a {
    color: inherit;
    text-decoration: none;
}

a:hover {
    color: inherit;
    text-decoration: none;
}

.topbar {
    min-height: 96px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 18px 24px;
    background: linear-gradient(90deg, #230000, #8d0000, #d00000);
    border-bottom: 1px solid rgba(255,255,255,.12);
    box-shadow: 0 12px 38px rgba(0,0,0,.45);
    position: sticky;
    top: 0;
    z-index: 1000;
}

.brand-block {
    display: flex;
    align-items: center;
    gap: 14px;
}

.burger {
    width: 58px;
    height: 58px;
    border-radius: 16px;
    background: rgba(0,0,0,.20);
    border: 1px solid rgba(255,255,255,.18);
    display: grid;
    place-items: center;
    font-size: 30px;
    line-height: 1;
}

.logo {
    width: 66px;
    height: 66px;
    object-fit: cover;
    border-radius: 16px;
    border: 2px solid rgba(255,255,255,.75);
    background: #111;
}

.brand-title {
    font-size: 1.5rem;
    font-weight: 1000;
    line-height: 1;
    letter-spacing: -.03em;
}

.brand-sub {
    margin-top: 6px;
    letter-spacing: .28em;
    font-size: .83rem;
    color: rgba(255,255,255,.78);
    font-weight: 800;
}

.top-actions {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.welcome {
    font-size: 1rem;
    font-weight: 600;
}

.pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 12px 20px;
    border-radius: 999px;
    background: rgba(0,0,0,.26);
    border: 1px solid rgba(255,255,255,.14);
    font-weight: 900;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.logout-pill {
    background: #111820;
}

.avatar {
    width: 62px;
    height: 62px;
    object-fit: cover;
    border-radius: 18px;
    border: 2px solid rgba(255,255,255,.8);
}

.page {
    padding: 28px 14px 56px;
}

.hero-grid {
    display: grid;
    grid-template-columns: 1.35fr .95fr;
    gap: 24px;
    margin-bottom: 24px;
}

.hero-card,
.side-card,
.metric-card,
.report-card {
    background:
        linear-gradient(135deg, rgba(255,255,255,.055), rgba(255,255,255,.02)),
        var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.hero-card {
    min-height: 250px;
    padding: 32px;
    background:
        radial-gradient(circle at top left, rgba(255, 0, 0, .30), transparent 40%),
        linear-gradient(135deg, rgba(179,0,0,.78), rgba(20,20,20,.96));
}

.hero-kicker {
    display: inline-flex;
    padding: 10px 16px;
    border-radius: 999px;
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.12);
    color: #fff;
    font-size: .82rem;
    font-weight: 900;
    letter-spacing: .12em;
    text-transform: uppercase;
    margin-bottom: 18px;
}

.hero-title {
    margin: 0;
    font-size: clamp(2rem, 4vw, 3.4rem);
    font-weight: 1000;
    letter-spacing: -.06em;
}

.hero-text {
    margin: 12px 0 22px;
    color: rgba(255,255,255,.86);
    max-width: 780px;
    line-height: 1.65;
    font-size: 1.05rem;
}

.hero-actions {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
}

.hero-btn {
    display: inline-flex;
    min-height: 48px;
    align-items: center;
    justify-content: center;
    padding: 12px 24px;
    border-radius: 999px;
    font-weight: 1000;
    letter-spacing: .06em;
    text-transform: uppercase;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.15);
}

.hero-btn.primary {
    background: #fff;
    color: #b30000;
}

.side-card {
    padding: 22px;
    display: grid;
    gap: 14px;
}

.side-link {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 58px;
    padding: 14px 18px;
    border-radius: 999px;
    background: linear-gradient(180deg, #252525, #191919);
    border: 1px solid rgba(255,255,255,.10);
    font-weight: 1000;
    font-size: 1.05rem;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.06);
}

.side-link.logout {
    background: linear-gradient(135deg, #e70000, #ff2f2f);
    border: none;
}

.metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 24px;
}

.metric-card {
    position: relative;
    min-height: 150px;
    padding: 24px;
}

.metric-card::after {
    content: "";
    position: absolute;
    width: 92px;
    height: 92px;
    border-radius: 0 0 0 60px;
    top: 0;
    right: 0;
    background: rgba(179,0,0,.20);
}

.metric-label {
    position: relative;
    z-index: 1;
    color: #d4d8df;
    text-transform: uppercase;
    letter-spacing: .12em;
    font-size: .9rem;
    font-weight: 1000;
    margin-bottom: 16px;
}

.metric-value {
    position: relative;
    z-index: 1;
    margin: 0;
    font-size: 2.1rem;
    font-weight: 1000;
    letter-spacing: -.04em;
}

.metric-sub {
    position: relative;
    z-index: 1;
    margin-top: 10px;
    color: var(--muted);
    font-size: .98rem;
    line-height: 1.45;
}

.report-card {
    padding: 24px;
}

.report-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
    border-bottom: 1px solid var(--line);
    padding-bottom: 18px;
    margin-bottom: 18px;
}

.report-title {
    margin: 0;
    font-size: 2rem;
    font-weight: 1000;
    letter-spacing: -.05em;
}

.report-sub {
    color: var(--muted);
    margin-top: 6px;
    font-size: 1.05rem;
}

.source {
    color: #d7dce5;
}

.chart-box {
    height: 430px;
    background: #101010;
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 18px;
    padding: 14px;
}

@media (max-width: 1100px) {
    .hero-grid {
        grid-template-columns: 1fr;
    }

    .metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .topbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .top-actions {
        justify-content: flex-start;
    }

    .metric-grid {
        grid-template-columns: 1fr;
    }

    .hero-card {
        padding: 24px;
    }
}

/* ============================================================
   COMPACT ADMIN DASHBOARD OVERRIDE
   Added only to minimize size.
   No PHP logic, links, buttons, cards, or other code removed.
   ============================================================ */

:root {
    --radius: 18px;
    --shadow: 0 10px 28px rgba(0,0,0,.45);
}

.topbar {
    min-height: 68px;
    padding: 10px 18px;
    gap: 12px;
}

.brand-block {
    gap: 10px;
}

.burger {
    width: 44px;
    height: 44px;
    border-radius: 13px;
    font-size: 23px;
}

.logo {
    width: 48px;
    height: 48px;
    border-radius: 13px;
}

.brand-title {
    font-size: 1.2rem;
}

.brand-sub {
    margin-top: 4px;
    letter-spacing: .22em;
    font-size: .70rem;
}

.top-actions {
    gap: 10px;
}

.welcome {
    font-size: .95rem;
}

.pill {
    padding: 9px 16px;
    font-size: .86rem;
}

.avatar {
    width: 46px;
    height: 46px;
    border-radius: 14px;
}

.page {
    padding: 18px 14px 42px;
    max-width: 1420px;
    margin: 0 auto;
}

.hero-grid {
    gap: 16px;
    margin-bottom: 16px;
}

.hero-card {
    min-height: 185px;
    padding: 22px;
}

.hero-kicker {
    padding: 7px 12px;
    font-size: .72rem;
    margin-bottom: 12px;
}

.hero-title {
    font-size: clamp(1.9rem, 3.2vw, 2.7rem);
    line-height: 1.05;
}

.hero-text {
    margin: 10px 0 16px;
    line-height: 1.5;
    font-size: .98rem;
}

.hero-actions {
    gap: 10px;
}

.hero-btn {
    min-height: 40px;
    padding: 9px 18px;
    font-size: .88rem;
}

.side-card {
    padding: 16px;
    gap: 10px;
}

.side-link {
    min-height: 44px;
    padding: 10px 14px;
    font-size: .95rem;
}

.metric-grid {
    gap: 14px;
    margin-bottom: 16px;
}

.metric-card {
    min-height: 112px;
    padding: 17px 18px;
}

.metric-card::after {
    width: 66px;
    height: 66px;
    border-radius: 0 0 0 45px;
}

.metric-label {
    font-size: .78rem;
    margin-bottom: 10px;
}

.metric-value {
    font-size: 1.7rem;
}

.metric-sub {
    margin-top: 7px;
    font-size: .88rem;
    line-height: 1.35;
}

.report-card {
    padding: 18px;
}

.report-head {
    gap: 12px;
    padding-bottom: 12px;
    margin-bottom: 14px;
}

.report-title {
    font-size: 1.6rem;
}

.report-sub {
    margin-top: 4px;
    font-size: .95rem;
}

.source {
    font-size: .9rem;
}

.chart-box {
    height: 330px;
    border-radius: 16px;
    padding: 12px;
}

@media (max-width: 640px) {
    .hero-card {
        padding: 18px;
    }

    .hero-title {
        font-size: 1.75rem;
    }
}


/* ============================================================
   BURGER DRAWER FIX
   Added only to make the existing burger open a menu.
   No existing content removed.
   ============================================================ */

.burger {
    cursor: pointer;
    color: #fff;
    appearance: none;
    -webkit-appearance: none;
}

.admin-menu-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.60);
    z-index: 2998;
    display: none;
}

.admin-menu-backdrop.show {
    display: block;
}

.admin-menu-drawer {
    position: fixed;
    top: 0;
    left: 0;
    width: min(310px, 88vw);
    height: 100vh;
    background:
        radial-gradient(circle at top left, rgba(179,0,0,.32), transparent 34%),
        linear-gradient(180deg, #171717, #090909);
    border-right: 1px solid rgba(255,255,255,.14);
    box-shadow: 18px 0 45px rgba(0,0,0,.55);
    z-index: 2999;
    transform: translateX(-105%);
    transition: transform .22s ease;
    padding: 18px;
    overflow-y: auto;
}

.admin-menu-drawer.show {
    transform: translateX(0);
}

.drawer-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    padding-bottom: 14px;
    margin-bottom: 12px;
    border-bottom: 1px solid rgba(255,255,255,.12);
}

.drawer-head strong {
    display: block;
    font-size: 1.1rem;
    color: #fff;
}

.drawer-head span {
    display: block;
    margin-top: 3px;
    color: rgba(255,255,255,.68);
    font-size: .82rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    font-weight: 800;
}

.drawer-close {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,.14);
    background: rgba(255,255,255,.06);
    color: #fff;
    font-size: 26px;
    line-height: 1;
    cursor: pointer;
}

.drawer-links {
    display: grid;
    gap: 9px;
}

.drawer-links a {
    display: flex;
    align-items: center;
    min-height: 44px;
    padding: 10px 12px;
    border-radius: 14px;
    background: rgba(255,255,255,.055);
    border: 1px solid rgba(255,255,255,.09);
    color: #fff;
    font-weight: 850;
    text-decoration: none;
}

.drawer-links a:hover {
    background: rgba(255,255,255,.10);
    color: #fff;
    text-decoration: none;
}

.drawer-links .drawer-logout {
    margin-top: 6px;
    background: linear-gradient(135deg, #c40000, #ff2b2b);
    border-color: transparent;
}

</style>
</head>

<body>

<header class="topbar">
    <div class="brand-block">
        <button type="button" class="burger" id="adminBurgerBtn" aria-label="Open menu">☰</button>
        <img src="photo/logo.jpg" class="logo" alt="RJL Fitness">
        <div>
            <div class="brand-title">RJL Fitness</div>
            <div class="brand-sub">ADMIN CONTROL PANEL</div>
        </div>
    </div>

    <div class="top-actions">
        <div class="welcome">Welcome, <strong><?= h($displayName) ?></strong></div>
        <span class="pill">Admin</span>
        <a href="logout.php" class="pill logout-pill">Logout</a>
        <img src="<?= h($avatarPath) ?>" class="avatar" alt="Profile">
    </div>
</header>

<!-- Burger Drawer Menu - added without removing existing dashboard content -->
<div class="admin-menu-backdrop" id="adminMenuBackdrop"></div>

<aside class="admin-menu-drawer" id="adminMenuDrawer" aria-hidden="true">
    <div class="drawer-head">
        <div>
            <strong>RJL Fitness</strong>
            <span>Admin Menu</span>
        </div>

        <button type="button" class="drawer-close" id="adminMenuClose" aria-label="Close menu">
            ×
        </button>
    </div>

    <nav class="drawer-links">
        <a href="home_admin.php">🏠 Admin Dashboard</a>
        <a href="admin_users.php">👥 Manage Members</a>
        <a href="payments.php">💳 View Payments</a>
        <a href="membership_monitor.php">📋 Membership Monitor</a>
        <a href="rfid_load.php">💰 Load RFID</a>
        <a href="admin_dashboard.php">📈 Admin Revenue Dashboard</a>
        <a href="all_pos_records.php">🧾 POS Records</a>
        <a href="pos_storage.php">📁 POS Storage</a>
        <a href="logout.php" class="drawer-logout">🚪 Logout</a>
    </nav>
</aside>


<main class="page container-fluid">

    <section class="hero-grid">
        <div class="hero-card">
            <div class="hero-kicker">RJL Power Fitness Center</div>
            <h1 class="hero-title">Admin Control Dashboard</h1>
            <p class="hero-text">
                Manage members, monitor membership status, handle RFID loading, view payments,
                and watch revenue performance for the year.
            </p>

            <div class="hero-actions">
                <a href="admin_users.php" class="hero-btn primary">Manage Members</a>
                <a href="membership_monitor.php" class="hero-btn">Monitor Membership</a>
                <a href="rfid_load.php" class="hero-btn">Load RFID</a>
            </div>
        </div>

        <div class="side-card">
            <a href="admin_users.php" class="side-link">Manage Users</a>
            <a href="payments.php" class="side-link">View Payments</a>
            <a href="membership_monitor.php" class="side-link">Membership Monitor</a>
            <a href="admin_dashboard.php" class="side-link">Admin Revenue Dashboard</a>
            <a href="all_pos_records.php" class="side-link">POS Records</a>
            <a href="logout.php" class="side-link logout">Logout</a>
        </div>
    </section>

    <section class="metric-grid">
        <div class="metric-card">
            <div class="metric-label">Total Members</div>
            <p class="metric-value"><?= (int)$totalMembers ?></p>
            <div class="metric-sub">All registered member accounts</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Active Members</div>
            <p class="metric-value"><?= (int)$activeMembers ?></p>
            <div class="metric-sub">Members currently active</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Pending Members</div>
            <p class="metric-value"><?= (int)$pendingMembers ?></p>
            <div class="metric-sub">Accounts waiting for approval</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Expiring Soon</div>
            <p class="metric-value"><?= (int)$expiringSoon ?></p>
            <div class="metric-sub">Memberships within 7 days</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Expired Members</div>
            <p class="metric-value"><?= (int)$expiredMembers ?></p>
            <div class="metric-sub">Need renewal or follow-up</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Pending Valid IDs</div>
            <p class="metric-value"><?= (int)$pendingValidIds ?></p>
            <div class="metric-sub">Valid IDs waiting for review</div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Today Revenue</div>
            <p class="metric-value"><?= h(peso($todayRevenue)) ?></p>
            <div class="metric-sub">
                GCash QR + RFID Load today<br>
                RFID balance excluded
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-label">Total Revenue</div>
            <p class="metric-value"><?= h(peso($totalRevenue)) ?></p>
            <div class="metric-sub">
                Year total for <?= h(date('Y')) ?><br>
                RFID balance excluded
            </div>
        </div>
    </section>

    <section class="report-card">
        <div class="report-head">
            <div>
                <h2 class="report-title">Revenue Report</h2>
                <div class="report-sub">
                    Daily revenue for <?= h(date('F Y')) ?> (line graph)
                </div>
            </div>

            <div class="source">
                Source: GCash QR + RFID wallet load only
            </div>
        </div>

        <div class="chart-box">
            <canvas id="revenueChart"></canvas>
        </div>
    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
const chartLabels = <?= json_encode($chartLabels) ?>;
const chartValues = <?= json_encode($chartValues) ?>;

const ctx = document.getElementById('revenueChart');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: chartLabels,
        datasets: [{
            label: 'Revenue (₱)',
            data: chartValues,
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
                labels: {
                    color: '#e5e5e5'
                }
            },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
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
                    color: '#cfcfcf'
                },
                grid: {
                    color: 'rgba(255,255,255,0.06)'
                }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    color: '#cfcfcf',
                    callback: function(value) {
                        return '₱' + Number(value).toLocaleString();
                    }
                },
                grid: {
                    color: 'rgba(255,255,255,0.08)'
                }
            }
        }
    }
});
</script>


<script>
(function () {
    const burger = document.getElementById('adminBurgerBtn');
    const drawer = document.getElementById('adminMenuDrawer');
    const backdrop = document.getElementById('adminMenuBackdrop');
    const closeBtn = document.getElementById('adminMenuClose');

    if (!burger || !drawer || !backdrop) {
        return;
    }

    function openMenu() {
        drawer.classList.add('show');
        backdrop.classList.add('show');
        drawer.setAttribute('aria-hidden', 'false');
        burger.setAttribute('aria-expanded', 'true');
    }

    function closeMenu() {
        drawer.classList.remove('show');
        backdrop.classList.remove('show');
        drawer.setAttribute('aria-hidden', 'true');
        burger.setAttribute('aria-expanded', 'false');
    }

    burger.addEventListener('click', function (event) {
        event.preventDefault();

        if (drawer.classList.contains('show')) {
            closeMenu();
        } else {
            openMenu();
        }
    });

    backdrop.addEventListener('click', closeMenu);

    if (closeBtn) {
        closeBtn.addEventListener('click', closeMenu);
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && drawer.classList.contains('show')) {
            closeMenu();
        }
    });
})();
</script>

</body>
</html>
