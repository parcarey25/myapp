<<<<<<< HEAD
<?php
// id_verifications.php — Staff/Admin review pending IDs
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__ . '/db.php';

$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['staff','admin'], true)) {
  http_response_code(403); die('Forbidden');
}

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

// Load pending
$sql = "SELECT id, username, full_name, email, valid_id_path, valid_id_status, valid_id_uploaded_at
        FROM users WHERE valid_id_status='pending'
        ORDER BY valid_id_uploaded_at DESC";
$res = $conn->query($sql);
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
if ($res) $res->free();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>ID Verifications | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#101010;color:#fff;font-family:'Poppins',sans-serif}
  .navbar{background:linear-gradient(90deg,#000,#b30000)}
  .card{background:#171717;border:1px solid #2a2a2a;border-radius:14px}
  .btn-danger{background:#b30000;border:none}.btn-danger:hover{background:#ff1a1a}
  .form-control{background:#121212;border:1px solid #2a2a2a;color:#eee}
  .thumb{max-width:180px; max-height:120px; border:1px solid #2a2a2a; border-radius:8px}
  a, a:hover{color:#fff}
  .muted{color:#bbb}
</style>
</head>
<body>
<nav class="navbar navbar-dark">
  <a class="navbar-brand ml-3" href="home.php"><img src="photo/logo.jpg" height="28" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto mr-3">
    <a class="btn btn-outline-light btn-sm" href="home.php">Home</a>
    <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
  </div>
</nav>

<div class="container py-4">
  <h3 class="mb-3">Pending ID Verifications</h3>

  <?php if (!$rows): ?>
    <div class="alert alert-secondary">No pending submissions.</div>
  <?php else: ?>
    <div class="card p-3">
      <div class="table-responsive">
        <table class="table table-dark table-striped mb-0">
          <thead>
            <tr>
              <th>User</th>
              <th>Email</th>
              <th>Uploaded</th>
              <th>Preview</th>
              <th style="width:250px">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['full_name'] ?: $r['username']) ?></td>
              <td><?= htmlspecialchars($r['email']) ?></td>
              <td class="muted"><?= htmlspecialchars($r['valid_id_uploaded_at']) ?></td>
              <td>
                <?php if (preg_match('/\.pdf$/i', $r['valid_id_path'])): ?>
                  <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars($r['valid_id_path']) ?>" target="_blank">Open PDF</a>
                <?php else: ?>
                  <a href="<?= htmlspecialchars($r['valid_id_path']) ?>" target="_blank"><img class="thumb" src="<?= htmlspecialchars($r['valid_id_path']) ?>" alt=""></a>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" action="id_verifications_action.php" class="form-inline">
                  <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                  <input type="text" class="form-control form-control-sm mr-2 mb-2" name="note" placeholder="Note (optional)">
                  <button name="action" value="approve" class="btn btn-success btn-sm mr-2 mb-2">Approve</button>
                  <button name="action" value="reject" class="btn btn-outline-danger btn-sm mb-2" onclick="return confirm('Reject this ID?');">Reject</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>

</html>