<?php
// all_attendance.php — staff/admin can view + manage attendance
if (session_status() === PHP_SESSION_NONE) session_start();

require __DIR__ . '/db.php';

// Only staff/admin allowed
$role = strtolower($_SESSION['role'] ?? '');
if (!in_array($role, ['staff', 'admin'], true)) {
    header('Location: home.php');
    exit;
}

// Set timezone so date/time matches PH time
date_default_timezone_set('Asia/Manila');

$today = date('Y-m-d');

// Read filters from GET or POST
$selectedDate = $_POST['date'] ?? $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = $today;
}

$search = trim($_POST['q'] ?? $_GET['q'] ?? '');

// Flash messages after redirect
$flash = $_SESSION['all_attendance_flash'] ?? '';
$flashType = $_SESSION['all_attendance_flash_type'] ?? 'info';
$flashFileUrl = $_SESSION['all_attendance_file_url'] ?? '';

unset($_SESSION['all_attendance_flash']);
unset($_SESSION['all_attendance_flash_type']);
unset($_SESSION['all_attendance_file_url']);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Reset a single row
    if (isset($_POST['reset_attendance_id'])) {
        $attId = (int)$_POST['reset_attendance_id'];

        if ($attId > 0) {
            if ($st = $conn->prepare("UPDATE attendance SET time_in = NULL, time_out = NULL WHERE id = ?")) {
                $st->bind_param('i', $attId);
                $ok = $st->execute();
                $st->close();

                $_SESSION['all_attendance_flash'] = $ok
                    ? 'Time in/out cleared for this record.'
                    : 'Failed to clear attendance record.';

                $_SESSION['all_attendance_flash_type'] = $ok ? 'success' : 'danger';
            } else {
                $_SESSION['all_attendance_flash'] = 'Database prepare failed while resetting attendance.';
                $_SESSION['all_attendance_flash_type'] = 'danger';
            }
        }
    }

    // Save selected date attendance into attendance_storage folder
    elseif (isset($_POST['save_all'])) {
        $libPath = __DIR__ . '/attendance_archive_lib.php';

        if (!is_file($libPath)) {
            $_SESSION['all_attendance_flash'] = 'Missing attendance_archive_lib.php. Create that file first.';
            $_SESSION['all_attendance_flash_type'] = 'danger';
        } else {
            require_once $libPath;

            if (!function_exists('archive_attendance_day')) {
                $_SESSION['all_attendance_flash'] = 'archive_attendance_day() function not found in attendance_archive_lib.php.';
                $_SESSION['all_attendance_flash_type'] = 'danger';
            } else {
                $result = archive_attendance_day($conn, $selectedDate);

                $_SESSION['all_attendance_flash'] = $result['message'] ?? 'Attendance save completed.';
                $_SESSION['all_attendance_flash_type'] = !empty($result['ok']) ? 'success' : 'danger';

                if (!empty($result['ok']) && !empty($result['file_url'])) {
                    $_SESSION['all_attendance_file_url'] = $result['file_url'];
                }
            }
        }
    }

    // Redirect to avoid resubmission
    $qs = http_build_query([
        'date' => $selectedDate,
        'q' => $search
    ]);

    header('Location: all_attendance.php?' . $qs);
    exit;
}

// Load current staff/admin user for navbar avatar and info
$userId = (int)($_SESSION['user_id'] ?? 0);

$user = [
    'username'         => $_SESSION['username'] ?? '',
    'full_name'        => '',
    'email'            => '',
    'id_number'        => '',
    'valid_id_status'  => '',
    'valid_id_path'    => ''
];

$avatarPath = 'photo/logo.jpg';

if ($userId > 0 && $st = $conn->prepare("SELECT full_name, email, id_number, valid_id_status, valid_id_path FROM users WHERE id = ? LIMIT 1")) {
    $st->bind_param('i', $userId);
    $st->execute();

    $res = $st->get_result();

    if ($row = $res->fetch_assoc()) {
        $user['full_name']       = $row['full_name'] ?? '';
        $user['email']           = $row['email'] ?? '';
        $user['id_number']       = $row['id_number'] ?? '';
        $user['valid_id_status'] = $row['valid_id_status'] ?? '';
        $user['valid_id_path']   = $row['valid_id_path'] ?? '';
    }

    $res->free();
    $st->close();
}

// Build query: show ALL users, left-join attendance for this date
$rows = [];

$sql = "SELECT 
          u.id          AS user_id,
          u.full_name,
          u.username,
          u.id_number,
          u.role,
          a.id          AS attendance_id,
          a.attendance_date,
          a.time_in,
          a.time_out
        FROM users u
        LEFT JOIN attendance a
          ON a.user_id = u.id
         AND a.attendance_date = ?
        WHERE 1=1";

$params = [$selectedDate];
$types  = 's';

