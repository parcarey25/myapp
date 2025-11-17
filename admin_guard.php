<<<<<<< HEAD
<?php
require __DIR__.'/auth.php';
if (($_SESSION['role'] ?? 'member') !== 'admin') {
  http_response_code(403);
  die('Forbidden: admin only');
}
=======
<?php
require __DIR__.'/auth.php';
if (($_SESSION['role'] ?? 'member') !== 'admin') {
  http_response_code(403);
  die('Forbidden: admin only');
}
>>>>>>> b78dc527f4ca1b402224214aa4f78775c370647f
require __DIR__.'/db.php';