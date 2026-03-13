<?php
// all_user_info.php — Two views: (1) Members only, (2) Staff/Trainer + attendance summary

if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

// allow staff/admin only
$role = strtolower((string)($_SESSION['role'] ?? ''));
if (!in_array($role, ['admin','staff'], true)) {
    header('Location: home.php');
    exit;
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function has_column(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $st->close();
    return $ok;
}

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('s', $table);
    $st->execute();
    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $st->close();
    return $ok;
}

function first_col(mysqli $conn, string $table, array $cands): ?string {
    foreach ($cands as $c) {
        if (has_column($conn, $table, $c)) return $c;
    }
    return null;
}

function first_existing_table(mysqli $conn, array $candidates): ?string {
    foreach ($candidates as $t) {
        if (table_exists($conn, $t)) return $t;
    }
    return null;
}

// ---------------- Detect optional columns in users ----------------
$HAS_FULLNAME = has_column($conn, 'users', 'full_name');
$HAS_EMAIL    = has_column($conn, 'users', 'email');
$HAS_STATUS   = has_column($conn, 'users', 'status');
$HAS_EXPIRY   = has_column($conn, 'users', 'membership_expires_at');

$AVATAR_COL = null;
if (has_column($conn,'users','avatar')) $AVATAR_COL = 'avatar';
elseif (has_column($conn,'users','profile_pic')) $AVATAR_COL = 'profile_pic';

$VALID_ID_STATUS_COL = null;
foreach (['valid_id_status','id_status','valid_id_approval','valid_id_state'] as $c) {
    if (has_column($conn,'users',$c)) { $VALID_ID_STATUS_COL = $c; break; }
}
$VALID_ID_FILE_COL = null;
foreach (['valid_id_path','valid_id_file','id_file','id_path','valid_id_image'] as $c) {
    if (has_column($conn,'users',$c)) { $VALID_ID_FILE_COL = $c; break; }
}

function badge_class($text){
    $t = strtolower(trim((string)$text));
    if ($t === 'active') return 'badge-success';
    if ($t === 'inactive') return 'badge-secondary';
    if ($t === 'approved') return 'badge-success';
    if ($t === 'pending') return 'badge-warning';
    if ($t === 'rejected') return 'badge-danger';
    if ($t === 'none') return 'badge-secondary';
    return 'badge-info';
}

// ---------------- Tabs (view) ----------------
$view = strtolower((string)($_GET['view'] ?? 'members')); // members | staff
if (!in_array($view, ['members','staff'], true)) $view = 'members';

// ---------------- Handle updates (POST) ----------------
$flash = null; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);
    $viewPost = strtolower((string)($_POST['view'] ?? $view));

    if ($uid <= 0) {
        $flash = "Invalid user.";
        $flashType = 'danger';
    } else {

        if ($action === 'update_status' && $HAS_STATUS) {
            $new = trim((string)($_POST['status'] ?? 'Active'));
            if ($new === '') $new = 'Active';

            $st = $conn->prepare("UPDATE users SET status=? WHERE id=? LIMIT 1");
            if ($st) {
                $st->bind_param('si', $new, $uid);
                $ok = $st->execute();
                $st->close();
                $flash = $ok ? "Status updated." : "Failed to update status.";
                $flashType = $ok ? 'success' : 'danger';
            }
        }

        if ($action === 'update_expiry' && $HAS_EXPIRY) {
            $expiry = trim((string)($_POST['membership_expires_at'] ?? ''));
            $val = ($expiry === '') ? null : ($expiry . ' 23:59:59');

            if ($val === null) {
                $st = $conn->prepare("UPDATE users SET membership_expires_at=NULL WHERE id=? LIMIT 1");
                if ($st) {
                    $st->bind_param('i', $uid);
                    $ok = $st->execute();
                    $st->close();
                    $flash = $ok ? "Membership expiry cleared." : "Failed to clear expiry.";
                    $flashType = $ok ? 'success' : 'danger';
                }
            } else {
                $st = $conn->prepare("UPDATE users SET membership_expires_at=? WHERE id=? LIMIT 1");
                if ($st) {
                    $st->bind_param('si', $val, $uid);
                    $ok = $st->execute();
                    $st->close();
                    $flash = $ok ? "Membership expiry updated." : "Failed to update expiry.";
                    $flashType = $ok ? 'success' : 'danger';
                }
            }
        }

        if ($action === 'update_valid_id' && $VALID_ID_STATUS_COL) {
            $new = trim((string)($_POST['valid_id_status'] ?? 'NONE'));
            if ($new === '') $new = 'NONE';

            $sql = "UPDATE users SET `{$VALID_ID_STATUS_COL}`=? WHERE id=? LIMIT 1";
            $st = $conn->prepare($sql);
            if ($st) {
                $st->bind_param('si', $new, $uid);
                $ok = $st->execute();
                $st->close();
                $flash = $ok ? "Valid ID status updated." : "Failed to update Valid ID status.";
                $flashType = $ok ? 'success' : 'danger';
            }
        }
    }

    // keep current view after POST
    $view = in_array($viewPost, ['members','staff'], true) ? $viewPost : $view;
}

