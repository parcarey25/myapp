<?php
// register.php — allows Member/Trainer/Staff/Admin with invite codes for Staff/Admin
// Adds auto-incrementing id_number like M-0225-01, M-0225-02, ...
session_start();
if (isset($_SESSION['user_id'])) { header('Location: home.php'); exit; }
require __DIR__ . '/db.php';

/* ================== CONFIG — CHANGE THESE ================== */
$STAFF_INVITE_CODE  = 'RJL-STAFF-2025';   // <-- set your staff secret
$ADMIN_INVITE_CODE  = 'RJL-ADMIN-2025';   // <-- set your admin secret
$ADMIN_AUTO_ACTIVE  = false;              // true = admin becomes active immediately (riskier)

// For id_number generation. You can swap prefix by role (see below).
$DEFAULT_PREFIX = 'M-0225-';
/* =========================================================== */

// Roles user can choose on this page
$ALLOWED_ROLES = ['member', 'trainer', 'staff', 'admin'];

$errors = [];
$success = false;
$finalRole = 'member'; // for success message

// --------- Helper: generate next id_number like M-0225-01, M-0225-02, ... ----------
function generate_user_id_number(mysqli $conn, string $prefix = 'M-0225-'): string {
  // Look up the highest id_number for this prefix (by newest row)
  $like = $prefix . '%';
  $sql  = "SELECT id_number FROM users WHERE id_number LIKE ? ORDER BY id DESC LIMIT 1";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param('s', $like);
    $st->execute();
    $res = $st->get_result();
    $nextInt = 1;
    if ($res && ($row = $res->fetch_assoc())) {
      $last = $row['id_number'];         // e.g. M-0225-07
      $parts = explode('-', $last);
      $numStr = end($parts);              // "07"
      $nextInt = max(1, (int)$numStr + 1);
    }
    if ($res) $res->free();
    $st->close();

    // Pad at least 2 digits; becomes 3 digits automatically if >= 100
    $pad = ($nextInt >= 100) ? 3 : 2;
    return $prefix . str_pad((string)$nextInt, $pad, '0', STR_PAD_LEFT);
  }
  // Fallback if prepare fails
  return $prefix . '01';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Inputs
  $username   = trim($_POST['username'] ?? '');
  $email      = trim($_POST['email'] ?? '');
  $full_name  = trim($_POST['full_name'] ?? '');
  $password   = $_POST['password'] ?? '';
  $confirm    = $_POST['confirm'] ?? '';
  $role_in    = strtolower(trim($_POST['role'] ?? 'member'));
  $staff_inv  = trim($_POST['staff_invite'] ?? '');
  $admin_inv  = trim($_POST['admin_invite'] ?? '');

  // Normalize role
  $role = in_array($role_in, $ALLOWED_ROLES, true) ? $role_in : 'member';
  $finalRole = $role;

  // Basic validation
  if ($username === '' || $email === '' || $password === '' || $confirm === '') {
    $errors[] = 'All required fields must be filled.';
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
  }
  if (strlen($password) < 6) {
    $errors[] = 'Password must be at least 6 characters.';
  }
  if ($password !== $confirm) {
    $errors[] = 'Passwords do not match.';
  }

  // Role-specific invite checks
  if ($role === 'staff') {
    if ($staff_inv === '' || !hash_equals($STAFF_INVITE_CODE, $staff_inv)) {
      $errors[] = 'Invalid Staff Invite Code.';
    }
  }
  if ($role === 'admin') {
    if ($admin_inv === '' || !hash_equals($ADMIN_INVITE_CODE, $admin_inv)) {
      $errors[] = 'Invalid Admin Invite Code.';
    }
  }

  // Duplicate username/email check
  if (!$errors) {
    if ($st = $conn->prepare("SELECT 1 FROM users WHERE username = ? OR email = ? LIMIT 1")) {
      $st->bind_param('ss', $username, $email);
      $st->execute();
      $st->store_result();
      if ($st->num_rows > 0) {
        $errors[] = 'Username or email already exists.';
      }
      $st->close();
    } else {
      $errors[] = 'Database error (duplicate check).';
    }
  }

  // Insert user
  if (!$errors) {
    $hash  = password_hash($password, PASSWORD_BCRYPT);

    // Status rules
    // - member/trainer: pending (must be activated by staff/admin)
    // - staff: active if invite OK
    // - admin: pending (recommended) or active if $ADMIN_AUTO_ACTIVE = true
    if ($role === 'member' || $role === 'trainer') {
      $status = 'pending';
    } elseif ($role === 'staff') {
      $status = 'active';
    } elseif ($role === 'admin') {
      $status = $ADMIN_AUTO_ACTIVE ? 'active' : 'pending';
    } else {
      $status = 'pending';
    }

    // OPTIONAL: Different prefixes per role
    // $prefixMap = ['member'=>'M-0225-','trainer'=>'T-0225-','staff'=>'S-0225-','admin'=>'A-0225-'];
    // $prefix = $prefixMap[$role] ?? $DEFAULT_PREFIX;
    $prefix = $DEFAULT_PREFIX;

    // Generate next ID number
    $id_number = generate_user_id_number($conn, $prefix);

    $membership_expires_at = NULL; // keep nullable

    if ($st = $conn->prepare("
      INSERT INTO users (username, email, password, role, full_name, membership_expires_at, status, id_number)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")) {
      $st->bind_param(
        'ssssssss',
        $username,
        $email,
        $hash,
        $role,
        $full_name,
        $membership_expires_at, // stays NULL in DB if column allows NULL (may insert empty string on some setups)
        $status,
        $id_number
      );
      if ($st->execute()) {
        $success = true;
      } else {
        // If UNIQUE constraint on id_number caused a collision (rare), try once more
        if ($conn->errno == 1062) {
          $id_number = generate_user_id_number($conn, $prefix);
          $st->bind_param(
            'ssssssss',
            $username,
            $email,
            $hash,
            $role,
            $full_name,
            $membership_expires_at,
            $status,
            $id_number
          );
          if ($st->execute()) {
            $success = true;
          } else {
            $errors[] = 'Failed to register (duplicate id_number). Please try again.';
          }
        } else {
          $errors[] = 'Failed to register. (' . $conn->error . ')';
        }
      }
      $st->close();
    } else {
      $errors[] = 'Database error (insert).';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>RJL Fitness | Register</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#111;color:#fff;min-height:100vh;display:grid;place-items:center;font-family:'Poppins',sans-serif}
  .card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px;max-width:680px;width:100%}
  .btn-danger{background:#b30000;border:none}.btn-danger:hover{background:#ff1a1a}
  .form-control,.custom-select{background:#121212;border:1px solid #2a2a2a;color:#eee}
  .hint{color:#aaa}
  .muted{color:#bbb}
</style>
</head>
<body>
<div class="card p-4">
  <h4 class="mb-3 text-center">Create Account</h4>

  <?php if ($success): ?>
    <?php if ($finalRole === 'admin'): ?>
      <?php if ($ADMIN_AUTO_ACTIVE): ?>
        <div class="alert alert-success text-center">
          Admin account created and <strong>activated</strong>. You can log in now.
        </div>
      <?php else: ?>
        <div class="alert alert-success text-center">
          Admin account created and <strong>pending approval</strong>. An existing admin must activate it.
        </div>
      <?php endif; ?>
    <?php elseif ($finalRole === 'staff'): ?>
      <div class="alert alert-success text-center">
        Staff account created and <strong>activated</strong>. You can log in now.
      </div>
    <?php else: ?>
      <div class="alert alert-success text-center">
        Account created and <strong>pending approval</strong>.<br>
        Please visit the gym to pay and activate your account.
      </div>
    <?php endif; ?>
    <div class="text-center">
      <p class="muted mb-2">Your ID Number: <strong><?= htmlspecialchars($id_number ?? '') ?></strong></p>
      <a href="login.php" class="btn btn-danger">Go to Login</a>
    </div>
  <?php else: ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?>
      </ul></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="form-row">
        <div class="form-group col-md-6">
          <label>Username</label>
          <input class="form-control" name="username" required value="<?=htmlspecialchars($_POST['username'] ?? '')?>">
        </div>
        <div class="form-group col-md-6">
          <label>Role</label>
          <select id="role" name="role" class="custom-select" required>
            <?php $sel = $_POST['role'] ?? 'member'; ?>
            <option value="member"  <?= $sel==='member'  ? 'selected':''; ?>>Member</option>
            <option value="trainer" <?= $sel==='trainer' ? 'selected':''; ?>>Trainer</option>
            <option value="staff"   <?= $sel==='staff'   ? 'selected':''; ?>>Staff</option>
            <option value="admin"   <?= $sel==='admin'   ? 'selected':''; ?>>Admin</option>
          </select>
        </div>
      </div>

      <!-- Staff invite -->
      <div id="staffInviteWrap" class="form-group" style="display:none">
        <label>Staff Invite Code</label>
        <input type="password" class="form-control" name="staff_invite" placeholder="Enter the staff code">
        <small class="hint">Required if you choose Staff.</small>
      </div>

      <!-- Admin invite -->
      <div id="adminInviteWrap" class="form-group" style="display:none">
        <label>Admin Invite Code</label>
        <input type="password" class="form-control" name="admin_invite" placeholder="Enter the admin code">
        <small class="hint">Required if you choose Admin.</small>
      </div>

      <div class="form-group">
        <label>Full Name (optional)</label>
        <input class="form-control" name="full_name" value="<?=htmlspecialchars($_POST['full_name'] ?? '')?>">
      </div>

      <div class="form-group">
        <label>Email</label>
        <input type="email" class="form-control" name="email" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>">
      </div>

      <div class="form-row">
        <div class="form-group col-md-6">
          <label>Password</label>
          <input type="password" class="form-control" name="password" required>
        </div>
        <div class="form-group col-md-6">
          <label>Confirm</label>
          <input type="password" class="form-control" name="confirm" required>
        </div>
      </div>

      <button class="btn btn-danger btn-block">Create Account</button>
      <small class="d-block text-center mt-2 muted">Your ID Number will be generated automatically.</small>
    </form>

    <div class="mt-3 text-center">
      <small>Already have an account? <a href="login.php">Log in</a></small>
    </div>
  <?php endif; ?>
</div>

<script>
// Toggle invite fields based on selected role
(function(){
  const roleSel = document.getElementById('role');
  const staffWrap = document.getElementById('staffInviteWrap');
  const adminWrap = document.getElementById('adminInviteWrap');
  function toggle(){
    const v = roleSel.value;
    staffWrap.style.display = (v === 'staff') ? '' : 'none';
    adminWrap.style.display = (v === 'admin') ? '' : 'none';
  }
  roleSel.addEventListener('change', toggle);
  toggle(); // initial
})();
</script>
</body>
</html>