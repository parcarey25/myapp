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