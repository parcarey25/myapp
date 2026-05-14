<?php
// facilities_trainer.php
// Keeps the JavaScript click-to-open box design.
// Trainer sets free time slots here. Members see only those slots in facilities.php.

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require __DIR__ . '/db.php';
require __DIR__ . '/trainer_booking_lib.php';

tb_ensure_schema($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = strtolower(trim($_SESSION['role'] ?? ''));

if ($role !== 'trainer' && $role !== 'admin') {
    header('Location: home.php');
    exit;
}

function trainer_redirect(string $date): void {
    header('Location: facilities_trainer.php?date=' . urlencode($date));
    exit;
}

$selectedDate = trim($_GET['date'] ?? $_POST['date'] ?? date('Y-m-d'));
if (!tb_valid_date($selectedDate)) $selectedDate = date('Y-m-d');

$timeSlots = tb_time_slots();
$slotLabels = [];
foreach ($timeSlots as $slot) $slotLabels[$slot] = tb_slot_label($slot);

$flash = null;
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strtolower(trim($_POST['action'] ?? ''));
    $postDate = trim($_POST['date'] ?? $selectedDate);
    if (!tb_valid_date($postDate)) $postDate = date('Y-m-d');

    if ($action === 'add_availability' || $action === 'remove_availability') {
        $facilitySlug = tb_normalize_slug($_POST['facility_slug'] ?? '');
        $slot = trim($_POST['time_slot'] ?? '');

        if ($facilitySlug === '' || !in_array($slot, $timeSlots, true)) {
            $_SESSION['trainer_flash'] = ['type' => 'danger', 'msg' => 'Invalid facility or time slot.'];
            trainer_redirect($postDate);
        }

        if ($action === 'remove_availability' && tb_is_slot_reserved($conn, $userId, $facilitySlug, $postDate, $slot)) {
            $_SESSION['trainer_flash'] = ['type' => 'danger', 'msg' => 'This slot already has a pending or approved booking, so it cannot be removed.'];
            trainer_redirect($postDate);
        }

        // Remove duplicates first. Your current table does not need a UNIQUE key.
        $stmt = $conn->prepare("DELETE FROM trainer_availability WHERE trainer_id=? AND facility_slug=? AND avail_date=? AND time_slot=?");
        if ($stmt) {
            $stmt->bind_param('isss', $userId, $facilitySlug, $postDate, $slot);
            $stmt->execute();
            $stmt->close();
        }

        if ($action === 'add_availability') {
            $stmt = $conn->prepare("INSERT INTO trainer_availability (trainer_id, facility_slug, avail_date, time_slot, is_available) VALUES (?, ?, ?, ?, 1)");
            if ($stmt) {
                $stmt->bind_param('isss', $userId, $facilitySlug, $postDate, $slot);
                $ok = $stmt->execute();
                $stmt->close();
                $_SESSION['trainer_flash'] = ['type' => $ok ? 'success' : 'danger', 'msg' => $ok ? 'Availability saved.' : 'Could not save availability.'];
            } else {
                $_SESSION['trainer_flash'] = ['type' => 'danger', 'msg' => 'Database error while saving availability.'];
            }
        } else {
            $_SESSION['trainer_flash'] = ['type' => 'success', 'msg' => 'Availability removed.'];
        }

        trainer_redirect($postDate);
    }

    if ($action === 'approve_booking' || $action === 'reject_booking') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            $_SESSION['trainer_flash'] = ['type' => 'danger', 'msg' => 'Invalid booking.'];
            trainer_redirect($postDate);
        }

        $stmt = $conn->prepare("SELECT id, facility_slug, facility_name, date, time, trainer_id, status FROM bookings WHERE id=? LIMIT 1");
        if (!$stmt) {
            $_SESSION['trainer_flash'] = ['type' => 'danger', 'msg' => 'Database error while reading booking.'];
            trainer_redirect($postDate);
        }
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $result = $stmt->get_result();
        $booking = $result ? $result->fetch_assoc() : null;
        if ($result) $result->free();
        $stmt->close();

        if (!$booking || (int)$booking['trainer_id'] !== $userId) {
            $_SESSION['trainer_flash'] = ['type' => 'danger', 'msg' => 'Booking not found for this trainer.'];
            trainer_redirect($postDate);
        }

        if (strtolower(trim($booking['status'] ?? '')) !== 'pending') {
            $_SESSION['trainer_flash'] = ['type' => 'danger', 'msg' => 'This booking is not pending anymore.'];
            trainer_redirect($postDate);
        }

        if ($action === 'approve_booking') {
            $facilitySlug = tb_normalize_slug($booking['facility_slug'] ?? '');
            $bookingDate = date('Y-m-d', strtotime($booking['date'] ?? $postDate));
            $slot = trim($booking['time'] ?? '');

            if (!tb_is_trainer_available($conn, $userId, $facilitySlug, $bookingDate, $slot, $bookingId)) {
                $_SESSION['trainer_flash'] = ['type' => 'danger', 'msg' => 'Cannot approve. The slot is no longer available or has a conflict.'];
                trainer_redirect($postDate);
            }

            $newStatus = 'approved';
        } else {
            $newStatus = 'disapproved';
        }

        $stmt = $conn->prepare("UPDATE bookings SET status=? WHERE id=? AND trainer_id=?");
        if ($stmt) {
            $stmt->bind_param('sii', $newStatus, $bookingId, $userId);
            $ok = $stmt->execute();
            $stmt->close();
            $_SESSION['trainer_flash'] = ['type' => $ok ? 'success' : 'danger', 'msg' => $ok ? 'Booking updated.' : 'Could not update booking.'];
        } else {
            $_SESSION['trainer_flash'] = ['type' => 'danger', 'msg' => 'Database error while updating booking.'];
        }

        trainer_redirect($postDate);
    }
}

