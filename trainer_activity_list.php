<?php
// trainer_activity_list.php
// Trainer/Admin: view member activity (read-only, with member dropdown)

if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

// Check role
$role   = strtolower($_SESSION['role'] ?? 'member');
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}
if (!in_array($role, ['trainer','admin'], true)) {
    header('Location: home.php');
    exit;
}

// Load current user for header profile
$user = [
    'username'        => $_SESSION['username'] ?? '',
    'full_name'       => $_SESSION['full_name'] ?? '',
    'email'           => '',
    'id_number'       => '',
    'valid_id_path'   => '',
    'valid_id_status' => 'none',
];

if ($st = $conn->prepare("SELECT email, full_name, id_number, valid_id_path, valid_id_status FROM users WHERE id = ? LIMIT 1")) {
    $st->bind_param('i', $userId);
    $st->execute();
    $res = $st->get_result();
    if ($row = $res->fetch_assoc()) {
        $user['email']           = $row['email'] ?? '';
        $user['full_name']       = $row['full_name'] ?: ($user['username']);
        $user['id_number']       = $row['id_number'] ?? '';
        $user['valid_id_path']   = $row['valid_id_path'] ?? '';
        $user['valid_id_status'] = $row['valid_id_status'] ?? 'none';
    }
    $res->free();
    $st->close();
}

$avatarPath = 'photo/logo.jpg'; // same as home_member for now

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---- Load all active members for dropdown (now also get id_number) ----
$members = [];
$mres = $conn->query("SELECT id, username, full_name, id_number FROM users WHERE role='member' AND status='active' ORDER BY full_name, username");
if ($mres) {
    while ($row = $mres->fetch_assoc()) {
        $members[] = $row;
    }
    $mres->free();
}

// ---- Determine selected member ----
$selectedMemberId   = 0;
$selectedMemberName = '';

if (isset($_GET['member_id'])) {
    $selectedMemberId = (int)$_GET['member_id'];
} elseif (!empty($members)) {
    $selectedMemberId = (int)$members[0]['id']; // default: first
}

if ($selectedMemberId > 0 && $members) {
    foreach ($members as $m) {
        if ((int)$m['id'] === $selectedMemberId) {
            $selectedMemberName = $m['full_name'] ?: $m['username'];
            break;
        }
    }
}

