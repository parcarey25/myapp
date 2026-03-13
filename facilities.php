<?php  
// facilities.php — Simple booking: date + time slot + trainer + name/email/notes.
// Clicking a card opens its booking panel, same design, red/black theme.

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__.'/db.php';

$role  = strtolower($_SESSION['role'] ?? 'member');
$userId = (int)($_SESSION['user_id'] ?? 0);

/* ---------- Forward POST to schedules.php ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['facility_slug'])) {
    require __DIR__ . '/schedules.php';
    exit;
}
/* -------------------------------------------------- */

// Small helper like you used elsewhere
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

/* ----- Read membership_type + trainer_sessions_remaining (if columns exist) ----- */
$membershipType   = '';
$sessionsLeft     = 0;
$membershipInfo   = 'No membership set';

if ($userId > 0) {
    $hasMemTypeCol = has_column($conn, 'users', 'membership_type');
    $hasSessCol    = has_column($conn, 'users', 'trainer_sessions_remaining');

    if ($hasMemTypeCol || $hasSessCol) {
        $cols = [];
        if ($hasMemTypeCol) $cols[] = 'membership_type';
        if ($hasSessCol)    $cols[] = 'trainer_sessions_remaining';
        $sqlU = "SELECT ".implode(',', $cols)." FROM users WHERE id=? LIMIT 1";

        if ($st = $conn->prepare($sqlU)) {
            $st->bind_param('i', $userId);
            $st->execute();
            if ($res = $st->get_result()) {
                if ($rowU = $res->fetch_assoc()) {
                    if ($hasMemTypeCol) {
                        $membershipType = strtolower(trim($rowU['membership_type'] ?? ''));
                    }
                    if ($hasSessCol) {
                        $sessionsLeft = (int)($rowU['trainer_sessions_remaining'] ?? 0);
                    }
                }
                $res->free();
            }
            $st->close();
        }
    }

    // Friendly label just for display at the top (does not affect logic)
    $labelMap = [
        'bodybuilding_with_trainer'    => 'Bodybuilding (with trainer)',
        'bodybuilding_without_trainer' => 'Bodybuilding (without trainer)',
        'zumba'                        => 'Zumba',
        'boxing_with_trainer'          => 'Boxing (with trainer)',
        'boxing_without_trainer'       => 'Boxing (without trainer)',
        'muaythai_with_trainer'        => 'Muay Thai (with trainer)',
        'muaythai_without_trainer'     => 'Muay Thai (without trainer)',
    ];
    if ($membershipType) {
        $membershipInfo = $labelMap[$membershipType] ?? $membershipType;
        if (in_array($membershipType, ['boxing_with_trainer','muaythai_with_trainer'], true)) {
            $membershipInfo .= " • Sessions left: ".$sessionsLeft;
        }
    }
}

/* ----- Load facilities (respect is_active / visible_to if present) ----- */
$has_is_active  = has_column($conn, 'facilities', 'is_active');
$has_visible_to = has_column($conn, 'facilities', 'visible_to');

$rows = [];
if (in_array($role, ['staff','admin'], true)) {
    $sql = "SELECT id,name,slug," .
           ($has_visible_to ? "visible_to," : "'both' AS visible_to,") .
           "description,image
           FROM facilities" .
           ($has_is_active ? " WHERE is_active=1" : "") .
           " ORDER BY name ASC";
    if ($res = $conn->query($sql)) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
} else {
    if ($has_visible_to) {
        $sql = "SELECT id,name,slug,visible_to,description,image
                FROM facilities
                WHERE " . ($has_is_active ? "is_active=1 AND " : "") . "
                      (LOWER(visible_to)='both' OR LOWER(visible_to)=?)
                ORDER BY name ASC";
        $st = $conn->prepare($sql);
        $st->bind_param('s', $role);
        $st->execute();
        if ($res = $st->get_result()) {
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();
        }
        $st->close();
    } else {
        $sql = "SELECT id,name,slug,'both' AS visible_to,description,image
                FROM facilities " . ($has_is_active ? "WHERE is_active=1 " : "") . "
                ORDER BY name ASC";
        if ($res = $conn->query($sql)) {
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();
        }
    }
}

