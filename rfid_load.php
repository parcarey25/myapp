<?php
// rfid_load.php
// Staff/Admin: load wallet via RFID, show receipt, send email

if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/auth.php';

// Only staff + admin
$role = strtolower($_SESSION['role'] ?? '');
if (!in_array($role, ['staff','admin'], true)) {
    header('Location: home.php');
    exit;
}

require __DIR__ . '/db.php';
require __DIR__ . '/send_mail.php';

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Manila');
}

$errors      = [];
$success     = null;
$showReceipt = false;
$receiptData = null;

$rfid_uid   = trim($_POST['rfid_uid'] ?? '');
$amountStr  = trim($_POST['amount'] ?? '');

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Basic validation
    if ($rfid_uid === '') {
        $errors[] = 'Please tap or enter an RFID UID.';
    }

    if ($amountStr === '' || !is_numeric($amountStr) || (float)$amountStr <= 0) {
        $errors[] = 'Please enter a valid positive amount to load.';
    } else {
        $amount = (float)$amountStr;
    }

    if (!$errors) {
        // 1) Find member by RFID
        if ($st = $conn->prepare("
            SELECT id, full_name, username, email, id_number, role, wallet_balance
            FROM users
            WHERE rfid_uid = ?
            LIMIT 1
        ")) {
            $st->bind_param('s', $rfid_uid);
            $st->execute();
            $res = $st->get_result();
            $userRow = $res ? $res->fetch_assoc() : null;
            if ($res) $res->free();
            $st->close();
        } else {
            $errors[] = 'Database error (prepare select).';
            $userRow = null;
        }

        if (!$userRow) {
            $errors[] = 'No user found for this RFID card.';
        } else {
            $oldBalance = (float)($userRow['wallet_balance'] ?? 0);
            $newBalance = $oldBalance + $amount;

            $conn->begin_transaction();
            try {
                $userId   = (int)$userRow['id'];
                $staffId  = (int)($_SESSION['user_id'] ?? 0);
                $staffName = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Staff');
                $method   = 'RFID Load';
                $reference = 'Wallet load by '.$staffName;
                $loadedAt  = date('Y-m-d H:i:s');

                // 2) Update wallet_balance
                if ($up = $conn->prepare("
                    UPDATE users
                    SET wallet_balance = wallet_balance + ?
                    WHERE id = ?
                ")) {
                    $up->bind_param('di', $amount, $userId);
                    if (!$up->execute()) {
                        throw new Exception('Failed to update wallet balance.');
                    }
                    $up->close();
                } else {
                    throw new Exception('DB error updating wallet.');
                }

                // 3) Log into payments (optional, but useful)
                if ($p = $conn->prepare("
                    INSERT INTO payments (user_id, staff_id, amount, method, reference)
                    VALUES (?,?,?,?,?)
                ")) {
                    $p->bind_param('iidss', $userId, $staffId, $amount, $method, $reference);
                    if (!$p->execute()) {
                        throw new Exception('Failed to insert payment record.');
                    }
                    $p->close();
                } else {
                    throw new Exception('DB error inserting payment.');
                }

                $conn->commit();

                // 4) Build receipt data
                $receiptData = [
                    'full_name'    => $userRow['full_name'] ?: $userRow['username'],
                    'id_number'    => $userRow['id_number'] ?? '',
                    'role'         => $userRow['role'] ?? '',
                    'staff_name'   => $staffName,
                    'amount'       => $amount,
                    'old_balance'  => $oldBalance,
                    'new_balance'  => $newBalance,
                    'loaded_at'    => $loadedAt,
                ];
                $showReceipt = true;
                $success = 'Wallet loaded successfully.';

                // 5) Send email receipt (best effort)
                if (!empty($userRow['email']) && function_exists('sendWalletLoadReceiptEmail')) {
                    $emailResult = sendWalletLoadReceiptEmail(
                        $userRow['email'],
                        $receiptData['full_name'],
                        $receiptData
                    );
                    if (!$emailResult['ok']) {
                        @file_put_contents(
                            __DIR__.'/logs/mail.log',
                            '['.date('Y-m-d H:i:s')."] wallet load receipt email error: ".$emailResult['error'].PHP_EOL,
                            FILE_APPEND
                        );
                    }
                }

            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Failed to load wallet: '.$e->getMessage();
                $showReceipt = false;
                $receiptData = null;
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Load Wallet via RFID | RJL Fitness</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
:root{
  --brand:#b30000;
  --brand-light:#ff4b4b;
  --bg:#111;
  --panel:#181818;
  --line:#2a2a2a;
  --muted:#9ca3af;
}
body{
  background:#000;
  color:#f9fafb;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
.navbar{
  background:linear-gradient(90deg,#000,var(--brand));
}
.card{
  background:var(--panel);
  border:1px solid var(--line);
  border-radius:14px;
}
.form-control{
  background:#101010;
  border:1px solid #262626;
  color:#f9fafb;
}
.form-control:focus{
  border-color:var(--brand-light);
  box-shadow:0 0 0 1px rgba(255,75,75,.4);
}
.btn-danger{
  background:var(--brand);
  border:none;
}
.btn-danger:hover{
  background:var(--brand-light);
}
.small-muted{
  font-size:.85rem;
  color:var(--muted);
}

/* Receipt overlay (centered, covers page temporarily) */
.receipt-overlay{
  position:fixed;
  inset:0;
  display:flex;
  align-items:center;
  justify-content:center;
  z-index:5000;
}
.receipt-backdrop{
  position:absolute;
  inset:0;
  background:rgba(0,0,0,.65);
}
.receipt-modal{
  position:relative;
  z-index:5001;
  width:100%;
  max-width:420px;
  background:#141414;
  border-radius:16px;
  border:1px solid #333;
  padding:20px 22px;
  box-shadow:0 18px 45px rgba(0,0,0,.7);
  color:#f9fafb;
}
.receipt-modal h4{
  margin:0 0 4px;
}
.receipt-meta{
  font-size:.85rem;
  color:#9ca3af;
  margin-bottom:10px;
}
.receipt-row{
  display:flex;
  justify-content:space-between;
  font-size:.9rem;
  margin:4px 0;
}
.receipt-row span:first-child{
  color:#9ca3af;
}
.close-btn{
  position:absolute;
  right:10px;
  top:8px;
  background:transparent;
  border:none;
  color:#9ca3af;
  font-size:20px;
  cursor:pointer;
}
.close-btn:hover{
  color:#fff;
}
</style>
</head>
<body>

<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand" href="home.php">
    <img src="photo/logo.jpg" height="30" class="mr-2" alt="">RJL Fitness
  </a>
  <span class="navbar-text ml-auto small">
    Load Wallet via RFID
  </span>
</nav>

<?php if (!empty($showReceipt) && !empty($receiptData)): ?>
<!-- Centered receipt modal (covers page temporarily) -->
<div class="receipt-overlay" id="receiptOverlay">
  <div class="receipt-backdrop"></div>
  <div class="receipt-modal" id="receiptModal">
    <button type="button" class="close-btn" id="receiptCloseBtn" aria-label="Close receipt">&times;</button>

    <h4>Wallet Load Receipt</h4>
    <div class="receipt-meta">
      RJL Fitness • <?= htmlspecialchars($receiptData['loaded_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
    </div>

    <div class="receipt-row">
      <span>Full Name</span>
      <span><?= htmlspecialchars($receiptData['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="receipt-row">
      <span>ID Number</span>
      <span><?= htmlspecialchars($receiptData['id_number'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="receipt-row">
      <span>Role</span>
      <span><?= htmlspecialchars($receiptData['role'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="receipt-row">
      <span>Staff</span>
      <span><?= htmlspecialchars($receiptData['staff_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="receipt-row">
      <span>Amount Loaded</span>
      <span>₱<?= number_format((float)($receiptData['amount'] ?? 0), 2) ?></span>
    </div>
    <div class="receipt-row">
      <span>Old Wallet Balance</span>
      <span>₱<?= number_format((float)($receiptData['old_balance'] ?? 0), 2) ?></span>
    </div>
    <div class="receipt-row">
      <span>New Wallet Balance</span>
      <span>₱<?= number_format((float)($receiptData['new_balance'] ?? 0), 2) ?></span>
    </div>

    <hr style="border-color:#333;">

    <button type="button" class="btn btn-danger btn-block" id="printReceiptBtn">
      Print Receipt
    </button>
  </div>
</div>
<?php endif; ?>

<div class="container py-4">
  <div class="card p-3">
    <h4 class="mb-3">Load Wallet via RFID</h4>

    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert alert-danger mb-3">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="form-group">
        <label>RFID UID</label>
        <input type="text" name="rfid_uid" class="form-control"
               value="<?= htmlspecialchars($rfid_uid, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Tap card or enter RFID UID">
        <small class="small-muted">Swiping the RFID card should fill this automatically (depending on your reader setup).</small>
      </div>

      <div class="form-group">
        <label>Amount to Load (₱)</label>
        <input type="number" step="0.01" min="1" name="amount" class="form-control"
               value="<?= htmlspecialchars($amountStr, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Enter amount to load">
      </div>

      <button type="submit" class="btn btn-danger">Load Wallet</button>
    </form>
  </div>
</div>

<script>
(function(){
  const overlay  = document.getElementById('receiptOverlay');
  const closeBtn = document.getElementById('receiptCloseBtn');
  const printBtn = document.getElementById('printReceiptBtn');

  function closeReceipt(){
    if (!overlay) return;
    overlay.style.display = 'none';
  }

  if (closeBtn){
    closeBtn.addEventListener('click', function(e){
      e.preventDefault();
      closeReceipt();
    });
  }

  if (overlay){
    overlay.addEventListener('click', function(e){
      if (e.target === overlay){
        closeReceipt();
      }
    });
  }

  if (printBtn){
    printBtn.addEventListener('click', function(e){
      e.preventDefault();
      window.print();
    });
  }
})();
</script>

</body>
</html>