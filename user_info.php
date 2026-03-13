<?php 
// user_info.php – member profile + valid ID + membership type / sessions info

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

// Must be logged in
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

// Get user row (we select * so it won't break if some columns don't exist)
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res  = $stmt->get_result();
$user = $res->fetch_assoc();
$res->free();
$stmt->close();

// Simple escape helper
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Safe defaults
$username    = $user['username'] ?? ($_SESSION['username'] ?? 'member');
$fullName    = $user['full_name'] ?? $username;
$idNumber    = $user['id_number'] ?? ('#' . $userId);
$status      = strtolower($user['status'] ?? 'pending'); // e.g. active/pending/blocked
$expiresRaw  = $user['membership_expires_at'] ?? null;
$validIdPath = $user['valid_id_path'] ?? null;

// Avatar / profile picture (fallback to logo)
// Try avatar_path (if you added this column), then avatar/profile_pic, then logo
$avatarPath = $user['avatar_path'] 
    ?? ($user['avatar'] 
    ?? ($user['profile_pic'] 
    ?? 'photo/logo.jpg'));

// Membership expiry text
$expiryText        = 'No membership expiry set.';
$expiryDateText    = 'Not set';
$statusBadgeClass  = 'badge-secondary';
$statusLabel       = ucfirst($status);

if ($expiresRaw) {
    $ts = strtotime($expiresRaw);
    if ($ts !== false) {
        $expiryDateText = date('M d, Y', $ts);

        if ($ts >= time()) {
            $days = floor(($ts - time()) / 86400);
            $expiryText = "Active — expires in {$days} day(s) on " . date('M d, Y g:ia', $ts);
            $statusBadgeClass = 'badge-success';
            if ($statusLabel === 'Pending') $statusLabel = 'Active';
        } else {
            $expiryText = "Expired on " . date('M d, Y g:ia', $ts);
            $statusBadgeClass = 'badge-danger';
            $statusLabel = 'Expired';
        }
    }
}

/* ---------------- Membership type + sessions (from extend_membership) ---------------- */

// Raw DB value (may not exist yet; that's fine)
$membershipTypeRaw = strtolower($user['membership_type'] ?? ''); // e.g. boxing_with_trainer
$membershipTypeLabel = 'Not set';
$membershipTypeNote  = 'No membership type recorded yet.';

// Map internal code to human label + note
switch ($membershipTypeRaw) {
    case 'bodybuilding_without_trainer':
        $membershipTypeLabel = 'Bodybuilding (without trainer)';
        $membershipTypeNote  = 'Gym access only, no personal trainer sessions.';
        break;
    case 'bodybuilding_with_trainer':
        $membershipTypeLabel = 'Bodybuilding (with trainer)';
        $membershipTypeNote  = 'Gym access with a personal trainer.';
        break;
    case 'zumba':
        $membershipTypeLabel = 'Zumba';
        $membershipTypeNote  = 'Access to Zumba classes.';
        break;
    case 'boxing_without_trainer':
        $membershipTypeLabel = 'Boxing (without trainer)';
        $membershipTypeNote  = 'Access to boxing area without trainer sessions.';
        break;
    case 'boxing_with_trainer':
        $membershipTypeLabel = 'Boxing (with trainer)';
        $membershipTypeNote  = 'Includes boxing trainer sessions.';
        break;
    case 'muaythai_without_trainer':
    case 'muay_thai_without_trainer':
        $membershipTypeLabel = 'Muay Thai (without trainer)';
        $membershipTypeNote  = 'Access to Muay Thai area without trainer sessions.';
        break;
    case 'muaythai_with_trainer':
    case 'muay_thai_with_trainer':
        $membershipTypeLabel = 'Muay Thai (with trainer)';
        $membershipTypeNote  = 'Includes Muay Thai trainer sessions.';
        break;
    default:
        // Unknown or not set
        $membershipTypeLabel = 'Not set';
        $membershipTypeNote  = 'Membership type will appear here after a plan is purchased.';
        break;
}

// Trainer sessions left (if column exists)
// Try trainer_sessions_remaining first; fallback to sessions_remaining if you named it differently
$sessionsLeft = null;
if (array_key_exists('trainer_sessions_remaining', $user)) {
    if ($user['trainer_sessions_remaining'] !== null && $user['trainer_sessions_remaining'] !== '') {
        $sessionsLeft = (int)$user['trainer_sessions_remaining'];
    }
} elseif (array_key_exists('sessions_remaining', $user)) {
    if ($user['sessions_remaining'] !== null && $user['sessions_remaining'] !== '') {
        $sessionsLeft = (int)$user['sessions_remaining'];
    }
}