/* ----- Load trainers for the dropdown ----- */
$trainers = [];
if ($tr = $conn->query("
    SELECT id, full_name, username
    FROM users
    WHERE LOWER(role)='trainer' AND status='active'
    ORDER BY full_name, username
")) {
    while ($r = $tr->fetch_assoc()) {
        $trainers[] = $r;
    }
    $tr->free();
}

// Time slots used for ALL facilities
$TIME_SLOTS = [
    '08:00-10:00',
    '10:00-12:00',
    '12:00-14:00',
    '16:00-18:00',
    '18:00-20:00',
    '20:00-22:00',
];

// Default date (today)
$today = date('Y-m-d');

// Helper to escape
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Facilities</title>
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
  .facility-card-wrap.active{ z-index:2000; } /* above overlay */

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

  .facility-card.locked{
    opacity:.6;
  }
  .facility-card.locked::after{
    content:"Not allowed for your membership";
    position:absolute;
    left:0; right:0; bottom:0;
    padding:4px 8px;
    background:rgba(179,0,0,.9);
    font-size:.8rem;
    text-align:center;
  }

  .schedule{
    background:#141414;border-top:1px dashed #303030;
    max-height:0; overflow:hidden;
    transition:max-height .40s ease, padding .28s ease;
    padding:0 16px;
  }
  .facility-card-wrap.active .schedule{ max-height:900px; padding:22px 22px; }

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

  .form-control, .custom-select{
      background:#121212;border:1px solid #2a2a2a;color:#eee;
  }

  #panelOverlay{
    position:fixed; inset:0; background:rgba(0,0,0,.45);
    z-index: 1500;
  }
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
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Facilities</h3>
    <small class="text-muted">
      Visible to: <?= h($role) ?>
      <?php if ($role === 'member'): ?>
        · Membership: <?= h($membershipInfo) ?>
      <?php endif; ?>
    </small>
  </div>

  <div class="row cards" id="cards">
    <?php if (!$rows): ?>
      <div class="col-12"><div class="alert alert-secondary">No facilities available.</div></div>
    <?php else: foreach ($rows as $f):

      // slug cleanup / fallback
      $rawSlug = trim($f['slug'] ?? '');
      if ($rawSlug === '') {
        $nm = trim($f['name'] ?? '');
        if ($nm !== '') {
          $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $nm));
          $slug = trim($slug, '-');
        } else {
          $slug = 'facility-' . (int)($f['id'] ?? 0);
        }
      } else {
        $slug = $rawSlug;
      }
      $slugKey = strtolower($slug);
      $slugKeyNorm = str_replace('_','-',$slugKey); // normalize muay_thai vs muay-thai

      $vis = isset($f['visible_to'])
        ? ($f['visible_to']==='both' ? 'Member & Trainer' : ucfirst($f['visible_to']))
        : 'Member & Trainer';

      /* ---------- Membership-based access control ---------- */
      $allowed    = true;
      $lockReason = '';

      if ($role === 'member') {
          $isBodybuilding = ($slugKeyNorm === 'bodybuilding');
          $isZumba        = ($slugKeyNorm === 'zumba');
          $isBoxing       = ($slugKeyNorm === 'boxing');
          $isMuayThai     = ($slugKeyNorm === 'muay-thai');

          if ($membershipType === '' || $membershipType === null) {
              $allowed    = false;
              $lockReason = 'You do not have an active membership that can book facilities.';
          } elseif ($membershipType === 'bodybuilding_with_trainer') {
              if (!$isBodybuilding) {
                  $allowed    = false;
                  $lockReason = 'Your membership only allows Bodybuilding facility.';
              }
          } elseif ($membershipType === 'bodybuilding_without_trainer') {
              $allowed    = false;
              $lockReason = 'Your Bodybuilding (without trainer) membership cannot book any facilities.';
          } elseif ($membershipType === 'zumba') {
              if (!$isZumba) {
                  $allowed    = false;
                  $lockReason = 'Your Zumba membership can book the Zumba facility only.';
              }
          } elseif ($membershipType === 'boxing_with_trainer' || $membershipType === 'muaythai_with_trainer') {
              // All 4 facilities visible, but Boxing/MuayThai need sessions
              if (($isBoxing || $isMuayThai) && $sessionsLeft <= 0) {
                  $allowed    = false;
                  $lockReason = 'You have no trainer sessions left for Boxing/Muay Thai.';
              }
          } elseif ($membershipType === 'boxing_without_trainer' || $membershipType === 'muaythai_without_trainer') {
              // As per your request: any *without trainer* membership cannot open facilities
              $allowed    = false;
              $lockReason = 'Your current membership (without trainer) cannot book any facilities.';
          }
      }

      $lockAttrs   = '';
      $cardClasses = 'facility-card';
      if (!$allowed && $role === 'member') {
          $lockAttrs   = ' data-locked="1" data-lock-message="'.h($lockReason).'"';
          $cardClasses .= ' locked';
      }
    ?>
      <div class="facility-col col-md-6 col-lg-4 mb-4" style="overflow:visible">
        <div class="facility-card-wrap" data-slug="<?= h($slug) ?>" data-name="<?= h($f['name']) ?>"<?= $lockAttrs ?>>
          <div class="<?= $cardClasses ?>">
            <!-- Teaser -->
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

            <!-- Expanded booking panel -->
            <div class="schedule">
              <div class="sched-grid">
                <div class="sched-visual"></div>

                <div>
                  <div class="sched-section mb-3">
                    <div class="section-title">Schedule a Session</div>
                    <p class="muted mb-2">
                      Pick a <strong>date</strong>, <strong>time slot</strong> and <strong>trainer</strong>,
                      then fill in your info and book. The request will be
                      <span class="text-warning">PENDING</span> until the trainer approves.
                    </p>
                  </div>

                  <div class="sched-section">
                    <div class="section-title">Booking Details</div>
                    <form class="book-form" action="facilities.php" method="post" autocomplete="off">
                      <input type="hidden" name="facility_slug" value="<?= h($slug) ?>">
                      <input type="hidden" name="facility_name" value="<?= h($f['name']) ?>">

                      <div class="form-row">
                        <div class="form-group col-md-6">
                          <label>Date</label>
                          <input type="date" name="date" class="form-control js-book-date"
                                 value="<?= h($today) ?>" required>
                        </div>
                        <div class="form-group col-md-6">
                          <label>Time slot</label>
                          <select name="time" class="custom-select js-book-time" required>
                            <option value="">Choose time</option>
                            <?php foreach ($TIME_SLOTS as $slot): ?>
                              <option value="<?= h($slot) ?>"><?= h($slot) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <small class="muted d-block mt-1 js-time-hint"></small>
                        </div>
                      </div>

                      <div class="form-group">
                        <label>Trainer</label>
                        <select name="trainer_id" class="custom-select js-book-trainer">
                          <option value="0">(Any available trainer)</option>
                          <?php foreach ($trainers as $t):
                              $label = $t['full_name'] ?: $t['username'];
                          ?>
                            <option value="<?= (int)$t['id'] ?>"><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <small class="muted d-block mt-1 js-trainer-hint"></small>
                      </div>

                      <div class="form-row">
                        <div class="form-group col-md-6">
                          <label>Full name</label>
                          <input name="full_name" class="form-control" required
                                 value="<?= h($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?>">
                        </div>
                      
                      </div>

                      <div class="form-group">
                        <label>Notes (optional)</label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Anything we should know?"></textarea>
                      </div>

                      <button class="btn btn-danger btn-block" type="submit">
                        Book Now (Pending Approval)
                      </button>
                      <small class="muted d-block mt-2">
                        After submit, this POST is forwarded to <code>schedules.php</code>.
                      </small>
                    </form>
                  </div>
                </div>
              </div>

              <div class="d-flex justify-content-end mt-3">
                <a class="btn btn-outline-light btn-sm mr-2" href="facility_view.php?f=<?= urlencode($slug) ?>">Details</a>
                <a class="btn btn-danger btn-sm" href="schedules.php?facility=<?= urlencode($slug) ?>">Full Schedule</a>
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
// Simple open/close logic for the cards
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

    const locked = wrap.dataset.locked === '1';
    if (locked) {
      const msg = wrap.dataset.lockMessage || 'You cannot open this facility with your current membership.';
      alert(msg);
      return;
    }

    const col = wrap.closest('.facility-col');
    closeAllPanels();
    grid.classList.add('has-active');
    wrap.classList.add('active');
    if (col) col.classList.add('expanded');
    if (overlay) overlay.hidden = false;
  });
});

