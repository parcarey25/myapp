<?php
require __DIR__.'/auth.php'; require_role('member');
require __DIR__.'/db.php';

$user_id = (int)$_SESSION['user_id'];
$schedule_id = (int)($_POST['schedule_id'] ?? 0);

$stmt = $conn->prepare("UPDATE reservations SET status='cancelled' WHERE schedule_id=? AND user_id=? AND status='reserved'");
$stmt->bind_param('ii',$schedule_id,$user_id);
$stmt->execute();
$stmt->close();

header('Location: schedules.php?cancelled=1'); exit;