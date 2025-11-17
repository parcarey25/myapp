
<?php
// users.php — Staff/Admin: list + manage all user info (defensive to missing columns)
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['staff','admin'], true)) { header('Location: home.php'); exit; }

require __DIR__ . '/db.php';

/* ---------- helpers ---------- */
function has_column(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param('ss', $table, $column);
  $st->execute();
  $res = $st->get_result();
  $ok = $res && $res->num_rows > 0;
  if ($res) $res->free();
  $st->close();
  return $ok;
}
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$has_full_name  = has_column($conn,'users','full_name');
$has_id_number  = has_column($conn,'users','id_number');
$has_valid_path = has_column($conn,'users','valid_id_path');
$has_valid_stat = has_column($conn,'users','valid_id_status');
$has_expires    = has_column($conn,'users','membership_expires_at');
$has_avatar     = has_column($conn,'users','avatar_path');
$has_status     = has_column($conn,'users','status'); // pending/active/rejected

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

// --- handle actions (POST) ---
$errors = [];
$flash  = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
    $errors[] = 'Security check failed.';
  } else {
    $uid = (int)($_POST['user_id'] ?? 0);
    $act = $_POST['action'] ?? '';

    if ($uid <= 0) { $errors[]='Invalid user id.'; }
    else {
      if ($act === 'set_status' && $has_status) {
        $new = $_POST['new_status'] ?? '';
        if (!in_array($new, ['pending','active','rejected'], true)) $errors[]='Invalid status value.';
        if (!$errors && ($st=$conn->prepare("UPDATE users SET status=? WHERE id=?"))) {
          $st->bind_param('si',$new,$uid);
          $flash = $st->execute() ? 'Status updated.' : 'Failed to update status.';
          $st->close();
        }
      }
      if ($act === 'set_role') {
        $new = $_POST['new_role'] ?? '';
        if (!in_array($new, ['member','trainer','staff','admin'], true)) $errors[]='Invalid role value.';
        // prevent staff promoting to admin unless current user is admin
        if ($new==='admin' && $role!=='admin') $errors[] = 'Only admins can promote to admin.';
        if (!$errors && ($st=$conn->prepare("UPDATE users SET role=? WHERE id=?"))) {
          $st->bind_param('si',$new,$uid);
          $flash = $st->execute() ? 'Role updated.' : 'Failed to update role.';
          $st->close();
        }
      }
      if ($act === 'set_expiry' && $has_expires) {
        $date = trim($_POST['expires'] ?? '');
        // very light validation: YYYY-MM-DD
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~',$date)) $errors[]='Invalid date (YYYY-MM-DD).';
        $date_full = $date.' 23:59:59';
        if (!$errors && ($st=$conn->prepare("UPDATE users SET membership_expires_at=? WHERE id=?"))) {
          $st->bind_param('si',$date_full,$uid);
          $flash = $st->execute() ? 'Membership expiry updated.' : 'Failed to update membership expiry.';
          $st->close();
        }
      }
      if ($act === 'valid_id_status' && $has_valid_stat) {
        $new = $_POST['new_valid_id_status'] ?? '';
        if (!in_array($new, ['none','pending','approved','rejected'], true)) $errors[]='Invalid Valid-ID status.';
        if (!$errors && ($st=$conn->prepare("UPDATE users SET valid_id_status=? WHERE id=?"))) {
          $st->bind_param('si',$new,$uid);
          $flash = $st->execute() ? 'Valid ID status updated.' : 'Failed to update Valid ID status.';
          $st->close();
        }
      }
    }
  }
}

/* ---------- filters & search ---------- */
$q       = trim($_GET['q'] ?? '');
$f_role  = trim($_GET['role'] ?? '');
$f_stat  = trim($_GET['status'] ?? '');
$roles   = ['member','trainer','staff','admin'];
$statuses= ['pending','active','rejected'];

$where   = [];
$params  = [];
$types   = '';

