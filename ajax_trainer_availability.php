<?php
// ajax_trainer_availability.php
// Used by facilities.php JavaScript.
// action=slots   -> returns available time slots for selected trainer
// action=trainers -> returns available trainers for selected time slot

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Please log in first.']);
    exit;
}

require __DIR__ . '/db.php';
require __DIR__ . '/trainer_booking_lib.php';

tb_ensure_schema($conn);

$action = strtolower(trim($_GET['action'] ?? ''));
$facilitySlug = tb_normalize_slug($_GET['facility_slug'] ?? '');
$date = trim($_GET['date'] ?? '');

if ($facilitySlug === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing facility.']);
    exit;
}

if (!tb_valid_date($date)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid date.']);
    exit;
}

if ($action === 'slots') {
    $trainerId = (int)($_GET['trainer_id'] ?? 0);
    if ($trainerId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Choose a trainer first.']);
        exit;
    }

    $slots = tb_available_slots_for_trainer($conn, $trainerId, $facilitySlug, $date);
    echo json_encode([
        'ok' => true,
        'slots' => $slots,
        'labels' => array_map('tb_slot_label', $slots),
    ]);
    exit;
}

if ($action === 'trainers') {
    $slot = trim($_GET['time'] ?? '');
    if (!in_array($slot, tb_time_slots(), true)) {
        echo json_encode(['ok' => false, 'error' => 'Choose a valid time slot.']);
        exit;
    }

    $trainers = tb_available_trainers_for_slot($conn, $facilitySlug, $date, $slot);
    echo json_encode([
        'ok' => true,
        'trainers' => $trainers,
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid action.']);