if (!empty($_SESSION['trainer_flash'])) {
    $flashType = $_SESSION['trainer_flash']['type'] ?? 'info';
    $flash = $_SESSION['trainer_flash']['msg'] ?? '';
    unset($_SESSION['trainer_flash']);
}

$trainerName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Trainer';
$facilities = tb_load_facilities($conn, 'trainer');

$availability = []; // facility_slug => slot => true
$stmt = $conn->prepare("SELECT facility_slug, time_slot FROM trainer_availability WHERE trainer_id=? AND avail_date=? AND COALESCE(is_available, 1)=1");
if ($stmt) {
    $stmt->bind_param('is', $userId, $selectedDate);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $slug = tb_normalize_slug($row['facility_slug'] ?? '');
        $slot = trim($row['time_slot'] ?? '');
        if ($slug !== '' && in_array($slot, $timeSlots, true)) {
            $availability[$slug][$slot] = true;
        }
    }
    if ($result) $result->free();
    $stmt->close();
}

$busy = []; // facility_slug => slot => true
$stmt = $conn->prepare("SELECT facility_slug, time FROM bookings WHERE trainer_id=? AND date=? AND LOWER(TRIM(status)) IN ('pending','approved','accepted')");
if ($stmt) {
    $stmt->bind_param('is', $userId, $selectedDate);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $slug = tb_normalize_slug($row['facility_slug'] ?? '');
        $slot = trim($row['time'] ?? '');
        if ($slug !== '' && in_array($slot, $timeSlots, true)) {
            $busy[$slug][$slot] = true;
        }
    }
    if ($result) $result->free();
    $stmt->close();
}

