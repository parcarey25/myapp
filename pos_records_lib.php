<?php
// pos_records_lib.php
// Shared POS record functions for RJL Fitness.
// Splits payments into 3 groups:
// 1) RFID Wallet Load
// 2) GCash QR
// 3) RFID Balance

if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    header('Location: home.php');
    exit;
}

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle): bool {
        return $needle !== '' && strpos((string)$haystack, (string)$needle) !== false;
    }
}

function pos_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function pos_money($v): string {
    return '₱' . number_format((float)$v, 2);
}

function pos_valid_date(string $date): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function pos_table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('s', $table);
    $st->execute();
    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $st->close();
    return $ok;
}

function pos_has_column(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $st->close();
    return $ok;
}

function pos_first_col(mysqli $conn, string $table, array $cols): ?string {
    foreach ($cols as $col) {
        if (pos_has_column($conn, $table, $col)) return $col;
    }
    return null;
}

function pos_first_table(mysqli $conn, array $tables): ?string {
    foreach ($tables as $table) {
        if (pos_table_exists($conn, $table)) return $table;
    }
    return null;
}

function pos_category_label(string $category): string {
    if ($category === 'rfid_load') return 'RFID Wallet Load';
    if ($category === 'gcash_qr') return 'GCash QR';
    if ($category === 'rfid_balance') return 'RFID Balance';
    return 'Other';
}

function pos_status_allowed(string $status): bool {
    $s = strtolower(trim($status));
    if ($s === '') return true;
    return !in_array($s, ['cancelled','canceled','void','refunded','failed','rejected'], true);
}

function pos_classify_payment(string $method, string $note): ?string {
    $method = strtolower(trim($method));
    $text = strtolower(trim($method . ' ' . $note));

    // Load first, so RFID Wallet Load will not be classified as balance payment.
    if (
        str_contains($text, 'rfid wallet load') ||
        str_contains($text, 'wallet load') ||
        str_contains($text, 'rfid load') ||
        str_contains($text, 'cash load') ||
        str_contains($text, 'cash (load)') ||
        str_contains($text, 'load rfid') ||
        str_contains($text, 'topup') ||
        str_contains($text, 'top-up') ||
        str_contains($text, 'cashin') ||
        str_contains($text, 'cash-in') ||
        str_contains($text, 'cash in') ||
        $method === 'load'
    ) {
        return 'rfid_load';
    }

    if (
        str_contains($text, 'gcash qr') ||
        str_contains($text, 'gcash_qr') ||
        str_contains($text, 'gcash')
    ) {
        return 'gcash_qr';
    }

    if (
        str_contains($text, 'rfid balance') ||
        str_contains($text, 'wallet balance') ||
        str_contains($text, 'rfid wallet payment') ||
        str_contains($text, 'paid using rfid') ||
        str_contains($text, 'paid by rfid') ||
        $method === 'rfid' ||
        $method === 'rfid wallet' ||
        $method === 'wallet'
    ) {
        return 'rfid_balance';
    }

    return null;
}

function pos_add_record(array &$records, string $category, array $data): void {
    $amount = (float)($data['amount'] ?? 0);
    if ($amount < 0) $amount = abs($amount);

    $records[] = [
        'category' => $category,
        'category_label' => pos_category_label($category),
        'source' => $data['source'] ?? '',
        'id' => $data['id'] ?? '',
        'user_id' => $data['user_id'] ?? '',
        'name' => $data['name'] ?? '',
        'username' => $data['username'] ?? '',
        'id_number' => $data['id_number'] ?? '',
        'amount' => $amount,
        'method' => $data['method'] ?? '',
        'status' => $data['status'] ?? '',
        'reference' => $data['reference'] ?? '',
        'date_time' => $data['date_time'] ?? '',
        'note' => $data['note'] ?? '',
    ];
}

