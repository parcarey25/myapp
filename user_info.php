<?php
// user_info.php – clean member profile + valid ID + membership info

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Must be logged in
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

// Simple escape helper
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Get user row
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");

if (!$stmt) {
    die('Database error: ' . h($conn->error));
}

$stmt->bind_param('i', $userId);
$stmt->execute();

$res = $stmt->get_result();
$user = $res->fetch_assoc();

if ($res) {
    $res->free();
}

$stmt->close();

if (!$user) {
    header('Location: logout.php');
    exit;
}

// Safe defaults
$username    = $user['username'] ?? ($_SESSION['username'] ?? 'member');
$fullName    = $user['full_name'] ?? $username;
$email       = $user['email'] ?? 'Not recorded';
$phone       = $user['phone'] ?? 'Not recorded';
$address     = $user['address'] ?? 'Not recorded';
$idNumber    = $user['id_number'] ?? ('#' . $userId);
$status      = strtolower($user['status'] ?? 'pending');
$expiresRaw  = $user['membership_expires_at'] ?? null;
$validIdPath = $user['valid_id_path'] ?? null;

// Avatar / profile picture fallback
$avatarPath = $user['avatar_path']
    ?? ($user['avatar']
    ?? ($user['profile_pic']
    ?? 'photo/logo.jpg'));

// Membership expiry text
$expiryText       = 'No membership expiry set.';
$expiryDateText   = 'Not set';
$statusLabel      = ucfirst($status);
$statusClass      = 'status-pending';
$daysRemaining    = 'N/A';

if ($expiresRaw) {
    $ts = strtotime($expiresRaw);

    if ($ts !== false) {
        $expiryDateText = date('M d, Y', $ts);

        if ($ts >= time()) {
            $days = (int)floor(($ts - time()) / 86400);
            $daysRemaining = $days . ' day(s)';
            $expiryText = "Active until " . date('M d, Y g:ia', $ts);
            $statusLabel = 'Active';
            $statusClass = 'status-active';
        } else {
            $expiryText = "Expired on " . date('M d, Y g:ia', $ts);
            $statusLabel = 'Expired';
            $statusClass = 'status-expired';
            $daysRemaining = 'Expired';
        }
    }
}

// Membership type + sessions
$membershipTypeRaw = strtolower($user['membership_type'] ?? '');
$membershipTypeLabel = 'Not set';
$membershipTypeNote  = 'Membership type will appear here after a plan is purchased.';

switch ($membershipTypeRaw) {
    case 'bodybuilding_without_trainer':
        $membershipTypeLabel = 'Bodybuilding';
        $membershipTypeNote  = 'Gym access only, no personal trainer sessions.';
        break;

    case 'bodybuilding_with_trainer':
        $membershipTypeLabel = 'Bodybuilding with Trainer';
        $membershipTypeNote  = 'Gym access with personal trainer assistance.';
        break;

    case 'zumba':
        $membershipTypeLabel = 'Zumba';
        $membershipTypeNote  = 'Access to Zumba classes.';
        break;

    case 'boxing_without_trainer':
        $membershipTypeLabel = 'Boxing';
        $membershipTypeNote  = 'Access to boxing area without trainer sessions.';
        break;

    case 'boxing_with_trainer':
        $membershipTypeLabel = 'Boxing with Trainer';
        $membershipTypeNote  = 'Includes boxing trainer sessions.';
        break;

    case 'muaythai_without_trainer':
    case 'muay_thai_without_trainer':
        $membershipTypeLabel = 'Muay Thai';
        $membershipTypeNote  = 'Access to Muay Thai area without trainer sessions.';
        break;

    case 'muaythai_with_trainer':
    case 'muay_thai_with_trainer':
        $membershipTypeLabel = 'Muay Thai with Trainer';
        $membershipTypeNote  = 'Includes Muay Thai trainer sessions.';
        break;
}

// Sessions left
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

