<?php
// staff_id_cards.php
// Staff/Admin page: create/view/download RFID ID cards for members, staff, and trainers.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = strtolower($_SESSION['role'] ?? '');

if (!in_array($role, ['staff', 'admin'], true)) {
    header('Location: home.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function has_column(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;

    if ($result) {
        $result->free();
    }

    $stmt->close();

    return $exists;
}

function generate_role_id_number(mysqli $conn, string $role): string
{
    $role = strtolower($role);

    if ($role === 'staff') {
        $prefix = 'S-0225-';
    } elseif ($role === 'trainer') {
        $prefix = 'T-0225-';
    } else {
        $prefix = 'M-0225-';
    }

    $lastSuffix = 0;

    $stmt = $conn->prepare("
        SELECT id_number
        FROM users
        WHERE id_number LIKE CONCAT(?, '%')
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('s', $prefix);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result && ($row = $result->fetch_assoc())) {
            $existing = $row['id_number'] ?? '';
            $suffixPart = substr($existing, strlen($prefix));

            if ($suffixPart !== '' && ctype_digit($suffixPart)) {
                $lastSuffix = (int)$suffixPart;
            }
        }

        if ($result) {
            $result->free();
        }

        $stmt->close();
    }

    $next = $lastSuffix + 1;

    while (true) {
        $candidate = $prefix . str_pad((string)$next, 2, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare("SELECT id FROM users WHERE id_number = ? LIMIT 1");

        if (!$stmt) {
            return $candidate;
        }

        $stmt->bind_param('s', $candidate);
        $stmt->execute();

        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;

        if ($result) {
            $result->free();
        }

        $stmt->close();

        if (!$exists) {
            return $candidate;
        }

        $next++;
    }
}

$flash = '';
$flashType = 'info';

$hasIdNumber = has_column($conn, 'users', 'id_number');
$hasRfidUid  = has_column($conn, 'users', 'rfid_uid');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'generate_id_number' && $targetUserId > 0) {
        if (!$hasIdNumber) {
            $flash = 'users table has no id_number column.';
            $flashType = 'danger';
        } else {
            $stmt = $conn->prepare("SELECT role, id_number FROM users WHERE id = ? LIMIT 1");

            if ($stmt) {
                $stmt->bind_param('i', $targetUserId);
                $stmt->execute();

                $result = $stmt->get_result();
                $targetUser = $result ? $result->fetch_assoc() : null;

                if ($result) {
                    $result->free();
                }

                $stmt->close();

                if (!$targetUser) {
                    $flash = 'User not found.';
                    $flashType = 'danger';
                } elseif (!empty($targetUser['id_number'])) {
                    $flash = 'This user already has an ID number.';
                    $flashType = 'warning';
                } else {
                    $targetRole = strtolower($targetUser['role'] ?? 'member');
                    $newIdNumber = generate_role_id_number($conn, $targetRole);

                    $stmtUpdate = $conn->prepare("UPDATE users SET id_number = ? WHERE id = ? LIMIT 1");

                    if ($stmtUpdate) {
                        $stmtUpdate->bind_param('si', $newIdNumber, $targetUserId);
                        $ok = $stmtUpdate->execute();
                        $stmtUpdate->close();

                        $flash = $ok
                            ? "ID number created successfully: {$newIdNumber}"
                            : 'Failed to create ID number.';

                        $flashType = $ok ? 'success' : 'danger';
                    } else {
                        $flash = 'Database prepare failed while updating ID number.';
                        $flashType = 'danger';
                    }
                }
            } else {
                $flash = 'Database prepare failed while reading user.';
                $flashType = 'danger';
            }
        }
    }

    if ($action === 'update_rfid' && $targetUserId > 0) {
        $rfidUid = trim($_POST['rfid_uid'] ?? '');

        if (!$hasRfidUid) {
            $flash = 'users table has no rfid_uid column.';
            $flashType = 'danger';
        } elseif ($rfidUid === '') {
            $flash = 'RFID UID cannot be empty.';
            $flashType = 'danger';
        } else {
            $stmtCheck = $conn->prepare("SELECT id FROM users WHERE rfid_uid = ? AND id <> ? LIMIT 1");

            if ($stmtCheck) {
                $stmtCheck->bind_param('si', $rfidUid, $targetUserId);
                $stmtCheck->execute();

                $result = $stmtCheck->get_result();
                $exists = $result && $result->num_rows > 0;

                if ($result) {
                    $result->free();
                }

                $stmtCheck->close();

                if ($exists) {
                    $flash = 'This RFID UID is already assigned to another user.';
                    $flashType = 'danger';
                } else {
                    $stmtUpdate = $conn->prepare("UPDATE users SET rfid_uid = ? WHERE id = ? LIMIT 1");

                    if ($stmtUpdate) {
                        $stmtUpdate->bind_param('si', $rfidUid, $targetUserId);
                        $ok = $stmtUpdate->execute();
                        $stmtUpdate->close();

                        $flash = $ok ? 'RFID UID updated successfully.' : 'Failed to update RFID UID.';
                        $flashType = $ok ? 'success' : 'danger';
                    } else {
                        $flash = 'Database prepare failed while updating RFID.';
                        $flashType = 'danger';
                    }
                }
            }
        }
    }
}

