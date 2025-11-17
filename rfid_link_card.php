<?php
// FILE: rfid_link_card.php
require_once 'auth.php';
require_once 'db.php';
require_once 'wallet_helpers.php';

if (!in_array($_SESSION['role'] ?? '', ['staff','admin'])) {
    die("Access denied.");
}

$message = '';
$is_ok   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = intval($_POST['user_id'] ?? 0);
    $rfid_uid = trim($_POST['rfid_uid'] ?? '');

    if ($user_id <= 0 || $rfid_uid === '') {
        $message = "Please select a member and tap a card.";
    } else {
        // check if card already linked
        $sql = "SELECT id, full_name FROM users WHERE rfid_uid = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $rfid_uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = $res->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $message = "This card is already linked to: " . htmlspecialchars($existing['full_name']);
        } else {
            $sql = "UPDATE users SET rfid_uid = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('si', $rfid_uid, $user_id);
            if ($stmt->execute()) {
                $message = "RFID card linked successfully.";
                $is_ok = true;
            } else {
                $message = "Error linking card.";
            }
            $stmt->close();
        }
    }
}

// load members
$users = [];
$res = $conn->query("SELECT id, full_name, email FROM users WHERE role = 'member' ORDER BY full_name ASC");
while ($row = $res->fetch_assoc()) {
    $users[] = $row;
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Link RFID Card</title>
    <?php // THEME ?>
    <style>
        /* paste THEME CSS from above here */
        :root { --bg-dark:#0c0c0f;--bg-card:#18181f;--accent-red:#e53935;--accent-red-dark:#b71c1c;--text-light:#f5f5f5;--text-muted:#aaaaaa;--border-soft:#2a2a33;}
        *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
        body{margin:0;background:radial-gradient(circle at top,#1b1b22 0,#050509 50%,#000 100%);color:var(--text-light);}
        .page-wrapper{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 12px;}
        .card{width:100%;max-width:900px;background:var(--bg-card);border-radius:16px;border:1px solid var(--border-soft);padding:24px 28px;box-shadow:0 16px 40px rgba(0,0,0,0.6);}
        .card-header{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:18px;border-bottom:1px solid var(--border-soft);padding-bottom:10px;}
        h1{margin:0;font-size:1.6rem;letter-spacing:0.03em;text-transform:uppercase;}
        .tagline{font-size:0.85rem;color:var(--accent-red);text-transform:uppercase;letter-spacing:0.15em;}
        .status-message{margin-bottom:16px;padding:10px 12px;border-radius:8px;font-size:0.95rem;}
        .status-ok{background:rgba(76,175,80,0.1);border:1px solid #4caf50;}
        .status-error{background:rgba(229,57,53,0.12);border:1px solid var(--accent-red);}
        label{font-size:0.9rem;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);}
        input,select{width:100%;padding:9px 11px;border-radius:8px;border:1px solid var(--border-soft);background:#101018;color:var(--text-light);font-size:0.95rem;outline:none;margin-bottom:12px;}
        input:focus,select:focus{border-color:var(--accent-red);box-shadow:0 0 0 1px rgba(229,57,53,0.3);}
        button{border:none;border-radius:999px;padding:10px 18px;font-size:0.95rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;cursor:pointer;background:var(--accent-red);color:#fff;transition:background 0.15s ease,transform 0.1s ease;}
        button:hover{background:var(--accent-red-dark);transform:translateY(-1px);}
        button:active{transform:translateY(0);}
        .hint{font-size:0.8rem;color:var(--text-muted);margin-top:-6px;margin-bottom:10px;}
        .pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.08em;}
        .pill-red{background:rgba(229,57,53,0.12);color:var(--accent-red);}
        .flex-row{display:flex;gap:12px;flex-wrap:wrap;}
        .flex-2{flex:2;min-width:200px;}
        .flex-1{flex:1;min-width:160px;}
    </style>
</head>
<body>
<div class="page-wrapper">
    <div class="card">
        <div class="card-header">
            <div>
                <div class="tagline">RJL FITNESS · RFID</div>
                <h1>Link Member Card</h1>
            </div>
            <span class="pill pill-red">Staff / Admin</span>
        </div>

        <?php if ($message): ?>
            <div class="status-message <?= $is_ok ? 'status-ok' : 'status-error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="flex-row">
                <div class="flex-2">
                    <label>Member</label>
                    <select name="user_id" required>
                        <option value="">-- Select member --</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>">
                                <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-1">
                    <label>RFID Card ID</label>
                    <input type="text" name="rfid_uid" id="rfid_uid" required autocomplete="off">
                    <div class="hint">Click here, then tap the card on the reader.</div>
                </div>
            </div>

            <button type="submit">Link Card</button>
        </form>
    </div>
</div>

<script>
document.getElementById('rfid_uid').focus();
</script>
</body>
</html>