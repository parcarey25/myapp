<?php
// membership.php — Member Status + Extend Membership (auto-detects payments columns)
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__ . '/db.php';

$userId   = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';

// ---- Config: your plans & prices ----
$PLANS = [
  ['label' => '30 Days',  'days' => 30,  'price' => 800.00],
  ['label' => '90 Days',  'days' => 90,  'price' => 2200.00],
  ['label' => '180 Days', 'days' => 180, 'price' => 4200.00],
];
$DEFAULT_METHOD = 'cash';

// ---- Helpers ----
function has_column(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param('ss', $table, $column);
  $st->execute(); $res = $st->get_result();
  $ok = $res && $res->num_rows > 0;
  if ($res) $res->free(); $st->close();
  return $ok;
}
function fmtDate($ts){ if(!$ts) return 'Not set'; $t=strtotime($ts); return $t?date('M d, Y',$t):'Not set'; }
function daysLeft($ts){ if(!$ts) return 0; $t=strtotime($ts); $today=strtotime(date('Y-m-d').' 00:00:00'); return (int)floor(($t-$today)/86400); }
function isExpired($ts){ if(!$ts) return true; return (strtotime($ts) < time()); }

// ---- Detect payments table capabilities ----
$has_paid_at = has_column($conn,'payments','paid_at');
$has_method  = has_column($conn,'payments','method');
$has_notes   = has_column($conn,'payments','notes');
$has_staff   = has_column($conn,'payments','staff_id');

// ---- CSRF token ----
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['csrf'];

$errors  = [];
$success = false;

// ---- Load current member info ----
$user = ['full_name'=>'','email'=>'','expires'=>null];
if ($st = $conn->prepare("SELECT full_name, email, membership_expires_at FROM users WHERE id=? LIMIT 1")) {
  $st->bind_param('i', $userId);
  $st->execute();
  $st->bind_result($full_name, $email, $expires_at);
  if ($st->fetch()) {
    $user['full_name'] = $full_name ?? '';
    $user['email']     = $email ?? '';
    $user['expires']   = $expires_at;
  }
  $st->close();
} else { $errors[] = 'Database error (load user).'; }

// ---- Handle POST (extend membership) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
    $errors[] = 'Security check failed. Please try again.';
  } else {
    $plan_days   = (int)($_POST['plan_days'] ?? 0);
    $plan_price  = (float)($_POST['plan_price'] ?? 0);
    $pay_method  = trim($_POST['method'] ?? $DEFAULT_METHOD);
    $note_input  = trim($_POST['notes'] ?? '');

    if ($plan_days <= 0 || $plan_price <= 0) {
      $errors[] = 'Please choose a valid membership plan.';
    }

    if (!$errors) {
      // Re-load current expiry for correctness
      $current = null;
      if ($st = $conn->prepare("SELECT membership_expires_at FROM users WHERE id=? LIMIT 1")) {
        $st->bind_param('i', $userId);
        $st->execute(); $st->bind_result($cur_exp);
        if ($st->fetch()) $current = $cur_exp;
        $st->close();
      }

      $startBase = (isExpired($current) ? date('Y-m-d 23:59:59') : $current);
      $newExpiry = date('Y-m-d 23:59:59', strtotime($startBase . " +{$plan_days} days"));

      // Build INSERT for payments based on available columns
      $cols = ['user_id','amount'];
      $vals = '?, ?';
      $types = 'id';
      $bind = [$userId, $plan_price];

      if ($has_method) { $cols[]='method'; $vals.=', ?'; $types.='s'; $bind[]=$pay_method; }
      if ($has_notes)  { $cols[]='notes';  $vals.=', ?'; $types.='s'; $bind[]=("Membership extension: +{$plan_days} days".($note_input?" ({$note_input})":"")); }
      if ($has_staff)  { $cols[]='staff_id'; $vals.=', ?'; $types.='i'; $bind[]=null; }
      if ($has_paid_at){ $cols[]='paid_at';  $vals.=', NOW()'; /* no bind for NOW() */ }

      $sql = "INSERT INTO payments (".implode(',',$cols).") VALUES ($vals)";
      if ($st = $conn->prepare($sql)) {
        // dynamic bind_param
        $ref = [];
        $ref[] = &$types;
        for ($i=0;$i<count($bind);$i++) { $ref[] = &$bind[$i]; }
        call_user_func_array([$st,'bind_param'], $ref);

        if ($st->execute()) {
          // Update expiry
          if ($st2 = $conn->prepare("UPDATE users SET membership_expires_at=? WHERE id=?")) {
            $st2->bind_param('si', $newExpiry, $userId);
            if ($st2->execute()) {
              $success = true;
              $user['expires'] = $newExpiry;
            } else { $errors[]='Failed to update membership expiry.'; }
            $st2->close();
          } else { $errors[]='Database error (update expiry).'; }
        } else { $errors[]='Failed to record payment.'; }
        $st->close();
      } else {
        $errors[] = 'Database error (insert payment).';
      }
    }
  }
}

