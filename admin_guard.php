<?php
require __DIR__.'/auth.php';
if (($_SESSION['role'] ?? 'member') !== 'admin') {
  http_response_code(403);
  die('Forbidden: admin only');
}
require __DIR__.'/db.php';