<?php
// admin_site_settings.php — Admin-only: Site settings + logo upload + invite codes + maintenance + SMTP
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$myRole = strtolower($_SESSION['role'] ?? 'member');
if ($myRole !== 'admin') { header('Location: home.php'); exit; }

require __DIR__ . '/db.php';

/* ---------------- Utilities ---------------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function ensure_settings_table(mysqli $conn){
  $conn->query("
    CREATE TABLE IF NOT EXISTS settings (
      `key` VARCHAR(100) NOT NULL PRIMARY KEY,
      `value` TEXT NULL,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

function get_settings(mysqli $conn, array $keys): array {
  if (!$keys) return [];
  $in = implode(',', array_fill(0, count($keys), '?'));
  $types = str_repeat('s', count($keys));
  $sql = "SELECT `key`,`value` FROM settings WHERE `key` IN ($in)";
  $out = [];
  if ($st = $conn->prepare($sql)) {
    $ref=[&$types]; foreach($keys as $k){ $ref[] = &$k; }
    call_user_func_array([$st,'bind_param'],$ref);
    $st->execute();
    $res = $st->get_result();
    if ($res) { while($r=$res->fetch_assoc()){ $out[$r['key']]=$r['value']; } $res->free(); }
    $st->close();
  }
  return $out;
}

function set_setting(mysqli $conn, string $key, ?string $value): bool {
  if ($st=$conn->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")) {
    $st->bind_param('ss', $key, $value);
    $ok = $st->execute();
    $st->close();
    return $ok;
  }
  return false;
}

ensure_settings_table($conn);

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

/* ---------------- Keys we manage ---------------- */
$KEYS = [
  'site_name','site_tagline','contact_email','contact_phone','contact_address',
  'hero_title','hero_subtitle','logo_path','maintenance_mode',
  'staff_invite_code','admin_invite_code',
  'smtp_host','smtp_user','smtp_pass','smtp_port','smtp_secure'
];
$settings = get_settings($conn, $KEYS);

/* ---------------- Handle POST ---------------- */
$errors = [];
$flash  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
    $errors[] = 'Security check failed. Please try again.';
  } else {
    // Basic text settings
    $site_name       = trim($_POST['site_name']       ?? '');
    $site_tagline    = trim($_POST['site_tagline']    ?? '');
    $contact_email   = trim($_POST['contact_email']   ?? '');
    $contact_phone   = trim($_POST['contact_phone']   ?? '');
    $contact_address = trim($_POST['contact_address'] ?? '');
    $hero_title      = trim($_POST['hero_title']      ?? '');
    $hero_subtitle   = trim($_POST['hero_subtitle']   ?? '');
    $staff_code      = trim($_POST['staff_invite_code'] ?? '');
    $admin_code      = trim($_POST['admin_invite_code'] ?? '');
    $maintenance     = isset($_POST['maintenance_mode']) ? '1' : '0';

    // SMTP (optional)
    $smtp_host   = trim($_POST['smtp_host']   ?? '');
    $smtp_user   = trim($_POST['smtp_user']   ?? '');
    $smtp_pass   = trim($_POST['smtp_pass']   ?? ''); // stored as plain text here
    $smtp_port   = trim($_POST['smtp_port']   ?? '');
    $smtp_secure = trim($_POST['smtp_secure'] ?? 'tls'); // tls/ssl/empty

    // Minimal validation
    if ($site_name === '') $errors[] = 'Site name is required.';
    if ($contact_email !== '' && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Contact email is invalid.';
    }
    if ($smtp_port !== '' && !preg_match('~^\d+$~',$smtp_port)) {
      $errors[] = 'SMTP port must be a number.';
    }

    // Handle Logo upload (optional)
    $logo_path = $settings['logo_path'] ?? '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
      if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Logo upload failed.';
      } else {
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg'];
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($_FILES['logo']['tmp_name']) ?: '';
        if (!isset($allowed[$mime])) {
          $errors[] = 'Logo must be JPG/PNG/GIF/WEBP/SVG.';
        } else {
          $size = (int)$_FILES['logo']['size'];
          if ($size > 3*1024*1024) $errors[] = 'Logo is too large (max 3MB).';
        }

        if (!$errors) {
          $dirFs = __DIR__.'/uploads/site';
          $dirWeb= 'uploads/site';
          if (!is_dir($dirFs) && !mkdir($dirFs,0755,true)) {
            $errors[] = 'Cannot create uploads/site folder.';
          } else {
            $ext = $allowed[$mime];
            $name = 'logo_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.'.$ext;
            $dstFs = $dirFs.'/'.$name; $dstWeb = $dirWeb.'/'.$name;

            if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dstFs)) {
              $errors[] = 'Failed to save logo file.';
            } else {
              // Optional: delete old logo if it’s in uploads/site
              if (!empty($logo_path) && strpos($logo_path,'uploads/site/')===0) {
                $old = __DIR__.'/'.$logo_path;
                if (is_file($old)) @unlink($old);
              }
              $logo_path = $dstWeb;
            }
          }
        }
      }
    }

    // Save all keys if ok
    if (!$errors) {
      $toSave = [
        'site_name'         => $site_name,
        'site_tagline'      => $site_tagline,
        'contact_email'     => $contact_email,
        'contact_phone'     => $contact_phone,
        'contact_address'   => $contact_address,
        'hero_title'        => $hero_title,
        'hero_subtitle'     => $hero_subtitle,
        'maintenance_mode'  => $maintenance,
        'staff_invite_code' => $staff_code,
        'admin_invite_code' => $admin_code,
        'logo_path'         => $logo_path,
        'smtp_host'         => $smtp_host,
        'smtp_user'         => $smtp_user,
        'smtp_pass'         => $smtp_pass,
        'smtp_port'         => $smtp_port,
        'smtp_secure'       => $smtp_secure,
      ];
      $okAll = true;
      foreach ($toSave as $k=>$v) {
        if (!set_setting($conn,$k,$v)) $okAll=false;
      }
      if ($okAll) {
        $flash = 'Settings saved.';
        $settings = get_settings($conn, $KEYS); // reload
      } else {
        $errors[] = 'Failed to save some settings.';
      }
    }
  }
}

