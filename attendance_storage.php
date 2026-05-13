<?php
// attendance_storage.php
// Real-folder style attendance storage.
// Root view: Year folders
// Year view: Month folders
// Month view: Word-style attendance .doc files

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

function safe_year($year): string
{
    $year = (string)$year;
    return preg_match('/^\d{4}$/', $year) ? $year : '';
}

function safe_month($month, array $monthNames): string
{
    $month = (string)$month;
    return in_array($month, $monthNames, true) ? $month : '';
}

function folder_size_label($bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }

    return number_format($bytes / 1024, 2) . ' KB';
}

function create_year_with_months(string $baseDir, string $year, array $monthNames): bool
{
    if (!preg_match('/^\d{4}$/', $year)) {
        return false;
    }

    $yearDir = $baseDir . '/' . $year;

    if (!is_dir($yearDir)) {
        if (!mkdir($yearDir, 0777, true)) {
            return false;
        }
    }

    foreach ($monthNames as $monthName) {
        $monthDir = $yearDir . '/' . $monthName;

        if (!is_dir($monthDir)) {
            if (!mkdir($monthDir, 0777, true)) {
                return false;
            }
        }
    }

    return true;
}

function get_existing_years(string $baseDir): array
{
    $years = [];

    foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $yearDir) {
        $yearName = basename($yearDir);

        if (preg_match('/^\d{4}$/', $yearName)) {
            $years[] = $yearName;
        }
    }

    sort($years);

    return $years;
}

$baseDir = __DIR__ . '/attendance_storage';
$baseUrl = 'attendance_storage';

$monthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

// Create current year and next year folders with all months
$currentYear = date('Y');
$nextYear = (string)((int)$currentYear + 1);

create_year_with_months($baseDir, $currentYear, $monthNames);
create_year_with_months($baseDir, $nextYear, $monthNames);

// Add Year Folder action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_year_folder') {
    $existingYears = get_existing_years($baseDir);

    if ($existingYears) {
        $latestYear = (int)max($existingYears);
        $newYear = (string)($latestYear + 1);
    } else {
        $newYear = $currentYear;
    }

    $created = create_year_with_months($baseDir, $newYear, $monthNames);

    if ($created) {
        $_SESSION['attendance_archive_flash'] = "Year folder {$newYear} created successfully.";
        $_SESSION['attendance_archive_flash_type'] = 'success';
    } else {
        $_SESSION['attendance_archive_flash'] = "Failed to create year folder {$newYear}.";
        $_SESSION['attendance_archive_flash_type'] = 'danger';
    }

    header('Location: attendance_storage.php');
    exit;
}

$view = $_GET['view'] ?? 'root';
$selectedYear = safe_year($_GET['year'] ?? '');
$selectedMonth = safe_month($_GET['month'] ?? '', $monthNames);

if (!in_array($view, ['root', 'year', 'month'], true)) {
    $view = 'root';
}

if ($view === 'year' && $selectedYear === '') {
    $view = 'root';
}

if ($view === 'month' && ($selectedYear === '' || $selectedMonth === '')) {
    $view = 'root';
}

if ($selectedYear !== '') {
    create_year_with_months($baseDir, $selectedYear, $monthNames);
}

// Get all year folders
$years = [];

foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $yearDir) {
    $yearName = basename($yearDir);

    if (!preg_match('/^\d{4}$/', $yearName)) {
        continue;
    }

    $monthCount = 0;
    $fileCount = 0;

    foreach ($monthNames as $monthName) {
        $monthDir = $yearDir . '/' . $monthName;

        if (is_dir($monthDir)) {
            $monthCount++;

            // Count only .doc files so old CSV files will not confuse you.
            $fileCount += count(glob($monthDir . '/*.doc') ?: []);
        }
    }

    $years[] = [
        'name' => $yearName,
        'month_count' => $monthCount,
        'file_count' => $fileCount,
        'modified' => date('Y-m-d H:i:s', filemtime($yearDir)),
    ];
}

usort($years, function ($a, $b) {
    return strcmp($b['name'], $a['name']);
});

// Get months for selected year
$months = [];

if ($view === 'year' || $view === 'month') {
    $yearDir = $baseDir . '/' . $selectedYear;

    foreach ($monthNames as $monthName) {
        $monthDir = $yearDir . '/' . $monthName;

        if (!is_dir($monthDir)) {
            mkdir($monthDir, 0777, true);
        }

        $files = glob($monthDir . '/*.doc') ?: [];

        $months[] = [
            'name' => $monthName,
            'file_count' => count($files),
            'modified' => date('Y-m-d H:i:s', filemtime($monthDir)),
        ];
    }
}