$pending = []; // facility_slug => rows
$stmt = $conn->prepare("SELECT id, facility_slug, facility_name, date, time, full_name, email, notes, created_at
                        FROM bookings
                        WHERE trainer_id=? AND date=? AND LOWER(TRIM(status))='pending'
                        ORDER BY created_at DESC, id DESC");
if ($stmt) {
    $stmt->bind_param('is', $userId, $selectedDate);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $slug = tb_normalize_slug($row['facility_slug'] ?? '');
        if ($slug === '') $slug = '_unknown';
        $pending[$slug][] = $row;
    }
    if ($result) $result->free();
    $stmt->close();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trainer Facilities | RJL Fitness</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
    <style>
        body{background:#0f0f0f;color:#fff;font-family:Arial,sans-serif;margin:0}.navbar{background:#111;border-bottom:1px solid #242424}.brand{font-weight:800;color:#fff}.container-narrow{max-width:1250px;margin:0 auto}.muted{color:#bcbcbc}.facility-card{background:#1a1a1a;border:1px solid #2c2c2c;border-radius:18px;overflow:hidden;box-shadow:0 10px 26px rgba(0,0,0,.28);height:100%;cursor:pointer;transition:.18s}.facility-card:hover{transform:translateY(-3px);border-color:#e7344c}.facility-card img{width:100%;height:210px;object-fit:cover;background:#222}.facility-body{padding:18px}.facility-title{font-weight:800;font-size:1.3rem}.badge-red{background:#e7344c;color:#fff}.btn-red{background:#e7344c;color:#fff;border:0}.btn-red:hover{background:#c82333;color:#fff}.empty-box{background:#191919;border:1px dashed #444;border-radius:16px;padding:28px;text-align:center;color:#aaa}.modal-shade{display:none;position:fixed;z-index:9999;inset:0;background:rgba(0,0,0,.78);padding:20px;overflow:auto}.manage-modal{max-width:900px;margin:35px auto;background:#181818;border:1px solid #383838;border-radius:18px;box-shadow:0 18px 45px rgba(0,0,0,.55);overflow:hidden}.manage-head{padding:18px 22px;border-bottom:1px solid #303030;display:flex;justify-content:space-between;align-items:center}.manage-content{padding:22px}.close-x{background:transparent;border:0;color:#fff;font-size:30px;line-height:1}.slot-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px}.slot-btn{border:1px solid #444;background:#101010;color:#fff;border-radius:12px;padding:14px 10px;text-align:center;width:100%;min-height:82px}.slot-btn.on{border-color:#2ecc71;background:#12381f}.slot-btn.off{border-color:#444}.slot-btn.busy{border-color:#ffc107;background:#403512;cursor:not-allowed}.slot-time{font-weight:800}.slot-state{font-size:.85rem;margin-top:6px}.request-card{background:#101010;border:1px solid #333;border-radius:12px;padding:13px;margin-bottom:12px}.form-control{background:#0c0c0c;color:#fff;border-color:#393939}.form-control:focus{background:#0c0c0c;color:#fff;border-color:#e7344c;box-shadow:0 0 0 .2rem rgba(231,52,76,.22)}.alert{border-radius:12px}.click-note{font-size:.9rem;color:#ccc}
    </style>
</head>
<body>
<nav class="navbar navbar-dark px-4">
    <a class="navbar-brand brand" href="home.php">RJL Fitness</a>
    <div>
        <a class="btn btn-outline-light btn-sm" href="home.php">Home</a>
        <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
    </div>
</nav>

<div class="container-narrow px-3 py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
        <div>
            <h2 class="mb-1">Trainer Facilities</h2>
            <p class="muted mb-0">Trainer: <strong><?= h($trainerName) ?></strong></p>
        </div>
        <form method="get" class="form-inline mt-2">
            <label class="mr-2 font-weight-bold">Date</label>
            <input type="date" name="date" class="form-control mr-2" value="<?= h($selectedDate) ?>">
            <button class="btn btn-red">Go</button>
        </form>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flashType) ?>"><?= h($flash) ?></div>
    <?php endif; ?>

    <p class="muted">Click a facility to open the time-slot box. The slot choices are not shown under every card anymore.</p>

    <?php if (!$facilities): ?>
        <div class="empty-box">No facilities available for trainer.</div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($facilities as $facility):
                $slug = tb_normalize_slug($facility['slug'] ?? $facility['name'] ?? '');
                $image = trim($facility['image'] ?? '');
                if ($image === '') $image = 'photo/logo.jpg';
                $availableCount = isset($availability[$slug]) ? count($availability[$slug]) : 0;
                $pendingCount = isset($pending[$slug]) ? count($pending[$slug]) : 0;
            ?>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="facility-card js-open-manage"
                         data-facility-slug="<?= h($slug) ?>"
                         data-facility-name="<?= h($facility['name'] ?? 'Facility') ?>">
                        <img src="<?= h($image) ?>" alt="<?= h($facility['name'] ?? 'Facility') ?>">
                        <div class="facility-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="facility-title"><?= h($facility['name'] ?? 'Facility') ?></div>
                                <span class="badge badge-red">Manage</span>
                            </div>
                            <p class="muted mb-2"><?= h($facility['description'] ?? '') ?></p>
                            <div class="click-note">
                                <?= (int)$availableCount ?> available slot(s) &bull; <?= (int)$pendingCount ?> pending request(s)
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="modal-shade" id="manageShade" aria-hidden="true">
    <div class="manage-modal">
        <div class="manage-head">
            <div>
                <h4 class="mb-0" id="manageTitle">Manage Facility</h4>
                <div class="muted">Date: <?= h($selectedDate) ?></div>
            </div>
            <button type="button" class="close-x" id="closeManage" aria-label="Close">&times;</button>
        </div>
        <div class="manage-content">
            <h5>Your Available Time Slots</h5>
            <p class="muted">Click a slot to turn availability ON or OFF. Busy slots cannot be removed.</p>
            <div class="slot-grid mb-4" id="slotGrid"></div>

            <h5>Pending Requests</h5>
            <div id="pendingBox"></div>
        </div>
    </div>
</div>

<form method="post" id="slotActionForm" style="display:none">
    <input type="hidden" name="action" id="slotAction">
    <input type="hidden" name="facility_slug" id="slotFacilitySlug">
    <input type="hidden" name="time_slot" id="slotTime">
    <input type="hidden" name="date" value="<?= h($selectedDate) ?>">
</form>

<script>
(() => {
    const SELECTED_DATE = <?= json_encode($selectedDate) ?>;
    const TIME_SLOTS = <?= json_encode($timeSlots, JSON_UNESCAPED_SLASHES) ?>;
    const SLOT_LABELS = <?= json_encode($slotLabels, JSON_UNESCAPED_SLASHES) ?>;
    const AVAILABILITY = <?= json_encode($availability, JSON_UNESCAPED_SLASHES) ?>;
    const BUSY = <?= json_encode($busy, JSON_UNESCAPED_SLASHES) ?>;
    const PENDING = <?= json_encode($pending, JSON_UNESCAPED_SLASHES) ?>;

    const shade = document.getElementById('manageShade');
    const closeBtn = document.getElementById('closeManage');
    const title = document.getElementById('manageTitle');
    const slotGrid = document.getElementById('slotGrid');
    const pendingBox = document.getElementById('pendingBox');
    const slotForm = document.getElementById('slotActionForm');
    const slotAction = document.getElementById('slotAction');
    const slotFacilitySlug = document.getElementById('slotFacilitySlug');
    const slotTime = document.getElementById('slotTime');

    function esc(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function submitSlot(slug, slot, action) {
        slotFacilitySlug.value = slug;
        slotTime.value = slot;
        slotAction.value = action;
        slotForm.submit();
    }

    function renderSlots(slug) {
        slotGrid.innerHTML = '';
        TIME_SLOTS.forEach(slot => {
            const isOn = !!(AVAILABILITY[slug] && AVAILABILITY[slug][slot]);
            const isBusy = !!(BUSY[slug] && BUSY[slug][slot]);
            const action = isOn ? 'remove_availability' : 'add_availability';
            const state = isBusy ? 'BUSY' : (isOn ? 'ON' : 'OFF');
            const klass = isBusy ? 'busy' : (isOn ? 'on' : 'off');
            const disabled = isBusy ? 'disabled' : '';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `slot-btn ${klass}`;
            button.disabled = isBusy;
            button.innerHTML = `<div class="slot-time">${esc(SLOT_LABELS[slot] || slot)}</div><div class="slot-state">${esc(state)}</div>`;
            button.addEventListener('click', () => submitSlot(slug, slot, action));
            slotGrid.appendChild(button);
        });
    }

    function renderPending(slug) {
        const rows = PENDING[slug] || [];
        if (!rows.length) {
            pendingBox.innerHTML = '<div class="muted">No pending requests for this facility on this date.</div>';
            return;
        }

        pendingBox.innerHTML = rows.map(row => `
            <div class="request-card">
                <div class="d-flex justify-content-between flex-wrap">
                    <div>
                        <strong>#${Number(row.id)} ${esc(row.full_name || '')}</strong><br>
                        <span class="muted">${esc(row.email || '')}</span><br>
                        <span>${esc(SLOT_LABELS[row.time] || row.time || '')}</span>
                        ${row.notes ? `<div class="muted mt-1">Notes: ${esc(row.notes)}</div>` : ''}
                    </div>
                    <div class="mt-2 mt-md-0">
                        <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="approve_booking">
                            <input type="hidden" name="booking_id" value="${Number(row.id)}">
                            <input type="hidden" name="date" value="${esc(SELECTED_DATE)}">
                            <button class="btn btn-success btn-sm">Approve</button>
                        </form>
                        <form method="post" class="d-inline ml-1">
                            <input type="hidden" name="action" value="reject_booking">
                            <input type="hidden" name="booking_id" value="${Number(row.id)}">
                            <input type="hidden" name="date" value="${esc(SELECTED_DATE)}">
                            <button class="btn btn-danger btn-sm">Reject</button>
                        </form>
                    </div>
                </div>
            </div>
        `).join('');
    }

    function openManage(card) {
        const slug = card.dataset.facilitySlug || '';
        const name = card.dataset.facilityName || 'Facility';
        title.textContent = name;
        renderSlots(slug);
        renderPending(slug);
        shade.style.display = 'block';
        shade.setAttribute('aria-hidden', 'false');
    }

    document.querySelectorAll('.js-open-manage').forEach(card => {
        card.addEventListener('click', () => openManage(card));
    });

    closeBtn.addEventListener('click', () => {
        shade.style.display = 'none';
        shade.setAttribute('aria-hidden', 'true');
    });

    shade.addEventListener('click', event => {
        if (event.target === shade) {
            shade.style.display = 'none';
            shade.setAttribute('aria-hidden', 'true');
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            shade.style.display = 'none';
            shade.setAttribute('aria-hidden', 'true');
        }
    });
})();
</script>
</body>
</html>