function pos_user_join_select(mysqli $conn, string $alias, ?string $userCol, array &$select): string {
    $join = '';
    $select[] = "'' AS full_name";
    $select[] = "'' AS username";
    $select[] = "'' AS id_number";

    if ($userCol && pos_table_exists($conn, 'users')) {
        $join = " LEFT JOIN users u ON u.id = {$alias}.`{$userCol}` ";

        if (pos_has_column($conn, 'users', 'full_name')) {
            $select[count($select) - 3] = "u.full_name AS full_name";
        }

        if (pos_has_column($conn, 'users', 'username')) {
            $select[count($select) - 2] = "u.username AS username";
        }

        if (pos_has_column($conn, 'users', 'id_number')) {
            $select[count($select) - 1] = "u.id_number AS id_number";
        }
    }

    return $join;
}

function pos_get_records(mysqli $conn, string $dateFrom, string $dateTo, string $search = ''): array {
    $records = [];

    if (!pos_valid_date($dateFrom)) $dateFrom = date('Y-m-d');
    if (!pos_valid_date($dateTo)) $dateTo = $dateFrom;

    // 1) payments table
    if (pos_table_exists($conn, 'payments')) {
        $table = 'payments';
        $idCol = pos_first_col($conn, $table, ['id', 'payment_id']);
        $userCol = pos_first_col($conn, $table, ['user_id', 'member_id', 'client_id']);
        $amountCol = pos_first_col($conn, $table, ['amount', 'total', 'paid_amount', 'total_amount']);
        $methodCol = pos_first_col($conn, $table, ['method', 'payment_method', 'mode_of_payment', 'payment_type']);
        $dateCol = pos_first_col($conn, $table, ['created_at', 'paid_at', 'payment_date', 'date_paid', 'transaction_date', 'date']);
        $statusCol = pos_first_col($conn, $table, ['status', 'payment_status']);
        $refCol = pos_first_col($conn, $table, ['reference', 'reference_no', 'receipt_no', 'transaction_ref']);
        $noteCol = pos_first_col($conn, $table, ['note', 'description', 'remarks', 'details']);

        if ($amountCol && $dateCol) {
            $select = [];
            $select[] = $idCol ? "p.`{$idCol}` AS rec_id" : "'' AS rec_id";
            $select[] = $userCol ? "p.`{$userCol}` AS user_id" : "'' AS user_id";
            $select[] = "p.`{$amountCol}` AS amount";
            $select[] = $methodCol ? "p.`{$methodCol}` AS method" : "'' AS method";
            $select[] = "p.`{$dateCol}` AS date_time";
            $select[] = $statusCol ? "p.`{$statusCol}` AS status" : "'' AS status";
            $select[] = $refCol ? "p.`{$refCol}` AS reference_no" : "'' AS reference_no";
            $select[] = $noteCol ? "p.`{$noteCol}` AS note_text" : "'' AS note_text";
            $join = pos_user_join_select($conn, 'p', $userCol, $select);

            $sql = "SELECT " . implode(', ', $select) . "
                    FROM payments p
                    {$join}
                    WHERE DATE(p.`{$dateCol}`) BETWEEN ? AND ?
                    ORDER BY p.`{$dateCol}` DESC";

            if ($st = $conn->prepare($sql)) {
                $st->bind_param('ss', $dateFrom, $dateTo);
                $st->execute();
                $res = $st->get_result();

                while ($row = $res->fetch_assoc()) {
                    $status = (string)($row['status'] ?? '');
                    if (!pos_status_allowed($status)) continue;

                    $method = (string)($row['method'] ?? '');
                    $note = (string)($row['note_text'] ?? '');
                    $category = pos_classify_payment($method, $note);
                    if (!$category) continue;

                    $name = trim((string)($row['full_name'] ?? ''));
                    if ($name === '') $name = trim((string)($row['username'] ?? ''));

                    pos_add_record($records, $category, [
                        'source' => 'payments',
                        'id' => $row['rec_id'] ?? '',
                        'user_id' => $row['user_id'] ?? '',
                        'name' => $name,
                        'username' => $row['username'] ?? '',
                        'id_number' => $row['id_number'] ?? '',
                        'amount' => $row['amount'] ?? 0,
                        'method' => $method,
                        'status' => $status,
                        'reference' => $row['reference_no'] ?? '',
                        'date_time' => $row['date_time'] ?? '',
                        'note' => $note,
                    ]);
                }

                if ($res) $res->free();
                $st->close();
            }
        }
    }

    // 2) gcash_payments table
    if (pos_table_exists($conn, 'gcash_payments')) {
        $table = 'gcash_payments';
        $idCol = pos_first_col($conn, $table, ['id', 'payment_id']);
        $userCol = pos_first_col($conn, $table, ['user_id', 'member_id', 'client_id']);
        $amountCol = pos_first_col($conn, $table, ['amount', 'total', 'paid_amount', 'total_amount']);
        $dateCol = pos_first_col($conn, $table, ['created_at', 'paid_at', 'payment_date', 'date_paid', 'transaction_date', 'date']);
        $statusCol = pos_first_col($conn, $table, ['status', 'payment_status']);
        $refCol = pos_first_col($conn, $table, ['reference', 'reference_no', 'gcash_ref', 'transaction_ref']);
        $noteCol = pos_first_col($conn, $table, ['note', 'description', 'remarks', 'details']);

        if ($amountCol && $dateCol) {
            $select = [];
            $select[] = $idCol ? "g.`{$idCol}` AS rec_id" : "'' AS rec_id";
            $select[] = $userCol ? "g.`{$userCol}` AS user_id" : "'' AS user_id";
            $select[] = "g.`{$amountCol}` AS amount";
            $select[] = "g.`{$dateCol}` AS date_time";
            $select[] = $statusCol ? "g.`{$statusCol}` AS status" : "'' AS status";
            $select[] = $refCol ? "g.`{$refCol}` AS reference_no" : "'' AS reference_no";
            $select[] = $noteCol ? "g.`{$noteCol}` AS note_text" : "'' AS note_text";
            $join = pos_user_join_select($conn, 'g', $userCol, $select);

            $sql = "SELECT " . implode(', ', $select) . "
                    FROM gcash_payments g
                    {$join}
                    WHERE DATE(g.`{$dateCol}`) BETWEEN ? AND ?
                    ORDER BY g.`{$dateCol}` DESC";

            if ($st = $conn->prepare($sql)) {
                $st->bind_param('ss', $dateFrom, $dateTo);
                $st->execute();
                $res = $st->get_result();

                while ($row = $res->fetch_assoc()) {
                    $status = (string)($row['status'] ?? '');
                    if (!pos_status_allowed($status)) continue;

                    $name = trim((string)($row['full_name'] ?? ''));
                    if ($name === '') $name = trim((string)($row['username'] ?? ''));

                    pos_add_record($records, 'gcash_qr', [
                        'source' => 'gcash_payments',
                        'id' => $row['rec_id'] ?? '',
                        'user_id' => $row['user_id'] ?? '',
                        'name' => $name,
                        'username' => $row['username'] ?? '',
                        'id_number' => $row['id_number'] ?? '',
                        'amount' => $row['amount'] ?? 0,
                        'method' => 'GCash QR',
                        'status' => $status,
                        'reference' => $row['reference_no'] ?? '',
                        'date_time' => $row['date_time'] ?? '',
                        'note' => $row['note_text'] ?? '',
                    ]);
                }

                if ($res) $res->free();
                $st->close();
            }
        }
    }

    // 3) wallet transaction table
    $walletTable = pos_first_table($conn, [
        'wallet_transactions',
        'rfid_wallet_transactions',
        'rfid_wallet_transaction'
    ]);

    if ($walletTable) {
        $table = $walletTable;
        $idCol = pos_first_col($conn, $table, ['id', 'transaction_id']);
        $userCol = pos_first_col($conn, $table, ['user_id', 'member_id', 'client_id']);
        $amountCol = pos_first_col($conn, $table, ['amount', 'load_amount', 'credit']);
        $dateCol = pos_first_col($conn, $table, ['created_at', 'date_created', 'transaction_date', 'date', 'paid_at']);
        $typeCol = pos_first_col($conn, $table, ['type', 'transaction_type', 'txn_type']);
        $refCol = pos_first_col($conn, $table, ['reference', 'reference_no', 'transaction_ref']);
        $noteCol = pos_first_col($conn, $table, ['note', 'description', 'remarks', 'details']);

        if ($amountCol && $dateCol) {
            $select = [];
            $select[] = $idCol ? "w.`{$idCol}` AS rec_id" : "'' AS rec_id";
            $select[] = $userCol ? "w.`{$userCol}` AS user_id" : "'' AS user_id";
            $select[] = "w.`{$amountCol}` AS amount";
            $select[] = "w.`{$dateCol}` AS date_time";
            $select[] = $typeCol ? "w.`{$typeCol}` AS txn_type" : "'' AS txn_type";
            $select[] = $refCol ? "w.`{$refCol}` AS reference_no" : "'' AS reference_no";
            $select[] = $noteCol ? "w.`{$noteCol}` AS note_text" : "'' AS note_text";
            $join = pos_user_join_select($conn, 'w', $userCol, $select);

            $sql = "SELECT " . implode(', ', $select) . "
                    FROM `{$walletTable}` w
                    {$join}
                    WHERE DATE(w.`{$dateCol}`) BETWEEN ? AND ?
                    ORDER BY w.`{$dateCol}` DESC";

            if ($st = $conn->prepare($sql)) {
                $st->bind_param('ss', $dateFrom, $dateTo);
                $st->execute();
                $res = $st->get_result();

                while ($row = $res->fetch_assoc()) {
                    $amount = (float)($row['amount'] ?? 0);
                    $type = strtolower(trim((string)($row['txn_type'] ?? '')));
                    $note = strtolower(trim((string)($row['note_text'] ?? '')));

                    $isLoad = false;
                    $isBalancePayment = false;

                    if ($amount > 0) {
                        if (
                            $type === '' ||
                            in_array($type, ['load', 'topup', 'top-up', 'cashin', 'cash-in', 'rfid load', 'rfid wallet load', 'wallet load'], true) ||
                            str_contains($note, 'load') ||
                            str_contains($note, 'topup') ||
                            str_contains($note, 'cash in') ||
                            str_contains($note, 'cash-in')
                        ) {
                            $isLoad = true;
                        }
                    }

                    if ($amount < 0) {
                        $isBalancePayment = true;
                    }

                    if (
                        in_array($type, ['payment', 'deduct', 'deduction', 'debit', 'purchase', 'rfid balance', 'wallet balance'], true) ||
                        str_contains($note, 'payment') ||
                        str_contains($note, 'deduct') ||
                        str_contains($note, 'rfid balance') ||
                        str_contains($note, 'wallet balance')
                    ) {
                        $isBalancePayment = true;
                    }

                    if (!$isLoad && !$isBalancePayment) continue;

                    $category = $isLoad ? 'rfid_load' : 'rfid_balance';
                    $name = trim((string)($row['full_name'] ?? ''));
                    if ($name === '') $name = trim((string)($row['username'] ?? ''));

                    pos_add_record($records, $category, [
                        'source' => $walletTable,
                        'id' => $row['rec_id'] ?? '',
                        'user_id' => $row['user_id'] ?? '',
                        'name' => $name,
                        'username' => $row['username'] ?? '',
                        'id_number' => $row['id_number'] ?? '',
                        'amount' => $amount,
                        'method' => $isLoad ? 'RFID Wallet Load' : 'RFID Balance',
                        'status' => '',
                        'reference' => $row['reference_no'] ?? '',
                        'date_time' => $row['date_time'] ?? '',
                        'note' => $row['note_text'] ?? '',
                    ]);
                }

                if ($res) $res->free();
                $st->close();
            }
        }
    }

    $search = strtolower(trim($search));

    if ($search !== '') {
        $records = array_values(array_filter($records, function ($r) use ($search) {
            $haystack = strtolower(
                ($r['category_label'] ?? '') . ' ' .
                ($r['source'] ?? '') . ' ' .
                ($r['id'] ?? '') . ' ' .
                ($r['user_id'] ?? '') . ' ' .
                ($r['name'] ?? '') . ' ' .
                ($r['username'] ?? '') . ' ' .
                ($r['id_number'] ?? '') . ' ' .
                ($r['method'] ?? '') . ' ' .
                ($r['reference'] ?? '') . ' ' .
                ($r['note'] ?? '')
            );

            return str_contains($haystack, $search);
        }));
    }

    usort($records, function ($a, $b) {
        return strtotime($b['date_time'] ?? '') <=> strtotime($a['date_time'] ?? '');
    });

    $totals = [
        'rfid_load' => 0.0,
        'gcash_qr' => 0.0,
        'rfid_balance' => 0.0,
    ];

    $counts = [
        'rfid_load' => 0,
        'gcash_qr' => 0,
        'rfid_balance' => 0,
    ];

    foreach ($records as $record) {
        $cat = $record['category'];

        if (isset($totals[$cat])) {
            $totals[$cat] += (float)$record['amount'];
            $counts[$cat]++;
        }
    }

    return [
        'records' => $records,
        'totals' => $totals,
        'counts' => $counts,
    ];
}

