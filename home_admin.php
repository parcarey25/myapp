<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require __DIR__.'/db.php';
if (strtolower($_SESSION['role']??'')!=='admin'){ header('Location: home.php'); exit; }

$userId=(int)$_SESSION['user_id'];
$user=['username'=>$_SESSION['username']??'','email'=>'','full_name'=>'','id_number'=>'','valid_id_path'=>'','valid_id_status'=>'none'];
if($st=$conn->prepare("SELECT email,full_name,id_number,valid_id_path,valid_id_status FROM users WHERE id=? LIMIT 1")){
  $st->bind_param('i',$userId); $st->execute();
  if($rs=$st->get_result()){ if($row=$rs->fetch_assoc()){
    $user['email']=$row['email']??''; $user['full_name']=$row['full_name']??'';
    $user['id_number']=$row['id_number']??''; $user['valid_id_path']=$row['valid_id_path']??'';
    $user['valid_id_status']=$row['valid_id_status']??'none';
  } $rs->free(); } $st->close();
}
$avatarPath='photo/logo.jpg';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Dashboard | RJL Fitness</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<style>
:root{--brand:#b30000;--bg:#111;--panel:#1a1a1a;--line:#2a2a2a}
body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
.navbar{background:linear-gradient(90deg,#000,#b30000);position:relative;z-index:1}
.side-link{display:block;padding:10px 12px;margin:6px 0;border:1px solid #262626;border-radius:10px;background:#1a1a1a;color:#eee;text-decoration:none}
.side-link:hover{background:#202020}
.dashboard{background:#1a1a1a;border-radius:14px;padding:24px}
.profile-wrap{position:relative}
.profile-circle{width:45px;height:45px;border-radius:50%;overflow:hidden;border:2px solid #ff3333;background:#222;cursor:pointer}
.profile-img{width:100%;height:100%;object-fit:cover;display:block}
.profile-panel{
  position:absolute;right:0;top:calc(100% + 10px);width:340px;max-width:90vw;background:#1a1a1a;
  border:1px solid #2a2a2a;border-radius:12px;box-shadow:0 16px 40px rgba(0,0,0,.45);padding:14px;display:none;z-index:3000;
}
.profile-panel.show{display:block}
.panel-row{display:flex;justify-content:space-between;margin:6px 0}.panel-row span:first-child{color:#9ca3af}
.btn-outline-light{border-color:#ff3333;color:#fff}.btn-outline-light:hover{background:#ff3333}
</style>
</head>
<body>
<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand" href="#"><img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto d-flex align-items-center">
    <span class="mr-3">Welcome, <?=htmlspecialchars($user['full_name']?:$user['username'])?></span>
    <div class="profile-wrap" id="user-info">
      <button id="profileBtn" class="profile-circle" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="profilePanel">
        <img src="<?=htmlspecialchars($avatarPath)?>" class="profile-img" alt="Profile">
      </button>
      <div id="profilePanel" class="profile-panel" role="dialog" aria-hidden="true">
        <div class="panel-row"><span>Name</span><span><?=htmlspecialchars($user['full_name']?:$user['username'])?></span></div>
        <div class="panel-row"><span>Email</span><span><?=htmlspecialchars($user['email']?:'—')?></span></div>
        <div class="panel-row"><span>ID Number</span><span><?=htmlspecialchars($user['id_number']?:'Not set')?></span></div>
        <div class="panel-row"><span>Valid ID Status</span><span><?=htmlspecialchars(strtoupper($user['valid_id_status']))?></span></div>
        <div class="panel-row"><span>Valid ID</span><span><?php if($user['valid_id_path']):?><a target="_blank" href="<?=htmlspecialchars($user['valid_id_path'])?>">View</a><?php else:?>Not uploaded<?php endif;?></span></div>
        <hr>
        <a href="upload_id.php" class="btn btn-outline-light btn-block mb-2">Upload / Replace Valid ID</a>
        <a href="change_password.php" class="btn btn-outline-light btn-block mb-2">Change Password</a>
        <a href="logout.php" class="btn btn-danger btn-block">Logout</a>
      </div>
    </div>
  </div>
</nav>

<div class="container my-4">
  <div class="dashboard">
    <h4>Admin Dashboard</h4>
    <div class="row mt-3">
      <div class="col-md-4">
        <a class="side-link" href="admin_dashboard.php">📈 Revenue & Reports</a>
        <a class="side-link" href="users.php">🔧 User Management</a>
        <a class="side-link" href="id_verifications.php">✅ ID Verifications</a>
        <a class="side-link" href="payments.php">💳 Payments</a>
        <a class="side-link" href="facilities.php">🏟 Facilities</a>
        <a class="side-link" href="pos.php">🧾 POS</a>
        <a class="side-link" href="admin_site_settings.php">⚙️ Site Settings</a>
      </div>
      <div class="col-md-8">
        <div class="p-3" style="background:#222;border-radius:12px;border-left:4px solid #ff1a1a">
          <h5 class="text-danger">Welcome, Admin</h5>
          <p class="mb-0">Use the menu to manage users, verify IDs, view revenue, and configure site settings.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const profileBtn=document.getElementById('profileBtn');
  const profilePanel=document.getElementById('profilePanel');
  function openPanel(){ if(!profilePanel) return; profilePanel.classList.add('show'); profilePanel.setAttribute('aria-hidden','false'); if(profileBtn) profileBtn.setAttribute('aria-expanded','true'); }
  function closePanel(){ if(!profilePanel) return; profilePanel.classList.remove('show'); profilePanel.setAttribute('aria-hidden','true'); if(profileBtn) profileBtn.setAttribute('aria-expanded','false'); }
  function togglePanel(){ if(!profilePanel) return; profilePanel.classList.contains('show') ? closePanel() : openPanel(); }

  if(profileBtn){ profileBtn.addEventListener('click',(e)=>{ e.preventDefault(); e.stopPropagation(); togglePanel(); }); }
  if(profilePanel){ profilePanel.addEventListener('click',(e)=>{ e.stopPropagation(); }); }
  document.addEventListener('click',(e)=>{ if(profilePanel && profilePanel.classList.contains('show')) closePanel(); });
  document.addEventListener('keydown',(e)=>{ if(e.key==='Escape' && profilePanel && profilePanel.classList.contains('show')) { e.preventDefault(); closePanel(); }});
})();
</script>
</body>
</html>