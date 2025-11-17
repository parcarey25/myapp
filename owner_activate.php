<?php
// owner_activate.php — owner-only tool to activate users and set role.
// *** DELETE THIS FILE AFTER YOU'RE DONE. ***

session_start();
require __DIR__ . '/db.php';

// ======= CONFIGURE YOUR OWNER SECRET HERE =======
$OWNER_SECRET = 'RJL-OWNER-ONLY-SECRET'; // <-- change this to a strong secret
// ================================================

$auth_ok = false;
$err = '';
$ok  = '';

// Handle login for this tool (session-based)
if (isset($_POST['owner_secret'])) {
  if (hash_equals($OWNER_SECRET, trim($_POST['owner_secret']))) {
    $_SESSION['owner_ok'] = true;
  } else {
    $err = 'Invalid owner secret.';
  }
}
$auth_ok = !empty($_SESSION['owner_ok']);

// Handle logout
if (isset($_GET['logout'])) {
  $_SESSION['owner_ok'] = false;
  header('Location: owner_activate.php');
  exit;
}

// Helper: fetch pending users (optionally filtered)
function get_pending($conn, $needle = '') {
  $needle = trim($needle);
  if ($needle === '') {
    return $conn->query("SELECT id, username, email, role, status, created_at FROM users WHERE status='pending' ORDER BY created_at ASC LIMIT 200");
  }
  $s = '%' . $conn->real_escape_string($needle) . '%';
  return $conn->query("
    SELECT id, username, email, role, status, created_at
    FROM users
    WHERE status='pending' AND (username LIKE '$s' OR email LIKE '$s')
    ORDER BY created_at ASC
    LIMIT 200
  ");
}

// Handle activation POST
if ($auth_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do']) && $_POST['do'] === 'activate') {
  $identifier = trim($_POST['identifier'] ?? '');
  $newRole    = trim($_POST['role'] ?? 'member');

  if ($identifier === '') {
    $err = 'Please enter a username or email.';
  } else {
    // Confirm allowed roles
    $allowedRoles = ['member','trainer','staff','admin'];
    if (!in_array($newRole, $allowedRoles, true)) $newRole = 'member';

    // Find the user
    $stmt = $conn->prepare("SELECT id, status FROM users WHERE username=? OR email=? LIMIT 1");
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $stmt->bind_result($uid, $ustatus);
    if ($stmt->fetch()) {
      $stmt->close();

      // Activate + set role
      $u = $conn->prepare("UPDATE users SET status='active', role=? WHERE id=?");
      $u->bind_param('si', $newRole, $uid);
      if ($u->execute()) {
        $ok = "Activated user #$uid ($identifier) as <strong>$newRole</strong>.";
      } else {
        $err = 'Update failed: ' . htmlspecialchars($conn->error);
      }
      $u->close();
    } else {
      $err = 'User not found.';
    }
  }
}

// Fetch list (if authenticated)
$search = '';
if ($auth_ok && isset($_GET['q'])) {
  $search = trim($_GET['q']);
  $pending = get_pending($conn, $search);
} elseif ($auth_ok) {
  $pending = get_pending($conn);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Owner • Activate Users</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
  .card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}
  .btn-danger{background:#b30000;border:none}.btn-danger:hover{background:#ff1a1a}
  .form-control,.custom-select{background:#121212;border:1px solid #2a2a2a;color:#eee}
  a, a:hover{color:#fff}
</style>
</head>
<body>
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Owner • Activate Users</h3>
    <?php if($auth_ok): ?>
      <a href="?logout=1" class="btn btn-outline-light btn-sm">Logout</a>
    <?php endif; ?>
  </div>

  <?php if(!$auth_ok): ?>
    <div class="card p-3 mx-auto" style="max-width:420px">
      <h5 class="mb-3">Owner Sign In</h5>
      <?php if($err): ?><div class="alert alert-danger py-2"><?= $err ?></div><?php endif; ?>
      <form method="post">
        <div class="form-group">
          <label>Owner Secret</label>
          <input type="password" class="form-control" name="owner_secret" required>
        </div>
        <button class="btn btn-danger btn-block">Enter</button>
      </form>
      <div class="mt-3 text-muted" style="font-size:.9rem">
        Tip: Edit <code>$OWNER_SECRET</code> at the top of this file before using.
      </div>
    </div>
  <?php else: ?>

    <?php if($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>
    <?php if($ok):  ?><div class="alert alert-success"><?= $ok ?></div><?php endif; ?>

    <div class="row">
      <!-- Left: Activate form -->
      <div class="col-md-6 mb-3">
        <div class="card p-3">
          <h5>Activate a user</h5>
          <form method="post">
            <input type="hidden" name="do" value="activate">
            <div class="form-group">
              <label>Username or Email</label>
              <input class="form-control" name="identifier" placeholder="e.g. admin1 or admin1@example.com" required>
            </div>
            <div class="form-group">
              <label>Set Role</label>
              <select name="role" class="custom-select">
                <option value="admin">admin</option>
                <option value="staff">staff</option>
                <option value="trainer">trainer</option>
                <option value="member">member</option>
              </select>
            </div>
            <button class="btn btn-danger">Activate</button>
          </form>
        </div>
      </div>

      <!-- Right: Pending list -->
      <div class="col-md-6 mb-3">
        <div class="card p-3">
          <h5 class="mb-3">Pending accounts</h5>
          <form class="form-inline mb-2" method="get">
            <input class="form-control mr-2" name="q" placeholder="Search username or email" value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-outline-light">Search</button>
          </form>

          <div class="table-responsive" style="max-height:420px;overflow:auto">
            <table class="table table-dark table-sm table-striped mb-0">
              <thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Created</th></tr></thead>
              <tbody>
                <?php if(isset($pending) && $pending && $pending->num_rows>0): ?>
                  <?php while($u = $pending->fetch_assoc()): ?>
                    <tr>
                      <td><?= $u['id'] ?></td>
                      <td><?= htmlspecialchars($u['username']) ?></td>
                      <td><?= htmlspecialchars($u['email']) ?></td>
                      <td><?= htmlspecialchars($u['role']) ?></td>
                      <td><?= date('M d, Y g:ia', strtotime($u['created_at'])) ?></td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="5" class="text-muted">No pending users found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php if(isset($pending) && $pending) $pending->free(); ?>
        </div>
      </div>
    </div>

    <div class="alert alert-warning">
      <strong>Security:</strong> After activating accounts, <u>delete this file</u> (<code>owner_activate.php</code>).
    </div>

  <?php endif; ?>
</div>
</body>
</html>