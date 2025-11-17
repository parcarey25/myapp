<<<<<<< HEAD
<?php 
// home.php — role-aware dispatcher (one URL, multiple views)
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// normalize role from session
$role = strtolower(trim($_SESSION['role'] ?? 'member'));

switch ($role) {
    case 'admin':
        require __DIR__.'/home_admin.php';
        break;

    case 'staff':
        require __DIR__.'/home_staff.php';
        break;

    case 'trainer':
        // TRAINER → trainer dashboard
        require __DIR__.'/home_trainer.php';
        break;

    case 'member':
    default:
        require __DIR__.'/home_member.php';
        break;
}
=======
<?php
// home.php — role-aware dispatcher (one URL, three views)
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$role = strtolower($_SESSION['role'] ?? 'member');

switch ($role) {
  case 'admin':  require __DIR__.'/home_admin.php';  break;
  case 'staff':  require __DIR__.'/home_staff.php';  break;
  case 'trainer': // (optional) route to member for now
  case 'member':
  default:       require __DIR__.'/home_member.php'; break;
}
>>>>>>> b78dc527f4ca1b402224214aa4f78775c370647f
