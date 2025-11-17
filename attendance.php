<?php
// FILE: attendance.php
// Shared attendance page for both members and staff/admin.

require_once 'auth.php';
require_once 'db.php';

$user_id = $_SESSION['user_id'] ?? 0;
$role    = $_SESSION['role'] ?? '';

if ($user_id <= 0) {
    die("Access denied.");
}

$isMember = ($role === 'member');
$isStaff  = ($role === 'trainer' || $role === 'admin');


// ===========================
// MEMBER VIEW (MY ATTENDANCE)
// ===========================
if ($isMember) {

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
    $res  = $stmt->get_result();
    $logs = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $total_visits = count($logs);
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>My Attendance</title>
        <style>
            :root {
                --bg-card: #181818;
                --accent-red: #e53935;
                --accent-red-dark: #b71c1c;
                --text-light: #f5f5f5;
                --text-muted: #aaaaaa;
                --border-soft: #2a2a33;
            }
            * {
                box-sizing: border-box;
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }
            body {
                margin: 0;
                background: #000;
                color: var(--text-light);
            }
            .wrapper {
                max-width: 1100px;
                margin: 40px auto;
                padding: 0 16px;
            }
            .card {
                background: var(--bg-card);
                border-radius: 16px;
                border: 1px solid var(--border-soft);
                padding: 24px 24px 28px;
                box-shadow: 0 16px 40px rgba(0,0,0,0.6);
            }
            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: baseline;
                margin-bottom: 18px;
                border-bottom: 1px solid var(--border-soft);
                padding-bottom: 10px;
            }
            .title {
                font-size: 1.6rem;
                font-weight: 600;
            }
            .tagline {
                font-size: 0.85rem;
                color: var(--accent-red);
                text-transform: uppercase;
                letter-spacing: 0.15em;
            }
            .pill {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 999px;
                font-size: 0.75rem;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                background: rgba(76, 175, 80, 0.15);
                color: #4caf50;
            }
            form.filters {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: flex-end;
                margin-bottom: 14px;
            }
            label {
                font-size: 0.8rem;
                color: var(--text-muted);
                text-transform: uppercase;
                letter-spacing: 0.08em;
                display: block;
                margin-bottom: 4px;
            }
            select {
                padding: 7px 10px;
                border-radius: 8px;
                border: 1px solid var(--border-soft);
                background: #101010;
                color: var(--text-light);
                outline: none;
            }
            select:focus {
                border-color: var(--accent-red);
                box-shadow: 0 0 0 1px rgba(229,57,53,0.3);
            }
            button {
                border: none;
                border-radius: 999px;
                padding: 8px 16px;
                font-size: 0.9rem;
                font-weight: 600;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                cursor: pointer;
                background: var(--accent-red);
                color: #fff;
                transition: background 0.15s ease, transform 0.1s ease;
            }
            button:hover {
                background: var(--accent-red-dark);
                transform: translateY(-1px);
            }
            .summary {
                font-size: 0.9rem;
                color: var(--text-muted);
                margin-bottom: 10px;
            }
            .summary strong {
                color: var(--accent-red);
            }
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.9rem;
                margin-top: 8px;
            }
            th, td {
                padding: 8px 10px;
                border-bottom: 1px solid var(--border-soft);
                text-align: left;
            }
            th {
                font-size: 0.78rem;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--text-muted);
            }
            .back-link {
                display: inline-block;
                margin-bottom: 12px;
                font-size: 0.85rem;
                color: #fff;
                text-decoration: none;
                border-radius: 999px;
                border: 1px solid var(--accent-red);
                padding: 6px 12px;
            }
            .back-link:hover {
                background: var(--accent-red);
            }
        </style>
    </head>
    <body>
    <div class="wrapper">
        <a href="home_member.php" class="back-link">&laquo; Back to Dashboard</a>

        <div class="card">
            <div class="card-header">
                <div>
                    <div class="tagline">RJL FITNESS · MEMBER</div>
                    <div class="title">My Attendance</div>
                </div>
                <span class="pill">RFID Logged</span>
            </div>

            <form method="get" class="filters">
                <div>
                    <label>Month</label>
                    <select name="month">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>>
                                <?= date('F', mktime(0,0,0,$m,1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
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
                <div>
                    <button type="submit">View</button>
                </div>
            </form>

            <p class="summary">
                Showing <strong><?= $total_visits ?></strong> visit<?= $total_visits === 1 ? '' : 's' ?>
                from <strong><?= htmlspecialchars($start_date) ?></strong>
                to <strong><?= htmlspecialchars($end_date) ?></strong>.
            </p>

            <table>
                <tr>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Time Out</th>
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
    <?php
    exit;
}


// ===============================
// STAFF / ADMIN VIEW (ALL USERS)
// ===============================
if ($isStaff) {

    $date = isset($_GET['date']) && $_GET['date'] !== '' ? $_GET['date'] : date('Y-m-d');

    $stmt = $conn->prepare("
        SELECT a.check_in, a.check_out, u.full_name, u.username
        FROM attendance a
        JOIN users u ON u.id = a.user_id
        WHERE DATE(a.check_in) = ?
        ORDER BY a.check_in ASC
    ");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $res = $stmt->get_result();
    $logs = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $total_checkins = count($logs);

    // compute unique members
    $usernames_seen = [];
    foreach ($logs as $row) {
        $uname = $row['username'];
        $usernames_seen[$uname] = true;
    }
    $unique_members = count($usernames_seen);
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Daily Attendance (All Members)</title>
        <style>
            :root {
                --bg-card:#181818;
                --accent-red:#e53935;
                --accent-red-dark:#b71c1c;
                --text-light:#f5f5f5;
                --text-muted:#aaaaaa;
                --border-soft:#2a2a33;
            }
            *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
            body{margin:0;background:#000;color:var(--text-light);}
            .wrapper{max-width:1100px;margin:40px auto;padding:0 16px;}
            .card{background:var(--bg-card);border-radius:16px;border:1px solid var(--border-soft);
                  padding:24px 24px 28px;box-shadow:0 16px 40px rgba(0,0,0,0.6);}
            .card-header{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:18px;
                         border-bottom:1px solid var(--border-soft);padding-bottom:10px;}
            .title{font-size:1.6rem;font-weight:600;}
            .tagline{font-size:0.85rem;color:var(--accent-red);text-transform:uppercase;letter-spacing:0.15em;}
            .pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:0.75rem;
                  text-transform:uppercase;letter-spacing:0.08em;background:rgba(229,57,53,0.12);color:var(--accent-red);}
            label{font-size:0.8rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.08em;display:block;margin-bottom:4px;}
            input{padding:7px 10px;border-radius:8px;border:1px solid var(--border-soft);background:#101010;color:var(--text-light);outline:none;}
            input:focus{border-color:var(--accent-red);box-shadow:0 0 0 1px rgba(229,57,53,0.3);}
            button{border:none;border-radius:999px;padding:8px 16px;font-size:0.9rem;font-weight:600;
                   letter-spacing:0.08em;text-transform:uppercase;cursor:pointer;background:var(--accent-red);color:#fff;
                   transition:background 0.15s ease,transform 0.1s ease;}
            button:hover{background:var(--accent-red-dark);transform:translateY(-1px);}
            .summary{margin-top:10px;font-size:0.9rem;color:var(--text-muted);}
            .summary strong{color:var(--accent-red);}
            table{width:100%;border-collapse:collapse;margin-top:10px;font-size:0.9rem;}
            th,td{padding:8px 10px;border-bottom:1px solid var(--border-soft);text-align:left;}
            th{text-transform:uppercase;letter-spacing:0.08em;font-size:0.78rem;color:var(--text-muted);}
        </style>
    </head>
    <body>
    <div class="wrapper">
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="tagline">RJL FITNESS · STAFF</div>
                    <div class="title">Daily Attendance (All Members)</div>
                </div>
                <span class="pill"><?= htmlspecialchars(strtoupper($role)) ?></span>
            </div>

            <form method="get">
                <label for="date">Select Date</label>
                <input type="date" id="date" name="date" value="<?= htmlspecialchars($date) ?>">
                <button type="submit">View</button>
            </form>

            <p class="summary">
                Total check-ins: <strong><?= $total_checkins ?></strong> ·
                Unique members: <strong><?= $unique_members ?></strong>
            </p>

            <table>
                <tr>
                    <th>Member</th>
                    <th>Username</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                </tr>
                <?php if (!$logs): ?>
                    <tr><td colspan="4">No attendance records for this day.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['full_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($log['username'] ?? '') ?></td>
                            <td><?= htmlspecialchars($log['check_in']) ?></td>
                            <td><?= htmlspecialchars($log['check_out'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// any other role falls through here
die("Access denied.");