if ($q !== '') {
  // search username/email/full_name
  if ($has_full_name) {
    $where[] = "(username LIKE CONCAT('%',?,'%') OR email LIKE CONCAT('%',?,'%') OR full_name LIKE CONCAT('%',?,'%'))";
    $params[]=$q; $params[]=$q; $params[]=$q; $types.='sss';
  } else {
    $where[] = "(username LIKE CONCAT('%',?,'%') OR email LIKE CONCAT('%',?,'%'))";
    $params[]=$q; $params[]=$q; $types.='ss';
  }
}
if ($f_role !== '' && in_array($f_role,$roles,true)) {
  $where[] = "role = ?";
  $params[] = $f_role; $types.='s';
}
if ($has_status && $f_stat !== '' && in_array($f_stat,$statuses,true)) {
  $where[] = "status = ?";
  $params[] = $f_stat; $types.='s';
}

$cols = "id, username, email, role".
        ($has_status     ? ", status" : "").
        ($has_full_name  ? ", full_name" : "").
        ($has_id_number  ? ", id_number" : "").
        ($has_valid_path ? ", valid_id_path" : "").
        ($has_valid_stat ? ", valid_id_status" : "").
        ($has_expires    ? ", membership_expires_at" : "").
        ($has_avatar     ? ", avatar_path" : "");

$sql = "SELECT $cols FROM users";
if ($where) $sql .= " WHERE ".implode(' AND ', $where);
$sql .= " ORDER BY role DESC, username ASC LIMIT 500";

$rows = [];
if ($st = $conn->prepare($sql)) {
  if ($params) { $ref=[&$types]; foreach($params as $k=>&$v){ $ref[]=&$v; } call_user_func_array([$st,'bind_param'], $ref); }
  $st->execute();
  $res = $st->get_result();
  if ($res) { $rows = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }
  $st->close();
}

