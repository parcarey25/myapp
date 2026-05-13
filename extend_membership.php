<?php 
// extend_membership.php
// Staff/Admin: extend membership using RFID wallet + show receipt + send email

if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__.'/auth.php';

// Only staff + admin
$role = strtolower($_SESSION['role'] ?? '');
if (!in_array($role, ['staff','admin'], true)) {
    header('Location: home.php');
    exit;
}

require __DIR__.'/db.php';
require __DIR__.'/send_mail.php';

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Manila');
}

/**
 * Small helper: check if a column exists in a table.
 * This prevents "Unknown column" errors if DB isn’t updated yet.
 */
function has_column(mysqli $conn, string $table, string $column): bool {
    $sql = "
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = ?
        AND COLUMN_NAME  = ?
      LIMIT 1
    ";
    if (!$st = $conn->prepare($sql)) return false;
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $res = $st->get_result();
    $ok  = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $st->close();
    return $ok;
}

// detect columns once
$HAS_MEMTYPE_COL   = has_column($conn, 'users', 'membership_type');
$HAS_SESSION_COL   = has_column($conn, 'users', 'trainer_sessions_remaining');

$errors       = [];
$success      = null;
$showReceipt  = false;
$receiptData  = null;

// ---------------- MEMBERSHIP PLANS CONFIG ----------------
// Each plan has: label, amount, interval (DateInterval spec), membership_type, sessions_add
$plansConfig = [
    'bodybuilding' => [
        'bb_day' => [
            'label'    => '1 Day Pass · ₱60.00 · 1 day',
            'amount'   => 60.00,
            'interval' => 'P1D',
            'membership_type' => 'bodybuilding_without_trainer',
            'sessions_add'    => null,
        ],
        'bb_month' => [
            'label'    => '1 Month Pass · ₱550.00 · 1 month',
            'amount'   => 550.00,
            'interval' => 'P1M',
            'membership_type' => 'bodybuilding_without_trainer',
            'sessions_add'    => null,
        ],
        'bb_w trainor' => [
            'label'    => '1 Month Pass · ₱3000.00 (with trainor) · 1 month',
            'amount'   => 3000.00,
            'interval' => 'P1M',
            'membership_type' => 'bodybuilding_with_trainer',
            'sessions_add'    => null,
        ],
    ],
    'zumba' => [
        'z_day' => [
            'label'    => '1 Day Pass · ₱100.00 · 1 day',
            'amount'   => 100.00,
            'interval' => 'P1D',
            'membership_type' => 'zumba',
            'sessions_add'    => null,
        ],
        'z_month' => [
            'label'    => '1 Month Pass · ₱1000.00 · 1 month',
            'amount'   => 1000.00,
            'interval' => 'P1M',
            'membership_type' => 'zumba',
            'sessions_add'    => null,
        ],
    ],
    'boxing' => [
        'box_10' => [
            'label'    => '₱2850.00 · 1 month all access + (10 session with personal trainor)',
            'amount'   => 2850.00,
            'interval' => 'P1M',
            'membership_type' => 'boxing_with_trainer',
            'sessions_add'    => 10,
        ],
        'box_no trainor' => [
            'label'    => '₱850.00 · 1 month all access',
            'amount'   => 850.00,
            'interval' => 'P1M',
            'membership_type' => 'boxing_without_trainer',
            'sessions_add'    => null,
        ],
        'box_x 10' => [
            'label'    => '₱2000.00 · 10 Session with personal trainor',
            'amount'   => 2000.00,
            'interval' => 'P1M',
            'membership_type' => 'boxing_with_trainer',
            'sessions_add'    => 10,
        ],
    ],
    'muay_thai' => [
        'mt_10' => [
            'label'    => '₱2850.00 · 1 month all access + (10 Session with personal trainor)',
            'amount'   => 2850.00,
            'interval' => 'P1M',
            'membership_type' => 'muaythai_with_trainer',
            'sessions_add'    => 10,
        ],
        'mt_no trainor' => [
            'label'    => '₱850.00 · 1 month all access',
            'amount'   => 850.00,
            'interval' => 'P1M',
            // if this should be "without trainer", you can change this string
            'membership_type' => 'muaythai_with_trainer',
            'sessions_add'    => 10,
        ],
        'mt_x 10' => [
            'label'    => '₱2000.00 · 10 Session with personal trainor',
            'amount'   => 2000.00,
            'interval' => 'P1M',
            'membership_type' => 'muaythai_with_trainer',
            'sessions_add'    => 10,
        ],
    ],
];