if (overlay) {
  overlay.addEventListener('click', () => {
    closeAllPanels();
  });
}

// Keep clicks inside the schedule/forms from bubbling up and closing
document.addEventListener('click', e => {
  if (e.target.closest('.facility-card-wrap .schedule')) {
    e.stopPropagation();
  }
}, true);
</script>

<!-- ✅ Dynamic trainer/slot availability -->
<script>
(() => {
  const API = 'ajax_trainer_availability.php';
  const ALL_SLOTS = <?php echo json_encode($TIME_SLOTS, JSON_UNESCAPED_SLASHES); ?>;

  function qs(el, sel){ return el.querySelector(sel); }
  function setHint(el, txt){ if (el) el.textContent = txt || ''; }

  async function fetchJSON(url){
    const res = await fetch(url, { credentials: 'same-origin' });
    return await res.json();
  }

  function esc(str){
    return String(str ?? '')
      .replaceAll('&','&amp;').replaceAll('<','&lt;')
      .replaceAll('>','&gt;').replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  function fillTrainerOptions(select, trainers, keepValue){
    if (!select) return;
    const current = keepValue ?? select.value;
    select.innerHTML = '';
    select.insertAdjacentHTML('beforeend', `<option value="0">(Any available trainer)</option>`);
    (trainers || []).forEach(t => {
      select.insertAdjacentHTML('beforeend', `<option value="${t.id}">${esc(t.name)}</option>`);
    });

    const still = Array.from(select.options).some(o => o.value === String(current));
    select.value = still ? String(current) : '0';
  }

  function fillSlotOptions(select, slots, keepValue){
    if (!select) return;
    const current = keepValue ?? select.value;
    select.innerHTML = `<option value="">Choose time</option>`;
    (slots || []).forEach(s => {
      select.insertAdjacentHTML('beforeend', `<option value="${esc(s)}">${esc(s)}</option>`);
    });

    const still = Array.from(select.options).some(o => o.value === String(current));
    select.value = still ? String(current) : '';
  }

  async function updateTrainers(form){
    const dateEl    = qs(form, '.js-book-date');
    const timeEl    = qs(form, '.js-book-time');
    const trainerEl = qs(form, '.js-book-trainer');

    const timeHint    = qs(form, '.js-time-hint');
    const trainerHint = qs(form, '.js-trainer-hint');

    const date = dateEl?.value || '';
    const slot = timeEl?.value || '';

    if (!date || !slot){
      setHint(trainerHint, 'Pick date and time slot to see available trainers.');
      return;
    }

    setHint(trainerHint, 'Checking available trainers...');
    const url = `${API}?action=trainers&date=${encodeURIComponent(date)}&time=${encodeURIComponent(slot)}`;
    const data = await fetchJSON(url);

    if (!data.ok){
      setHint(trainerHint, data.error || 'Error loading trainers.');
      return;
    }

    fillTrainerOptions(trainerEl, data.trainers, trainerEl.value);

    if ((data.trainers || []).length === 0){
      setHint(trainerHint, 'No trainers available for this slot.');
    } else {
      setHint(trainerHint, `${data.trainers.length} trainer(s) available for this slot.`);
    }

    setHint(timeHint, '');
  }

  async function updateSlots(form){
    const dateEl    = qs(form, '.js-book-date');
    const timeEl    = qs(form, '.js-book-time');
    const trainerEl = qs(form, '.js-book-trainer');

    const timeHint    = qs(form, '.js-time-hint');
    const trainerHint = qs(form, '.js-trainer-hint');

    const date = dateEl?.value || '';
    const trainerId = parseInt(trainerEl?.value || '0', 10);

    if (!date){
      setHint(timeHint, 'Pick date to see available slots.');
      return;
    }

    if (!trainerId){
      // Any trainer => restore all slots
      const prev = timeEl.value;
      fillSlotOptions(timeEl, ALL_SLOTS, prev);
      setHint(timeHint, '');
      setHint(trainerHint, 'Pick a time slot to see available trainers.');
      // After restoring slots, if time is selected, update trainers
      if (timeEl.value) await updateTrainers(form);
      return;
    }

    setHint(timeHint, 'Checking available slots for this trainer...');
    const url = `${API}?action=slots&date=${encodeURIComponent(date)}&trainer_id=${trainerId}`;
    const data = await fetchJSON(url);

    if (!data.ok){
      setHint(timeHint, data.error || 'Error loading slots.');
      return;
    }

    const prev = timeEl.value;
    fillSlotOptions(timeEl, data.slots, prev);

    if ((data.slots || []).length === 0){
      setHint(timeHint, 'This trainer has no available slots for this day.');
    } else {
      setHint(timeHint, `${data.slots.length} slot(s) available for this trainer.`);
    }

    // If slot chosen, refresh trainers (optional)
    if (timeEl.value) await updateTrainers(form);
    else setHint(trainerHint, 'Pick a time slot to see trainers available.');
  }

  // attach listeners to every booking form
  document.querySelectorAll('form.book-form').forEach(form => {
    const dateEl    = qs(form, '.js-book-date');
    const timeEl    = qs(form, '.js-book-time');
    const trainerEl = qs(form, '.js-book-trainer');

    const trainerHint = qs(form, '.js-trainer-hint');
    if (trainerHint) trainerHint.textContent = 'Pick a time slot to see trainers available.';

    dateEl?.addEventListener('change', async () => {
      if (parseInt(trainerEl.value || '0', 10) > 0) await updateSlots(form);
      else await updateTrainers(form);
    });

    timeEl?.addEventListener('change', async () => {
      await updateTrainers(form);
    });

    trainerEl?.addEventListener('change', async () => {
      await updateSlots(form);
    });
  });
})();
</script>

</body>
</html>