// Search by name / username / id_number / role
if ($search !== '') {
    $sql .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.id_number LIKE ? OR u.role LIKE ?)";
    $like = '%' . $search . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    $types .= 'ssss';
}

$sql .= " ORDER BY u.full_name ASC, u.username ASC";

if ($st = $conn->prepare($sql)) {
    $bind = [];
    $bind[] = &$types;

    foreach ($params as $k => &$v) {
        $bind[] = &$v;
    }

    call_user_func_array([$st, 'bind_param'], $bind);

    $st->execute();
    $res = $st->get_result();

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }

        $res->free();
    }

    $st->close();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>All Attendance | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">

<style>
:root{
  --brand:#b30000;
  --brand-soft:#ff4d4d;
  --bg:#111;
  --panel:#1a1a1a;
  --line:#2a2a2a;
  --text:#f5f5f5;
  --muted:#9ca3af;
}

*{
  box-sizing:border-box;
  font-family:'Poppins',system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}

body{
  margin:0;
  background:var(--bg);
  color:var(--text);
}

.navbar{
  background:linear-gradient(90deg,#000,var(--brand));
  position:relative;
  z-index:1;
}

.burger-btn{
  width:40px;
  height:32px;
  border-radius:10px;
  border:1px solid #333;
  background:#111;
  display:flex;
  flex-direction:column;
  justify-content:center;
  padding:4px;
  cursor:pointer;
  margin-right:10px;
}

.burger-line{
  height:2px;
  background:#fff;
  border-radius:999px;
  margin:3px 0;
}

.profile-wrap{
  position:relative;
}

.profile-circle{
  width:42px;
  height:42px;
  border-radius:50%;
  overflow:hidden;
  border:2px solid var(--brand-soft);
  background:#222;
  cursor:pointer;
}

.profile-img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}

.profile-panel{
  position:absolute;
  right:0;
  top:calc(100% + 10px);
  width:320px;
  max-width:90vw;
  background:var(--panel);
  border:1px solid var(--line);
  border-radius:12px;
  box-shadow:0 16px 40px rgba(0,0,0,.6);
  padding:14px;
  display:none;
  z-index:3000;
}

.profile-panel.show{
  display:block;
}

.panel-row{
  display:flex;
  justify-content:space-between;
  margin:4px 0;
  font-size:.85rem;
}

.panel-row span:first-child{
  color:var(--muted);
}

.sidebar{
  position:fixed;
  top:56px;
  left:0;
  bottom:0;
  width:230px;
  background:var(--panel);
  border-right:1px solid var(--line);
  padding:10px;
  transform:translateX(-100%);
  transition:transform .2s ease-out;
  z-index:900;
}

.sidebar.show{
  transform:translateX(0);
}

.side-link{
  display:block;
  padding:9px 10px;
  margin:4px 0;
  border-radius:10px;
  background:#161616;
  border:1px solid #262626;
  color:#eee;
  text-decoration:none;
  font-size:.9rem;
}

.side-link:hover{
  background:#202020;
  text-decoration:none;
  color:#fff;
}

.main-wrap{
  padding:16px;
}

@media (min-width: 992px){
  .sidebar{
    position:static;
    transform:none;
    width:230px;
  }

  .layout-row{
    display:flex;
  }

  .main-wrap{
    flex:1;
    padding:16px 16px 16px 8px;
  }
}

.card-panel{
  background:var(--panel);
  border-radius:14px;
  border:1px solid var(--line);
  padding:18px;
}

.badge-soft{
  background:#222;
  border:1px solid #333;
  color:#eee;
  padding:.2rem .5rem;
  border-radius:999px;
  font-size:.75rem;
}

.table thead th{
  border-bottom:1px solid var(--line);
  color:var(--muted);
  font-size:.8rem;
  text-transform:uppercase;
  letter-spacing:.08em;
}

.table td{
  border-top:1px solid #222;
  font-size:.85rem;
  vertical-align:middle;
}

.table-dark{
  background:#101010;
  color:#eee;
}

.table-dark tbody tr:hover{
  background:#181818;
}

.btn-danger{
  background:var(--brand);
  border:none;
}

.btn-danger:hover{
  background:#e60000;
}

.btn-outline-light{
  border-color:var(--brand-soft);
  color:#fff;
}

.btn-outline-light:hover{
  background:var(--brand-soft);
}

.filter-label{
  font-size:.8rem;
  color:var(--muted);
  margin-bottom:2px;
}

.small-muted{
  font-size:.8rem;
  color:var(--muted);
}

.save-actions{
  gap:10px;
}
</style>
</head>

<body>

