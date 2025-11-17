<<<<<<< HEAD
<?php
// id_verifications_action.php — Approve/Reject handler
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__ . '/db.php';

$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['staff','admin'], true)) {
  http_response_code(403); die('Forbidden');
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
  http_response_code(400); die('Invalid CSRF');
}

$uid = (int)($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? '';
$note = trim($_POST['note'] ?? '');

if ($uid <= 0 || !in_array($action, ['approve','reject'], true)) {
  header('Location: id_verifications.php'); exit;
}

// Ensure target user exists and has a pending ID
$st = $conn->prepare("SELECT id, valid_id_status FROM users WHERE id=? LIMIT 1");
$st->bind_param('i', $uid);
$st->execute(); $res = $st->get_result();
$row = $res ? $res->fetch_assoc() : null;
if ($res) $res->free();
$st->close();

if (!$row || $row['valid_id_status'] !== 'pending') {
  header('Location: id_verifications.php'); exit;
}

if ($action === 'approve') {
  $status = 'approved';
} else {
  $status = 'rejected';
}

// Update
$st = $conn->prepare("UPDATE users SET valid_id_status=?, valid_id_note=? WHERE id=?");
$st->bind_param('ssi', $status, $note, $uid);
$st->execute(); $st->close();

header('Location: id_verifications.php');
=======
<?php
// id_verifications_action.php — Approve/Reject handler
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__ . '/db.php';

$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['staff','admin'], true)) {
  http_response_code(403); die('Forbidden');
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
  http_response_code(400); die('Invalid CSRF');
}

$uid = (int)($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? '';
$note = trim($_POST['note'] ?? '');

if ($uid <= 0 || !in_array($action, ['approve','reject'], true)) {
  header('Location: id_verifications.php'); exit;
}

// Ensure target user exists and has a pending ID
$st = $conn->prepare("SELECT id, valid_id_status FROM users WHERE id=? LIMIT 1");
$st->bind_param('i', $uid);
$st->execute(); $res = $st->get_result();
$row = $res ? $res->fetch_assoc() : null;
if ($res) $res->free();
$st->close();

if (!$row || $row['valid_id_status'] !== 'pending') {
  header('Location: id_verifications.php'); exit;
}

if ($action === 'approve') {
  $status = 'approved';
} else {
  $status = 'rejected';
}

// Update
$st = $conn->prepare("UPDATE users SET valid_id_status=?, valid_id_note=? WHERE id=?");
$st->bind_param('ssi', $status, $note, $uid);
$st->execute(); $st->close();

header('Location: id_verifications.php');
>>>>>>> b78dc527f4ca1b402224214aa4f78775c370647f
