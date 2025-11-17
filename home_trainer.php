<?php
// FILE: home_trainer.php
// Trainer dashboard – similar style to home_staff.php, with training features.

if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__.'/db.php';

// Allow trainer and admin to use this dashboard
$role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($role, ['trainer','admin'], true)) {
    header('Location: home.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$user   = [
    'username'  => $_SESSION['username'] ?? '',
    'email'     => '',
    'full_name' => ''
];

// Load trainer basic info
if ($st = $conn->prepare("SELECT full_name, email FROM users WHERE id=? LIMIT 1")) {
    $st->bind_param('i', $userId);
    $st->execute();
    if ($rs = $st->get_result()) {
        if ($row = $rs->fetch_assoc()) {
            $user['full_name'] = $row['full_name'] ?? '';
            $user['email']     = $row['email'] ?? '';
        }
        $rs->free();
    }
    $st->close();
}

// Optional small stats (safe, simple)
$totalMembers = 0;
if ($r = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='member'")) {
    $totalMembers = (int)$r->fetch_assoc()['c'];
    $r->free();
}

$avatarPath = 'photo/logo.jpg';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trainer Dashboard | RJL Fitness</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
:root{--brand:#b30000;--bg:#111;--panel:#1a1a1a;--line:#2a2a2a}
body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
.navbar{background:linear-gradient(90deg,#000,#b30000);position:relative;z-index:1}
.dashboard{background:#1a1a1a;border-radius:14px;padding:24px}
.side-link{
  display:block;padding:10px 12px;margin:6px 0;
  border:1px solid #262626;border-radius:10px;
  background:#1a1a1a;color:#eee;text-decoration:none;
}
.side-link:hover{background:#202020}
.profile-wrap{position:relative}
.profile-circle{
  width:45px;height:45px;border-radius:50%;overflow:hidden;
  border:2px solid #ff3333;background:#222;cursor:pointer;
}
.profile-img{width:100%;height:100%;object-fit:cover;display:block}
.profile-panel{
  position:absolute;right:0;top:calc(100% + 10px);width:320px;max-width:90vw;
  background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;
  box-shadow:0 16px 40px rgba(0,0,0,.45);padding:14px;display:none;z-index:3000;
}
.profile-panel.show{display:block}
.panel-row{display:flex;justify-content:space-between;margin:6px 0}
.panel-row span:first-child{color:#9ca3af}
.btn-outline-light{border-color:#ff3333;color:#fff}
.btn-outline-light:hover{background:#ff3333}
.badge-soft{
  background:#222;border:1px solid #333;color:#eee;
  padding:.25rem .5rem;border-radius:6px
}
.card-info{
  background:#222;border-radius:12px;border-left:4px solid #ff1a1a;
  padding:16px;
}
.card-info p{margin:0 0 4px;}
</style>
</head>
<body>
<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand" href="#"><img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto d-flex align-items-center">
    <span class="mr-3">
      Welcome, <?=htmlspecialchars($user['full_name'] ?: $user['username'])?>
    </span>
    <div class="profile-wrap" id="user-info">
      <button id="profileBtn" class="profile-circle" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="profilePanel">
        <img src="<?=htmlspecialchars($avatarPath)?>" class="profile-img" alt="Profile">
      </button>
      <div id="profilePanel" class="profile-panel" role="dialog" aria-hidden="true">
        <div class="panel-row">
          <span>Name</span>
          <span><?=htmlspecialchars($user['full_name'] ?: $user['username'])?></span>
        </div>
        <div class="panel-row">
          <span>Email</span>
          <span><?=htmlspecialchars($user['email'] ?: '—')?></span>
        </div>
        <div class="panel-row">
          <span>Role</span>
          <span><?=htmlspecialchars(strtoupper($role))?></span>
        </div>
        <hr>
        <a href="change_password.php" class="btn btn-outline-light btn-block mb-2">Change Password</a>
        <a href="logout.php" class="btn btn-danger btn-block">Logout</a>
      </div>
    </div>
  </div>
</nav>

<div class="container my-4">
  <div class="dashboard">
    <h4>Trainer Dashboard</h4>
    <div class="row mt-3">
      <!-- LEFT SIDE: menu like home_member style -->
      <div class="col-md-4">
        <!-- Main feature: training planner that connects to member view -->
        <a class="side-link" href="trainer_training.php">📋 Member Training Planner</a>
        <!-- Trainer tools -->
        <a class="side-link" href="attendance.php">🕒 Attendance (All Members)</a>
        <a class="side-link" href="users.php">👥 Members List</a>
        <!-- If you want, you can add more links here -->
      </div>

      <!-- RIGHT SIDE: overview / explanation -->
      <div class="col-md-8">
        <div class="card-info">
          <h5 class="text-danger">Training Overview</h5>
          <p class="mb-1">
            Total registered members: <span class="badge-soft"><?=$totalMembers?></span>
          </p>
          <p class="mb-2">
            Use the <strong>Member Training Planner</strong> to design weekly plans for each member:
          </p>
          <ul class="mb-2" style="padding-left:18px;font-size:0.9rem;">
            <li>Set <strong>workout plan</strong> per day (exercises, sets, reps, focus).</li>
            <li>Set <strong>meal plan</strong> per day (what the member should eat).</li>
            <li>Add <strong>notes/focus</strong> (injuries, form reminders, goals).</li>
            <li>Add <strong>demo/stretching video links</strong> (e.g., YouTube).</li>
          </ul>
          <p class="mb-1" style="font-size:0.9rem;">
            Members will see everything you set in their own dashboard under
            <strong>“Training &amp; Meal Plan”</strong> (page: <code>member_training.php</code>),
            so your plan here is directly connected to their <strong>home_member.php</strong> view.
          </p>
          <p class="mb-0" style="font-size:0.9rem;">
            You can also monitor how often they attend through the
            <strong>Attendance</strong> page to check if they follow the program.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const profileBtn   = document.getElementById('profileBtn');
  const profilePanel = document.getElementById('profilePanel');

  function openPanel(){
    if(!profilePanel) return;
    profilePanel.classList.add('show');
    profilePanel.setAttribute('aria-hidden','false');
    if(profileBtn) profileBtn.setAttribute('aria-expanded','true');
  }
  function closePanel(){
    if(!profilePanel) return;
    profilePanel.classList.remove('show');
    profilePanel.setAttribute('aria-hidden','true');
    if(profileBtn) profileBtn.setAttribute('aria-expanded','false');
  }
  function togglePanel(){
    if(!profilePanel) return;
    profilePanel.classList.contains('show') ? closePanel() : openPanel();
  }

  if(profileBtn){
    profileBtn.addEventListener('click',function(e){
      e.preventDefault();
      e.stopPropagation();
      togglePanel();
    });
  }
  if(profilePanel){
    profilePanel.addEventListener('click',function(e){
      e.stopPropagation();
    });
  }
  document.addEventListener('click',function(){
    if(profilePanel && profilePanel.classList.contains('show')) closePanel();
  });
  document.addEventListener('keydown',function(e){
    if(e.key==='Escape' && profilePanel && profilePanel.classList.contains('show')){
      e.preventDefault();
      closePanel();
    }
  });
})();
</script>
</body>
</html>