$showsSessions = in_array($membershipTypeRaw, [
    'boxing_with_trainer',
    'boxing_without_trainer',
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

    <style>
        :root {
            --red: #c4161c;
            --red-light: #ff3b45;
            --red-dark: #8e1015;
            --bg: #050505;
            --panel: #121212;
            --panel-soft: #1b1b1b;
            --border: rgba(255, 255, 255, 0.10);
            --text: #ffffff;
            --muted: #a8a8a8;
            --green: #21c55d;
            --yellow: #f6c343;
            --danger: #ff4d5a;
            --shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(196, 22, 28, 0.20), transparent 34%),
                radial-gradient(circle at bottom right, rgba(196, 22, 28, 0.12), transparent 30%),
                linear-gradient(135deg, #030303, #111111 55%, #050505);
        }

        a {
            text-decoration: none;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            height: 72px;
            padding: 0 26px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(0, 0, 0, 0.78);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(14px);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #fff;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .brand img {
            width: 42px;
            height: 42px;
            object-fit: cover;
            border-radius: 14px;
            border: 1px solid var(--border);
        }

        .topbar-user {
            color: var(--muted);
            font-size: 0.92rem;
        }

        .topbar-user strong {
            color: #fff;
        }

        .page-wrap {
            width: min(1120px, calc(100% - 30px));
            margin: 0 auto;
            padding: 34px 0 50px;
        }

        .hero {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            padding: 30px;
            background:
                linear-gradient(135deg, rgba(196, 22, 28, 0.86), rgba(20, 20, 20, 0.96)),
                linear-gradient(145deg, #171717, #090909);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            margin-bottom: 18px;
        }

        .hero::after {
            content: "";
            position: absolute;
            width: 360px;
            height: 360px;
            right: -120px;
            top: -140px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.10);
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-kicker {
            display: inline-flex;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            font-size: 0.75rem;
            font-weight: 900;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(2rem, 4vw, 3.2rem);
            font-weight: 950;
            letter-spacing: -0.04em;
        }

        .hero p {
            margin: 10px 0 0;
            color: rgba(255, 255, 255, 0.86);
            max-width: 720px;
            line-height: 1.55;
        }

        .profile-layout {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 18px;
            align-items: start;
        }

        .card {
            border-radius: 24px;
            background: linear-gradient(145deg, rgba(255,255,255,0.065), rgba(255,255,255,0.025));
            border: 1px solid var(--border);
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(12px);
        }

        .profile-card {
            padding: 24px;
            text-align: center;
        }

        .avatar {
            width: 132px;
            height: 132px;
            margin: 0 auto 16px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid rgba(255, 255, 255, 0.90);
            box-shadow: 0 0 0 5px rgba(196, 22, 28, 0.25);
            background: #111;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-name {
            font-size: 1.35rem;
            font-weight: 900;
            margin-bottom: 4px;
        }

        .profile-username {
            color: var(--muted);
            margin-bottom: 16px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            font-weight: 900;
            font-size: 0.82rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }

        .status-active {
            color: #d9ffe8;
            background: rgba(33, 197, 93, 0.16);
            border: 1px solid rgba(33, 197, 93, 0.36);
        }

        .status-expired {
            color: #ffe1e4;
            background: rgba(255, 77, 90, 0.16);
            border: 1px solid rgba(255, 77, 90, 0.36);
        }

        .status-pending {
            color: #fff2c8;
            background: rgba(246, 195, 67, 0.16);
            border: 1px solid rgba(246, 195, 67, 0.36);
        }

        .mini-info {
            display: grid;
            gap: 10px;
            text-align: left;
            margin-top: 8px;
        }

        .mini-item {
            padding: 14px;
            border-radius: 16px;
            background: rgba(0, 0, 0, 0.24);
            border: 1px solid var(--border);
        }

        .mini-item small {
            display: block;
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            margin-bottom: 6px;
        }

        .mini-item strong {
            display: block;
            color: #fff;
            line-height: 1.35;
            word-break: break-word;
        }

        .content-grid {
            display: grid;
            gap: 18px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }

        .stat-card {
            padding: 20px;
            border-radius: 22px;
            background: linear-gradient(145deg, #181818, #101010);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: "";
            position: absolute;
            width: 120px;
            height: 120px;
            right: -50px;
            top: -50px;
            border-radius: 50%;
            background: rgba(196, 22, 28, 0.20);
        }

        .stat-card small {
            position: relative;
            z-index: 2;
            display: block;
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            margin-bottom: 8px;
        }

        .stat-card strong {
            position: relative;
            z-index: 2;
            display: block;
            color: #fff;
            font-size: 1.35rem;
            line-height: 1.25;
        }

        .section-card {
            padding: 22px;
        }

        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 16px;
        }

        .section-title h2 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 950;
        }

        .section-title span {
            color: var(--muted);
            font-size: 0.88rem;
        }

        .detail-list {
            display: grid;
            gap: 10px;
        }

        .detail-row {
            display: grid;
            grid-template-columns: 190px 1fr;
            gap: 14px;
            padding: 14px;
            border-radius: 16px;
            background: rgba(0, 0, 0, 0.22);
            border: 1px solid var(--border);
        }

        .detail-label {
            color: var(--muted);
            font-weight: 800;
            font-size: 0.88rem;
        }

        .detail-value {
            color: #fff;
            font-weight: 700;
            text-align: right;
            word-break: break-word;
        }

        .detail-value.muted {
            color: var(--muted);
            font-weight: 500;
        }

        .valid-id-box {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 18px;
            align-items: center;
        }

        .valid-id-thumb {
            width: 100%;
            aspect-ratio: 1.55 / 1;
            border-radius: 18px;
            overflow: hidden;
            background: #080808;
            border: 1px solid var(--border);
        }

        .valid-id-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .valid-empty {
            min-height: 150px;
            border-radius: 18px;
            display: grid;
            place-items: center;
            text-align: center;
            padding: 20px;
            background: rgba(0, 0, 0, 0.25);
            border: 1px dashed rgba(255, 255, 255, 0.20);
            color: var(--muted);
        }

        .valid-id-text h3 {
            margin: 0 0 8px;
            font-size: 1rem;
            font-weight: 900;
        }

        .valid-id-text p {
            margin: 0;
            color: var(--muted);
            line-height: 1.55;
        }

        .actions {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 10px 18px;
            border-radius: 999px;
            border: 1px solid var(--border);
            font-weight: 900;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-size: 0.78rem;
            color: #fff;
            transition: 0.2s ease;
        }

        .btn-red {
            background: linear-gradient(135deg, var(--red), var(--red-light));
            box-shadow: 0 14px 28px rgba(196, 22, 28, 0.30);
            border: 0;
        }

        .btn-dark {
            background: rgba(255, 255, 255, 0.06);
        }

        .btn:hover {
            transform: translateY(-1px);
            color: #fff;
        }

        @media (max-width: 991px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .topbar {
                height: auto;
                padding: 14px 18px;
                align-items: flex-start;
                flex-direction: column;
                gap: 8px;
            }

            .hero {
                padding: 22px;
            }

            .section-title {
                flex-direction: column;
                align-items: flex-start;
            }

            .detail-row {
                grid-template-columns: 1fr;
                gap: 6px;
            }

            .detail-value {
                text-align: left;
            }

            .valid-id-box {
                grid-template-columns: 1fr;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <a href="home.php" class="brand">
        <img src="photo/logo.jpg" alt="RJL Fitness">
        <span>RJL Fitness</span>
    </a>

    <div class="topbar-user">
        Welcome, <strong><?= h($username) ?></strong>
    </div>
</header>

<main class="page-wrap">

    <section class="hero">
        <div class="hero-content">
            <div class="hero-kicker">Member Profile</div>
            <h1>User Information</h1>
            <p>View your account details, membership status, membership type, sessions, and uploaded valid ID.</p>
        </div>
    </section>

    <section class="profile-layout">

        <aside class="card profile-card">
            <div class="avatar">
                <img src="<?= h($avatarPath) ?>" alt="Profile Picture">
            </div>

            <div class="profile-name"><?= h($fullName) ?></div>
            <div class="profile-username">@<?= h($username) ?></div>

            <div class="status-pill <?= h($statusClass) ?>">
                <?= h($statusLabel) ?>
            </div>

            <div class="mini-info">
                <div class="mini-item">
                    <small>ID Number</small>
                    <strong><?= h($idNumber) ?></strong>
                </div>

                <div class="mini-item">
                    <small>Email</small>
                    <strong><?= h($email) ?></strong>
                </div>

                <div class="mini-item">
                    <small>Phone</small>
                    <strong><?= h($phone) ?></strong>
                </div>
            </div>
        </aside>

        <div class="content-grid">

            <div class="stats-grid">
                <div class="stat-card">
                    <small>Membership Type</small>
                    <strong><?= h($membershipTypeLabel) ?></strong>
                </div>

                <div class="stat-card">
                    <small>Expiration Date</small>
                    <strong><?= h($expiryDateText) ?></strong>
                </div>

                <div class="stat-card">
                    <small>Days Remaining</small>
                    <strong><?= h($daysRemaining) ?></strong>
                </div>
            </div>

            <section class="card section-card">
                <div class="section-title">
                    <div>
                        <h2>Membership Details</h2>
                        <span>Your current membership information</span>
                    </div>
                </div>

                <div class="detail-list">
                    <div class="detail-row">
                        <div class="detail-label">Membership Status</div>
                        <div class="detail-value"><?= h($statusLabel) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Membership Type</div>
                        <div class="detail-value"><?= h($membershipTypeLabel) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Type Detail</div>
                        <div class="detail-value muted"><?= h($membershipTypeNote) ?></div>
                    </div>

                    <?php if ($showsSessions): ?>
                        <div class="detail-row">
                            <div class="detail-label">Trainer Sessions Left</div>
                            <div class="detail-value">
                                <?php if ($sessionsLeft !== null): ?>
                                    <?= h($sessionsLeft) ?>
                                <?php else: ?>
                                    <span class="muted">Not recorded</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="detail-row">
                        <div class="detail-label">Expiration</div>
                        <div class="detail-value"><?= h($expiryDateText) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Expiration Detail</div>
                        <div class="detail-value muted"><?= h($expiryText) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Address</div>
                        <div class="detail-value muted"><?= h($address) ?></div>
                    </div>
                </div>
            </section>

            <section class="card section-card">
                <div class="section-title">
                    <div>
                        <h2>Valid ID on File</h2>
                        <span>Uploaded identification document</span>
                    </div>
                </div>

                <?php if ($validIdPath): ?>
                    <div class="valid-id-box">
                        <a href="<?= h($validIdPath) ?>" target="_blank" class="valid-id-thumb">
                            <img src="<?= h($validIdPath) ?>" alt="Valid ID">
                        </a>

                        <div class="valid-id-text">
                            <h3>Valid ID uploaded</h3>
                            <p>
                                This is the latest valid ID saved on your account.
                                Click the image to open it in full size.
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="valid-empty">
                        <div>
                            <strong>No valid ID uploaded yet.</strong>
                            <br>
                            Upload your valid ID from the ID verification page.
                        </div>
                    </div>
                <?php endif; ?>

                <div class="actions">
                    <a href="id_verifications.php" class="btn btn-dark">
                        Upload / Change Valid ID
                    </a>

                    <a href="home.php" class="btn btn-red">
                        Back to Dashboard
                    </a>
                </div>
            </section>

        </div>

    </section>

</main>

</body>
</html>