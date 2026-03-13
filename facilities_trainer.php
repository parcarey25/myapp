<?php
// facilities_trainer.php — Trainer Facilities (availability + approve/reject bookings)
// UI is based on your member facilities cards with expand panel.
//
// REQUIREMENT: trainer_availability table (see SQL in chat).

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__ . '/db.php';

$role   = strtolower($_SESSION['role'] ?? 'member');
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($role !== 'trainer') {
    header('Location: facilities.php');
    exit;
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('s', $table);
    $st->execute();
    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $st->close();
    return $ok;
}

function has_column(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $st->close();
    return $ok;
}

function first_col(mysqli $conn, string $table, array $cands): ?string {
    foreach ($cands as $c) {
        if (has_column($conn, $table, $c)) return $c;
    }
    return null;
}

function valid_date(string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

function slot_parts(string $slot): array {
    $p = explode('-', $slot, 2);
    return [trim($p[0] ?? ''), trim($p[1] ?? '')];
}

function normalize_slug(string $slug): string {
    $s = strtolower(trim($slug));
    $s = str_replace('_','-',$s);
    return $s;
}

/* ---------- Time slots (must match member) ---------- */
$TIME_SLOTS = [
    '08:00-10:00',
    '10:00-12:00',
    '12:00-14:00',
    '16:00-18:00',
    '18:00-20:00',
    '20:00-22:00',
];

/* ---------- Date filter ---------- */
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!valid_date($selectedDate)) $selectedDate = date('Y-m-d');

/* ---------- Trainer display name ---------- */
$trainerName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Trainer';

/* ---------- Load facilities visible to trainer ---------- */
$has_is_active  = has_column($conn, 'facilities', 'is_active');
$has_visible_to = has_column($conn, 'facilities', 'visible_to');

