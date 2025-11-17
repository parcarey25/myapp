<?php
// admin_guard.php - ensure only admins can access a page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/auth.php';

if (strtolower($_SESSION['role'] ?? 'member') !== 'admin') {
    http_response_code(403);
    die('Forbidden: admin only');
}

require __DIR__ . '/db.php';