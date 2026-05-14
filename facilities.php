<?php
// facilities.php
// Keeps the click-to-open booking box/modal design.
// Only change: trainer/time choices now come from facilities_trainer.php availability.

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require __DIR__ . '/db.php';
require __DIR__ . '/trainer_booking_lib.php';

tb_ensure_schema($conn);

$role = strtolower(trim($_SESSION['role'] ?? 'member'));
$userId = (int)($_SESSION['user_id'] ?? 0);
$today = date('Y-m-d');
$timeSlots = tb_time_slots();
$slotLabels = [];
foreach ($timeSlots as $slot) $slotLabels[$slot] = tb_slot_label($slot);

$trainerAccess = tb_user_trainer_access($conn, $userId);
$hasTrainerMembership = !empty($trainerAccess['has_trainer_membership']);
$canReserveTrainer = in_array($role, ['admin', 'staff'], true) || $hasTrainerMembership;
$membershipInfo = $trainerAccess['membership_label'] ?? 'No membership set';
$sessionsLeft = $trainerAccess['trainer_sessions_remaining'] ?? null;

$flash = null;
$flashType = 'info';

// Save booking request as pending. Trainer must approve in facilities_trainer.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['facility_slug'])) {
    $facilitySlug = tb_normalize_slug($_POST['facility_slug'] ?? '');
    $facilityName = trim($_POST['facility_name'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $time = trim($_POST['time'] ?? '');
    $trainerId = (int)($_POST['trainer_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? ($_SESSION['email'] ?? ''));
    $notes = trim($_POST['notes'] ?? '');

    $errors = [];
    if (!$canReserveTrainer) $errors[] = 'Trainer reservation is only available for memberships with trainer. You can still open and view facilities.';
    if ($facilitySlug === '' || $facilityName === '') $errors[] = 'Missing facility information.';
    if (!tb_valid_date($date)) $errors[] = 'Please choose a valid date.';
    if (!in_array($time, $timeSlots, true)) $errors[] = 'Please choose a valid time slot.';
    if ($trainerId <= 0) $errors[] = 'Please choose an available trainer.';
    if ($fullName === '') $errors[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

    if (!$errors && !tb_is_trainer_available($conn, $trainerId, $facilitySlug, $date, $time)) {
        $errors[] = 'That trainer is no longer available for the selected time. Please choose another trainer or time slot.';
    }

    if (!$errors) {
        $status = 'pending';
        $stmt = $conn->prepare("INSERT INTO bookings
            (facility_slug, facility_name, date, time, full_name, email, status, notes, user_id, trainer_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if ($stmt) {
            $uid = $userId ?: null;
            $stmt->bind_param(
                'ssssssssii',
                $facilitySlug,
                $facilityName,
                $date,
                $time,
                $fullName,
                $email,
                $status,
                $notes,
                $uid,
                $trainerId
            );

            if ($stmt->execute()) {
                $bookingId = $stmt->insert_id;
                $stmt->close();
                ?>
                <!doctype html>
                <html lang="en">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>Booking Pending | RJL Fitness</title>
                    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
                    <style>
                        body{background:#0f0f0f;color:#fff;font-family:Arial,sans-serif}.card{background:#181818;border:1px solid #333;border-radius:16px}.muted{color:#aaa}.btn-red{background:#e7344c;color:#fff;border:0}.btn-red:hover{background:#c82333;color:#fff}
                    </style>
                </head>
                <body>
                <div class="container py-5">
                    <div class="card p-4 mx-auto" style="max-width:650px">
                        <h3 class="mb-2">Booking request sent</h3>
                        <p class="muted mb-4">Your request is <strong class="text-warning">pending</strong>. The trainer must approve it first.</p>
                        <div class="mb-3">
                            <div><strong>Booking ID:</strong> #<?= (int)$bookingId ?></div>
                            <div><strong>Facility:</strong> <?= h($facilityName) ?></div>
                            <div><strong>Date:</strong> <?= h($date) ?></div>
                            <div><strong>Time:</strong> <?= h(tb_slot_label($time)) ?></div>
                        </div>
                        <a href="facilities.php" class="btn btn-red">Back to Facilities</a>
                        <a href="schedules.php" class="btn btn-outline-light ml-2">View Schedule</a>
                    </div>
                </div>
                </body>
                </html>
                <?php
                exit;
            }

            $errors[] = 'Could not save booking. Please try again.';
            $stmt->close();
        } else {
            $errors[] = 'Database error while preparing booking.';
        }
    }

    $flash = implode('<br>', array_map('h', $errors));
    $flashType = 'danger';
}

$facilities = tb_load_facilities($conn, $role, $hasTrainerMembership);
$trainers = tb_get_active_trainers($conn);
$sessionName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
$sessionEmail = $_SESSION['email'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Facilities | RJL Fitness</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
    <style>
        body{background:#0f0f0f;color:#fff;font-family:Arial,sans-serif;margin:0}.navbar{background:#111;border-bottom:1px solid #242424}.brand{font-weight:800;color:#fff}.container-narrow{max-width:1250px;margin:0 auto}.muted{color:#bcbcbc}.facility-card{background:#1a1a1a;border:1px solid #2c2c2c;border-radius:18px;overflow:hidden;box-shadow:0 10px 26px rgba(0,0,0,.28);height:100%;cursor:pointer;transition:.18s}.facility-card:hover{transform:translateY(-3px);border-color:#e7344c}.facility-card img{width:100%;height:210px;object-fit:cover;background:#222}.facility-body{padding:18px}.facility-title{font-weight:800;font-size:1.3rem}.badge-red{background:#e7344c;color:#fff}.btn-red{background:#e7344c;color:#fff;border:0}.btn-red:hover{background:#c82333;color:#fff}.empty-box{background:#191919;border:1px dashed #444;border-radius:16px;padding:28px;text-align:center;color:#aaa}.modal-shade{display:none;position:fixed;z-index:9999;inset:0;background:rgba(0,0,0,.78);padding:20px;overflow:auto}.booking-modal{max-width:760px;margin:40px auto;background:#181818;border:1px solid #383838;border-radius:18px;box-shadow:0 18px 45px rgba(0,0,0,.55);overflow:hidden}.booking-head{padding:18px 22px;border-bottom:1px solid #303030;display:flex;justify-content:space-between;align-items:center}.booking-content{padding:22px}.close-x{background:transparent;border:0;color:#fff;font-size:30px;line-height:1}.form-control,.custom-select{background:#0c0c0c;color:#fff;border-color:#393939}.form-control:focus,.custom-select:focus{background:#0c0c0c;color:#fff;border-color:#e7344c;box-shadow:0 0 0 .2rem rgba(231,52,76,.22)}.hint{font-size:.86rem;color:#aaa;margin-top:5px}.alert{border-radius:12px}.click-note{font-size:.9rem;color:#ccc}.select-empty{color:#888}
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
            <h2 class="mb-1">Facilities</h2>
            <p class="muted mb-0">Click a facility to open it. Trainer reservation is only for memberships with trainer.</p>
            <p class="muted mb-0 small">
                Membership: <strong><?= h($membershipInfo) ?></strong>
                <?php if ($sessionsLeft !== null): ?> · Trainer sessions left: <strong><?= (int)$sessionsLeft ?></strong><?php endif; ?>
                <?php if ($hasTrainerMembership): ?> · <span class="text-success">All facility access enabled</span><?php else: ?> · <span class="text-warning">Trainer reservation locked</span><?php endif; ?>
            </p>
        </div>
        <a href="schedules.php" class="btn btn-outline-light btn-sm mt-2">Full Schedule</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flashType) ?>"><?= $flash ?></div>
    <?php endif; ?>

    <?php if (!$facilities): ?>
        <div class="empty-box">No facilities available.</div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($facilities as $facility):
                $slug = tb_normalize_slug($facility['slug'] ?? $facility['name'] ?? '');
                $image = trim($facility['image'] ?? '');
                if ($image === '') $image = 'photo/logo.jpg';
            ?>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="facility-card js-open-booking"
                         data-facility-slug="<?= h($slug) ?>"
                         data-facility-name="<?= h($facility['name'] ?? 'Facility') ?>"
                         data-facility-image="<?= h($image) ?>">
                        <img src="<?= h($image) ?>" alt="<?= h($facility['name'] ?? 'Facility') ?>">
                        <div class="facility-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="facility-title"><?= h($facility['name'] ?? 'Facility') ?></div>
                                <span class="badge badge-red">Book</span>
                            </div>
                            <p class="muted mb-2"><?= h($facility['description'] ?? '') ?></p>
                            <div class="click-note">
                                <?php if ($canReserveTrainer): ?>
                                    Click to choose trainer and time slot
                                <?php else: ?>
                                    Click to view. Trainer booking needs membership with trainer.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="modal-shade" id="bookingShade" aria-hidden="true">
    <div class="booking-modal">
        <div class="booking-head">
            <div>
                <h4 class="mb-0" id="modalFacilityTitle">Schedule a Session</h4>
                <div class="muted" id="modalFacilitySub">Choose trainer and time</div>
            </div>
            <button type="button" class="close-x" id="closeBooking" aria-label="Close">&times;</button>
        </div>
        <div class="booking-content">
            <?php if (!$canReserveTrainer): ?>
                <div class="alert alert-warning mb-0">
                    <strong>Trainer reservation locked.</strong><br>
                    Your current membership is <strong><?= h($membershipInfo) ?></strong>.
                    Only members with a <strong>membership with trainer</strong> can reserve trainer time slots.
                    You can still open and view all available facilities.
                </div>
            <?php endif; ?>

            <form method="post" action="facilities.php" id="bookingForm" autocomplete="off" class="<?= $canReserveTrainer ? '' : 'd-none' ?>">
                <input type="hidden" name="facility_slug" id="facilitySlug">
                <input type="hidden" name="facility_name" id="facilityName">

                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Date</label>
                        <input type="date" name="date" id="bookingDate" class="form-control" value="<?= h($today) ?>" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Trainer</label>
                        <select name="trainer_id" id="trainerSelect" class="custom-select" required>
                            <option value="">Choose trainer</option>
                        </select>
                        <div class="hint" id="trainerHint">Choose a time to show available trainers.</div>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Time slot</label>
                        <select name="time" id="timeSelect" class="custom-select" required>
                            <option value="">Choose time</option>
                        </select>
                        <div class="hint" id="timeHint">Choose a trainer to show only that trainer's slots.</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Full name</label>
                        <input type="text" name="full_name" class="form-control" value="<?= h($sessionName) ?>" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" value="<?= h($sessionEmail) ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Notes optional</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Anything the trainer should know?"></textarea>
                </div>

                <button type="submit" class="btn btn-red btn-block">Apply / Book Now - Pending Trainer Approval</button>
                <p class="hint mb-0 mt-2">After applying, your request stays pending until the selected trainer approves it.</p>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
    const API = 'ajax_trainer_availability.php';
    const ALL_TRAINERS = <?= json_encode($trainers, JSON_UNESCAPED_SLASHES) ?>;
    const ALL_SLOTS = <?= json_encode($timeSlots, JSON_UNESCAPED_SLASHES) ?>;
    const SLOT_LABELS = <?= json_encode($slotLabels, JSON_UNESCAPED_SLASHES) ?>;
    const CAN_RESERVE_TRAINER = <?= $canReserveTrainer ? 'true' : 'false' ?>;

    const shade = document.getElementById('bookingShade');
    const closeBtn = document.getElementById('closeBooking');
    const form = document.getElementById('bookingForm');
    const facilitySlug = document.getElementById('facilitySlug');
    const facilityName = document.getElementById('facilityName');
    const modalTitle = document.getElementById('modalFacilityTitle');
    const modalSub = document.getElementById('modalFacilitySub');
    const dateEl = document.getElementById('bookingDate');
    const trainerEl = document.getElementById('trainerSelect');
    const timeEl = document.getElementById('timeSelect');
    const trainerHint = document.getElementById('trainerHint');
    const timeHint = document.getElementById('timeHint');

    function esc(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function fillTrainers(list, keepValue = '') {
        const current = keepValue || trainerEl.value;
        trainerEl.innerHTML = '<option value="">Choose trainer</option>';
        (list || []).forEach(trainer => {
            trainerEl.insertAdjacentHTML('beforeend', `<option value="${Number(trainer.id)}">${esc(trainer.name)}</option>`);
        });
        if ([...trainerEl.options].some(option => option.value === String(current))) trainerEl.value = String(current);
    }

    function fillSlots(list, keepValue = '') {
        const current = keepValue || timeEl.value;
        timeEl.innerHTML = '<option value="">Choose time</option>';
        (list || []).forEach(slot => {
            timeEl.insertAdjacentHTML('beforeend', `<option value="${esc(slot)}">${esc(SLOT_LABELS[slot] || slot)}</option>`);
        });
        if ([...timeEl.options].some(option => option.value === current)) timeEl.value = current;
    }

    async function getJSON(url) {
        const response = await fetch(url, {credentials: 'same-origin'});
        return await response.json();
    }

    async function updateSlotsByTrainer() {
        const trainerId = trainerEl.value;
        const date = dateEl.value;
        const slug = facilitySlug.value;

        if (!trainerId || !date || !slug) {
            fillSlots(ALL_SLOTS, timeEl.value);
            timeHint.textContent = 'Choose a trainer to show only that trainer\'s slots.';
            return;
        }

        timeHint.textContent = 'Checking slots...';
        const url = `${API}?action=slots&facility_slug=${encodeURIComponent(slug)}&date=${encodeURIComponent(date)}&trainer_id=${encodeURIComponent(trainerId)}`;
        const data = await getJSON(url);

        if (!data.ok) {
            fillSlots([], '');
            timeHint.textContent = data.error || 'Could not load slots.';
            return;
        }

        fillSlots(data.slots || [], timeEl.value);
        timeHint.textContent = (data.slots || []).length
            ? `${data.slots.length} available slot(s) for this trainer.`
            : 'This trainer has no available slots for this date.';
    }

    async function updateTrainersByTime() {
        const time = timeEl.value;
        const date = dateEl.value;
        const slug = facilitySlug.value;

        if (!time || !date || !slug) {
            fillTrainers(ALL_TRAINERS, trainerEl.value);
            trainerHint.textContent = 'Choose a time to show available trainers.';
            return;
        }

        trainerHint.textContent = 'Checking trainers...';
        const url = `${API}?action=trainers&facility_slug=${encodeURIComponent(slug)}&date=${encodeURIComponent(date)}&time=${encodeURIComponent(time)}`;
        const data = await getJSON(url);

        if (!data.ok) {
            fillTrainers([], '');
            trainerHint.textContent = data.error || 'Could not load trainers.';
            return;
        }

        fillTrainers(data.trainers || [], trainerEl.value);
        trainerHint.textContent = (data.trainers || []).length
            ? `${data.trainers.length} trainer(s) available for this time.`
            : 'No trainers are available for this time.';
    }

    function openBooking(card) {
        const slug = card.dataset.facilitySlug || '';
        const name = card.dataset.facilityName || 'Facility';

        facilitySlug.value = slug;
        facilityName.value = name;
        modalTitle.textContent = name;
        modalSub.textContent = 'Choose trainer and time slot';

        if (CAN_RESERVE_TRAINER) {
            fillTrainers(ALL_TRAINERS, '');
            fillSlots(ALL_SLOTS, '');
            trainerHint.textContent = 'Choose a time to show available trainers.';
            timeHint.textContent = 'Choose a trainer to show only that trainer\'s slots.';
        }

        shade.style.display = 'block';
        shade.setAttribute('aria-hidden', 'false');
    }

    document.querySelectorAll('.js-open-booking').forEach(card => {
        card.addEventListener('click', () => openBooking(card));
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

    trainerEl.addEventListener('change', updateSlotsByTrainer);
    timeEl.addEventListener('change', updateTrainersByTime);
    dateEl.addEventListener('change', async () => {
        if (trainerEl.value) await updateSlotsByTrainer();
        if (timeEl.value) await updateTrainersByTime();
    });

    form.addEventListener('submit', event => {
        if (!trainerEl.value || !timeEl.value) {
            event.preventDefault();
            alert('Please choose an available trainer and time slot first.');
        }
    });
})();
</script>
</body>
</html>