$search = trim($_GET['q'] ?? '');
$filterRole = strtolower(trim($_GET['role'] ?? 'all'));

$allowedRoles = ['all', 'member', 'staff', 'trainer'];

if (!in_array($filterRole, $allowedRoles, true)) {
    $filterRole = 'all';
}

$users = [];

$sql = "
    SELECT
        id,
        username,
        full_name,
        email,
        phone,
        role,
        status,
        id_number,
        rfid_uid,
        avatar_path
    FROM users
    WHERE LOWER(role) IN ('member', 'staff', 'trainer')
";

$params = [];
$types = '';

if ($filterRole !== 'all') {
    $sql .= " AND LOWER(role) = ?";
    $params[] = $filterRole;
    $types .= 's';
}

if ($search !== '') {
    $sql .= " AND (
        full_name LIKE ?
        OR username LIKE ?
        OR email LIKE ?
        OR id_number LIKE ?
        OR rfid_uid LIKE ?
    )";

    $like = '%' . $search . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    $types .= 'sssss';
}

$sql .= "
    ORDER BY
        FIELD(LOWER(role), 'member', 'trainer', 'staff'),
        full_name ASC,
        username ASC
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    if ($params) {
        $refs = [];
        $refs[] = &$types;

        foreach ($params as $k => &$v) {
            $refs[] = &$v;
        }

        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    $stmt->execute();

    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        $result->free();
    }

    $stmt->close();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Create ID Cards | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
    :root {
        --bg: #101010;
        --panel: #171717;
        --line: #2a2a2a;
        --brand: #b30000;
        --muted: #a9a9a9;
        --green: #28a745;
        --yellow: #ffc107;
    }

    body {
        background: var(--bg);
        color: #fff;
        font-family: 'Poppins', sans-serif;
    }

    .navbar {
        background: linear-gradient(90deg, #000, var(--brand));
    }

    a,
    a:hover {
        color: #fff;
    }

    .btn-danger {
        background: var(--brand);
        border: none;
    }

    .btn-danger:hover {
        background: #ff1a1a;
    }

    .page-card {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 8px 24px rgba(0,0,0,.25);
    }

    .form-control,
    .custom-select {
        background: #121212;
        border: 1px solid #2a2a2a;
        color: #eee;
    }

    .form-control:focus,
    .custom-select:focus {
        background: #121212;
        color: #fff;
        border-color: var(--brand);
        box-shadow: 0 0 0 0.2rem rgba(179,0,0,.25);
    }

    .muted {
        color: var(--muted);
    }

    .user-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
        gap: 16px;
    }

    .user-card {
        background: #151515;
        border: 1px solid #292929;
        border-radius: 14px;
        padding: 16px;
        box-shadow: 0 8px 24px rgba(0,0,0,.22);
    }

    .user-top {
        display: flex;
        gap: 14px;
        align-items: center;
        margin-bottom: 14px;
    }

    .avatar {
        width: 76px;
        height: 76px;
        border-radius: 16px;
        object-fit: cover;
        background: #222;
        border: 2px solid rgba(255,255,255,.15);
    }

    .name {
        font-weight: 800;
        font-size: 1.05rem;
        line-height: 1.2;
    }

    .badge-role {
        display: inline-flex;
        padding: 4px 9px;
        border-radius: 999px;
        background: rgba(179,0,0,.18);
        border: 1px solid rgba(179,0,0,.55);
        color: #ffb3b3;
        font-size: .72rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .06em;
    }

    .info-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        border-top: 1px solid rgba(255,255,255,.08);
        padding: 8px 0;
        font-size: .88rem;
    }

    .info-row span:first-child {
        color: var(--muted);
    }

    .info-row span:last-child {
        text-align: right;
        font-weight: 700;
    }

    .action-wrap {
        display: grid;
        gap: 8px;
        margin-top: 12px;
    }

    .rfid-form {
        display: flex;
        gap: 8px;
    }

    .rfid-form input {
        flex: 1;
    }

    .empty-box {
        background: #171717;
        border: 1px dashed rgba(255,255,255,.18);
        border-radius: 14px;
        padding: 30px;
        text-align: center;
        color: var(--muted);
    }

    @media(max-width: 576px) {
        .rfid-form {
            display: block;
        }

        .rfid-form button {
            margin-top: 8px;
            width: 100%;
        }
    }
</style>
</head>

<body>