$facilities = [];
if ($has_visible_to) {
    $sql = "SELECT id,name,slug,visible_to,description,image
            FROM facilities
            WHERE ".($has_is_active ? "is_active=1 AND " : "")."
                  (LOWER(visible_to)='both' OR LOWER(visible_to)='trainer')
            ORDER BY name ASC";
} else {
    $sql = "SELECT id,name,slug,'both' AS visible_to,description,image
            FROM facilities ".($has_is_active ? "WHERE is_active=1 " : "")."
            ORDER BY name ASC";
}
if ($res = $conn->query($sql)) {
    $facilities = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

/* ---------- trainer_availability ---------- */
$availTable = 'trainer_availability';
$availExists = table_exists($conn, $availTable);
$canUseAvailability = $availExists
    && has_column($conn, $availTable, 'trainer_id')
    && (has_column($conn, $availTable, 'facility_slug') || has_column($conn, $availTable, 'facility'))
    && (has_column($conn, $availTable, 'avail_date') || has_column($conn, $availTable, 'date'))
    && (has_column($conn, $availTable, 'time_slot') || has_column($conn, $availTable, 'slot') || has_column($conn, $availTable, 'time_range'));

$avail_fac_col  = $availExists ? (has_column($conn,$availTable,'facility_slug') ? 'facility_slug' : (has_column($conn,$availTable,'facility') ? 'facility' : null)) : null;
$avail_date_col = $availExists ? (has_column($conn,$availTable,'avail_date') ? 'avail_date' : (has_column($conn,$availTable,'date') ? 'date' : null)) : null;
$avail_slot_col = $availExists ? (has_column($conn,$availTable,'time_slot') ? 'time_slot' : (has_column($conn,$availTable,'slot') ? 'slot' : (has_column($conn,$availTable,'time_range') ? 'time_range' : null))) : null;
$avail_is_col   = $availExists && has_column($conn,$availTable,'is_available') ? 'is_available' : null;

/* ---------- Detect booking table (your schedules.php insert table) ---------- */
$BOOKING_TABLE_CANDIDATES = [
    'schedules',
    'bookings',
    'schedule_requests',
    'trainer_bookings',
    'facility_bookings'
];
$bookTable = null;
foreach ($BOOKING_TABLE_CANDIDATES as $t) {
    if (table_exists($conn, $t)) { $bookTable = $t; break; }
}

$bookCols = [];
if ($bookTable) {
    $bookCols = [
        'id'       => first_col($conn, $bookTable, ['id','schedule_id','booking_id']),
        'trainer'  => first_col($conn, $bookTable, ['trainer_id','coach_id']),
        'member'   => first_col($conn, $bookTable, ['member_id','user_id','client_id']),
        'status'   => first_col($conn, $bookTable, ['status','booking_status','request_status']),
        'date'     => first_col($conn, $bookTable, ['date','booking_date','session_date','schedule_date','schedule_day']),
        'range'    => first_col($conn, $bookTable, ['time_slot','time_range','slot']),
        'start'    => first_col($conn, $bookTable, ['time_start','start_time','from_time']),
        'end'      => first_col($conn, $bookTable, ['time_end','end_time','to_time']),
        'timeOnly' => first_col($conn, $bookTable, ['time']),
        'notes'    => first_col($conn, $bookTable, ['notes','remark','remarks','comment']),
        'fac_slug' => first_col($conn, $bookTable, ['facility_slug','slug']),
        'fac_name' => first_col($conn, $bookTable, ['facility_name','facility']),
        'fullName' => first_col($conn, $bookTable, ['full_name','name']),
        'created'  => first_col($conn, $bookTable, ['created_at','created']),
    ];
}

/* ---------- status helpers ---------- */
function status_value(string $action): string {
    // adjust here if your system uses ACCEPTED instead of APPROVED
    if ($action === 'approve') return 'APPROVED';
    if ($action === 'reject')  return 'REJECTED';
    return 'PENDING';
}

function status_is_pending(?string $col): string {
    if (!$col) return "1=1";
    return "UPPER(TRIM(`{$col}`))='PENDING'";
}
function status_is_occupied(?string $col): string {
    if (!$col) return "1=1";
    return "UPPER(TRIM(`{$col}`)) IN ('PENDING','APPROVED','ACCEPTED')";
}
function date_where(string $dateCol): string {
    return "DATE(`{$dateCol}`)=?";
}

/* ---------- Get booking slot label from row ---------- */
function booking_slot_label(array $row, array $cols, array $TIME_SLOTS): string {
    if (!empty($cols['range']) && isset($row['b_range']) && $row['b_range'] !== '') {
        return (string)$row['b_range'];
    }
    if (!empty($cols['start']) && !empty($cols['end']) && isset($row['b_start']) && isset($row['b_end'])) {
        $s = substr((string)$row['b_start'], 0, 5);
        $e = substr((string)$row['b_end'], 0, 5);
        return "{$s}-{$e}";
    }
    if (!empty($cols['timeOnly']) && isset($row['b_time']) && $row['b_time'] !== '') {
        $t = substr((string)$row['b_time'], 0, 5);
        // map to known slot by start time
        foreach ($TIME_SLOTS as $slot) {
            [$ss, $ee] = slot_parts($slot);
            if ($ss === $t) return $slot;
        }
        return $t;
    }
    return '—';
}

/* ---------- Check trainer conflict (busy) for a slot on date (excluding a booking id optionally) ---------- */
function trainer_busy(mysqli $conn, string $bookTable, array $cols, int $trainerId, string $date, string $slot, ?int $excludeId = null): bool {
    if (!$bookTable || empty($cols['trainer']) || empty($cols['date'])) return false;

    $whereStatus = status_is_occupied($cols['status']);
    $whereDate   = date_where($cols['date']);
    [$slotStart, $slotEnd] = slot_parts($slot);

    $extra = "";
    $types = "";
    $params = [];

    if ($excludeId && !empty($cols['id'])) {
        $extra = " AND `{$cols['id']}` <> ? ";
        $types .= "i";
        $params[] = $excludeId;
    }

    if (!empty($cols['start']) && !empty($cols['end'])) {
        $sql = "SELECT 1 FROM `{$bookTable}`
                WHERE `{$cols['trainer']}`=?
                  AND {$whereDate}
                  AND {$whereStatus}
                  {$extra}
                  AND (TIME(`{$cols['start']}`) < TIME(?) AND TIME(`{$cols['end']}`) > TIME(?))
                LIMIT 1";
        $st = $conn->prepare($sql);
        $bindTypes = "is{$types}ss";
        // params order: trainerId, date, excludeId?, slotEnd, slotStart
        $bind = [$trainerId, $date];
        foreach ($params as $p) $bind[] = $p;
        $bind[] = $slotEnd;
        $bind[] = $slotStart;
    } elseif (!empty($cols['range'])) {
        $sql = "SELECT 1 FROM `{$bookTable}`
                WHERE `{$cols['trainer']}`=?
                  AND {$whereDate}
                  AND {$whereStatus}
                  {$extra}
                  AND `{$cols['range']}`=?
                LIMIT 1";
        $st = $conn->prepare($sql);
        $bindTypes = "is{$types}s";
        $bind = [$trainerId, $date];
        foreach ($params as $p) $bind[] = $p;
        $bind[] = $slot;
    } elseif (!empty($cols['timeOnly'])) {
        $sql = "SELECT 1 FROM `{$bookTable}`
                WHERE `{$cols['trainer']}`=?
                  AND {$whereDate}
                  AND {$whereStatus}
                  {$extra}
                  AND TIME(`{$cols['timeOnly']}`)=TIME(?)
                LIMIT 1";
        $st = $conn->prepare($sql);
        $bindTypes = "is{$types}s";
        $bind = [$trainerId, $date];
        foreach ($params as $p) $bind[] = $p;
        $bind[] = $slotStart;
    } else {
        return false;
    }

    if (!$st) return false;

    // bind dynamically
    $refs = [];
    $refs[] = &$bindTypes;
    foreach ($bind as $k => $v) $refs[] = &$bind[$k];
    call_user_func_array([$st, 'bind_param'], $refs);

    $busy = false;
    if ($st->execute()) {
        $res = $st->get_result();
        $busy = ($res && $res->num_rows > 0);
        if ($res) $res->free();
    }
    $st->close();
    return $busy;
}

/* ---------- Flash message ---------- */
$flash = null;
$flashType = 'info';

/* ======================================================================
   POST ACTIONS:
   - add_availability / remove_availability
   - approve_request / reject_request
   ====================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strtolower($_POST['action'] ?? '');

    // availability
    if ($action === 'add_availability' || $action === 'remove_availability') {
        if (!$canUseAvailability) {
            $flash = "trainer_availability table is missing or columns are not correct. Create it using the SQL provided.";
            $flashType = 'danger';
        } else {
            $facilitySlug = normalize_slug($_POST['facility_slug'] ?? '');
            $date = trim($_POST['date'] ?? '');
            $slot = trim($_POST['time_slot'] ?? '');

            if ($facilitySlug === '' || !valid_date($date) || !in_array($slot, $TIME_SLOTS, true)) {
                $flash = "Invalid data submitted.";
                $flashType = 'danger';
            } else {
                if ($action === 'add_availability') {
                    // insert/update
                    $sql = "INSERT INTO `{$availTable}` (trainer_id, `{$avail_fac_col}`, `{$avail_date_col}`, `{$avail_slot_col}`".($avail_is_col ? ", `{$avail_is_col}`" : "").")
                            VALUES (?, ?, ?, ?".($avail_is_col ? ", 1" : "").")
                            ON DUPLICATE KEY UPDATE ".($avail_is_col ? "`{$avail_is_col}`=1" : "`{$avail_slot_col}`=VALUES(`{$avail_slot_col}`)");
                    $st = $conn->prepare($sql);
                    if ($st) {
                        $st->bind_param('isss', $userId, $facilitySlug, $date, $slot);
                        $ok = $st->execute();
                        $st->close();
                        $flash = $ok ? "Marked available: {$slot}" : "Failed to save availability.";
                        $flashType = $ok ? 'success' : 'danger';
                    } else {
                        $flash = "DB prepare failed while saving availability.";
                        $flashType = 'danger';
                    }
                } else {
                    // remove availability (delete)
                    $sql = "DELETE FROM `{$availTable}`
                            WHERE trainer_id=? AND `{$avail_fac_col}`=? AND `{$avail_date_col}`=? AND `{$avail_slot_col}`=?";
                    $st = $conn->prepare($sql);
                    if ($st) {
                        $st->bind_param('isss', $userId, $facilitySlug, $date, $slot);
                        $ok = $st->execute();
                        $st->close();
                        $flash = $ok ? "Removed availability: {$slot}" : "Failed to remove availability.";
                        $flashType = $ok ? 'success' : 'danger';
                    } else {
                        $flash = "DB prepare failed while removing availability.";
                        $flashType = 'danger';
                    }
                }
            }
        }
    }

    // approve/reject booking requests
    if ($action === 'approve_request' || $action === 'reject_request') {
        if (!$bookTable || empty($bookCols['id'])) {
            $flash = "Booking table not detected. Add your booking table name in BOOKING_TABLE_CANDIDATES.";
            $flashType = 'danger';
        } elseif (empty($bookCols['status'])) {
            $flash = "Booking table has no status column. Cannot approve/reject.";
            $flashType = 'danger';
        } else {
            $bookingId = (int)($_POST['booking_id'] ?? 0);
            if ($bookingId <= 0) {
                $flash = "Invalid booking id.";
                $flashType = 'danger';
            } else {
                // load booking row to determine date/slot and if it's any-trainer
                $sel = [];
                $sel[] = "`{$bookCols['id']}` AS bid";
                if (!empty($bookCols['trainer']))  $sel[] = "`{$bookCols['trainer']}` AS b_trainer";
                if (!empty($bookCols['date']))     $sel[] = "`{$bookCols['date']}` AS b_date";
                if (!empty($bookCols['range']))    $sel[] = "`{$bookCols['range']}` AS b_range";
                if (!empty($bookCols['start']))    $sel[] = "`{$bookCols['start']}` AS b_start";
                if (!empty($bookCols['end']))      $sel[] = "`{$bookCols['end']}` AS b_end";
                if (!empty($bookCols['timeOnly'])) $sel[] = "`{$bookCols['timeOnly']}` AS b_time";
                if (!empty($bookCols['fac_slug'])) $sel[] = "`{$bookCols['fac_slug']}` AS b_fac_slug";
                if (!empty($bookCols['fac_name'])) $sel[] = "`{$bookCols['fac_name']}` AS b_fac_name";

                $sql = "SELECT ".implode(',', $sel)." FROM `{$bookTable}` WHERE `{$bookCols['id']}`=? LIMIT 1";
                $st = $conn->prepare($sql);
                if (!$st) {
                    $flash = "DB prepare failed while reading booking.";
                    $flashType = 'danger';
                } else {
                    $st->bind_param('i', $bookingId);
                    $st->execute();
                    $res = $st->get_result();
                    $row = $res ? $res->fetch_assoc() : null;
                    if ($res) $res->free();
                    $st->close();

                    if (!$row) {
                        $flash = "Booking not found.";
                        $flashType = 'danger';
                    } else {
                        $bDate = '';
                        if (!empty($row['b_date'])) {
                            $bDate = date('Y-m-d', strtotime($row['b_date']));
                        }
                        $slotLabel = booking_slot_label($row, $bookCols, $TIME_SLOTS);

                        if ($action === 'approve_request') {
                            // conflict check only if we can determine booking slot + date + trainer column exists
                            if ($bDate && in_array($slotLabel, $TIME_SLOTS, true) && !empty($bookCols['trainer']) && !empty($bookCols['date'])) {
                                $busy = trainer_busy($conn, $bookTable, $bookCols, $userId, $bDate, $slotLabel, $bookingId);
                                if ($busy) {
                                    $flash = "Cannot approve. You already have a booking on {$bDate} at {$slotLabel}.";
                                    $flashType = 'danger';
                                    goto done_post;
                                }
                            }

                            // update booking: status + trainer_id (if exists) assign to this trainer
                            $newStatus = status_value('approve');
                            $sets = [];
                            $types = "";
                            $params = [];

                            $sets[] = "`{$bookCols['status']}`=?";
                            $types .= "s";
                            $params[] = $newStatus;

                            if (!empty($bookCols['trainer'])) {
                                $sets[] = "`{$bookCols['trainer']}`=?";
                                $types .= "i";
                                $params[] = $userId;
                            }

                            $sqlU = "UPDATE `{$bookTable}` SET ".implode(',', $sets)." WHERE `{$bookCols['id']}`=?";
                            $types .= "i";
                            $params[] = $bookingId;

                            $stU = $conn->prepare($sqlU);
                            if (!$stU) {
                                $flash = "DB prepare failed while approving booking.";
                                $flashType = 'danger';
                            } else {
                                $refs = [];
                                $refs[] = &$types;
                                foreach ($params as $k => $v) $refs[] = &$params[$k];
                                call_user_func_array([$stU, 'bind_param'], $refs);

                                $ok = $stU->execute();
                                $stU->close();
                                $flash = $ok ? "Approved booking #{$bookingId}." : "Failed to approve booking.";
                                $flashType = $ok ? 'success' : 'danger';
                            }
                        } else {
                            // reject
                            $newStatus = status_value('reject');
                            $sets = [];
                            $types = "";
                            $params = [];

                            $sets[] = "`{$bookCols['status']}`=?";
                            $types .= "s";
                            $params[] = $newStatus;

                            // optional: assign trainer on reject too (only if column exists)
                            if (!empty($bookCols['trainer'])) {
                                $sets[] = "`{$bookCols['trainer']}`=?";
                                $types .= "i";
                                $params[] = $userId;
                            }

                            $sqlU = "UPDATE `{$bookTable}` SET ".implode(',', $sets)." WHERE `{$bookCols['id']}`=?";
                            $types .= "i";
                            $params[] = $bookingId;

                            $stU = $conn->prepare($sqlU);
                            if (!$stU) {
                                $flash = "DB prepare failed while rejecting booking.";
                                $flashType = 'danger';
                            } else {
                                $refs = [];
                                $refs[] = &$types;
                                foreach ($params as $k => $v) $refs[] = &$params[$k];
                                call_user_func_array([$stU, 'bind_param'], $refs);

                                $ok = $stU->execute();
                                $stU->close();
                                $flash = $ok ? "Rejected booking #{$bookingId}." : "Failed to reject booking.";
                                $flashType = $ok ? 'success' : 'danger';
                            }
                        }
                    }
                }
            }
        }
    }
}

done_post:

/* ======================================================================
   LOAD DATA for the selected date:
   - availability slots for trainer
   - pending requests for trainer/any-trainer
   - approved bookings for trainer (to show busy)
   ====================================================================== */

// availability for trainer+date grouped by facility
$availByFacility = []; // [facility_slug][slot] => true
if ($canUseAvailability) {
    $sql = "SELECT `{$avail_fac_col}` AS fac, `{$avail_slot_col}` AS slot".($avail_is_col ? ", `{$avail_is_col}` AS ia" : "")."
            FROM `{$availTable}`
            WHERE trainer_id=? AND `{$avail_date_col}`=?";
    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param('is', $userId, $selectedDate);
        $st->execute();
        if ($res = $st->get_result()) {
            while ($r = $res->fetch_assoc()) {
                $fac = normalize_slug($r['fac'] ?? '');
                $slot = trim((string)($r['slot'] ?? ''));
                if ($fac === '' || $slot === '') continue;
                $isAvail = true;
                if ($avail_is_col) {
                    $isAvail = ((int)($r['ia'] ?? 1) === 1);
                }
                if ($isAvail) $availByFacility[$fac][$slot] = true;
            }
            $res->free();
        }
        $st->close();
    }
}