// Defaults for display
$site_name       = $settings['site_name']       ?? 'RJL Fitness';
$site_tagline    = $settings['site_tagline']    ?? '';
$contact_email   = $settings['contact_email']   ?? '';
$contact_phone   = $settings['contact_phone']   ?? '';
$contact_address = $settings['contact_address'] ?? '';
$hero_title      = $settings['hero_title']      ?? 'Welcome to RJL Fitness';
$hero_subtitle   = $settings['hero_subtitle']   ?? 'Train strong. Live stronger.';
$logo_path       = $settings['logo_path']       ?? 'photo/logo.jpg';
$maintenance     = ($settings['maintenance_mode'] ?? '0') === '1';
$staff_code      = $settings['staff_invite_code'] ?? 'RJL-STAFF-2025';
$admin_code      = $settings['admin_invite_code'] ?? 'RJL-ADMIN-2025';

$smtp_host   = $settings['smtp_host']   ?? '';
$smtp_user   = $settings['smtp_user']   ?? '';
$smtp_pass   = $settings['smtp_pass']   ?? '';
$smtp_port   = $settings['smtp_port']   ?? '';
$smtp_secure = $settings['smtp_secure'] ?? 'tls';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Site Settings | RJL Fitness (Admin)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{--brand:#b30000;--hover:#ff1a1a;--bg:#111;--panel:#1a1a1a;--line:#2a2a2a;--muted:#aaa}
body{background:#111;color:#fff;font-family:'Poppins',sans-serif}
.navbar{background:linear-gradient(90deg,#000,var(--brand))}
.card{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:14px}
.form-control,.custom-select{background:#121212;border:1px solid #2a2a2a;color:#eee}
.small-muted{color:#aaa;font-size:.85rem}
.preview-logo{max-height:60px;display:block}
.btn-danger{background:var(--brand);border:none}.btn-danger:hover{background:var(--hover)}
a,a:hover{color:#fff}
</style>
</head>
<body>
<nav class="navbar navbar-dark px-3">
  <a class="navbar-brand" href="home.php"><img src="<?=h($logo_path)?>" height="30" class="mr-2" alt="">RJL Fitness Admin</a>
  <div class="ml-auto">
    <a class="btn btn-outline-light btn-sm" href="home.php">Admin Dashboard</a>
  </div>
</nav>

<div class="container py-4">
  <div class="card p-3 mb-3">
    <h4 class="mb-3">Site Settings</h4>

    <?php if($flash && !$errors): ?>
      <div class="alert alert-success"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

      <div class="row">
        <div class="col-md-7">
          <div class="form-group">
            <label>Site Name</label>
            <input class="form-control" name="site_name" value="<?= h($site_name) ?>" required>
          </div>
          <div class="form-group">
            <label>Tagline</label>
            <input class="form-control" name="site_tagline" value="<?= h($site_tagline) ?>">
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Contact Email</label>
              <input type="email" class="form-control" name="contact_email" value="<?= h($contact_email) ?>">
            </div>
            <div class="form-group col-md-6">
              <label>Contact Phone</label>
              <input class="form-control" name="contact_phone" value="<?= h($contact_phone) ?>">
            </div>
          </div>

          <div class="form-group">
            <label>Address</label>
            <textarea class="form-control" name="contact_address" rows="2"><?= h($contact_address) ?></textarea>
          </div>

          <div class="form-group">
            <label>Home Hero Title</label>
            <input class="form-control" name="hero_title" value="<?= h($hero_title) ?>">
          </div>
          <div class="form-group">
            <label>Home Hero Subtitle</label>
            <input class="form-control" name="hero_subtitle" value="<?= h($hero_subtitle) ?>">
          </div>
        </div>

        <div class="col-md-5">
          <div class="form-group">
            <label>Site Logo</label>
            <div class="mb-2">
              <img class="preview-logo" src="<?= h($logo_path) ?>" alt="Logo preview">
            </div>
            <input type="file" name="logo" class="form-control-file" accept="image/*">
            <small class="small-muted">JPG/PNG/GIF/WEBP/SVG up to 3MB. Replaces existing logo.</small>
          </div>

          <div class="form-group mt-3">
            <div class="custom-control custom-switch">
              <input type="checkbox" class="custom-control-input" id="mm" name="maintenance_mode" <?= $maintenance ? 'checked':'' ?>>
              <label class="custom-control-label" for="mm">Maintenance mode</label>
            </div>
            <small class="small-muted">You can check this in your public pages to show a maintenance banner.</small>
          </div>

          <div class="form-group">
            <label>Staff Invite Code</label>
            <input class="form-control" name="staff_invite_code" value="<?= h($staff_code) ?>">
          </div>
          <div class="form-group">
            <label>Admin Invite Code</label>
            <input class="form-control" name="admin_invite_code" value="<?= h($admin_code) ?>">
          </div>
        </div>
      </div>

      <hr>
      <h5 class="mb-2">Email / SMTP (optional)</h5>
      <div class="form-row">
        <div class="form-group col-md-4">
          <label>Host</label>
          <input class="form-control" name="smtp_host" value="<?= h($smtp_host) ?>">
        </div>
        <div class="form-group col-md-4">
          <label>Username</label>
          <input class="form-control" name="smtp_user" value="<?= h($smtp_user) ?>">
        </div>
        <div class="form-group col-md-4">
          <label>Password</label>
          <input type="text" class="form-control" name="smtp_pass" value="<?= h($smtp_pass) ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group col-md-3">
          <label>Port</label>
          <input class="form-control" name="smtp_port" value="<?= h($smtp_port) ?>" placeholder="587">
        </div>
        <div class="form-group col-md-3">
          <label>Secure</label>
          <select class="custom-select" name="smtp_secure">
            <option value="">(none)</option>
            <option value="tls" <?= $smtp_secure==='tls'?'selected':''; ?>>TLS</option>
            <option value="ssl" <?= $smtp_secure==='ssl'?'selected':''; ?>>SSL</option>
          </select>
        </div>
      </div>

      <button class="btn btn-danger">Save Settings</button>
    </form>
  </div>
</div>
</body>
</html>