// Get .doc files for selected month
$files = [];

if ($view === 'month') {
    $monthDir = $baseDir . '/' . $selectedYear . '/' . $selectedMonth;

    if (!is_dir($monthDir)) {
        mkdir($monthDir, 0777, true);
    }

    foreach (glob($monthDir . '/*.doc') ?: [] as $filePath) {
        $fileName = basename($filePath);

        $files[] = [
            'name' => $fileName,
            'url' => $baseUrl . '/'
                . rawurlencode($selectedYear) . '/'
                . rawurlencode($selectedMonth) . '/'
                . rawurlencode($fileName),
            'size' => filesize($filePath),
            'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
        ];
    }

    usort($files, function ($a, $b) {
        return strcmp($b['name'], $a['name']);
    });
}

$flash = $_SESSION['attendance_archive_flash'] ?? '';
$flashType = $_SESSION['attendance_archive_flash_type'] ?? 'info';
$fileUrl = $_SESSION['attendance_archive_file_url'] ?? '';

unset($_SESSION['attendance_archive_flash']);
unset($_SESSION['attendance_archive_flash_type']);
unset($_SESSION['attendance_archive_file_url']);

$currentBack = 'attendance_storage.php';

if ($view === 'year') {
    $currentBack = 'attendance_storage.php?view=year&year=' . urlencode($selectedYear);
} elseif ($view === 'month') {
    $currentBack = 'attendance_storage.php?view=month&year=' . urlencode($selectedYear) . '&month=' . urlencode($selectedMonth);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Attendance Storage | RJL Fitness</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
    :root {
        --bg: #101010;
        --panel: #171717;
        --line: #2a2a2a;
        --brand: #b30000;
        --muted: #a9a9a9;
        --folder: #ffc107;
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
        text-decoration: none;
    }

    .btn-danger {
        background: var(--brand);
        border: none;
    }

    .btn-danger:hover {
        background: #ff1a1a;
    }

    .btn-warning {
        color: #111;
        font-weight: 700;
    }

    .page-card {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 8px 24px rgba(0,0,0,.25);
    }

    .muted {
        color: var(--muted);
    }

    .breadcrumb-custom {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
        color: var(--muted);
        font-size: .95rem;
    }

    .breadcrumb-custom a {
        color: #fff;
        font-weight: 700;
    }

    .folder-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 16px;
    }

    .folder-card {
        display: block;
        background: #151515;
        border: 1px solid #2a2a2a;
        border-radius: 14px;
        padding: 18px;
        min-height: 140px;
        transition: .2s ease;
        box-shadow: 0 8px 24px rgba(0,0,0,.22);
    }

    .folder-card:hover {
        transform: translateY(-3px);
        border-color: rgba(255,193,7,.55);
        box-shadow: 0 14px 34px rgba(0,0,0,.35);
    }

    .folder-icon {
        font-size: 2.4rem;
        line-height: 1;
        color: var(--folder);
        margin-bottom: 12px;
    }

    .folder-title {
        font-size: 1.15rem;
        font-weight: 800;
        margin-bottom: 5px;
    }

    .folder-meta {
        font-size: .88rem;
        color: var(--muted);
        line-height: 1.5;
    }

    .file-table {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 8px 24px rgba(0,0,0,.25);
    }

    .file-row {
        display: grid;
        grid-template-columns: 1fr 130px 180px 170px;
        gap: 12px;
        align-items: center;
        border-bottom: 1px solid rgba(255,255,255,.08);
        padding: 14px 16px;
    }

    .file-row:last-child {
        border-bottom: 0;
    }

    .file-head {
        background: #111;
        color: #ccc;
        font-size: .78rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .08em;
    }

    .empty-box {
        background: var(--panel);
        border: 1px dashed rgba(255,255,255,.18);
        border-radius: 14px;
        padding: 22px;
        color: var(--muted);
        text-align: center;
    }

    .form-control {
        background: #121212;
        border: 1px solid #2a2a2a;
        color: #eee;
    }

    .form-control:focus {
        background: #121212;
        color: #fff;
        border-color: var(--brand);
        box-shadow: 0 0 0 0.2rem rgba(179,0,0,.25);
    }

    .top-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    @media(max-width: 768px) {
        .file-row {
            grid-template-columns: 1fr;
        }

        .file-head {
            display: none;
        }
    }
</style>
</head>

<body>