// occupied slots for trainer+date (approved/pending assigned to THIS trainer)
$occupiedSlots = []; // [slot] => true
if ($bookTable && !empty($bookCols['trainer']) && !empty($bookCols['date'])) {
    $whereOcc = status_is_occupied($bookCols['status']);
    $whereDate = date_where($bookCols['date']);

    $sel = [];
    if (!empty($bookCols['range']))    $sel[] = "`{$bookCols['range']}` AS b_range";
    if (!empty($bookCols['start']))    $sel[] = "`{$bookCols['start']}` AS b_start";
    if (!empty($bookCols['end']))      $sel[] = "`{$bookCols['end']}` AS b_end";
    if (!empty($bookCols['timeOnly'])) $sel[] = "`{$bookCols['timeOnly']}` AS b_time";

    $sql = "SELECT ".implode(',', $sel)."
            FROM `{$bookTable}`
            WHERE `{$bookCols['trainer']}`=?
              AND {$whereDate}
              AND {$whereOcc}";
    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param('is', $userId, $selectedDate);
        $st->execute();
        if ($res = $st->get_result()) {
            while ($r = $res->fetch_assoc()) {
                $slot = booking_slot_label($r, $bookCols, $TIME_SLOTS);
                if (in_array($slot, $TIME_SLOTS, true)) $occupiedSlots[$slot] = true;
            }
            $res->free();
        }
        $st->close();
    }
}

