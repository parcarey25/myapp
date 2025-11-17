a<?php
require_once 'auth.php';
require_once 'wallet_helpers.php';
global $conn;

if (!in_array($_SESSION['role'] ?? '', ['staff','admin'])) {
    die("Access denied.");
}

$message = '';
$is_ok   = false;

// handle tap
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rfid_uid = trim($_POST['rfid_uid'] ?? '');

    if ($rfid_uid === '') {
        $message = "Please tap a card.";
    } else {
        $user = find_user_by_rfid($rfid_uid);

        if (!$user) {
            $message = "No member found for this card.";
        } else {
            $user_id = (int)$user['id'];

            // get last record
            $stmt = $conn->prepare("SELECT id, check_in, check_out FROM attendance WHERE user_id = ? ORDER BY check_in DESC LIMIT 1");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $last = $res->fetch_assoc();
            $stmt->close();

            $now = date('Y-m-d H:i:s');

            if ($last && $last['check_out'] === null) {
                // time-out
                $stmt = $conn->prepare("UPDATE attendance SET check_out = ? WHERE id = ?");
                $stmt->bind_param('si', $now, $last['id']);
                if ($stmt->execute()) {
                    $message = "Time-out recorded for " . htmlspecialchars($user['full_name']);
                    $is_ok = true;
                } else {
                    $message = "Error saving time-out.";
                }
                $stmt->close();
            } else {
                // time-in
                $stmt = $conn->prepare("INSERT INTO attendance (user_id, check_in) VALUES (?, ?)");
                $stmt->bind_param('is', $user_id, $now);
                if ($stmt->execute()) {
                    $message = "Time-in recorded for " . htmlspecialchars($user['full_name']);
                    $is_ok = true;
                } else {
                    $message = "Error saving time-in.";
                }
                $stmt->close();
            }
        }
    }
}

// recent logs
$logs = [];
$result = $conn->query("
    SELECT a.*, u.full_name
    FROM attendance a
    JOIN users u ON u.id = a.user_id
    ORDER BY a.check_in DESC
    LIMIT 20
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>RFID Attendance</title>
    <!-- paste CSS here -->
</head>
<body>
<div class="page-wrapper">
    <div class="card">
        <div class="card-header">
            <div>
                <div class="tagline">RJL FITNESS · RFID</div>
                <h1>Attendance Scanner</h1>
            </div>
            <span class="pill pill-red">Staff / Kiosk</span>
        </div>

        <?php if ($message): ?>
            <div class="status-message <?= $is_ok ? 'status-ok' : 'status-error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php else: ?>
            <div class="status-message status-ok">
                Tap a card to record <strong>Time-in</strong> or <strong>Time-out</strong>.
            </div>
        <?php endif; ?>

        <form method="post">
            <label>Tap RFID Card</label>
            <input type="text" name="rfid_uid" id="rfid_uid" autocomplete="off" required>
            <div class="hint">Keep this page open and let members tap their cards.</div>
            <button type="submit">Submit</button>
        </form>

        <h2 style="margin-top:20px;">Recent Records</h2>
        <table>
            <tr>
                <th>Member</th>
                <th>Time-in</th>
                <th>Time-out</th>
            </tr>
            <?php if (!$logs): ?>
            <tr><td colspan="3">No attendance records yet.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= htmlspecialchars($log['full_name']) ?></td>
                        <td><?= htmlspecialchars($log['check_in']) ?></td>
                        <td><?= htmlspecialchars($log['check_out'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>
</div>

<script>
document.getElementById('rfid_uid').focus();
</script>
</body>
</html>