<nav class="navbar navbar-dark">
    <a class="navbar-brand ml-3" href="home.php">
        <img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness
    </a>

    <div class="ml-auto mr-3">
        <a class="btn btn-outline-light btn-sm" href="all_attendance.php">All Attendance</a>
        <a class="btn btn-danger btn-sm" href="logout.php">Logout</a>
    </div>
</nav>

<div class="container py-4">

    <div class="page-card mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h3 class="mb-1">Attendance Storage</h3>

                <div class="muted">
                    Folder view for saved Word-style attendance files.
                </div>

                <div class="breadcrumb-custom">
                    <a href="attendance_storage.php">Attendance Storage</a>

                    <?php if ($view === 'year' || $view === 'month'): ?>
                        <span>/</span>
                        <a href="attendance_storage.php?view=year&year=<?= urlencode($selectedYear) ?>">
                            <?= h($selectedYear) ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($view === 'month'): ?>
                        <span>/</span>
                        <span><?= h($selectedMonth) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="top-actions mt-3 mt-md-0">
                <form method="post" action="attendance_storage.php" style="margin:0;">
                    <input type="hidden" name="action" value="add_year_folder">

                    <button class="btn btn-warning btn-sm" type="submit">
                        + Add Year Folder
                    </button>
                </form>

                <form method="post" action="attendance_archive_action.php" class="form-inline" style="margin:0;">
                    <input type="hidden" name="back" value="<?= h($currentBack) ?>">

                    <label class="mr-2 muted">Save date:</label>

                    <input
                        type="date"
                        name="date"
                        class="form-control form-control-sm mr-2"
                        value="<?= h(date('Y-m-d')) ?>"
                        required
                    >

                    <button class="btn btn-danger btn-sm" type="submit">
                        Save Attendance File
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flashType) ?>">
            <?= h($flash) ?>

            <?php if ($fileUrl): ?>
                <br>
                <a href="<?= h($fileUrl) ?>" target="_blank">
                    Open saved file
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($view === 'root'): ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Year Folders</h4>
        </div>

        <?php if (!$years): ?>
            <div class="empty-box">No year folders found.</div>
        <?php else: ?>
            <div class="folder-grid">
                <?php foreach ($years as $year): ?>
                    <a
                        class="folder-card"
                        href="attendance_storage.php?view=year&year=<?= urlencode($year['name']) ?>"
                    >
                        <div class="folder-icon">📁</div>
                        <div class="folder-title"><?= h($year['name']) ?></div>
                        <div class="folder-meta">
                            <?= (int)$year['month_count'] ?> month folders<br>
                            <?= (int)$year['file_count'] ?> attendance files
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'year'): ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><?= h($selectedYear) ?> Month Folders</h4>

            <a href="attendance_storage.php" class="btn btn-outline-light btn-sm">
                ← Back
            </a>
        </div>

        <div class="folder-grid">
            <?php foreach ($months as $month): ?>
                <a
                    class="folder-card"
                    href="attendance_storage.php?view=month&year=<?= urlencode($selectedYear) ?>&month=<?= urlencode($month['name']) ?>"
                >
                    <div class="folder-icon">📂</div>
                    <div class="folder-title"><?= h($month['name']) ?></div>
                    <div class="folder-meta">
                        <?= (int)$month['file_count'] ?> attendance files<br>
                        Folder inside <?= h($selectedYear) ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

    <?php elseif ($view === 'month'): ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">
                <?= h($selectedYear) ?> / <?= h($selectedMonth) ?>
            </h4>

            <a
                href="attendance_storage.php?view=year&year=<?= urlencode($selectedYear) ?>"
                class="btn btn-outline-light btn-sm"
            >
                ← Back
            </a>
        </div>

        <?php if (!$files): ?>
            <div class="empty-box">
                This folder is empty. No saved Word-style attendance file yet.
            </div>
        <?php else: ?>
            <div class="file-table">
                <div class="file-row file-head">
                    <div>File Name</div>
                    <div>Size</div>
                    <div>Modified</div>
                    <div>Actions</div>
                </div>

                <?php foreach ($files as $file): ?>
                    <div class="file-row">
                        <div>
                            <strong>📄 <?= h($file['name']) ?></strong>
                        </div>

                        <div class="muted">
                            <?= h(folder_size_label($file['size'])) ?>
                        </div>

                        <div class="muted">
                            <?= h($file['modified']) ?>
                        </div>

                        <div>
                            <a
                                class="btn btn-sm btn-outline-light"
                                href="<?= h($file['url']) ?>"
                                target="_blank"
                            >
                                Open
                            </a>

                            <a
                                class="btn btn-sm btn-danger"
                                href="<?= h($file['url']) ?>"
                                download
                            >
                                Download
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

</body>
</html>