// pending requests grouped by facility for that date:
// - trainer_id = this trainer
// - OR trainer_id = 0/NULL (any trainer)
$pendingByFacility = []; // [facility_slug][] rows
if ($bookTable && !empty($bookCols['status']) && !empty($bookCols['date'])) {
    $wherePending = status_is_pending($bookCols['status']);
    $whereDate = date_where($bookCols['date']);

    $trainerFilter = "1=1";
    if (!empty($bookCols['trainer'])) {
        $trainerFilter = "(`{$bookCols['trainer']}`=? OR `{$bookCols['trainer']}`=0 OR `{$bookCols['trainer']}` IS NULL)";
    }

    $sel = [];
    $sel[] = "`{$bookCols['id']}` AS bid";
    if (!empty($bookCols['trainer']))  $sel[] = "`{$bookCols['trainer']}` AS b_trainer";
    if (!empty($bookCols['fullName'])) $sel[] = "`{$bookCols['fullName']}` AS b_fullname";
    if (!empty($bookCols['notes']))   $sel[] = "`{$bookCols['notes']}` AS b_notes";
    if (!empty($bookCols['fac_slug'])) $sel[] = "`{$bookCols['fac_slug']}` AS b_fac_slug";
    if (!empty($bookCols['fac_name'])) $sel[] = "`{$bookCols['fac_name']}` AS b_fac_name";
    if (!empty($bookCols['range']))    $sel[] = "`{$bookCols['range']}` AS b_range";
    if (!empty($bookCols['start']))    $sel[] = "`{$bookCols['start']}` AS b_start";
    if (!empty($bookCols['end']))      $sel[] = "`{$bookCols['end']}` AS b_end";
    if (!empty($bookCols['timeOnly'])) $sel[] = "`{$bookCols['timeOnly']}` AS b_time";
    if (!empty($bookCols['created']))  $sel[] = "`{$bookCols['created']}` AS b_created";

    $sql = "SELECT ".implode(',', $sel)."
            FROM `{$bookTable}`
            WHERE {$whereDate}
              AND {$wherePending}
              AND {$trainerFilter}
            ORDER BY ".(!empty($bookCols['created']) ? "`{$bookCols['created']}` DESC" : "`{$bookCols['id']}` DESC")."
            LIMIT 50";

    if (!empty($bookCols['trainer'])) {
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('is', $userId, $selectedDate);
            $st->execute();
            if ($res = $st->get_result()) {
                while ($r = $res->fetch_assoc()) {
                    $fac = '';
                    if (!empty($r['b_fac_slug'])) $fac = normalize_slug($r['b_fac_slug']);
                    if (!$fac && !empty($r['b_fac_name'])) $fac = normalize_slug($r['b_fac_name']);
                    if (!$fac) $fac = '_unknown';
                    $pendingByFacility[$fac][] = $r;
                }
                $res->free();
            }
            $st->close();
        }
    } else {
        // no trainer column => just pending by date (rare)
        if ($res = $conn->query(str_replace($trainerFilter, "1=1", $sql))) {
            while ($r = $res->fetch_assoc()) {
                $fac = '';
                if (!empty($r['b_fac_slug'])) $fac = normalize_slug($r['b_fac_slug']);
                if (!$fac && !empty($r['b_fac_name'])) $fac = normalize_slug($r['b_fac_name']);
                if (!$fac) $fac = '_unknown';
                $pendingByFacility[$fac][] = $r;
            }
            $res->free();
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Trainer Facilities</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{ --bg:#101010; --panel:#171717; --line:#2a2a2a; --brand:#b30000; --muted:#a9a9a9; }
  body{background:var(--bg);color:#fff;font-family:'Poppins',sans-serif}
  .navbar{background:linear-gradient(90deg,#000,var(--brand))}
  a, a:hover{color:#fff}

  .cards.has-active .facility-col:not(.expanded) .facility-card-wrap{
    transform: scale(.92);
    opacity:.55;
    filter:saturate(.85);
  }
  .facility-col{ transition: all .28s ease; }
  .facility-col.expanded{ flex:0 0 100%; max-width:100%; }

  .facility-card-wrap{ transition:transform .28s ease, opacity .25s ease, filter .25s ease; position:relative; }
  .facility-card-wrap.active{ z-index:2000; }

  .facility-card{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:12px;
    overflow:hidden;
    cursor:pointer;
    position:relative;
    transition: box-shadow .3s ease, transform .28s ease, border-radius .2s ease;
    box-shadow:0 6px 22px rgba(0,0,0,.28);
  }
  .facility-card:hover{ box-shadow:0 16px 40px rgba(0,0,0,.45); }
  .img-top{height:240px;object-fit:cover;width:100%}

  .badge-rol{background:#222;border:1px solid #333}
  .btn-danger{background:var(--brand);border:none}
  .btn-danger:hover{background:#ff1a1a}

  .facility-card-wrap.active .facility-card{ transform: scale(1.06); border-radius:14px; }
  .card-main{ display:block; }
  .facility-card-wrap.active .card-main{ display:none; }

  .schedule{
    background:#141414;border-top:1px dashed #303030;
    max-height:0; overflow:hidden;
    transition:max-height .40s ease, padding .28s ease;
    padding:0 16px;
  }
  .facility-card-wrap.active .schedule{ max-height:1200px; padding:22px 22px; }

  .sched-grid{ display:grid; grid-template-columns: 40% 60%; gap:16px; }
  @media (max-width: 992px){ .sched-grid{ grid-template-columns: 1fr; } }

  .sched-visual{
    min-height:380px;
    background:#0f0f0f url('photo/man_left.jpg') center/cover no-repeat;
    border:1px solid #272727; border-radius:12px; position:relative;
  }
  .sched-visual::after{
    content:""; position:absolute; inset:0;
    background:linear-gradient(180deg, rgba(0,0,0,.0), rgba(0,0,0,.35));
    border-radius:12px;
  }

  .sched-section{ background:#171717; border:1px solid #2a2a2a; border-radius:12px; padding:16px; }
  .section-title{ font-weight:700; margin-bottom:8px; }
  .muted{ color:#9aa0a6; }

  #panelOverlay{
    position:fixed; inset:0; background:rgba(0,0,0,.45);
    z-index: 1500;
  }

  .slot-grid{
    display:grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap:10px;
    margin-top:10px;
  }
  @media(max-width: 575px){ .slot-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }

  .slot-btn{
    width:100%;
    border-radius:999px;
    padding:9px 10px;
    border:1px solid rgba(255,255,255,.18);
    background:#111;
    color:#fff;
    font-weight:700;
    font-size:.85rem;
    cursor:pointer;
    text-align:center;
  }
  .slot-btn.available{
    background: rgba(40,167,69,.18);
    border-color: rgba(40,167,69,.55);
    color:#28a745;
  }
  .slot-btn.busy{
    background: rgba(220,53,69,.18);
    border-color: rgba(220,53,69,.55);
    color:#dc3545;
    cursor:not-allowed;
    opacity:.8;
  }
  .slot-btn:hover{ filter:brightness(1.08); }

  .req-table{
    width:100%;
    border-collapse:collapse;
    font-size:.92rem;
  }
  .req-table th,.req-table td{
    padding:10px 10px;
    border-bottom:1px solid rgba(255,255,255,.08);
    vertical-align:top;
  }
  .req-table th{
    color:#cfcfcf;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.08em;
    font-size:.74rem;
  }

  .pill{
    display:inline-flex;
    padding:3px 10px;
    border-radius:999px;
    font-weight:800;
    font-size:.72rem;
    letter-spacing:.06em;
    border:1px solid rgba(255,255,255,.18);
    background:#111;
    color:#ddd;
  }
  .pill.any{ color:#ffc107; border-color: rgba(255,193,7,.5); background: rgba(255,193,7,.12); }
</style>
</head>
<body>

<nav class="navbar navbar-dark">
  <a class="navbar-brand ml-3" href="home.php"><img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness</a>
  <div class="ml-auto mr-3">
    <a class="btn btn-outline-light btn-sm" href="home.php">Home</a>
    <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
  </div>
</nav>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h3 class="mb-0">Trainer Facilities</h3>
    <small class="text-muted">Trainer: <?= h($trainerName) ?></small>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flashType) ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <?php if (!$canUseAvailability): ?>
    <div class="alert alert-warning">
      <strong>Availability table not detected.</strong>
      Create <code>trainer_availability</code> using the SQL I provided, so you can set free time slots.
    </div>
  <?php endif; ?>

  <?php if (!$bookTable): ?>
    <div class="alert alert-warning">
      <strong>Booking table not detected.</strong>
      Add your booking table name in <code>BOOKING_TABLE_CANDIDATES</code> inside this file.
    </div>
  <?php endif; ?>

  <div class="d-flex align-items-center justify-content-between mb-3">
    <div class="muted">Pick a date to manage your availability and requests.</div>
    <form method="get" class="form-inline">
      <label class="mr-2 muted">Date:</label>
      <input type="date" name="date" class="form-control form-control-sm mr-2" value="<?= h($selectedDate) ?>">
      <button class="btn btn-danger btn-sm" type="submit">Go</button>
    </form>
  </div>

  <div class="row cards" id="cards">
    <?php if (!$facilities): ?>
      <div class="col-12"><div class="alert alert-secondary">No facilities available for trainer.</div></div>
    <?php else: foreach ($facilities as $f):

      $rawSlug = trim($f['slug'] ?? '');
      if ($rawSlug === '') {
        $nm = trim($f['name'] ?? '');
        $slug = $nm ? strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $nm), '-')) : ('facility-'.$f['id']);
      } else {
        $slug = $rawSlug;
      }
      $slugNorm = normalize_slug($slug);

      $vis = isset($f['visible_to'])
        ? ($f['visible_to']==='both' ? 'Member & Trainer' : ucfirst($f['visible_to']))
        : 'Member & Trainer';

      $availSlots = $availByFacility[$slugNorm] ?? [];
      $pendingRows = $pendingByFacility[$slugNorm] ?? ($pendingByFacility[normalize_slug($f['name'] ?? '')] ?? []);
    ?>
      <div class="facility-col col-md-6 col-lg-4 mb-4" style="overflow:visible">
        <div class="facility-card-wrap" data-slug="<?= h($slugNorm) ?>" data-name="<?= h($f['name']) ?>">
          <div class="facility-card">
            <div class="card-main">
              <img class="img-top" src="<?= h($f['image'] ?: 'photo/logo.jpg') ?>" alt="">
              <div class="p-3">
                <div class="d-flex justify-content-between align-items-center">
                  <h5 class="mb-1"><?= h($f['name']) ?></h5>
                  <span class="badge badge-rol"><?= h($vis) ?></span>
                </div>
                <p class="mb-0 text-muted"><?= h($f['description'] ?: '—') ?></p>
              </div>
            </div>

            <div class="schedule">
              <div class="sched-grid">
                <div class="sched-visual"></div>

                <div>
                  <div class="sched-section mb-3">
                    <div class="section-title">Manage Availability & Requests</div>
                    <p class="muted mb-0">
                      Date: <strong><?= h($selectedDate) ?></strong><br>
                      Mark your free slots, then approve or reject pending requests.
                    </p>
                  </div>

                  <div class="sched-section mb-3">
                    <div class="section-title">Your Available Time Slots</div>
                    <div class="muted">Tap a slot to toggle availability.</div>

                    <div class="slot-grid">
                      <?php foreach ($TIME_SLOTS as $slot):
                        $isAvail = isset($availSlots[$slot]);
                        $isBusy  = isset($occupiedSlots[$slot]); // already has booking assigned to you
                        $btnClass = $isBusy ? 'busy' : ($isAvail ? 'available' : '');
                      ?>
                        <?php if ($isBusy): ?>
                          <button class="slot-btn busy" type="button" title="You already have a booking in this slot">
                            <?= h($slot) ?><br><small>BUSY</small>
                          </button>
                        <?php else: ?>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="<?= $isAvail ? 'remove_availability' : 'add_availability' ?>">
                            <input type="hidden" name="facility_slug" value="<?= h($slugNorm) ?>">
                            <input type="hidden" name="date" value="<?= h($selectedDate) ?>">
                            <input type="hidden" name="time_slot" value="<?= h($slot) ?>">
                            <button class="slot-btn <?= h($btnClass) ?>" type="submit">
                              <?= h($slot) ?><br>
                              <small><?= $isAvail ? 'AVAILABLE' : 'SET FREE' ?></small>
                            </button>
                          </form>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <div class="sched-section">
                    <div class="section-title">Pending Requests</div>

                    <?php if (!$bookTable || empty($pendingRows)): ?>
                      <div class="muted">No pending requests for this facility on this date.</div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="req-table">
                          <thead>
                            <tr>
                              <th>Member</th>
                              <th>Time</th>
                              <th>Type</th>
                              <th style="width:160px;">Actions</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($pendingRows as $r):
                              $slot = booking_slot_label($r, $bookCols, $TIME_SLOTS);
                              $isAny = (isset($r['b_trainer']) && (int)$r['b_trainer'] === 0) || empty($r['b_trainer']);
                              $memberName = $r['b_fullname'] ?? ('Booking #'.$r['bid']);
                            ?>
                              <tr>
                                <td>
                                  <strong><?= h($memberName) ?></strong>
                                  <?php if (!empty($r['b_notes'])): ?>
                                    <div class="muted" style="margin-top:4px;"><?= h($r['b_notes']) ?></div>
                                  <?php endif; ?>
                                </td>
                                <td><?= h($slot) ?></td>
                                <td>
                                  <span class="pill <?= $isAny ? 'any' : '' ?>">
                                    <?= $isAny ? 'ANY TRAINER' : 'ASSIGNED' ?>
                                  </span>
                                </td>
                                <td>
                                  <form method="post" style="display:inline-block;">
                                    <input type="hidden" name="action" value="approve_request">
                                    <input type="hidden" name="booking_id" value="<?= (int)$r['bid'] ?>">
                                    <button class="btn btn-sm btn-success" type="submit">Approve</button>
                                  </form>
                                  <form method="post" style="display:inline-block;">
                                    <input type="hidden" name="action" value="reject_request">
                                    <input type="hidden" name="booking_id" value="<?= (int)$r['bid'] ?>">
                                    <button class="btn btn-sm btn-outline-light" type="submit">Reject</button>
                                  </form>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>

                </div>
              </div>

              <div class="d-flex justify-content-end mt-3">
                <a class="btn btn-outline-light btn-sm" href="schedules.php?date=<?= urlencode($selectedDate) ?>">Full Schedule</a>
              </div>
            </div>

          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div id="panelOverlay" hidden></div>

<script>
// Same open/close logic as member facilities
const grid    = document.getElementById('cards');
const cards   = document.querySelectorAll('.facility-card-wrap');
const cols    = document.querySelectorAll('.facility-col');
const overlay = document.getElementById('panelOverlay');

function closeAllPanels(){
  grid.classList.remove('has-active');
  cards.forEach(w => w.classList.remove('active'));
  cols.forEach(c => c.classList.remove('expanded'));
  if (overlay) overlay.hidden = true;
}

cards.forEach(wrap => {
  wrap.addEventListener('click', e => {
    const interactive = e.target.closest('a,button,input,textarea,select,label,form');
    if (interactive) return;

    const col = wrap.closest('.facility-col');
    closeAllPanels();
    grid.classList.add('has-active');
    wrap.classList.add('active');
    if (col) col.classList.add('expanded');
    if (overlay) overlay.hidden = false;
  });
});

if (overlay) overlay.addEventListener('click', closeAllPanels);

// Keep clicks inside schedule/forms from closing panel
document.addEventListener('click', e => {
  if (e.target.closest('.facility-card-wrap .schedule')) e.stopPropagation();
}, true);
</script>
</body>
</html>