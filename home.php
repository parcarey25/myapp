<?php
// home.php — route user to the correct dashboard based on role

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Not logged in? Go to login.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Normalize role
$role = strtolower(trim($_SESSION['role'] ?? 'member'));

switch ($role) {
    case 'admin':
        require __DIR__ . '/home_admin.php';
        break;

    case 'staff':
        require __DIR__ . '/home_staff.php';
        break;

    case 'trainer':
        require __DIR__ . '/home_trainer.php';
        break;

    case 'member':
    default:
        require __DIR__ . '/home_member.php';
        break;
}