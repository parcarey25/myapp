
<?php
// auth.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

function user_role(): string {
  return $_SESSION['role'] ?? 'member';
}
function require_role(string ...$roles): void {
  $r = user_role();
  if (!in_array($r, $roles, true)) {
    http_response_code(403);
    die('Forbidden');
  }

}