function pos_create_year_month_folder(string $baseDir, string $year, array $monthNames): bool {
    if (!preg_match('/^\d{4}$/', $year)) return false;

    $yearDir = $baseDir . '/' . $year;

    if (!is_dir($yearDir)) {
        if (!mkdir($yearDir, 0777, true)) return false;
    }

    foreach ($monthNames as $monthName) {
        $monthDir = $yearDir . '/' . $monthName;

        if (!is_dir($monthDir)) {
            if (!mkdir($monthDir, 0777, true)) return false;
        }
    }

    return true;
}

function pos_report_html(array $records, array $totals, array $counts, string $dateFrom, string $dateTo): string {
    $grouped = [
        'rfid_load' => [],
        'gcash_qr' => [],
        'rfid_balance' => [],
    ];

    foreach ($records as $record) {
        $grouped[$record['category']][] = $record;
    }

    $sections = '';

    foreach ($grouped as $category => $items) {
        $rows = '';

        if (!$items) {
            $rows = '<tr><td colspan="10" class="empty">No records.</td></tr>';
        } else {
            $i = 1;

            foreach ($items as $r) {
                $rows .= '
                <tr>
                    <td>' . $i++ . '</td>
                    <td>' . pos_h($r['date_time']) . '</td>
                    <td>' . pos_h($r['name'] ?: '—') . '</td>
                    <td>' . pos_h($r['id_number'] ?: '—') . '</td>
                    <td>' . pos_h($r['method'] ?: '—') . '</td>
                    <td>' . pos_h(pos_money($r['amount'])) . '</td>
                    <td>' . pos_h($r['reference'] ?: '—') . '</td>
                    <td>' . pos_h($r['status'] ?: '—') . '</td>
                    <td>' . pos_h($r['source'] ?: '—') . '</td>
                    <td>' . pos_h($r['note'] ?: '—') . '</td>
                </tr>';
            }
        }

        $sections .= '
        <h2>' . pos_h(pos_category_label($category)) . '</h2>
        <div class="summary">
            <strong>Total:</strong> ' . pos_h(pos_money($totals[$category] ?? 0)) . '
            &nbsp; | &nbsp;
            <strong>Records:</strong> ' . (int)($counts[$category] ?? 0) . '
        </div>
        <table>
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
            <tbody>' . $rows . '</tbody>
        </table>';
    }

    $grandTotal = (float)$totals['rfid_load'] + (float)$totals['gcash_qr'] + (float)$totals['rfid_balance'];

    return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>POS Report</title>
<style>
@page { size: landscape; margin: 0.45in; }
body { font-family: Arial, sans-serif; color: #111; background: #fff; margin: 0; }
.header { border-bottom: 4px solid #b30000; padding-bottom: 10px; margin-bottom: 14px; }
.brand { font-size: 24px; font-weight: bold; color: #b30000; margin: 0; }
.title { font-size: 20px; font-weight: bold; margin: 6px 0 0 0; }
.sub { color: #555; font-size: 12px; }
.summary { margin: 10px 0 12px 0; padding: 9px; background: #f3f3f3; border-left: 5px solid #b30000; font-size: 12px; }
h2 { margin: 18px 0 6px 0; font-size: 16px; color: #b30000; }
table { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 12px; }
th { background: #111; color: #fff; padding: 6px; border: 1px solid #333; text-align: left; }
td { padding: 5px; border: 1px solid #ccc; vertical-align: top; }
tr:nth-child(even) td { background: #f7f7f7; }
.empty { text-align: center; color: #777; padding: 18px; }
.footer { margin-top: 14px; font-size: 10px; color: #666; border-top: 1px solid #ccc; padding-top: 8px; }
</style>
</head>
<body>
<div class="header">
    <p class="brand">RJL Fitness</p>
    <p class="title">POS Payment Report</p>
    <div class="sub">Split into RFID Wallet Load, GCash QR, and RFID Balance</div>
</div>

<div class="summary">
    <strong>Date:</strong> ' . pos_h($dateFrom) . ($dateFrom !== $dateTo ? ' to ' . pos_h($dateTo) : '') . '<br>
    <strong>RFID Wallet Load:</strong> ' . pos_h(pos_money($totals['rfid_load'] ?? 0)) . '<br>
    <strong>GCash QR:</strong> ' . pos_h(pos_money($totals['gcash_qr'] ?? 0)) . '<br>
    <strong>RFID Balance:</strong> ' . pos_h(pos_money($totals['rfid_balance'] ?? 0)) . '<br>
    <strong>Grand Total:</strong> ' . pos_h(pos_money($grandTotal)) . '<br>
    <strong>Generated:</strong> ' . pos_h(date('Y-m-d h:i A')) . '
</div>

' . $sections . '

<div class="footer">Generated by RJL Fitness POS Storage.</div>
</body>
</html>';
}

function pos_save_report(mysqli $conn, string $date): array {
    if (!pos_valid_date($date)) {
        return ['ok' => false, 'message' => 'Invalid date.', 'file_url' => ''];
    }

    $monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];

    $timestamp = strtotime($date);
    $year = date('Y', $timestamp);
    $month = date('F', $timestamp);

    $baseDir = __DIR__ . '/pos_storage';

    if (!pos_create_year_month_folder($baseDir, $year, $monthNames)) {
        return ['ok' => false, 'message' => 'Failed to create POS storage folders.', 'file_url' => ''];
    }

    $data = pos_get_records($conn, $date, $date, '');
    $html = pos_report_html($data['records'], $data['totals'], $data['counts'], $date, $date);

    $fileName = 'pos_report_' . $date . '.doc';
    $filePath = $baseDir . '/' . $year . '/' . $month . '/' . $fileName;

    $saved = file_put_contents($filePath, $html);

    if ($saved === false) {
        return ['ok' => false, 'message' => 'Failed to save POS report.', 'file_url' => ''];
    }

    $fileUrl = 'pos_storage/'
        . rawurlencode($year) . '/'
        . rawurlencode($month) . '/'
        . rawurlencode($fileName);

    return ['ok' => true, 'message' => 'POS report saved successfully.', 'file_url' => $fileUrl];
}