// For dropdown display
$membershipTypeLabels = [
    'bodybuilding' => 'Bodybuilding',
    'zumba'        => 'Zumba',
    'boxing'       => 'Boxing',
    'muay_thai'    => 'Muay Thai',
];

// Build a simpler array for JS: [type][plan_key] => label
$plansLabels = [];
foreach ($plansConfig as $typeKey => $plans) {
    $plansLabels[$typeKey] = [];
    foreach ($plans as $planKey => $plan) {
        $plansLabels[$typeKey][$planKey] = $plan['label'];
    }
}

$selectedType   = $_POST['membership_type'] ?? '';
$selectedPlan   = $_POST['plan_key'] ?? '';
$rfid_uid       = trim($_POST['rfid_uid'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) Basic validation
    if ($rfid_uid === '') {
        $errors[] = 'Please tap or enter RFID UID.';
    }

    if ($selectedType === '' || !isset($plansConfig[$selectedType])) {
        $errors[] = 'Please select a membership type.';
    }

    if ($selectedPlan === '' || !isset($plansConfig[$selectedType][$selectedPlan])) {
        $errors[] = 'Please select a membership plan.';
    }

    if (!$errors) {
        $plan               = $plansConfig[$selectedType][$selectedPlan];
        $amount             = (float)$plan['amount'];
        $planLabel          = $plan['label'];
        $membershipTypeCode = $plan['membership_type'] ?? '';
        $sessionsToAdd      = (int)($plan['sessions_add'] ?? 0);
        $membershipTypeLabel = $membershipTypeLabels[$selectedType] ?? ucfirst(str_replace('_',' ',$selectedType));

        // 2) Find user by RFID
        if ($st = $conn->prepare("
            SELECT id, full_name, username, email, id_number,
                   membership_expires_at, wallet_balance
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
            $errors[] = 'Database error (prepare user).';
            $userRow = null;
        }

        if (!$userRow) {
            $errors[] = 'No user found for this RFID card.';
        } else {
            $walletBalance = (float)($userRow['wallet_balance'] ?? 0);

            // 3) Check wallet balance
            if ($walletBalance < $amount) {
                $errors[] = 'Insufficient wallet balance. Need ₱'.number_format($amount,2).
                            ', current balance ₱'.number_format($walletBalance,2).'.';
            } else {
                // 4) Compute new expiry
                $now      = new DateTime();
                $oldExp   = $userRow['membership_expires_at'] ?? null;
                $baseDate = null;

                if ($oldExp) {
                    try {
                        $baseDate = new DateTime($oldExp);
                    } catch (Exception $e) {
                        $baseDate = null;
                    }
                }
                if (!$baseDate || $baseDate < $now) {
                    $baseDate = clone $now;
                }

                try {
                    $interval  = new DateInterval($plan['interval']);
                    $newDate   = clone $baseDate;
                    $newDate->add($interval);
                    $newExpiry = $newDate->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $errors[] = 'Internal error computing new expiry.';
                    $newExpiry = null;
                }

                if (!$errors && $newExpiry) {
                    $conn->begin_transaction();
                    try {
                        $userId  = (int)$userRow['id'];
                        $staffId = (int)($_SESSION['user_id'] ?? 0);
                        $method  = 'RFID Wallet';
                        $reference = $membershipTypeLabel.' - '.$planLabel;
                        $paidAt    = date('Y-m-d H:i:s');

                        // 5) Update users (wallet, expiry, plus membership_type + sessions if columns exist)
                        $up = null;

                        if ($HAS_MEMTYPE_COL && $HAS_SESSION_COL) {
                            // Update membership_type and add sessions
                            $up = $conn->prepare("
                                UPDATE users
                                SET wallet_balance = wallet_balance - ?,
                                    membership_expires_at = ?,
                                    membership_type = ?,
                                    trainer_sessions_remaining = COALESCE(trainer_sessions_remaining,0) + ?
                                WHERE id = ?
                            ");
                            if (!$up) {
                                throw new Exception('DB error updating user (with membership_type + sessions)');
                            }
                            $up->bind_param(
                                'dssii',
                                $amount,
                                $newExpiry,
                                $membershipTypeCode,
                                $sessionsToAdd,
                                $userId
                            );
                        } elseif ($HAS_MEMTYPE_COL) {
                            // Only membership_type (no sessions column)
                            $up = $conn->prepare("
                                UPDATE users
                                SET wallet_balance = wallet_balance - ?,
                                    membership_expires_at = ?,
                                    membership_type = ?
                                WHERE id = ?
                            ");
                            if (!$up) {
                                throw new Exception('DB error updating user (with membership_type)');
                            }
                            $up->bind_param(
                                'dssi',
                                $amount,
                                $newExpiry,
                                $membershipTypeCode,
                                $userId
                            );
                        } else {
                            // Legacy: only wallet & expiry
                            $up = $conn->prepare("
                                UPDATE users
                                SET wallet_balance = wallet_balance - ?,
                                    membership_expires_at = ?
                                WHERE id = ?
                            ");
                            if (!$up) {
                                throw new Exception('DB error updating user (legacy)');
                            }
                            $up->bind_param(
                                'dsi',
                                $amount,
                                $newExpiry,
                                $userId
                            );
                        }

                        if (!$up->execute()) {
                            $up->close();
                            throw new Exception('Failed to update user');
                        }
                        $up->close();

                        // 6) Insert into payments
                        if ($p = $conn->prepare("
                            INSERT INTO payments (user_id, staff_id, amount, method, reference)
                            VALUES (?,?,?,?,?)
                        ")) {
                            $p->bind_param('iidss', $userId, $staffId, $amount, $method, $reference);
                            if (!$p->execute()) {
                                $p->close();
                                throw new Exception('Failed to insert payment');
                            }
                            $p->close();
                        } else {
                            throw new Exception('DB error inserting payment');
                        }

                        $conn->commit();

                        // 7) Build receipt data
                        $receipt = [
                            'full_name'       => $userRow['full_name'] ?: $userRow['username'],
                            'id_number'       => $userRow['id_number'] ?? '',
                            'membership_type' => $membershipTypeLabel,
                            'plan_label'      => $planLabel,
                            'amount'          => $amount,
                            'method'          => $method,
                            'paid_at'         => $paidAt,
                            'old_exp'         => $oldExp ?: 'N/A',
                            'new_exp'         => $newExpiry,
                        ];

                        $showReceipt = true;
                        $receiptData = $receipt;
                        $success     = 'Payment successful. Membership extended.';

                        // 8) Send email receipt (best-effort)
                        if (!empty($userRow['email'])) {
                            $emailResult = sendMembershipReceiptEmail(
                                $userRow['email'],
                                $receipt['full_name'],
                                $receipt
                            );
                            if (!$emailResult['ok']) {
                                @file_put_contents(
                                    __DIR__.'/logs/mail.log',
                                    '['.date('Y-m-d H:i:s')."] extend_membership receipt error: ".$emailResult['error'].PHP_EOL,
                                    FILE_APPEND
                                );
                            }
                        }

                    } catch (Exception $e) {
                        $conn->rollback();
                        $errors[]   = 'Payment failed: '.$e->getMessage();
                        $showReceipt = false;
                        $receiptData = null;
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Extend Membership | RJL Fitness</title>
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
.form-control, .custom-select{
  background:#101010;
  border:1px solid #262626;
  color:#f9fafb;
}
.form-control:focus, .custom-select:focus{
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

/* Receipt overlay */
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
    Extend Membership (RFID Wallet)
  </span>
</nav>

<?php if (!empty($showReceipt) && !empty($receiptData)): ?>
<!-- Centered receipt modal -->
<div class="receipt-overlay" id="receiptOverlay">
  <div class="receipt-backdrop"></div>
  <div class="receipt-modal" id="receiptModal">
    <button type="button" class="close-btn" id="receiptCloseBtn" aria-label="Close receipt">&times;</button>

    <h4>Membership Receipt</h4>
    <div class="receipt-meta">
      RJL Fitness • <?= htmlspecialchars($receiptData['paid_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
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
      <span>Membership Type</span>
      <span><?= htmlspecialchars($receiptData['membership_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="receipt-row">
      <span>Plan</span>
      <span><?= htmlspecialchars($receiptData['plan_label'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="receipt-row">
      <span>Amount</span>
      <span>₱<?= number_format((float)($receiptData['amount'] ?? 0), 2) ?></span>
    </div>
    <div class="receipt-row">
      <span>Payment Method</span>
      <span><?= htmlspecialchars($receiptData['method'] ?? 'RFID Wallet', ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="receipt-row">
      <span>Old Expiry</span>
      <span><?= htmlspecialchars($receiptData['old_exp'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="receipt-row">
      <span>New Expiry</span>
      <span><?= htmlspecialchars($receiptData['new_exp'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
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
    <h4 class="mb-3">Extend Membership via RFID Wallet</h4>

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
        <small class="small-muted">This will look up the member by their RFID card and charge their wallet balance.</small>
      </div>

      <div class="form-row">
        <div class="form-group col-md-6">
          <label>Type of Membership</label>
          <select name="membership_type" id="membershipType" class="custom-select" required>
            <option value="">-- Select type --</option>
            <?php foreach ($membershipTypeLabels as $key => $label): ?>
              <option value="<?= htmlspecialchars($key) ?>"
                <?= $selectedType === $key ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group col-md-6">
          <label>Membership Plan</label>
          <select name="plan_key" id="planSelect" class="custom-select" required>
            <option value="">-- Select plan --</option>
            <!-- Options injected by JS -->
          </select>
          <small class="small-muted">
            Plan options change depending on the membership type selected above.
          </small>
        </div>
      </div>

      <button type="submit" class="btn btn-danger">Charge RFID Wallet & Extend</button>
    </form>
  </div>
</div>

<script>
// PHP -> JS: plan labels config
const PLANS_CONFIG = <?= json_encode($plansLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

(function(){
  // Receipt overlay logic
  const overlay   = document.getElementById('receiptOverlay');
  const closeBtn  = document.getElementById('receiptCloseBtn');
  const printBtn  = document.getElementById('printReceiptBtn');

  function closeReceipt(){
    if(!overlay) return;
    overlay.style.display = 'none';
  }

  if(closeBtn){
    closeBtn.addEventListener('click', function(e){
      e.preventDefault();
      closeReceipt();
    });
  }

  if(overlay){
    overlay.addEventListener('click', function(e){
      if(e.target === overlay){
        closeReceipt();
      }
    });
  }

  if(printBtn){
    printBtn.addEventListener('click', function(e){
      e.preventDefault();
      window.print();
    });
  }

  // Dynamic membership plan dropdown
  const typeSelect   = document.getElementById('membershipType');
  const planSelect   = document.getElementById('planSelect');
  const selectedType = "<?= htmlspecialchars($selectedType, ENT_QUOTES, 'UTF-8') ?>";
  const selectedPlan = "<?= htmlspecialchars($selectedPlan, ENT_QUOTES, 'UTF-8') ?>";

  function rebuildPlanOptions() {
    if (!planSelect) return;
    const t = typeSelect ? typeSelect.value : '';

    planSelect.innerHTML = '<option value="">-- Select plan --</option>';

    if (!t || !PLANS_CONFIG[t]) return;

    const plans = PLANS_CONFIG[t];
    Object.keys(plans).forEach(function(planKey){
      const opt = document.createElement('option');
      opt.value = planKey;
      opt.textContent = plans[planKey];
      if (t === selectedType && planKey === selectedPlan) {
        opt.selected = true;
      }
      planSelect.appendChild(opt);
    });
  }

  if (typeSelect) {
    typeSelect.addEventListener('change', function(){
      planSelect.value = '';
      rebuildPlanOptions();
    });
  }

  rebuildPlanOptions();
})();
</script>

</body>
</html>