// ---- Compute status ----
$statusExpired   = isExpired($user['expires']);
$days_remaining  = daysLeft($user['expires']);
$status_label    = $statusExpired ? 'Expired' : 'Active';

// ---- Recent membership payments (only select existing cols) ----
$selCols = [];
$selCols[] = $has_paid_at ? "paid_at" : "NULL AS paid_at";
$selCols[] = "amount";
if ($has_method) $selCols[] = "method";
if ($has_notes)  $selCols[] = "notes";

$sqlRecent = "SELECT ".implode(',', $selCols)." FROM payments WHERE user_id=? ";
if ($has_notes) {
  $sqlRecent .= "AND notes LIKE 'Membership extension:%' ";
}
$sqlRecent .= "ORDER BY ".($has_paid_at ? "paid_at" : "id")." DESC LIMIT 10";

$recent = [];
if ($st = $conn->prepare($sqlRecent)) {
  $st->bind_param('i',$userId);
  $st->execute();
  $res = $st->get_result();
  if ($res) { $recent = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }
  $st->close();
}

$avatarPath='photo/logo.jpg';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Membership Status | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{ --brand:#b30000; --brand-hover:#ff1a1a; --bg:#111; --panel:#1a1a1a; --line:#2a2a2a; --muted:#aaa; }
body{background:#111;color:#fff;font-family:'Poppins',sans-serif;min-height:100vh}
.navbar{background:linear-gradient(90deg,#000,var(--brand))}
.card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}
.badge-soft{background:#222;border:1px solid #333;color:#eee;padding:.25rem .5rem;border-radius:6px}
.form-control,.custom-select{background:#121212;border:1px solid #2a2a2a;color:#eee}
.btn-danger{background:var(--brand);border:none}.btn-danger:hover{background:var(--brand-hover)}
.table thead th{border-top:0}
a,a:hover{color:#fff}
</style>
</head>
<body>
<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand" href="home.php"><img src="photo/logo.jpg" height="30" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto">
    <a class="btn btn-outline-light btn-sm" href="home.php">Back to Dashboard</a>
  </div>
</nav>

<div class="container py-4">
  <!-- Status -->
  <div class="card p-3 mb-3">
    <h4 class="mb-2">Membership Status</h4>
    <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($user['full_name'] ?: $username) ?></p>
    <p class="mb-1"><strong>Status:</strong> <span class="badge-soft"><?= htmlspecialchars($status_label) ?></span></p>
    <p class="mb-1"><strong>Expires on:</strong> <?= htmlspecialchars(fmtDate($user['expires'])) ?></p>
    <p class="mb-0"><strong>Days remaining:</strong> <?= $statusExpired ? 0 : (int)$days_remaining ?></p>
  </div>

  <!-- Extend -->
  <div class="card p-3 mb-3">
    <h5 class="mb-3">Extend Membership</h5>
    <?php if ($success): ?>
      <div class="alert alert-success">Membership extended! New expiry: <strong><?= htmlspecialchars(fmtDate($user['expires'])) ?></strong></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
    <?php endif; ?>

    <form method="post" class="mb-0" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $CSRF ?>">

      <div class="form-row">
        <div class="form-group col-md-5">
          <label>Plan</label>
          <select id="plan" class="custom-select">
            <?php foreach ($PLANS as $i => $p): ?>
              <option value="<?= $i ?>"><?= htmlspecialchars($p['label']) ?> — ₱<?= number_format($p['price'],2) ?></option>
            <?php endforeach; ?>
            <option value="custom">Custom…</option>
          </select>
        </div>
        <div class="form-group col-md-3">
          <label>Days</label>
          <input type="number" id="days_input" class="form-control" min="1" value="<?= (int)$PLANS[0]['days'] ?>">
        </div>
        <div class="form-group col-md-4">
          <label>Price</label>
          <input type="number" id="price_input" class="form-control" step="0.01" min="0" value="<?= number_format($PLANS[0]['price'],2,'.','') ?>">
        </div>
      </div>

      <?php if ($has_method): ?>
      <div class="form-row">
        <div class="form-group col-md-4">
          <label>Payment Method</label>
          <select name="method" class="custom-select">
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="card">Card</option>
          </select>
        </div>
        <div class="form-group col-md-8">
          <label>Notes (optional)</label>
          <input type="text" name="notes" class="form-control" placeholder="Receipt #, reference, etc." <?= $has_notes ? '' : 'disabled' ?>>
          <?php if(!$has_notes): ?><small class="text-muted">Your payments table doesn’t have a <code>notes</code> column.</small><?php endif; ?>
        </div>
      </div>
      <?php else: ?>
        <input type="hidden" name="method" value="<?= htmlspecialchars($DEFAULT_METHOD) ?>">
      <?php endif; ?>

      <input type="hidden" name="plan_days"  id="plan_days"  value="<?= (int)$PLANS[0]['days'] ?>">
      <input type="hidden" name="plan_price" id="plan_price" value="<?= number_format($PLANS[0]['price'],2,'.','') ?>">

      <button class="btn btn-danger">Extend Now</button>
    </form>

    <small class="text-muted d-block mt-2">
      Rule: If your membership is expired, we start counting from today. If it’s still active, we add days after your current expiry date.
    </small>
  </div>

  <!-- Recent membership payments -->
  <div class="card p-3">
    <h5 class="mb-3">Recent Membership Payments</h5>
    <div class="table-responsive">
      <table class="table table-dark table-striped table-sm mb-0">
        <thead>
          <tr>
            <th><?= $has_paid_at ? 'When' : 'Record' ?></th>
            <th>Amount</th>
            <?php if ($has_method): ?><th>Method</th><?php endif; ?>
            <?php if ($has_notes) : ?><th>Notes</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$recent): ?>
            <tr><td colspan="<?= 2 + ($has_method?1:0) + ($has_notes?1:0) ?>" class="text-center text-muted">No recent membership payments.</td></tr>
          <?php else: foreach ($recent as $row): ?>
            <tr>
              <td>
                <?php
                  if ($has_paid_at && !empty($row['paid_at'])) {
                    echo htmlspecialchars(date('M d, Y g:ia', strtotime($row['paid_at'])));
                  } else {
                    echo '—';
                  }
                ?>
              </td>
              <td>₱<?= number_format((float)$row['amount'],2) ?></td>
              <?php if ($has_method): ?><td><?= htmlspecialchars($row['method'] ?? '—') ?></td><?php endif; ?>
              <?php if ($has_notes) : ?><td><?= htmlspecialchars($row['notes']  ?? '') ?></td><?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function(){
  const plans = <?= json_encode($PLANS, JSON_UNESCAPED_UNICODE) ?>;
  const sel   = document.getElementById('plan');
  const days  = document.getElementById('days_input');
  const price = document.getElementById('price_input');
  const hDays = document.getElementById('plan_days');
  const hPric = document.getElementById('plan_price');

  function syncHidden(){ hDays.value = days.value || 0; hPric.value = price.value || 0; }
  sel.addEventListener('change', () => {
    const v = sel.value;
    if (v === 'custom') {
      days.removeAttribute('readonly'); price.removeAttribute('readonly'); days.focus();
    } else {
      const p = plans[parseInt(v,10)];
      if (p) {
        days.value  = p.days;
        price.value = Number(p.price).toFixed(2);
        days.setAttribute('readonly','readonly');
        price.setAttribute('readonly','readonly');
      }
    }
    syncHidden();
  });
  days.addEventListener('input', syncHidden);
  price.addEventListener('input', syncHidden);
  days.setAttribute('readonly','readonly'); price.setAttribute('readonly','readonly');
})();
</script>
</body>
</html>