<nav class="navbar navbar-dark px-3">
  <div class="d-flex align-items-center">
    
      <span class="burger-line"></span>
      <span class="burger-line"></span>
      <span class="burger-line"></span>
    </button>

    <a class="navbar-brand d-flex align-items-center" href="home_staff.php">
      <img src="photo/logo.jpg" height="30" class="mr-2" alt="">
      <span>RJL Fitness Staff</span>
    </a>
  </div>

  <div class="ml-auto d-flex align-items-center">
    <span class="mr-3 d-none d-sm-inline">
      <?= htmlspecialchars($user['full_name'] ?: $user['username']) ?>
    </span>

    <div class="profile-wrap" id="user-info">
      <button id="profileBtn" class="profile-circle" type="button"
              aria-haspopup="true" aria-expanded="false" aria-controls="profilePanel">
        <img src="<?= htmlspecialchars($avatarPath) ?>" class="profile-img" alt="Profile">
      </button>

      <div id="profilePanel" class="profile-panel" role="dialog" aria-hidden="true">
        <div class="panel-row">
          <span>Name</span>
          <span><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></span>
        </div>

        <div class="panel-row">
          <span>Email</span>
          <span><?= htmlspecialchars($user['email'] ?: '—') ?></span>
        </div>

        <div class="panel-row">
          <span>ID Number</span>
          <span><?= htmlspecialchars($user['id_number'] ?: 'Not set') ?></span>
        </div>

        <div class="panel-row">
          <span>Valid ID Status</span>
          <span><?= htmlspecialchars(strtoupper($user['valid_id_status'] ?: 'NONE')) ?></span>
        </div>

        <div class="panel-row">
          <span>Valid ID</span>
          <span>
            <?php if (!empty($user['valid_id_path'])): ?>
              <a target="_blank" href="<?= htmlspecialchars($user['valid_id_path']) ?>">View</a>
            <?php else: ?>
              Not uploaded
            <?php endif; ?>
          </span>
        </div>

        <hr>

        <a href="upload_id.php" class="btn btn-outline-light btn-block mb-2">Upload / Replace Valid ID</a>
        <a href="change_password.php" class="btn btn-outline-light btn-block mb-2">Change Password</a>
        <a href="logout.php" class="btn btn-danger btn-block">Logout</a>
      </div>
    </div>
  </div>
</nav>

<div class="layout-row">



  <!-- Main content -->
  <div class="main-wrap">
    <div class="card-panel">

      <div class="d-flex justify-content-between align-items-center mb-2">
        <h4 class="mb-0">All Attendance</h4>
        <span class="small-muted">RFID-based time in / time out</span>
      </div>

      <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flashType) ?> py-2">
          <?= htmlspecialchars($flash) ?>

          <?php if ($flashFileUrl): ?>
            <br>
            <a href="<?= htmlspecialchars($flashFileUrl) ?>" target="_blank">
              Open saved attendance file
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Filters: Date + Search -->
      <form method="get" class="mb-3">
        <div class="form-row">
          <div class="col-md-3 mb-2">
            <label class="filter-label">Attendance Date</label>
            <input type="date" name="date" class="form-control"
                   value="<?= htmlspecialchars($selectedDate) ?>">
          </div>

          <div class="col-md-5 mb-2">
            <label class="filter-label">Search (name / ID number / role)</label>
            <input type="text" name="q" class="form-control"
                   placeholder="Type name, ID number, or role…"
                   value="<?= htmlspecialchars($search) ?>">
          </div>

          <div class="col-md-4 mb-2 d-flex align-items-end">
            <button type="submit" class="btn btn-danger mr-2">Search</button>
            <a href="all_attendance.php" class="btn btn-outline-light">Clear</a>
          </div>
        </div>
      </form>

      <!-- Info -->
      <p class="small-muted mb-2">
        First RFID swipe = <strong>Time In</strong>,
        second = <strong>Time Out</strong>,
        3rd+ swipe same day updates <strong>Time Out</strong>.
      </p>

      <!-- Attendance table -->
      <div class="table-responsive">
        <table class="table table-dark table-sm mb-2">
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>ID Number</th>
              <th>Role</th>
              <th>Date</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th>Action</th>
            </tr>
          </thead>

          <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="8" class="text-center small-muted">
                No users found.
              </td>
            </tr>
          <?php else: ?>
            <?php $i = 1; foreach ($rows as $row): ?>
              <?php
                $attId = $row['attendance_id'] ?? null;
                $timeIn  = $row['time_in']  ?? null;
                $timeOut = $row['time_out'] ?? null;
              ?>

              <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['full_name'] ?: $row['username']) ?></td>
                <td><?= htmlspecialchars($row['id_number'] ?: '—') ?></td>
                <td><?= htmlspecialchars(ucfirst($row['role'] ?? '')) ?></td>
                <td><?= htmlspecialchars($selectedDate) ?></td>
                <td><?= $timeIn  ? htmlspecialchars(substr($timeIn, 0, 5))  : '—' ?></td>
                <td><?= $timeOut ? htmlspecialchars(substr($timeOut, 0, 5)) : '—' ?></td>
                <td>
                  <?php if ($attId && ($timeIn || $timeOut)): ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="reset_attendance_id" value="<?= (int)$attId ?>">
                      <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
                      <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">

                      <button type="submit"
                              class="btn btn-sm btn-outline-light"
                              onclick="return confirm('Clear time in/out for this record?');">
                        Reset
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="small-muted">No record</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Bottom buttons -->
      <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 save-actions">

        <div class="d-flex flex-wrap save-actions">
          <form method="post" class="mb-2">
            <input type="hidden" name="save_all" value="1">
            <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">

            <button type="submit"
                    class="btn btn-outline-light"
                    onclick="return confirm('Save attendance for <?= htmlspecialchars($selectedDate) ?> to Attendance Storage? If already saved, the old file will be replaced with the updated one.');">
              Save Attendance to Storage (<?= htmlspecialchars($selectedDate) ?>)
            </button>
          </form>

          <a href="attendance_storage.php" class="btn btn-outline-light mb-2">
            View Attendance Storage
          </a>
        </div>

        <button type="button" class="btn btn-danger mb-2"
                data-toggle="modal" data-target="#rfidModal">
          Log Attendance via RFID
        </button>
      </div>

    </div>
  </div>
