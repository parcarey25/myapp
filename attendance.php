<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

$userId   = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'Member';
$role     = strtolower(trim($_SESSION['role'] ?? 'member'));

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");

    if (!$result) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();

    return $exists;
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    if ($result) {
        $result->free();
    }

    $stmt->close();

    return !empty($row) && (int)$row['total'] > 0;
}

function first_existing_column(mysqli $conn, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (column_exists($conn, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function format_date_value(?string $date): string
{
    if (!$date) {
        return '—';
    }

    $ts = strtotime($date);
    if ($ts === false) {
        return '—';
    }

    return date('M d, Y', $ts);
}

function format_time_value(?string $time): string
{
    if (!$time || trim($time) === '' || $time === '0000-00-00 00:00:00') {
        return '—';
    }

    $ts = strtotime($time);
    if ($ts === false) {
        return '—';
    }

    return date('h:i A', $ts);
}

function get_duration_text(?string $date, ?string $timeIn, ?string $timeOut): string
{
    if (!$timeIn || !$timeOut) {
        return '—';
    }

    $inTs = strtotime($timeIn);
    $outTs = strtotime($timeOut);

    if ($inTs === false || $outTs === false || $outTs < $inTs) {
        return '—';
    }

    $seconds = $outTs - $inTs;
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    return sprintf('%02dh %02dm', $hours, $minutes);
}

$dashboardLink = 'home_member.php';
if ($role === 'admin') {
    $dashboardLink = 'home_admin.php';
} elseif ($role === 'staff') {
    $dashboardLink = 'home_staff.php';
} elseif ($role === 'trainer') {
    $dashboardLink = file_exists(__DIR__ . '/home_trainer.php') ? 'home_trainer.php' : 'home_staff.php';
} elseif (!file_exists(__DIR__ . '/home_member.php')) {
    $dashboardLink = 'home.php';
}

$attendanceTable = null;
foreach (['attendance', 'attendances'] as $tbl) {
    if (table_exists($conn, $tbl)) {
        $attendanceTable = $tbl;
        break;
    }
}

$error = '';
$attendanceRows = [];
$filterDate = trim($_GET['filter_date'] ?? '');

$totalAttendance = 0;
$todayAttendance = 0;
$latestVisit = '—';

if (!$attendanceTable) {
    $error = 'Attendance table was not found in the database.';
} else {
    $userColumn = first_existing_column($conn, $attendanceTable, ['user_id', 'member_id']);
    $dateColumn = first_existing_column($conn, $attendanceTable, ['attendance_date', 'date', 'created_at']);
    $timeInColumn = first_existing_column($conn, $attendanceTable, ['time_in', 'check_in', 'timein']);
    $timeOutColumn = first_existing_column($conn, $attendanceTable, ['time_out', 'check_out', 'timeout']);

    if (!$userColumn || !$dateColumn) {
        $error = 'Attendance table is missing required columns.';
    } else {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $attendanceTable);
        $safeUserCol = preg_replace('/[^a-zA-Z0-9_]/', '', $userColumn);
        $safeDateCol = preg_replace('/[^a-zA-Z0-9_]/', '', $dateColumn);
        $safeTimeInCol = $timeInColumn ? preg_replace('/[^a-zA-Z0-9_]/', '', $timeInColumn) : null;
        $safeTimeOutCol = $timeOutColumn ? preg_replace('/[^a-zA-Z0-9_]/', '', $timeOutColumn) : null;

        $selectTimeIn = $safeTimeInCol ? "`{$safeTimeInCol}` AS time_in_val" : "NULL AS time_in_val";
        $selectTimeOut = $safeTimeOutCol ? "`{$safeTimeOutCol}` AS time_out_val" : "NULL AS time_out_val";

        $sql = "
            SELECT
                id,
                DATE(`{$safeDateCol}`) AS attendance_day,
                {$selectTimeIn},
                {$selectTimeOut}
            FROM `{$safeTable}`
            WHERE `{$safeUserCol}` = ?
        ";

        $types = 'i';
        $params = [$userId];

        if ($filterDate !== '') {
            $sql .= " AND DATE(`{$safeDateCol}`) = ? ";
            $types .= 's';
            $params[] = $filterDate;
        }

        $sql .= " ORDER BY `{$safeDateCol}` DESC, id DESC ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $error = 'Failed to load attendance records.';
        } else {
            $bindParams = [];
            $bindParams[] = $types;
            foreach ($params as $key => $value) {
                $bindParams[] = &$params[$key];
            }

            call_user_func_array([$stmt, 'bind_param'], $bindParams);
            $stmt->execute();

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $attendanceRows[] = $row;
            }

            $result->free();
            $stmt->close();
        }

        // total attendance
        $stmtTotal = $conn->prepare("
            SELECT COUNT(*) AS total_count
            FROM `{$safeTable}`
            WHERE `{$safeUserCol}` = ?
        ");
        if ($stmtTotal) {
            $stmtTotal->bind_param('i', $userId);
            $stmtTotal->execute();
            $resultTotal = $stmtTotal->get_result();
            $rowTotal = $resultTotal ? $resultTotal->fetch_assoc() : null;
            $totalAttendance = (int)($rowTotal['total_count'] ?? 0);

            if ($resultTotal) {
                $resultTotal->free();
            }
            $stmtTotal->close();
        }

        // today's attendance
        $today = date('Y-m-d');
        $stmtToday = $conn->prepare("
            SELECT COUNT(*) AS total_today
            FROM `{$safeTable}`
            WHERE `{$safeUserCol}` = ?
            AND DATE(`{$safeDateCol}`) = ?
        ");
        if ($stmtToday) {
            $stmtToday->bind_param('is', $userId, $today);
            $stmtToday->execute();
            $resultToday = $stmtToday->get_result();
            $rowToday = $resultToday ? $resultToday->fetch_assoc() : null;
            $todayAttendance = (int)($rowToday['total_today'] ?? 0);

            if ($resultToday) {
                $resultToday->free();
            }
            $stmtToday->close();
        }

        // latest visit
        $stmtLatest = $conn->prepare("
            SELECT DATE(`{$safeDateCol}`) AS latest_day
            FROM `{$safeTable}`
            WHERE `{$safeUserCol}` = ?
            ORDER BY `{$safeDateCol}` DESC, id DESC
            LIMIT 1
        ");
        if ($stmtLatest) {
            $stmtLatest->bind_param('i', $userId);
            $stmtLatest->execute();
            $resultLatest = $stmtLatest->get_result();
            $rowLatest = $resultLatest ? $resultLatest->fetch_assoc() : null;
            $latestVisit = !empty($rowLatest['latest_day']) ? format_date_value($rowLatest['latest_day']) : '—';

            if ($resultLatest) {
                $resultLatest->free();
            }
            $stmtLatest->close();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>My Attendance | RJL Fitness</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root{
            --red:#c00000;
            --red-bright:#ff2e2e;
            --bg:#060606;
            --panel:#121212;
            --panel-2:#191919;
            --line:rgba(255,255,255,.08);
            --text:#f5f5f5;
            --muted:#aaaaaa;
            --shadow:0 20px 45px rgba(0,0,0,.35);
            --radius:22px;
        }

        *{
            box-sizing:border-box;
        }

        body{
            margin:0;
            min-height:100vh;
            font-family:"Segoe UI", Arial, sans-serif;
            color:var(--text);
            background:
                radial-gradient(circle at top left, rgba(192,0,0,.20), transparent 30%),
                linear-gradient(135deg, #020202, #0a0a0a 55%, #040404);
        }

        a{
            text-decoration:none;
            color:inherit;
        }

        .topbar{
            height:72px;
            background:linear-gradient(90deg, #7b0000, #a30000 50%, #7b0000);
            border-bottom:1px solid rgba(255,255,255,.08);
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:0 24px;
            box-shadow:0 12px 24px rgba(0,0,0,.25);
        }

        .topbar-left{
            display:flex;
            align-items:center;
            gap:12px;
            min-width:0;
        }

        .topbar-left img{
            width:40px;
            height:40px;
            object-fit:cover;
            border-radius:10px;
            background:#111;
        }

        .brand-title{
            font-size:1.1rem;
            font-weight:900;
            letter-spacing:-.02em;
        }

        .brand-sub{
            font-size:.76rem;
            color:rgba(255,255,255,.82);
            letter-spacing:.15em;
            text-transform:uppercase;
        }

        .topbar-right{
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
            justify-content:flex-end;
        }

        .welcome{
            color:#fff;
            font-size:.95rem;
        }

        .top-btn{
            border:1px solid rgba(255,255,255,.18);
            background:rgba(255,255,255,.06);
            color:#fff;
            padding:9px 14px;
            border-radius:12px;
            font-size:.85rem;
            font-weight:800;
            transition:.18s ease;
        }

        .top-btn:hover{
            background:rgba(255,255,255,.14);
        }

        .top-btn.logout{
            background:rgba(0,0,0,.14);
        }

        .page-wrap{
            width:min(1180px, calc(100% - 28px));
            margin:26px auto 40px;
        }

        .hero{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:16px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }

        .hero h1{
            margin:0 0 8px;
            font-size:2.1rem;
            font-weight:950;
            letter-spacing:-.04em;
        }

        .hero p{
            margin:0;
            color:var(--muted);
            font-size:.98rem;
            max-width:720px;
        }

        .summary-grid{
            display:grid;
            grid-template-columns:repeat(3, 1fr);
            gap:14px;
            margin-bottom:18px;
        }

        .summary-card{
            background:linear-gradient(145deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
            border:1px solid var(--line);
            border-radius:var(--radius);
            padding:18px;
            box-shadow:var(--shadow);
        }

        .summary-card small{
            display:block;
            color:var(--muted);
            text-transform:uppercase;
            letter-spacing:.08em;
            font-size:.72rem;
            font-weight:900;
            margin-bottom:8px;
        }

        .summary-card strong{
            font-size:1.45rem;
            font-weight:950;
            display:block;
        }

        .panel{
            background:linear-gradient(145deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
            border:1px solid var(--line);
            border-radius:26px;
            box-shadow:var(--shadow);
            overflow:hidden;
        }

        .panel-header{
            padding:18px 20px;
            border-bottom:1px solid var(--line);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            flex-wrap:wrap;
        }

        .panel-title{
            font-size:1.08rem;
            font-weight:900;
        }

        .panel-sub{
            color:var(--muted);
            font-size:.9rem;
            margin-top:4px;
        }

        .filter-form{
            display:flex;
            gap:10px;
            align-items:end;
            flex-wrap:wrap;
        }

        .filter-group{
            display:flex;
            flex-direction:column;
            gap:6px;
        }

        .filter-group label{
            color:#ddd;
            font-size:.84rem;
            font-weight:800;
        }

        .filter-input{
            min-width:180px;
            height:44px;
            border-radius:12px;
            border:1px solid rgba(255,255,255,.10);
            background:#0e0e0e;
            color:#fff;
            padding:0 12px;
            outline:none;
            font-size:.95rem;
        }

        .filter-input:focus{
            border-color:rgba(255,46,46,.75);
            box-shadow:0 0 0 4px rgba(255,46,46,.12);
        }

        .btn-filter,
        .btn-clear{
            height:44px;
            border:none;
            border-radius:12px;
            padding:0 16px;
            cursor:pointer;
            font-weight:900;
            font-size:.9rem;
        }

        .btn-filter{
            background:linear-gradient(135deg, var(--red), var(--red-bright));
            color:#fff;
            box-shadow:0 12px 24px rgba(192,0,0,.28);
        }

        .btn-clear{
            background:#202020;
            color:#fff;
            border:1px solid rgba(255,255,255,.08);
        }

        .table-wrap{
            width:100%;
            overflow:auto;
            padding:0;
        }

        table{
            width:100%;
            border-collapse:collapse;
            min-width:760px;
        }

        thead th{
            text-align:left;
            font-size:.88rem;
            letter-spacing:.05em;
            text-transform:uppercase;
            color:#f2f2f2;
            background:rgba(255,255,255,.03);
            padding:14px 18px;
            border-bottom:1px solid var(--line);
        }

        tbody td{
            padding:16px 18px;
            border-bottom:1px solid rgba(255,255,255,.05);
            color:#f5f5f5;
            font-size:.97rem;
        }

        tbody tr:nth-child(even){
            background:rgba(255,255,255,.02);
        }

        tbody tr:hover{
            background:rgba(255,255,255,.04);
        }

        .number-cell{
            width:70px;
            color:#ddd;
            font-weight:800;
        }

        .duration-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:88px;
            padding:7px 12px;
            border-radius:999px;
            background:rgba(255,255,255,.07);
            border:1px solid rgba(255,255,255,.08);
            font-weight:800;
            font-size:.85rem;
        }

        .empty-state{
            padding:48px 20px;
            text-align:center;
        }

        .empty-icon{
            width:64px;
            height:64px;
            margin:0 auto 14px;
            border-radius:20px;
            display:grid;
            place-items:center;
            font-size:1.8rem;
            background:rgba(255,255,255,.06);
            border:1px solid rgba(255,255,255,.08);
        }

        .empty-title{
            font-size:1.08rem;
            font-weight:900;
            margin-bottom:6px;
        }

        .empty-text{
            color:var(--muted);
            max-width:460px;
            margin:0 auto;
            line-height:1.6;
        }

        .error-box{
            margin-bottom:18px;
            padding:16px 18px;
            border-radius:18px;
            background:rgba(255,46,46,.12);
            border:1px solid rgba(255,46,46,.28);
            color:#ffd8d8;
        }

        @media (max-width: 920px){
            .summary-grid{
                grid-template-columns:1fr;
            }
        }

        @media (max-width: 720px){
            .topbar{
                height:auto;
                padding:14px 16px;
                align-items:flex-start;
                flex-direction:column;
                gap:12px;
            }

            .topbar-right{
                width:100%;
                justify-content:flex-start;
            }

            .hero h1{
                font-size:1.7rem;
            }

            .panel-header{
                padding:16px;
            }

            .filter-input{
                min-width:100%;
            }

            .filter-form{
                width:100%;
            }

            .filter-group{
                width:100%;
            }
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="topbar-left">
        <img src="photo/logo.jpg" alt="RJL Fitness Logo">
        <div>
            <div class="brand-title">RJL Fitness</div>
            <div class="brand-sub">Member Attendance</div>
        </div>
    </div>

    <div class="topbar-right">
        <div class="welcome">Welcome, <strong><?= h($username) ?></strong></div>
        <a class="top-btn" href="<?= h($dashboardLink) ?>">Dashboard</a>
        <a class="top-btn logout" href="logout.php">Logout</a>
    </div>
</header>

<main class="page-wrap">
    <section class="hero">
        <div>
            <h1>My Attendance</h1>
            <p>This page shows only your saved attendance records. You can filter by date and review your time in, time out, and total duration.</p>
        </div>
    </section>

    <?php if ($error): ?>
        <div class="error-box"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="summary-grid">
        <div class="summary-card">
            <small>Total Attendance Records</small>
            <strong><?= (int)$totalAttendance ?></strong>
        </div>

        <div class="summary-card">
            <small>Today's Attendance</small>
            <strong><?= (int)$todayAttendance ?></strong>
        </div>

        <div class="summary-card">
            <small>Latest Visit</small>
            <strong><?= h($latestVisit) ?></strong>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title">Attendance History</div>
                <div class="panel-sub">Clean list of your attendance logs.</div>
            </div>

            <form method="get" class="filter-form">
                <div class="filter-group">
                    <label for="filter_date">Filter by Date</label>
                    <input
                        type="date"
                        id="filter_date"
                        name="filter_date"
                        class="filter-input"
                        value="<?= h($filterDate) ?>"
                    >
                </div>

                <button type="submit" class="btn-filter">Filter</button>

                <?php if ($filterDate !== ''): ?>
                    <a href="attendance.php" class="btn-clear">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (!$error && count($attendanceRows) > 0): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:72px;">#</th>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendanceRows as $index => $row): ?>
                            <tr>
                                <td class="number-cell"><?= $index + 1 ?></td>
                                <td><?= h(format_date_value($row['attendance_day'] ?? null)) ?></td>
                                <td><?= h(format_time_value($row['time_in_val'] ?? null)) ?></td>
                                <td><?= h(format_time_value($row['time_out_val'] ?? null)) ?></td>
                                <td>
                                    <span class="duration-badge">
                                        <?= h(get_duration_text(
                                            $row['attendance_day'] ?? null,
                                            $row['time_in_val'] ?? null,
                                            $row['time_out_val'] ?? null
                                        )) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (!$error): ?>
            <div class="empty-state">
                <div class="empty-icon">📅</div>
                <div class="empty-title">No attendance records found</div>
                <div class="empty-text">
                    <?php if ($filterDate !== ''): ?>
                        There are no attendance records for the selected date. Try another date or clear the filter.
                    <?php else: ?>
                        Your attendance records will appear here once staff saves your attendance.
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
</main>

</body>
</html>