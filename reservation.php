
<?php
require __DIR__.'/auth.php'; require_role('member');
require __DIR__.'/db.php';

$user_id = (int)$_SESSION['user_id'];
$schedule_id = (int)($_POST['schedule_id'] ?? 0);

// capacity check
$stmt = $conn->prepare("SELECT capacity, start_time FROM schedules WHERE id=? AND is_active=1");
$stmt->bind_param('i',$schedule_id);
$stmt->execute();
$stmt->bind_result($cap,$start);
if (!$stmt->fetch()) { $stmt->close(); die('Invalid schedule'); }
$stmt->close();

// current booked
$stmt = $conn->prepare("SELECT COUNT(*) FROM reservations WHERE schedule_id=? AND status='reserved'");
$stmt->bind_param('i',$schedule_id);
$stmt->execute(); $stmt->bind_result($booked); $stmt->fetch(); $stmt->close();

if ($booked >= $cap) { header('Location: schedules.php?msg=full'); exit; }

$stmt = $conn->prepare("INSERT INTO reservations (schedule_id, user_id) VALUES (?,?)");
$stmt->bind_param('ii',$schedule_id,$user_id);
if (!$stmt->execute()) {
  // likely already reserved (unique constraint)
  header('Location: schedules.php?msg=already'); exit;
}
$stmt->close();