// ---------------- Filters ----------------
$q      = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));

// role filters
$roleFilter = [];
if ($view === 'members') {
    $roleFilter = ['member'];
} else {
    $roleFilter = ['staff','trainer'];
}

// Build WHERE
$where = [];
$params = [];
$types  = "";

// role in (...)
$in = implode(',', array_fill(0, count($roleFilter), '?'));
$where[] = "LOWER(role) IN ($in)";
foreach ($roleFilter as $rv) { $params[] = strtolower($rv); $types .= "s"; }

// status filter
if ($HAS_STATUS && $status !== '' && strtolower($status) !== 'all') {
    $where[] = "status = ?";
    $params[] = $status;
    $types .= "s";
}

// search filter
if ($q !== '') {
    $like = "%{$q}%";
    $parts = [];
    $parts[] = "username LIKE ?";
    $params[] = $like; $types .= "s";

    if ($HAS_FULLNAME) { $parts[] = "full_name LIKE ?"; $params[] = $like; $types .= "s"; }
    if ($HAS_EMAIL)    { $parts[] = "email LIKE ?";     $params[] = $like; $types .= "s"; }

    $where[] = "(" . implode(" OR ", $parts) . ")";
}

// ---------------- Attendance summary ONLY for staff/trainers ----------------
$attTable = null;
$attUserCol = null;
$attTimeCol = null;

$attendanceEnabled = false;
if ($view === 'staff') {
    $attTable = first_existing_table($conn, [
        'attendance','attendances','attendance_logs','member_attendance','rfid_attendance'
    ]);
    if ($attTable) {
        $attUserCol = first_col($conn, $attTable, ['user_id','member_id','uid','userID','memberID']);
        $attTimeCol = first_col($conn, $attTable, ['time_in','check_in_time','check_in','created_at','datetime','date_time']);
        if ($attUserCol && $attTimeCol) $attendanceEnabled = true;
    }
}

// Subquery for attendance (visits + last check-in)
$attJoinSql = "";
$attSelectSql = "";
if ($attendanceEnabled) {
    $attJoinSql = " LEFT JOIN (
        SELECT `{$attUserCol}` AS auid,
               COUNT(*) AS visits,
               MAX(`{$attTimeCol}`) AS last_checkin
        FROM `{$attTable}`
        GROUP BY `{$attUserCol}`
    ) att ON att.auid = u.id ";
    $attSelectSql = ", COALESCE(att.visits,0) AS visits, att.last_checkin ";
}

// ---------------- Query users ----------------
$sql = "SELECT u.id, u.username, u.role"
     . ($HAS_FULLNAME ? ", u.full_name" : "")
     . ($HAS_EMAIL ? ", u.email" : "")
     . ($HAS_STATUS ? ", u.status" : "")
     . ($HAS_EXPIRY ? ", u.membership_expires_at" : "")
     . ($AVATAR_COL ? ", u.`{$AVATAR_COL}` AS avatar" : "")
     . ($VALID_ID_STATUS_COL ? ", u.`{$VALID_ID_STATUS_COL}` AS valid_id_status" : "")
     . ($VALID_ID_FILE_COL ? ", u.`{$VALID_ID_FILE_COL}` AS valid_id_file" : "")
     . $attSelectSql
     . " FROM users u "
     . $attJoinSql
     . " WHERE " . implode(" AND ", $where)
     . " ORDER BY u.id DESC";