<nav class="navbar navbar-dark">
    <a class="navbar-brand ml-3" href="home_staff.php">
        <img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness Staff
    </a>

    <div class="ml-auto mr-3">
        <a class="btn btn-outline-light btn-sm" href="home_staff.php">Dashboard</a>
        <a class="btn btn-outline-light btn-sm" href="all_attendance.php">Attendance</a>
        <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
    </div>
</nav>

<div class="container py-4">

    <div class="page-card mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h3 class="mb-1">Create RFID ID Cards</h3>
                <div class="muted">
                    Generate and view portrait ID cards for members, trainers, and staff.
                </div>
            </div>

            <a href="id_rfid_card.php" target="_blank" class="btn btn-danger btn-sm mt-3 mt-md-0">
                Preview My ID
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flashType) ?>">
            <?= h($flash) ?>
        </div>
    <?php endif; ?>

    <div class="page-card mb-4">
        <form method="get">
            <div class="form-row">
                <div class="col-md-5 mb-2">
                    <label class="muted">Search</label>
                    <input
                        type="text"
                        name="q"
                        class="form-control"
                        placeholder="Search name, username, email, ID number, RFID..."
                        value="<?= h($search) ?>"
                    >
                </div>

                <div class="col-md-3 mb-2">
                    <label class="muted">Role</label>
                    <select name="role" class="custom-select">
                        <option value="all" <?= $filterRole === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="member" <?= $filterRole === 'member' ? 'selected' : '' ?>>Member</option>
                        <option value="trainer" <?= $filterRole === 'trainer' ? 'selected' : '' ?>>Trainer</option>
                        <option value="staff" <?= $filterRole === 'staff' ? 'selected' : '' ?>>Staff</option>
                    </select>
                </div>

                <div class="col-md-4 mb-2 d-flex align-items-end">
                    <button class="btn btn-danger mr-2" type="submit">Search</button>
                    <a href="staff_id_cards.php" class="btn btn-outline-light">Clear</a>
                </div>
            </div>
        </form>
    </div>

    <?php if (!$users): ?>
        <div class="empty-box">
            No users found.
        </div>
    <?php else: ?>
        <div class="user-grid">
            <?php foreach ($users as $u): ?>
                <?php
                    $displayName = trim($u['full_name'] ?? '') !== ''
                        ? $u['full_name']
                        : ($u['username'] ?? 'User');

                    $avatarPath = trim($u['avatar_path'] ?? '');

                    if ($avatarPath === '' || !is_file(__DIR__ . '/' . $avatarPath)) {
                        $avatarPath = 'photo/logo.jpg';
                    }

                    $idCardUrl = 'id_rfid_card.php?user_id=' . (int)$u['id'];
                    $downloadUrl = 'id_rfid_card.php?user_id=' . (int)$u['id'] . '&download=1';
                ?>

                <div class="user-card">
                    <div class="user-top">
                        <img src="<?= h($avatarPath) ?>" class="avatar" alt="">

                        <div>
                            <div class="name"><?= h($displayName) ?></div>
                            <div class="muted">@<?= h($u['username'] ?? 'user') ?></div>
                            <div class="mt-1">
                                <span class="badge-role"><?= h($u['role'] ?? '') ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="info-row">
                        <span>Status</span>
                        <span><?= h(strtoupper($u['status'] ?? '')) ?></span>
                    </div>

                    <div class="info-row">
                        <span>ID Number</span>
                        <span><?= h($u['id_number'] ?: 'Not set') ?></span>
                    </div>

                    <div class="info-row">
                        <span>RFID UID</span>
                        <span><?= h($u['rfid_uid'] ?: 'Not set') ?></span>
                    </div>

                    <div class="info-row">
                        <span>Email</span>
                        <span><?= h($u['email'] ?: '—') ?></span>
                    </div>

                    <div class="action-wrap">

                        <?php if (empty($u['id_number'])): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="generate_id_number">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">

                                <button class="btn btn-warning btn-sm btn-block" type="submit">
                                    Generate ID Number
                                </button>
                            </form>
                        <?php endif; ?>

                        <form method="post" class="rfid-form">
                            <input type="hidden" name="action" value="update_rfid">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">

                            <input
                                type="text"
                                name="rfid_uid"
                                class="form-control form-control-sm"
                                placeholder="Enter RFID UID"
                                value="<?= h($u['rfid_uid'] ?? '') ?>"
                            >

                            <button class="btn btn-outline-light btn-sm" type="submit">
                                Save RFID
                            </button>
                        </form>

                        <a href="<?= h($idCardUrl) ?>" target="_blank" class="btn btn-danger btn-sm btn-block">
                            View ID Card
                        </a>

                        <a href="print_id_card.php?user_id=<?= (int)$u['id'] ?>" target="_blank" class="btn btn-outline-light btn-sm btn-block">
                            Print ID
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

</body>
</html>