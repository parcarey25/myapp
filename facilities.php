<?php
// facilities.php — POST-to-self (then forwards POST to schedule.php)
// Uses RADIO BUTTONS for time slots (reliable clicks), overlay to close, no hover rotation.

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__.'/db.php';

/* ---------- Forward POST to schedule.php ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['facility_slug'])) {
  require __DIR__ . '/schedules.php';
  exit;
}
/* -------------------------------------------------- */

$role = strtolower($_SESSION['role'] ?? 'member');

function has_column(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param('ss', $table, $column);
  $st->execute();
  $res = $st->get_result();
  $ok = $res && $res->num_rows > 0;
  if ($res) $res->free();
  $st->close();
  return $ok;
}

$has_is_active  = has_column($conn, 'facilities', 'is_active');
$has_visible_to = has_column($conn, 'facilities', 'visible_to');

$rows = [];
if (in_array($role, ['staff','admin'], true)) {
  $sql = "SELECT id,name,slug," . ($has_visible_to ? "visible_to," : "'both' AS visible_to,") .
         "description,image FROM facilities" . ($has_is_active ? " WHERE is_active=1" : "") .
         " ORDER BY name ASC";
  if ($res = $conn->query($sql)) { $rows = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }
} else {
  if ($has_visible_to) {
    $sql = "SELECT id,name,slug,visible_to,description,image
            FROM facilities
            WHERE " . ($has_is_active ? "is_active=1 AND " : "") . "(LOWER(visible_to)='both' OR LOWER(visible_to)=?)
            ORDER BY name ASC";
    $st = $conn->prepare($sql);
    $st->bind_param('s', $role);
    $st->execute();
    if ($res = $st->get_result()) { $rows = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }
    $st->close();
  } else {
    $sql = "SELECT id,name,slug,'both' AS visible_to,description,image
            FROM facilities " . ($has_is_active ? "WHERE is_active=1 " : "") . "ORDER BY name ASC";
    if ($res = $conn->query($sql)) { $rows = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }
  }
}
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

  .facility-card-wrap.active .facility-card{ transform: scale(1.12); border-radius:14px; }
  .card-main{ display:block; }
  .facility-card-wrap.active .card-main{ display:none; }

  /* expanded panel (40/60) */
  .schedule{
    background:#141414;border-top:1px dashed #303030;
    max-height:0; overflow:hidden;
    transition:max-height .40s ease, padding .28s ease;
    padding:0 16px;
  }
  .facility-card-wrap.active .schedule{ max-height:820px; padding:22px 22px; }

  .sched-grid{ display:grid; grid-template-columns: 40% 60%; gap:16px; }
  @media (max-width: 992px){ .sched-grid{ grid-template-columns: 1fr; } }

  .sched-visual{
    min-height:380px;
    background:#0f0f0f url('photo/man_left.jpg') center/cover no-repeat; /* change path if needed */
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

  /* radio-time list styling */
  .slot-list{ display:flex; flex-wrap:wrap; gap:8px; }
  .slot-item{ position:relative; }
  .slot-radio{ position:absolute; opacity:0; pointer-events:none; }
  .slot-label{
    display:inline-flex; align-items:center; gap:6px;
    padding:6px 10px; border:1px solid #333; border-radius:999px;
    background:#1c1c1c; font-size:.9rem; cursor:pointer; user-select:none;
  }
  .slot-radio:checked + .slot-label{ border-color:#ff4444; background:#2a1a1a; }
  .slot-cap{ color:#9aa0a6; font-size:.8rem; }

  .form-control, .custom-select{ background:#121212;border:1px solid #2a2a2a;color:#eee; }

  /* overlay to close when clicking outside */
  #panelOverlay{
    position:fixed; inset:0; background:rgba(0,0,0,.45);
    z-index: 1500; /* below active card (2000) */
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
    <small class="text-muted">Visible to: <?= htmlspecialchars($role) ?></small>
  </div>

  <div class="row cards" id="cards">
    <?php if (!$rows): ?>
      <div class="col-12"><div class="alert alert-secondary">No facilities available.</div></div>
    <?php else: foreach ($rows as $f):
      // Safe slug fallback
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
      $vis = isset($f['visible_to']) ? ($f['visible_to']==='both' ? 'Member & Trainer' : ucfirst($f['visible_to'])) : 'Member & Trainer';
    ?>
      <div class="facility-col col-md-6 col-lg-4 mb-4" style="overflow:visible">
        <div class="facility-card-wrap" data-slug="<?= htmlspecialchars($slug) ?>" data-name="<?= htmlspecialchars($f['name']) ?>">
          <div class="facility-card">
            <!-- Teaser -->
            <div class="card-main">
              <img class="img-top" src="<?= htmlspecialchars($f['image'] ?: 'photo/logo.jpg') ?>" alt="">
              <div class="p-3">
                <div class="d-flex justify-content-between align-items-center">
                  <h5 class="mb-1"><?= htmlspecialchars($f['name']) ?></h5>
                  <span class="badge badge-rol"><?= $vis ?></span>
                </div>
                <p class="mb-0 text-muted"><?= htmlspecialchars($f['description'] ?: '—') ?></p>
              </div>
            </div>

            <!-- Expanded -->
            <div class="schedule">
              <div class="sched-grid">
                <div class="sched-visual"></div>

                <div>
                  <div class="sched-section mb-3">
                    <div class="section-title">Scheduling</div>
                    <div class="form-row">
                      <div class="form-group col-md-6">
                        <label>Date</label>
                        <input type="date" class="form-control sched-date">
                      </div>
                      <div class="form-group col-md-6">
                        <label>Coach/Room (optional)</label>
                        <select class="custom-select sched-coach">
                          <option value="">Any</option>
                          <option>Coach Jay</option>
                          <option>Coach Ana</option>
                          <option>Kru Petch</option>
                          <option>Floor Trainers</option>
                        </select>
                      </div>
                    </div>
                    <div class="mt-1">
                      <div class="font-weight-bold">Available slots</div>
                      <div class="slots mt-2"><span class="muted">Choose a date to see slots.</span></div>
                    </div>
                  </div>

                  <div class="sched-section">
                    <div class="section-title">Booking</div>
                    <form class="book-form" action="facilities.php" method="post">
                      <input type="hidden" name="facility_slug" class="bf-slug" value="<?= htmlspecialchars($slug) ?>">
                      <input type="hidden" name="facility_name" class="bf-name" value="<?= htmlspecialchars($f['name']) ?>">
                      <input type="hidden" name="date" class="bf-date" value="">
                      <input type="hidden" name="time" class="bf-time" value="">
                      <div class="form-row">
                        <div class="form-group col-md-6">
                          <label>Full name</label>
                          <input name="full_name" class="form-control" required
                                 value="<?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?>">
                        </div>
                        <div class="form-group col-md-6">
                          <label>Email</label>
                          <input type="email" name="email" class="form-control" required
                                 value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>">
                        </div>
                      </div>
                      <div class="form-group">
                        <label>Notes (optional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Anything we should know?"></textarea>
                      </div>
                      <button class="btn btn-danger btn-block book-btn" type="submit" disabled>Book Now</button>
                      <small class="muted d-block mt-2">After submit, this page forwards your POST to schedule.php.</small>
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

<!-- Overlay used to close the expanded panel -->
<div id="panelOverlay" hidden></div>

<script>
// ===== Weekly demo rules =====
const WEEKLY_RULES = {
  boxing:      { 1:['06:00','18:00'], 3:['18:00'], 6:['09:00'] },
  'muay-thai': { 2:['19:00'], 4:['19:00'] },
  zumba:       { 1:['17:30'], 5:['17:30'] },
  bodybuilding:{ 0:['06:00','12:00','18:00'],1:['06:00','12:00','18:00'],2:['06:00','12:00','18:00'],
                 3:['06:00','12:00','18:00'],4:['06:00','12:00','18:00'],5:['06:00','12:00','18:00'],6:['06:00','12:00','18:00'] }
};
const SLOT_CAPACITY = 12;

const grid = document.getElementById('cards');
const overlay = document.getElementById('panelOverlay');

function openPanel(wrap){
  const col = wrap.closest('.facility-col');
  grid.querySelectorAll('.facility-card-wrap.active').forEach(w => w.classList.remove('active'));
  grid.querySelectorAll('.facility-col.expanded').forEach(c => c.classList.remove('expanded'));
  grid.classList.add('has-active');
  wrap.classList.add('active');
  if (col) col.classList.add('expanded');
  if (overlay) overlay.hidden = false;

  const dateInput = wrap.querySelector('.sched-date');
  if (dateInput) {
    const t = new Date();
    const yyyy = t.getFullYear();
    const mm = String(t.getMonth()+1).padStart(2,'0');
    const dd = String(t.getDate()).padStart(2,'0');
    dateInput.value = `${yyyy}-${mm}-${dd}`;
    renderSlotsFor(wrap);
  }
}

function closeAllPanels(){
  grid.classList.remove('has-active');
  grid.querySelectorAll('.facility-card-wrap.active').forEach(w => w.classList.remove('active'));
  grid.querySelectorAll('.facility-col.expanded').forEach(c => c.classList.remove('expanded'));
  if (overlay) overlay.hidden = true;
}

// Open panel (ignore clicks on controls)
grid.querySelectorAll('.facility-card-wrap').forEach(wrap => {
  wrap.addEventListener('click', e => {
    const interactive = e.target.closest('a, form, .schedule, .sched-section, input, select, textarea, button, label');
    if (interactive) return;
    if (wrap.classList.contains('active')) return;
    openPanel(wrap);
  });
});

// Close via overlay only
if (overlay) overlay.addEventListener('click', closeAllPanels);

// Re-render on date/coach change
grid.addEventListener('change', e => {
  if (e.target.classList.contains('sched-date') || e.target.classList.contains('sched-coach')) {
    const wrap = e.target.closest('.facility-card-wrap');
    renderSlotsFor(wrap);
  }
});

// Stop bubbling from interactive controls (keep panel open)
document.addEventListener('click', (e) => {
  if (e.target.closest('.facility-card-wrap .schedule')
      || e.target.closest('.facility-card-wrap form')
      || e.target.closest('.facility-card-wrap .sched-section')
      || e.target.closest('.facility-card-wrap input')
      || e.target.closest('.facility-card-wrap select')
      || e.target.closest('.facility-card-wrap textarea')
      || e.target.closest('.facility-card-wrap button')
      || e.target.closest('.facility-card-wrap label')) {
    e.stopPropagation();
  }
}, true);

// Build *radio* slots for a wrap
function renderSlotsFor(wrap){
  const slug = (wrap.dataset.slug || '').toLowerCase();
  const dateInput = wrap.querySelector('.sched-date');
  const list = wrap.querySelector('.slots');
  const bookBtn = wrap.querySelector('.book-btn');
  const bfDate  = wrap.querySelector('.bf-date');
  const bfTime  = wrap.querySelector('.bf-time');

  if (bfDate) bfDate.value = '';
  if (bfTime) bfTime.value = '';
  if (bookBtn) bookBtn.disabled = true;

  if (!dateInput || !list) return;
  const d = new Date(dateInput.value + 'T00:00:00');
  if (isNaN(d.getTime())) { list.innerHTML = '<span class="muted">Invalid date.</span>'; return; }

  const day  = d.getDay(); // 0..6
  const rule = (typeof WEEKLY_RULES !== 'undefined' ? WEEKLY_RULES[slug] : {}) || {};
  const times= rule[day] || [];

  if (!times.length) { list.innerHTML = '<span class="muted">No sessions on this day. Pick another date.</span>'; return; }

  // radio group name must be unique per card
  const group = `slot-${slug}`;
  let html = '<div class="slot-list">';
  times.forEach((t, i) => {
    const id = `${group}-${i}`;
    const seats = Math.max(0, SLOT_CAPACITY - Math.floor(Math.random()*5));
    html += `
      <div class="slot-item">
        <input class="slot-radio" type="radio" name="${group}" id="${id}" value="${t}">
        <label class="slot-label" for="${id}">
          <span>${t}</span>
          <span class="slot-cap">(${seats} seats)</span>
        </label>
      </div>`;
  });
  html += '</div>';
  list.innerHTML = html;
}

// Enable button + fill hidden fields when a radio is selected
grid.addEventListener('change', function(e){
  if (!e.target.classList.contains('slot-radio')) return;
  const radio  = e.target;
  const wrap   = radio.closest('.facility-card-wrap');
  const dateIn = wrap.querySelector('.sched-date');
  const bfDate = wrap.querySelector('.bf-date');
  const bfTime = wrap.querySelector('.bf-time');
  const bookBtn= wrap.querySelector('.book-btn');

  if (bfDate && dateIn) bfDate.value = dateIn.value;
  if (bfTime) bfTime.value = radio.value || '';
  if (bookBtn) bookBtn.disabled = !(bfDate && bfDate.value && bfTime && bfTime.value);
}, true);

// Submit guard per form
document.querySelectorAll('.facility-card-wrap').forEach(wrap => {
  const form   = wrap.querySelector('form.book-form');
  if (!form) return;

  const bookBtn= wrap.querySelector('.book-btn');
  const bfDate = wrap.querySelector('.bf-date');
  const bfTime = wrap.querySelector('.bf-time');

  form.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && (!bfDate.value || !bfTime.value)) e.preventDefault();
  });

  form.addEventListener('submit', (e) => {
    const slug = (wrap.dataset.slug || '').trim();
    const name = (wrap.dataset.name || '').trim();
    const hSlug = form.querySelector('.bf-slug');
    const hName = form.querySelector('.bf-name');
    if (hSlug && !hSlug.value) hSlug.value = slug;
    if (hName && !hName.value) hName.value = name;

    if (!slug || !name || !bfDate.value || !bfTime.value) {
      e.preventDefault();
      inlineWarn(form, !slug || !name ? 'Missing facility information.' :
                      (!bfDate.value || !bfTime.value ? 'Please choose a date and time.' : 'Please complete the form.'));
      return false;
    }
    return true;
  });
});

function inlineWarn(form, msg){
  let w = form.querySelector('.inline-warn');
  if (!w) {
    w = document.createElement('div');
    w.className = 'inline-warn';
    w.style.color = '#ff9b9b';
    w.style.marginTop = '8px';
    form.appendChild(w);
  }
  w.textContent = '⚠ ' + msg;
}
</script>
</body>
</html>