// For which membership types should we show sessions?
$showsSessions = in_array($membershipTypeRaw, [
    'boxing_with_trainer',
    'boxing_without_trainer',    // you can keep or remove this if only "with trainer" uses sessions
    'muaythai_with_trainer',
    'muaythai_without_trainer',
    'muay_thai_with_trainer',
    'muay_thai_without_trainer',
], true);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>User Info | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">

<style>
:root{
    --brand-red:#b30000;
    --brand-red-bright:#ff3333;
    --bg:#050505;
    --panel:#111111;
    --panel-border:#2a2a2a;
    --muted:#aaaaaa;
    --text-main:#f5f5f5;
    --radius-lg:18px;
    --shadow-soft:0 18px 35px rgba(0,0,0,0.55);
}

body{
    margin:0;
    min-height:100vh;
    background:radial-gradient(circle at top,#111 0,#000 50%,#000 100%);
    color:var(--text-main);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
}

/* top bar re-use style */
.topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 24px;
    height:64px;
    background:linear-gradient(90deg,#000,var(--brand-red));
    box-shadow:0 10px 25px rgba(0,0,0,0.5);
}
.topbar-left{
    display:flex;
    align-items:center;
}
.brand-logo{
    display:flex;
    align-items:center;
    color:#fff;
    text-decoration:none;
    font-weight:600;
    letter-spacing:.04em;
}
.brand-logo img{
    height:34px;
    width:auto;
    margin-right:10px;
    border-radius:8px;
}
.topbar-right{
    font-size:.9rem;
}

/* main card */
.page-wrap{
    max-width:960px;
    margin:30px auto;
    padding:0 16px 40px;
}

.card-profile{
    background:radial-gradient(circle at top,#222 0,#111 50%,#050505 100%);
    border-radius:var(--radius-lg);
    border:1px solid var(--panel-border);
    box-shadow:var(--shadow-soft);
    padding:24px 22px 26px;
}

.profile-header{
    text-align:center;
    margin-bottom:16px;
}
.profile-avatar{
    height:96px;
    width:96px;
    border-radius:999px;
    border:3px solid #fff;
    overflow:hidden;
    box-shadow:0 0 0 2px rgba(0,0,0,0.4);
    margin:0 auto 10px;
}
.profile-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
}
.profile-name{
    font-size:1.25rem;
    font-weight:600;
}
.profile-id{
    font-size:.9rem;
    color:var(--muted);
}

/* info rows */
.info-row{
    display:flex;
    justify-content:space-between;
    font-size:.9rem;
    padding:6px 0;
    border-bottom:1px solid #1f1f1f;
}
.info-row:last-child{
    border-bottom:none;
}
.info-label{
    color:var(--muted);
}
.info-value{
    font-weight:500;
}

/* valid id block */
.valid-id-box{
    margin-top:18px;
    background:#0b0b0b;
    border-radius:14px;
    padding:14px 14px 16px;
    border:1px solid #252525;
}
.valid-id-title{
    font-size:.9rem;
    text-transform:uppercase;
    letter-spacing:.12em;
    color:var(--muted);
    margin-bottom:8px;
}
.valid-id-preview{
    display:flex;
    align-items:center;
    gap:14px;
}
.valid-id-thumb{
    width:140px;
    max-width:40%;
    border-radius:10px;
    overflow:hidden;
    border:1px solid #333;
}
.valid-id-thumb img{
    width:100%;
    display:block;
    object-fit:cover;
}
.valid-id-text{
    font-size:.85rem;
    color:var(--muted);
}

/* buttons */
.btn-pill-red{
    border-radius:999px;
    border:none;
    padding:8px 18px;
    font-size:.85rem;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.06em;
    background:linear-gradient(120deg,var(--brand-red),var(--brand-red-bright));
    color:#fff;
    box-shadow:0 10px 20px rgba(179,0,0,0.65);
}
.btn-pill-red:hover{
    background:linear-gradient(120deg,#ff4444,#ff7777);
}

.badge-status{
    border-radius:999px;
    padding:3px 10px;
    font-size:.75rem;
}

@media (max-width:575.98px){
    .valid-id-preview{
        flex-direction:column;
        align-items:flex-start;
    }
    .valid-id-thumb{
        max-width:100%;
    }
}
</style>
</head>
<body>

<header class="topbar">
    <div class="topbar-left">
        <a href="home.php" class="brand-logo">
            <img src="photo/logo.jpg" alt="RJL Fitness">
            <span>RJL Fitness</span>
        </a>
    </div>
    <div class="topbar-right">
        <span>Welcome, <strong><?= h($username) ?></strong></span>
    </div>
</header>

<main class="page-wrap">
    <div class="card-profile">
        <div class="profile-header">
            <div class="profile-avatar">
                <img src="<?= h($avatarPath) ?>" alt="Profile">
            </div>
            <div class="profile-name"><?= h($fullName) ?></div>
            <div class="profile-id">ID Number: <?= h($idNumber) ?></div>
        </div>

        <div class="info-row">
            <div class="info-label">Membership Status</div>
            <div class="info-value">
                <span class="badge badge-status <?= h($statusBadgeClass) ?>">
                    <?= h($statusLabel) ?>
                </span>
            </div>
        </div>

        <div class="info-row">
            <div class="info-label">Membership Type</div>
            <div class="info-value" style="text-align:right;max-width:60%;">
                <?= h($membershipTypeLabel) ?>
            </div>
        </div>

        <?php if ($membershipTypeNote): ?>
        <div class="info-row">
            <div class="info-label">Type Detail</div>
            <div class="info-value" style="text-align:right;max-width:60%;">
                <span style="color:var(--muted);font-size:.85rem;">
                    <?= h($membershipTypeNote) ?>
                </span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($showsSessions): ?>
        <div class="info-row">
            <div class="info-label">Trainer Sessions Left</div>
            <div class="info-value">
                <?php if ($sessionsLeft !== null): ?>
                    <?= h($sessionsLeft) ?>
                <?php else: ?>
                    <span style="color:var(--muted);font-size:.85rem;">Not recorded</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="info-row">
            <div class="info-label">Expiration</div>
            <div class="info-value"><?= h($expiryDateText) ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Detail</div>
            <div class="info-value" style="max-width:60%;text-align:right;">
                <span style="color:var(--muted);font-size:.85rem;">
                    <?= h($expiryText) ?>
                </span>
            </div>
        </div>

        <div class="valid-id-box">
            <div class="valid-id-title">Valid ID on File</div>

            <?php if ($validIdPath): ?>
                <div class="valid-id-preview">
                    <div class="valid-id-thumb">
                        <a href="<?= h($validIdPath) ?>" target="_blank">
                            <img src="<?= h($validIdPath) ?>" alt="Valid ID">
                        </a>
                    </div>
                    <div class="valid-id-text">
                        This is the latest valid ID you uploaded.
                        Click the image to open it in full size.
                    </div>
                </div>
            <?php else: ?>
                <div class="valid-id-text">
                    No valid ID image is saved yet.
                    You can upload one from the ID verification page.
                </div>
                <img src="id_rfid_card.php" alt="RFID ID Card" style="max-width:650px;">
            <?php endif; ?>

        </div>
        <!-- RFID ID CARD -->
<div class="mt-4 card bg-dark border-secondary">
  <div class="card-header border-secondary">
    <strong>RFID ID CARD</strong>
  </div>
  <div class="card-body d-flex align-items-center">
    <a href="id_rfid_card.php?user_id=<?= (int)$user['id'] ?>" target="_blank">
      <img src="id_rfid_card.php?user_id=<?= (int)$user['id'] ?>&t=<?= time() ?>"
           alt="RFID ID Card"
           class="img-thumbnail bg-black"
           style="width: 190px; height: auto;">
    </a>
    <p class="mb-0 ml-3 small text-muted">
      This ID card is automatically generated using your 2x2 profile photo, ID number,
      role, email, phone and address. Click the image to open it in a new tab and print.
    </p>
  </div>
</div>


        <div class="mt-3 d-flex justify-content-between flex-wrap" style="gap:10px;">
            <a href="id_verifications.php" class="btn btn-secondary btn-sm">Upload / Change Valid ID</a>
            <a href="home.php" class="btn btn-pill-red btn-sm">Back to Dashboard</a>
        </div>
    </div>
</main>

</body>
</html>