// ---- Load activities for that member (read-only, same style as activity_list.php) ----
$activities = [];
if ($selectedMemberId > 0) {
    $sql = "SELECT activity_date, note, photo_path, created_at
            FROM member_activities
            WHERE member_id = ?
            ORDER BY activity_date DESC, created_at DESC";
    if ($st = $conn->prepare($sql)) {
        $st->bind_param('i', $selectedMemberId);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $activities[] = $row;
        }
        $res->free();
        $st->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Trainer Activity Viewer | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
:root{
  --bg:#000;
  --panel:#121212;
  --panel-soft:#181818;
  --accent:#e53935;
  --accent-soft:#ff6b6b;
  --border:#262626;
  --text:#f9f9f9;
  --muted:#a1a1aa;
}
*{
  box-sizing:border-box;
  font-family:'Poppins',system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
body{margin:0;background:var(--bg);color:var(--text);}

/* Navbar same style as home_member.php */
.navbar{
  background:linear-gradient(90deg,#000,var(--accent));
  position:relative;
  z-index:10;
}
.burger-btn{
  width:36px;height:36px;border-radius:999px;border:1px solid rgba(255,255,255,.35);
  background:rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;
  cursor:pointer;padding:0;margin-right:10px;
}
.burger-lines{
  width:18px;height:2px;background:#fff;position:relative;
}
.burger-lines::before,.burger-lines::after{
  content:"";position:absolute;left:0;width:18px;height:2px;background:#fff;
}
.burger-lines::before{top:-5px;}
.burger-lines::after{top:5px;}

/* Side drawer (same feel as member) */
.side-drawer{
  position:fixed;top:0;left:0;height:100vh;width:260px;max-width:80vw;
  background:#0b0b0b;border-right:1px solid var(--border);
  transform:translateX(-100%);
  transition:transform .25s ease-out;
  z-index:1000;padding:16px 14px;
}
.side-drawer.open{transform:translateX(0);}
.side-title{font-weight:600;margin-bottom:12px;font-size:.95rem;}
.side-link{
  display:block;padding:9px 11px;margin:4px 0;border-radius:10px;
  color:#e5e7eb;text-decoration:none;font-size:.9rem;border:1px solid transparent;
}
.side-link:hover{background:#18181b;border-color:#27272a;}
.side-link.active{background:#b91c1c;border-color:#ef4444;}

/* Profile bubble */
.profile-wrap{position:relative;}
.profile-circle{
  width:40px;height:40px;border-radius:50%;overflow:hidden;border:2px solid #f97373;
  background:#000;cursor:pointer;display:flex;align-items:center;justify-content:center;
}
.profile-img{width:100%;height:100%;object-fit:cover;}
.profile-panel{
  position:absolute;right:0;top:calc(100% + 10px);width:320px;max-width:90vw;
  background:#09090b;border:1px solid #27272a;border-radius:12px;
  box-shadow:0 18px 45px rgba(0,0,0,.7);padding:14px;display:none;z-index:1100;
}
.profile-panel.show{display:block;}
.panel-row{display:flex;justify-content:space-between;margin:4px 0;font-size:.85rem;}
.panel-row span:first-child{color:var(--muted);}
.badge-soft{
  background:#18181b;border:1px solid #3f3f46;color:#e4e4e7;
  padding:2px 6px;border-radius:999px;font-size:.7rem;
}

/* Content */
.main-wrap{padding:16px;}
.card-soft{
  background:var(--panel);border:1px solid var(--border);
  border-radius:14px;padding:16px 18px;
}
.section-title{font-size:1.2rem;font-weight:600;margin-bottom:8px;}
.small-muted{font-size:.8rem;color:var(--muted);}

/* Activity cards – match activity_list.php style */
.activity-card{
  background:var(--panel-soft);
  border-radius:12px;
  border:1px solid #27272a;
  padding:12px 14px;
  margin-bottom:10px;
}
.activity-head{
  display:flex;
  justify-content:space-between;
  align-items:center;
  font-size:.85rem;
}
.activity-date{
  color:var(--accent-soft);
  font-weight:500;
}
.activity-created{
  color:var(--muted);
}
.activity-note{
  font-size:.9rem;
  margin-top:6px;
}
.activity-photo{
  max-height:180px;
  border-radius:10px;
  margin-top:8px;
  border:1px solid #27272a;
}
</style>
</head>
<body>

<!-- Side Drawer -->
<div id="sideDrawer" class="side-drawer">
  <div class="side-title">Trainer Menu</div>
  <a href="home_trainer.php" class="side-link">🏠 Trainer Dashboard</a>
  <a href="trainer_activity_list.php" class="side-link active">📋 Member Activities</a>
  <a href="my_attendance.php" class="side-link">🕒 My Attendance</a>
  <a href="logout.php" class="side-link">🚪 Logout</a>
</div>

<nav class="navbar navbar-dark px-3 py-2">
  <div class="d-flex align-items-center">
    <button id="burgerBtn" class="burger-btn" type="button" aria-label="Open menu">
      <div class="burger-lines"></div>
    </button>
    <a class="navbar-brand mb-0" href="home_trainer.php">
      <img src="photo/logo.jpg" height="30" class="mr-2" alt="">RJL Fitness
    </a>
  </div>
  <div class="ml-auto d-flex align-items-center">
    <span class="mr-3" style="font-size:.9rem;">
      Welcome, <?=h($user['full_name'] ?: $user['username'])?>
    </span>
    <div class="profile-wrap" id="user-info">
      <button id="profileBtn" class="profile-circle" type="button"
              aria-haspopup="true" aria-expanded="false" aria-controls="profilePanel">
        <img src="<?=h($avatarPath)?>" class="profile-img" alt="Profile">
      </button>
      <div id="profilePanel" class="profile-panel" role="dialog" aria-hidden="true">
        <div class="panel-row"><span>Name</span><span><?=h($user['full_name'] ?: $user['username'])?></span></div>
        <div class="panel-row"><span>Email</span><span><?=h($user['email'] ?: '—')?></span></div>
        <div class="panel-row"><span>ID Number</span><span><?=h($user['id_number'] ?: 'Not set')?></span></div>
        <div class="panel-row">
          <span>Valid ID Status</span>
          <span class="badge-soft"><?=strtoupper(h($user['valid_id_status']))?></span>
        </div>
        <div class="panel-row">
          <span>Valid ID</span>
          <span>
            <?php if($user['valid_id_path']): ?>
              <a href="<?=h($user['valid_id_path'])?>" target="_blank">View</a>
            <?php else: ?>
              Not uploaded
            <?php endif; ?>
          </span>
        </div>
        <hr>
        <a href="upload_id.php" class="btn btn-outline-light btn-block btn-sm mb-2">Upload / Replace Valid ID</a>
        <a href="change_password.php" class="btn btn-outline-light btn-block btn-sm mb-2">Change Password</a>
        <a href="logout.php" class="btn btn-danger btn-block btn-sm">Logout</a>
      </div>
    </div>
  </div>
</nav>

<div class="main-wrap container-fluid">
  <div class="row justify-content-center">
    <div class="col-lg-8">
      <div class="card-soft">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="section-title mb-0">Member Activity List</div>
          <div class="small-muted">Trainer view (read-only)</div>
        </div>

        <!-- Member dropdown (now showing id_number) -->
        <form method="get" class="form-inline mb-3">
          <label class="mr-2" style="font-size:.85rem;">Select Member</label>
          <select name="member_id" class="form-control form-control-sm mr-2"
                  onchange="this.form.submit()">
            <option value="">-- Choose --</option>
            <?php foreach($members as $m): ?>
              <?php
                $display = $m['full_name'] ?: $m['username'];
                if (!empty($m['id_number'])) {
                    $display .= ' (ID Number: '.$m['id_number'].')';
                }
              ?>
              <option value="<?=$m['id']?>"
                <?=$selectedMemberId == $m['id'] ? 'selected' : ''; ?>>
                <?=h($display)?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($selectedMemberId <= 0): ?>
          <div class="alert alert-secondary py-1">
            Please select a member from the list above.
          </div>
        <?php elseif (!$activities): ?>
          <div class="alert alert-secondary py-1">
            No activities found for
            <strong><?=h($selectedMemberName ?: 'this member')?></strong>.
          </div>
        <?php else: ?>
          <div class="small-muted mb-2">
            Showing latest activities for
            <strong><?=h($selectedMemberName)?></strong>
          </div>

          <?php foreach($activities as $act): ?>
            <div class="activity-card">
              <div class="activity-head">
                <div class="activity-date">
                  <?=h(date('M d, Y', strtotime($act['activity_date'])))?>
                </div>
                <div class="activity-created">
                  <?=h(date('M d, Y g:ia', strtotime($act['created_at'])))?>
                </div>
              </div>

              <?php if (!empty($act['note'])): ?>
                <div class="activity-note">
                  <?=nl2br(h($act['note']))?>
                </div>
              <?php endif; ?>

              <?php if (!empty($act['photo_path'])): ?>
                <img src="<?=h($act['photo_path'])?>" class="activity-photo"
                     alt="Activity photo">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const burgerBtn    = document.getElementById('burgerBtn');
  const sideDrawer   = document.getElementById('sideDrawer');
  const profileBtn   = document.getElementById('profileBtn');
  const profilePanel = document.getElementById('profilePanel');

  function toggleDrawer(){
    if(!sideDrawer) return;
    sideDrawer.classList.toggle('open');
  }
  function closeDrawer(){
    if(!sideDrawer) return;
    sideDrawer.classList.remove('open');
  }

  function openPanel(){
    if(!profilePanel) return;
    profilePanel.classList.add('show');
    profilePanel.setAttribute('aria-hidden','false');
  }
  function closePanel(){
    if(!profilePanel) return;
    profilePanel.classList.remove('show');
    profilePanel.setAttribute('aria-hidden','true');
  }
  function togglePanel(){
    if(!profilePanel) return;
    if(profilePanel.classList.contains('show')) closePanel(); else openPanel();
  }

  if(burgerBtn){
    burgerBtn.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation(); toggleDrawer();
    });
  }

  if(profileBtn){
    profileBtn.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation(); togglePanel();
    });
  }

  document.addEventListener('click', function(e){
    if(sideDrawer && !sideDrawer.contains(e.target) && (!burgerBtn || !burgerBtn.contains(e.target))){
      closeDrawer();
    }
    if(profilePanel && !profilePanel.contains(e.target) && (!profileBtn || !profileBtn.contains(e.target))){
      closePanel();
    }
  });

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
      closeDrawer();
      closePanel();
    }
  });
})();
</script>

</body>
</html>