$rows = [];
$st = $conn->prepare($sql);
if ($st) {
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();
    if ($res) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
    $st->close();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>All User Info | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">

<style>
:root{
  --brand:#b30000; --brand2:#ff3333;
  --bg:#0b0b0b; --panel:#121212; --line:#2a2a2a;
  --muted:#a9a9a9; --text:#f5f5f5; --shadow:0 18px 35px rgba(0,0,0,.55); --r:18px;
}
*{box-sizing:border-box}
body{
  margin:0; min-height:100vh;
  background: radial-gradient(circle at top, #111 0, #000 55%, #000 100%);
  color:var(--text);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
}
.topbar{
  position:sticky;top:0;z-index:1000;height:64px;
  display:flex;align-items:center;justify-content:space-between;
  padding:0 22px;background:linear-gradient(90deg,#000,var(--brand));
  box-shadow:0 10px 25px rgba(0,0,0,.5);
}
.brand{
  display:flex;align-items:center;color:#fff;text-decoration:none;
  font-weight:900;letter-spacing:.04em;
}
.brand img{height:34px;border-radius:8px;margin-right:10px;}
.main-wrap{max-width:1180px;margin:22px auto 48px;padding:0 18px;}
.card-dark{
  background: radial-gradient(circle at top, #222 0, #111 55%, #0b0b0b 100%);
  border:1px solid var(--line);
  border-radius:var(--r);
  box-shadow:var(--shadow);
  padding:18px 20px;
}
.muted{color:var(--muted)}
.btn-pill{border-radius:999px;font-weight:900;letter-spacing:.04em;}
.btn-red{background:linear-gradient(120deg,var(--brand),var(--brand2));border:none;color:#fff;}
.btn-red:hover{filter:brightness(1.08);color:#fff;}
.btn-outline-soft{
  border-radius:999px;border:1px solid rgba(255,255,255,.18);
  background:transparent;color:#fff;
}
.btn-outline-soft:hover{background:rgba(255,255,255,.08);color:#fff;}

.tabs{
  display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;
}
.tabbtn{
  text-decoration:none;
  padding:8px 12px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.18);
  color:#fff;
  font-weight:900;
}
.tabbtn.active{
  background:linear-gradient(120deg,var(--brand),var(--brand2));
  border:none;
}
.filters{
  display:grid;
  grid-template-columns: 1.6fr 1fr auto;
  gap:12px;
  align-items:end;
  margin-top:14px;
}
@media(max-width: 991px){ .filters{grid-template-columns:1fr;} }

.form-control, .custom-select{
  background:#121212;border:1px solid #2a2a2a;color:#eee;
  border-radius:12px;
}
.table-wrap{
  overflow:auto;border-radius:14px;border:1px solid rgba(255,255,255,.10);
}
.table{margin:0;color:#eee;background:transparent;}
.table thead th{
  position:sticky;top:0;background:#1a1a1a;
  border-bottom:1px solid rgba(255,255,255,.10);
  color:#cfcfcf;font-size:.78rem;text-transform:uppercase;letter-spacing:.10em;
}
.table td{border-top:1px solid rgba(255,255,255,.06);vertical-align:middle;}
.avatar{
  width:42px;height:42px;border-radius:999px;overflow:hidden;
  border:2px solid rgba(255,255,255,.18);background:#222;
}
.avatar img{width:100%;height:100%;object-fit:cover;display:block;}
.small-actions{display:flex;gap:8px;flex-wrap:wrap;}
.badge{border-radius:999px;padding:.35rem .6rem;}
.role-chip{
  display:inline-flex;
  padding:2px 10px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.14);
  background:#111;
  color:#ddd;
  font-size:.72rem;
  font-weight:900;
  margin-left:8px;
}
</style>
</head>

<body>
<header class="topbar">
  <a class="brand" href="home.php">
    <img src="photo/logo.jpg" alt="RJL">
    <span>RJL Fitness</span>
  </a>
  <div>
    <a class="btn btn-sm btn-outline-soft btn-pill" href="home.php">Home</a>
    <a class="btn btn-sm btn-red btn-pill" href="logout.php">Logout</a>
  </div>
</header>

<main class="main-wrap">

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flashType) ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <section class="card-dark mb-3">
    <h4 style="margin:0;font-weight:1000;">All User Info</h4>
    <div class="muted">
      <?= $view === 'members' ? 'Members view (member only)' : 'Staff/Trainer view + attendance summary' ?>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <a class="tabbtn <?= $view==='members'?'active':'' ?>" href="all_user_info.php?view=members">👤 Members Only</a>
      <a class="tabbtn <?= $view==='staff'?'active':'' ?>" href="all_user_info.php?view=staff">🧑‍🏫 Staff / Trainer Attendance</a>
    </div>

    <!-- Filters -->
    <form class="filters" method="get">
      <input type="hidden" name="view" value="<?= h($view) ?>">

      <div>
        <label class="muted mb-1">Search</label>
        <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="name, username, email">
      </div>

      <div>
        <label class="muted mb-1">Status</label>
        <select class="custom-select" name="status">
          <option value="all" <?= strtolower($status)==='all'?'selected':''; ?>>All</option>
          <option value="Active" <?= $status==='Active'?'selected':''; ?>>Active</option>
          <option value="Inactive" <?= $status==='Inactive'?'selected':''; ?>>Inactive</option>
          <option value="Pending" <?= $status==='Pending'?'selected':''; ?>>Pending</option>
        </select>
      </div>

      <div>
        <button class="btn btn-red btn-pill" type="submit" style="height:38px;min-width:90px;">Go</button>
      </div>
    </form>
  </section>

  <section class="card-dark">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="muted">Results: <strong><?= count($rows) ?></strong></div>
      <div class="muted">
        <?= $view==='members' ? 'Showing members only' : 'Showing staff + trainers only' ?>
        <?= $attendanceEnabled ? ' • Attendance: ON' : ($view==='staff' ? ' • Attendance: N/A' : '') ?>
      </div>
    </div>

    <div class="table-wrap">
      <table class="table table-borderless">
        <thead>
          <tr>
            <th style="width:60px;">ID</th>
            <th style="width:70px;">Avatar</th>
            <th>Username / Name</th>
            <?php if ($HAS_EMAIL): ?><th>Email</th><?php endif; ?>
            <?php if ($HAS_STATUS): ?><th style="width:200px;">Status</th><?php endif; ?>

            <?php if ($view==='members' && $HAS_EXPIRY): ?>
              <th style="width:230px;">Membership Expires</th>
            <?php endif; ?>

            <?php if ($view==='staff'): ?>
              <th style="width:130px;">Visits</th>
              <th style="width:220px;">Last Check-in</th>
            <?php endif; ?>

            <?php if ($VALID_ID_STATUS_COL): ?><th style="width:260px;">Valid ID</th><?php endif; ?>
            <th style="width:240px;">Actions</th>
          </tr>
        </thead>

        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="12" class="muted">No results found.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <?php
            $avatar = $r['avatar'] ?? 'photo/logo.jpg';
            $fullname = $HAS_FULLNAME ? ($r['full_name'] ?? '') : '';
            $email = $HAS_EMAIL ? ($r['email'] ?? '') : '';
            $statusVal = $HAS_STATUS ? ($r['status'] ?? 'Active') : '';
            $expiryVal = $HAS_EXPIRY ? ($r['membership_expires_at'] ?? '') : '';
            $expiryDate = $expiryVal ? substr($expiryVal,0,10) : '';
            $vidStatus = $VALID_ID_STATUS_COL ? ($r['valid_id_status'] ?? 'NONE') : '';
            $vidFile = $VALID_ID_FILE_COL ? ($r['valid_id_file'] ?? '') : '';
            $userRole = strtolower((string)($r['role'] ?? ''));
            $visits = isset($r['visits']) ? (int)$r['visits'] : null;
            $lastCheck = $r['last_checkin'] ?? null;
            $lastCheckText = $lastCheck ? @date('M d – g:ia', strtotime($lastCheck)) : '—';
          ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>

            <td>
              <div class="avatar">
                <img src="<?= h($avatar ?: 'photo/logo.jpg') ?>" alt="">
              </div>
            </td>

            <td>
              <div style="font-weight:1000;">
                <?= h($r['username'] ?? '') ?>
                <?php if ($view==='staff'): ?>
                  <span class="role-chip"><?= h(strtoupper($userRole)) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($fullname !== ''): ?>
                <div class="muted" style="font-size:.88rem;"><?= h($fullname) ?></div>
              <?php endif; ?>
            </td>

            <?php if ($HAS_EMAIL): ?>
              <td><?= h($email) ?></td>
            <?php endif; ?>

            <?php if ($HAS_STATUS): ?>
              <td>
                <form method="post" class="d-flex align-items-center" style="gap:8px;">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="view" value="<?= h($view) ?>">
                  <select name="status" class="custom-select" style="max-width:140px;">
                    <option value="Active" <?= $statusVal==='Active'?'selected':''; ?>>Active</option>
                    <option value="Inactive" <?= $statusVal==='Inactive'?'selected':''; ?>>Inactive</option>
                    <option value="Pending" <?= $statusVal==='Pending'?'selected':''; ?>>Pending</option>
                  </select>
                  <button class="btn btn-sm btn-outline-soft btn-pill" type="submit">Save</button>
                </form>
              </td>
            <?php endif; ?>

            <?php if ($view==='members' && $HAS_EXPIRY): ?>
              <td>
                <form method="post" class="d-flex align-items-center" style="gap:8px;">
                  <input type="hidden" name="action" value="update_expiry">
                  <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="view" value="<?= h($view) ?>">
                  <input type="date" name="membership_expires_at" class="form-control" style="max-width:160px;"
                         value="<?= h($expiryDate) ?>">
                  <button class="btn btn-sm btn-outline-soft btn-pill" type="submit">Update</button>
                </form>
              </td>
            <?php endif; ?>

            <?php if ($view==='staff'): ?>
              <td><?= $attendanceEnabled ? h($visits) : 'N/A' ?></td>
              <td><?= $attendanceEnabled ? h($lastCheckText) : 'N/A' ?></td>
            <?php endif; ?>

            <?php if ($VALID_ID_STATUS_COL): ?>
              <td>
                <div class="mb-2">
                  Status:
                  <span class="badge <?= h(badge_class($vidStatus)) ?>"><?= h($vidStatus ?: 'NONE') ?></span>
                </div>

                <form method="post" class="d-flex align-items-center" style="gap:8px;">
                  <input type="hidden" name="action" value="update_valid_id">
                  <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="view" value="<?= h($view) ?>">
                  <select name="valid_id_status" class="custom-select" style="max-width:140px;">
                    <option value="NONE" <?= strtoupper($vidStatus)==='NONE'?'selected':''; ?>>NONE</option>
                    <option value="PENDING" <?= strtoupper($vidStatus)==='PENDING'?'selected':''; ?>>PENDING</option>
                    <option value="APPROVED" <?= strtoupper($vidStatus)==='APPROVED'?'selected':''; ?>>APPROVED</option>
                    <option value="REJECTED" <?= strtoupper($vidStatus)==='REJECTED'?'selected':''; ?>>REJECTED</option>
                  </select>
                  <button class="btn btn-sm btn-outline-soft btn-pill" type="submit">Save</button>

                  <?php if ($VALID_ID_FILE_COL && $vidFile): ?>
                    <a class="btn btn-sm btn-red btn-pill" target="_blank" href="<?= h($vidFile) ?>">View</a>
                  <?php endif; ?>
                </form>
              </td>
            <?php endif; ?>

            <td>
              <div class="small-actions">
                <a class="btn btn-sm btn-outline-soft btn-pill" href="admin_user_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>

                <?php if ($view==='staff'): ?>
                  <a class="btn btn-sm btn-red btn-pill" href="attendance.php?user_id=<?= (int)$r['id'] ?>">Attendance</a>
                <?php endif; ?>

                <?php if ($VALID_ID_FILE_COL && $vidFile): ?>
                  <a class="btn btn-sm btn-red btn-pill" target="_blank" href="<?= h($vidFile) ?>">Valid ID</a>
                <?php endif; ?>
              </div>
            </td>

          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </section>
</main>

</body>
</html>