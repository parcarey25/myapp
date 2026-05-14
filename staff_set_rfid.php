<?php
// staff_set_rfid.php
// Staff/Admin page to assign an RFID card UID to a member account.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is missing. Please check db.php.');
}

$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($role, ['staff', 'admin'], true)) {
    header('Location: home.php');
    exit;
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function has_table_column(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;

    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $exists;
}

function user_select_columns(mysqli $conn): array
{
    $wanted = ['id', 'full_name', 'username', 'email', 'id_number', 'role', 'rfid_uid', 'created_at'];
    $columns = [];

    foreach ($wanted as $column) {
        if ($column === 'id' || has_table_column($conn, 'users', $column)) {
            $columns[] = $column;
        }
    }

    return array_values(array_unique($columns));
}

function select_sql_from_columns(array $columns): string
{
    $safe = [];
    foreach ($columns as $column) {
        $safe[] = '`' . str_replace('`', '', $column) . '`';
    }
    return implode(', ', $safe);
}

function find_member_matches(mysqli $conn, string $search): array
{
    $search = trim($search);
    if ($search === '') {
        return [];
    }

    $columns = user_select_columns($conn);
    $select = select_sql_from_columns($columns);

    $whereParts = [];
    $params = [];
    $types = '';

    if (has_table_column($conn, 'users', 'role')) {
        $whereParts[] = "LOWER(TRIM(`role`)) = 'member'";
    }

    $exactColumns = ['email', 'full_name', 'username', 'id_number'];
    $searchParts = [];

    foreach ($exactColumns as $column) {
        if (has_table_column($conn, 'users', $column)) {
            $searchParts[] = "`{$column}` = ?";
            $params[] = $search;
            $types .= 's';
        }
    }

    if (ctype_digit($search)) {
        $searchParts[] = '`id` = ?';
        $params[] = (int)$search;
        $types .= 'i';
    }

    foreach (['email', 'full_name', 'username'] as $column) {
        if (has_table_column($conn, 'users', $column)) {
            $searchParts[] = "`{$column}` LIKE ?";
            $params[] = '%' . $search . '%';
            $types .= 's';
        }
    }

    if (!$searchParts) {
        return [];
    }

    $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';

    $sql = "SELECT {$select} FROM `users` WHERE " . implode(' AND ', $whereParts) . " ORDER BY `id` DESC LIMIT 20";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    $stmt->close();
    return $rows;
}

function get_member_by_id(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $columns = user_select_columns($conn);
    $select = select_sql_from_columns($columns);

    $where = '`id` = ?';
    if (has_table_column($conn, 'users', 'role')) {
        $where .= " AND LOWER(TRIM(`role`)) = 'member'";
    }

    $stmt = $conn->prepare("SELECT {$select} FROM `users` WHERE {$where} LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $row ?: null;
}

function find_duplicate_rfid(mysqli $conn, string $rfidUid, int $exceptUserId): ?array
{
    if (!has_table_column($conn, 'users', 'rfid_uid')) {
        return null;
    }

    $columns = user_select_columns($conn);
    $select = select_sql_from_columns($columns);

    $stmt = $conn->prepare("SELECT {$select} FROM `users` WHERE `rfid_uid` = ? AND `id` <> ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('si', $rfidUid, $exceptUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $row ?: null;
}

function assign_rfid_to_user(mysqli $conn, int $userId, string $rfidUid): bool
{
    $stmt = $conn->prepare("UPDATE `users` SET `rfid_uid` = ? WHERE `id` = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $rfidUid, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function display_name_from_user(array $user): string
{
    foreach (['full_name', 'username', 'email', 'id_number'] as $field) {
        if (!empty($user[$field])) {
            return (string)$user[$field];
        }
    }
    return 'User #' . (string)($user['id'] ?? '');
}

function get_recent_members_without_rfid(mysqli $conn): array
{
    if (!has_table_column($conn, 'users', 'rfid_uid')) {
        return [];
    }

    $columns = user_select_columns($conn);
    $select = select_sql_from_columns($columns);

    $where = "(`rfid_uid` IS NULL OR TRIM(`rfid_uid`) = '')";
    if (has_table_column($conn, 'users', 'role')) {
        $where .= " AND LOWER(TRIM(`role`)) = 'member'";
    }

    $order = has_table_column($conn, 'users', 'created_at') ? '`created_at` DESC' : '`id` DESC';
    $sql = "SELECT {$select} FROM `users` WHERE {$where} ORDER BY {$order} LIMIT 10";

    $result = $conn->query($sql);
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    return $rows;
}

$rfidColumnReady = has_table_column($conn, 'users', 'rfid_uid');
$success = '';
$error = '';
$matches = [];
$searchValue = trim($_POST['member_search'] ?? '');
$rfidValue = strtoupper(trim($_POST['rfid_uid'] ?? ''));
$selectedUserId = (int)($_POST['selected_user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$rfidColumnReady) {
        $error = 'The users table does not have rfid_uid column.';
    } elseif ($rfidValue === '') {
        $error = 'Please enter or scan the RFID card number.';
    } elseif (strlen($rfidValue) > 100) {
        $error = 'RFID card number is too long.';
    } else {
        $targetUser = null;

        if ($selectedUserId > 0) {
            $targetUser = get_member_by_id($conn, $selectedUserId);
            if (!$targetUser) {
                $error = 'Selected member was not found.';
            }
        } else {
            if ($searchValue === '') {
                $error = 'Please enter the member full name, email, username, ID number, or user ID.';
            } else {
                $matches = find_member_matches($conn, $searchValue);

                if (count($matches) === 0) {
                    $error = 'No member found. Please check the full name or email.';
                } elseif (count($matches) === 1) {
                    $targetUser = $matches[0];
                } else {
                    $error = 'Multiple members found. Please choose the correct member below.';
                }
            }
        }

        if (!$error && $targetUser) {
            $targetUserId = (int)$targetUser['id'];
            $duplicate = find_duplicate_rfid($conn, $rfidValue, $targetUserId);

            if ($duplicate) {
                $error = 'This RFID number is already assigned to ' . display_name_from_user($duplicate) . '.';
            } else {
                if (assign_rfid_to_user($conn, $targetUserId, $rfidValue)) {
                    $success = 'RFID ' . $rfidValue . ' was saved for ' . display_name_from_user($targetUser) . '.';
                    $searchValue = '';
                    $rfidValue = '';
                    $selectedUserId = 0;
                    $matches = [];
                } else {
                    $error = 'Failed to save RFID. Please try again.';
                }
            }
        }
    }
}

$recentMembers = get_recent_members_without_rfid($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set RFID Card | RJL Fitness</title>
    <style>
        :root {
            --bg: #0d0d0f;
            --panel: #151518;
            --panel-2: #1d1d22;
            --border: rgba(255, 255, 255, 0.12);
            --text: #ffffff;
            --muted: #b8c0cc;
            --red: #d60000;
            --red-2: #ef3340;
            --yellow: #ffc107;
            --green: #22c55e;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(214, 0, 0, 0.22), transparent 30%),
                linear-gradient(180deg, #09090a 0%, #111113 100%);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .topbar {
            min-height: 70px;
            padding: 14px 28px;
            background: linear-gradient(90deg, #050505 0%, #520000 55%, #d60000 100%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 23px;
            font-weight: 800;
            letter-spacing: 0.2px;
        }

        .brand img {
            width: 48px;
            height: 48px;
            object-fit: contain;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
        }

        .nav-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn,
        button {
            border: 1px solid rgba(255,255,255,0.18);
            background: var(--red);
            color: #fff;
            padding: 10px 15px;
            border-radius: 6px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: 0.18s ease;
        }

        .btn:hover,
        button:hover {
            transform: translateY(-1px);
            background: #ff1b1b;
        }

        .btn.outline {
            background: rgba(0,0,0,0.25);
            border-color: rgba(255,255,255,0.55);
        }

        .container {
            width: min(1150px, calc(100% - 32px));
            margin: 34px auto;
        }

        .header-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-end;
            margin-bottom: 22px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: clamp(28px, 4vw, 42px);
        }

        .subtitle {
            color: var(--muted);
            margin: 0;
            line-height: 1.5;
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(320px, 0.9fr);
            gap: 20px;
            align-items: start;
        }

        .card {
            background: rgba(21, 21, 24, 0.96);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.38);
            padding: 22px;
            overflow: hidden;
        }

        .card h2 {
            margin: 0 0 16px;
            font-size: 22px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #edf2f7;
            font-weight: 700;
        }

        input[type="text"] {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.16);
            border-radius: 10px;
            padding: 13px 14px;
            font-size: 16px;
            background: #0f0f12;
            color: #fff;
            outline: none;
        }

        input[type="text"]:focus {
            border-color: var(--red-2);
            box-shadow: 0 0 0 3px rgba(239, 51, 64, 0.18);
        }

        .hint {
            margin-top: 7px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.45;
        }

        .alert {
            padding: 13px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            line-height: 1.45;
            border: 1px solid transparent;
        }

        .alert.success {
            color: #eafff1;
            background: rgba(34, 197, 94, 0.16);
            border-color: rgba(34, 197, 94, 0.42);
        }

        .alert.error {
            color: #fff3f3;
            background: rgba(239, 51, 64, 0.16);
            border-color: rgba(239, 51, 64, 0.42);
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 680px;
            background: #16171b;
        }

        th,
        td {
            padding: 12px 13px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.09);
            vertical-align: middle;
        }

        th {
            background: #252931;
            color: #ffffff;
            font-size: 14px;
        }

        tr:hover td {
            background: rgba(255,255,255,0.035);
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 999px;
            background: rgba(255, 193, 7, 0.16);
            color: #ffd66b;
            border: 1px solid rgba(255, 193, 7, 0.32);
            font-size: 12px;
            font-weight: 800;
        }

        .badge.ok {
            background: rgba(34, 197, 94, 0.16);
            color: #8ff0ad;
            border-color: rgba(34, 197, 94, 0.35);
        }

        .small-form {
            display: inline;
        }

        .small-form button {
            padding: 8px 10px;
            font-size: 13px;
            white-space: nowrap;
        }

        .empty {
            color: var(--muted);
            padding: 16px;
            background: rgba(255,255,255,0.04);
            border-radius: 12px;
            border: 1px dashed rgba(255,255,255,0.16);
        }

        .scan-box {
            margin-top: 18px;
            padding: 14px;
            border-radius: 13px;
            border: 1px solid rgba(255,255,255,0.11);
            background: #101013;
        }

        .scan-box strong {
            display: block;
            margin-bottom: 7px;
        }

        @media (max-width: 900px) {
            .grid,
            .header-row {
                grid-template-columns: 1fr;
                display: block;
            }

            .card + .card {
                margin-top: 18px;
            }

            .topbar {
                padding: 12px 16px;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">
            <img src="photo/logo.jpg" alt="RJL Fitness">
            <span>RJL Fitness</span>
        </div>
        <div class="nav-actions">
            <a class="btn outline" href="home_staff.php">Dashboard</a>
            <a class="btn outline" href="id_rfid_card.php">RFID Card</a>
            <a class="btn" href="logout.php">Logout</a>
        </div>
    </header>

    <main class="container">
        <div class="header-row">
            <div>
                <h1>Set Member RFID</h1>
                <p class="subtitle">Search a new member by full name or Gmail/email, then scan or type the RFID card number. Press Enter to save.</p>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!$rfidColumnReady): ?>
            <div class="alert error">
                Missing column: users.rfid_uid. Add it first in phpMyAdmin:
                <br><br>
                <code>ALTER TABLE users ADD COLUMN rfid_uid VARCHAR(100) NULL;</code>
            </div>
        <?php endif; ?>

        <div class="grid">
            <section class="card">
                <h2>Assign RFID Card Number</h2>

                <form method="POST" autocomplete="off">
                    <div class="form-group">
                        <label for="member_search">Full Name or Gmail / Email</label>
                        <input
                            type="text"
                            id="member_search"
                            name="member_search"
                            value="<?= e($searchValue) ?>"
                            placeholder="Example: Juan Dela Cruz or juan@gmail.com"
                            required
                        >
                        <div class="hint">You can also search by username, member ID number, or user ID.</div>
                    </div>

                    <div class="form-group">
                        <label for="rfid_uid">RFID Card Number</label>
                        <input
                            type="text"
                            id="rfid_uid"
                            name="rfid_uid"
                            value="<?= e($rfidValue) ?>"
                            placeholder="Tap/scan card here or type RFID UID"
                            required
                            autofocus
                        >
                        <div class="hint">Most RFID readers type the card number automatically and press Enter. This form will save when Enter is sent.</div>
                    </div>

                    <button type="submit">Save RFID to User</button>
                </form>

                <div class="scan-box">
                    <strong>How to use</strong>
                    <div class="hint">
                        1. Type the member's full name or Gmail/email.<br>
                        2. Click the RFID field.<br>
                        3. Tap the card on the RFID reader or type the UID.<br>
                        4. Press Enter or click Save.
                    </div>
                </div>
            </section>

            <section class="card">
                <h2>Recent Members Without RFID</h2>

                <?php if (!$recentMembers): ?>
                    <div class="empty">No member without RFID found.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>ID No.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentMembers as $member): ?>
                                    <tr>
                                        <td><?= e($member['id'] ?? '') ?></td>
                                        <td><?= e($member['full_name'] ?? $member['username'] ?? '') ?></td>
                                        <td><?= e($member['email'] ?? '') ?></td>
                                        <td><?= e($member['id_number'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <?php if (count($matches) > 1): ?>
            <section class="card" style="margin-top:20px;">
                <h2>Choose Correct Member</h2>
                <p class="subtitle" style="margin-bottom:14px;">Your search found more than one member. Click Assign on the correct row.</p>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>ID No.</th>
                                <th>Current RFID</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($matches as $member): ?>
                                <tr>
                                    <td><?= e($member['id'] ?? '') ?></td>
                                    <td><?= e($member['full_name'] ?? $member['username'] ?? '') ?></td>
                                    <td><?= e($member['email'] ?? '') ?></td>
                                    <td><?= e($member['id_number'] ?? '') ?></td>
                                    <td>
                                        <?php if (!empty($member['rfid_uid'])): ?>
                                            <span class="badge ok"><?= e($member['rfid_uid']) ?></span>
                                        <?php else: ?>
                                            <span class="badge">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form class="small-form" method="POST">
                                            <input type="hidden" name="selected_user_id" value="<?= e($member['id'] ?? '') ?>">
                                            <input type="hidden" name="member_search" value="<?= e($searchValue) ?>">
                                            <input type="hidden" name="rfid_uid" value="<?= e($rfidValue) ?>">
                                            <button type="submit">Assign</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <script>
        const rfidInput = document.getElementById('rfid_uid');
        if (rfidInput) {
            rfidInput.addEventListener('input', function () {
                this.value = this.value.trim().toUpperCase();
            });
        }
    </script>
</body>
</html>