// small util
function fmtDate($ts){ if(!$ts) return '—'; $t=strtotime($ts); return $t?date('M d, Y',$t):'—'; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>All User Info | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{--brand:#b30000;--hover:#ff1a1a;--bg:#111;--panel:#1a1a1a;--line:#2a2a2a;--muted:#aaa}
body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
.navbar{background:linear-gradient(90deg,#000,var(--brand))}
.card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}
.form-control,.custom-select{background:#121212;border:1px solid #2a2a2a;color:#eee}
.table td,.table th{vertical-align:middle}
.thumb{width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid #333;background:#222}
.badge-soft{background:#222;border:1px solid #333;color:#eee;padding:.2rem .45rem;border-radius:6px}
.btn-danger{background:var(--brand);border:none}.btn-danger:hover{background:var(--hover)}
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
  <div class="card p-3 mb-3">
    <h4 class="mb-3">All User Info</h4>

    <?php if ($flash && !$errors): ?>
      <div class="alert alert-success"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="get" class="form-row">
      <div class="form-group col-md-5">
        <label>Search</label>
        <input class="form-control" name="q" placeholder="name, username, email" value="<?= h($q) ?>">
      </div>
      <div class="form-group col-md-3">
        <label>Role</label>
        <select class="custom-select" name="role">
          <option value="">All</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= h($r) ?>" <?= $f_role===$r?'selected':''; ?>><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-3">
        <label>Status</label>
        <select class="custom-select" name="status" <?= $has_status?'':'disabled' ?>>
          <option value="">All</option>
          <?php foreach ($statuses as $s): ?>
            <option value="<?= h($s) ?>" <?= $f_stat===$s?'selected':''; ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$has_status): ?><small class="text-muted">users.status column not found</small><?php endif; ?>
      </div>
      <div class="form-group col-md-1 d-flex align-items-end">
        <button class="btn btn-danger btn-block">Go</button>
      </div>
    </form>
  </div>

  <div class="card p-3">
    <div class="table-responsive">
      <table class="table table-dark table-striped table-sm mb-0">
        <thead>
          <tr>
            <th>#</th>
            <th>Avatar</th>
            <th>Username / Name</th>
            <th>Email</th>
            <th>Role</th>
            <?php if ($has_status): ?><th>Status</th><?php endif; ?>
            <?php if ($has_expires): ?><th>Membership Expires</th><?php endif; ?>
            <?php if ($has_valid_stat || $has_valid_path): ?><th>Valid ID</th><?php endif; ?>
            <th style="min-width:280px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="text-center text-muted">No users found.</td></tr>
          <?php else: foreach ($rows as $u): ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td>
                <?php
                  $avatar = ($has_avatar && !empty($u['avatar_path'])) ? $u['avatar_path'] : 'photo/logo.jpg';
                ?>
                <img class="thumb" src="<?= h($avatar) ?>" alt="">
              </td>
              <td>
                <strong><?= h($u['username']) ?></strong><br>
                <small class="text-muted">
                  <?= $has_full_name ? h($u['full_name'] ?: '—') : '—' ?>
                  <?php if ($has_id_number && !empty($u['id_number'])): ?>
                    · ID: <?= h($u['id_number']) ?>
                  <?php endif; ?>
                </small>
              </td>
              <td><?= h($u['email']) ?></td>
              <td>
                <!-- change role -->
                <form method="post" class="form-inline mb-0">
                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="action" value="set_role">
                  <select name="new_role" class="custom-select custom-select-sm mr-2" style="width:130px">
                    <?php foreach (['member','trainer','staff','admin'] as $rr): ?>
                      <option value="<?= h($rr) ?>" <?= ($u['role']===$rr?'selected':'') ?>><?= ucfirst($rr) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-outline-light btn-sm">Save</button>
                </form>
              </td>

              <?php if ($has_status): ?>
                <td>
                  <span class="badge-soft"><?= h(ucfirst($u['status'])) ?></span>
                  <div class="mt-1">
                    <form method="post" class="form-inline mb-0">
                      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="action" value="set_status">
                      <select name="new_status" class="custom-select custom-select-sm mr-2">
                        <?php foreach (['pending','active','rejected'] as $ss): ?>
                          <option value="<?= h($ss) ?>" <?= ($u['status']===$ss?'selected':'') ?>><?= ucfirst($ss) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-outline-light btn-sm">Save</button>
                    </form>
                  </div>
                </td>
              <?php endif; ?>

              <?php if ($has_expires): ?>
                <td>
                  <?= h(fmtDate($u['membership_expires_at'] ?? null)) ?>
                  <form method="post" class="form-inline mt-1">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="action" value="set_expiry">
                    <input type="date" name="expires" class="form-control form-control-sm mr-2"
                           value="<?= !empty($u['membership_expires_at']) ? h(date('Y-m-d', strtotime($u['membership_expires_at']))) : '' ?>">
                    <button class="btn btn-outline-light btn-sm">Update</button>
                  </form>
                </td>
              <?php endif; ?>

              <?php if ($has_valid_stat || $has_valid_path): ?>
                <td>
                  <?php if ($has_valid_stat): ?>
                    <div>Status: <span class="badge-soft"><?= h(strtoupper($u['valid_id_status'] ?? 'NONE')) ?></span></div>
                  <?php endif; ?>
                  <?php if ($has_valid_path): ?>
                    <div>
                      <?php if (!empty($u['valid_id_path'])): ?>
                        <a href="<?= h($u['valid_id_path']) ?>" target="_blank">View</a>
                      <?php else: ?>
                        <span class="text-muted">No file</span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <?php if ($has_valid_stat): ?>
                    <form method="post" class="form-inline mt-1">
                      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="action" value="valid_id_status">
                      <select name="new_valid_id_status" class="custom-select custom-select-sm mr-2">
                        <?php foreach (['none','pending','approved','rejected'] as $vs): ?>
                          <option value="<?= h($vs) ?>" <?= (($u['valid_id_status']??'none')===$vs?'selected':'') ?>><?= ucfirst($vs) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-outline-light btn-sm">Save</button>
                    </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>

              <td>
                <div class="d-flex flex-wrap" style="gap:6px">
                  <a class="btn btn-sm btn-outline-light" href="upload_avatar.php?u=<?= (int)$u['id'] ?>" onclick="return alert('This page changes your own avatar. For changing others, add an admin tool.');">Avatar</a>
                  <a class="btn btn-sm btn-outline-light" href="upload_id.php?u=<?= (int)$u['id'] ?>" onclick="return alert('Members upload their own valid ID. Staff can approve here.');">Valid ID</a>
                  <a class="btn btn-sm btn-danger" href="payments.php?user=<?= (int)$u['id'] ?>">Payments</a>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>

</html>