</div>

<!-- RFID Modal -->
<div class="modal fade" id="rfidModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:#1a1a1a;color:#fff;">
      <div class="modal-header border-0">
        <h5 class="modal-title">RFID Attendance</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <p class="small-muted">
          Focus the box below, then let the RFID reader type the card number.
          First swipe = Time In, second = Time Out, 3rd+ swipes today update Time Out.
        </p>

        <input type="text" id="rfidInput" class="form-control text-center"
               placeholder="Tap card or type RFID UID">

        <div id="rfidStatus" class="mt-2 small"></div>
      </div>

      <div class="modal-footer border-0">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
  const burgerBtn    = document.getElementById('burgerBtn');
  const sidebar      = document.getElementById('sidebar');
  const profileBtn   = document.getElementById('profileBtn');
  const profilePanel = document.getElementById('profilePanel');
  const rfidInput    = document.getElementById('rfidInput');
  const rfidStatus   = document.getElementById('rfidStatus');

  // Burger menu
  if (burgerBtn && sidebar) {
    burgerBtn.addEventListener('click', function(e){
      e.preventDefault();
      sidebar.classList.toggle('show');
    });
  }

  // Profile dropdown
  function openPanel(){
    if (!profilePanel) return;

    profilePanel.classList.add('show');
    profilePanel.setAttribute('aria-hidden', 'false');

    if (profileBtn) {
      profileBtn.setAttribute('aria-expanded', 'true');
    }
  }

  function closePanel(){
    if (!profilePanel) return;

    profilePanel.classList.remove('show');
    profilePanel.setAttribute('aria-hidden', 'true');

    if (profileBtn) {
      profileBtn.setAttribute('aria-expanded', 'false');
    }
  }

  if (profileBtn) {
    profileBtn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();

      if (profilePanel.classList.contains('show')) {
        closePanel();
      } else {
        openPanel();
      }
    });
  }

  if (profilePanel) {
    profilePanel.addEventListener('click', function(e){
      e.stopPropagation();
    });
  }

  document.addEventListener('click', function(){
    if (profilePanel && profilePanel.classList.contains('show')) {
      closePanel();
    }
  });

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && profilePanel && profilePanel.classList.contains('show')) {
      e.preventDefault();
      closePanel();
    }
  });

  // RFID modal behavior
  $('#rfidModal').on('shown.bs.modal', function(){
    if (rfidInput) {
      rfidInput.value = '';
      rfidInput.focus();

      if (rfidStatus) {
        rfidStatus.textContent = '';
      }
    }
  });

  function showStatus(msg, isError){
    if (!rfidStatus) return;

    rfidStatus.textContent = msg;
    rfidStatus.style.color = isError ? '#ff9999' : '#99ff99';
  }

  if (rfidInput) {
    rfidInput.addEventListener('keydown', function(e){
      if (e.key === 'Enter') {
        e.preventDefault();

        const uid = rfidInput.value.trim();

        if (!uid) {
          showStatus('Please tap card or type RFID UID.', true);
          return;
        }

        fetch('rfid_attendance.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: 'card_id=' + encodeURIComponent(uid)
        })
        .then(r => r.json())
        .then(data => {
          if (data && data.success) {
            showStatus(data.message || 'Attendance updated.', false);

            setTimeout(function(){
              window.location.reload();
            }, 700);
          } else {
            showStatus((data && data.message) || 'Error logging attendance.', true);
          }
        })
        .catch(() => {
          showStatus('Network error while logging attendance.', true);
        });
      }
    });
  }
})();
</script>

</body>
</html>