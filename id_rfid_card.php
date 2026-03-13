<?php
// id_rfid_card.php
// Clean RFID ID card (red / white) using user info + 2x2 photo + logo

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/db.php';

// Do NOT show warnings/notices inside image output
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// Need logged-in user or explicit ?user_id=...
if (!isset($_SESSION['user_id']) && empty($_GET['user_id'])) {
    http_response_code(403);
    exit;
}

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)$_SESSION['user_id'];

// Fetch user record
$st = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$st->bind_param('i', $userId);
$st->execute();
$res  = $st->get_result();
$user = $res ? $res->fetch_assoc() : null;
if ($res) $res->free();
$st->close();

if (!$user) {
    http_response_code(404);
    exit;
}

// ---------- user fields ----------
$fullName = trim($user['full_name'] ?? $user['username'] ?? 'Member');
$idNumber = trim($user['id_number'] ?? '');
$role     = trim($user['role'] ?? 'member');
$email    = trim($user['email'] ?? '');
$phone    = trim($user['contact_number'] ?? ($user['phone'] ?? ''));
$address  = trim($user['address'] ?? '');

// avatar / 2x2 photo column guessing
$avatarRel = null;
foreach (['avatar_path', 'avatar', 'profile_pic', 'photo'] as $col) {
    if (!empty($user[$col])) {
        $avatarRel = $user[$col];
        break;
    }
}

$avatarFs = $avatarRel ? __DIR__ . '/' . $avatarRel : __DIR__ . '/photo/logo.jpg';
if (!is_file($avatarFs)) {
    $avatarFs = __DIR__ . '/photo/logo.jpg';
}

// ---------- create base image ----------
$width  = 1016;  // ~3.4 x 2.1 inch @ 300 DPI
$height = 638;

$img = imagecreatetruecolor($width, $height);

// Colors (red theme, clean white card)
$white      = imagecolorallocate($img, 255, 255, 255);
$black      = imagecolorallocate($img, 10, 10, 10);
$red        = imagecolorallocate($img, 196, 35, 35);
$darkRed    = imagecolorallocate($img, 150, 20, 20);
$lightGray  = imagecolorallocate($img, 235, 235, 235);
$midGray    = imagecolorallocate($img, 150, 150, 150);
$borderGray = imagecolorallocate($img, 200, 200, 200);

// white background
imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $white);

// outer rounded-ish border (simple rectangle)
imagerectangle($img, 8, 8, $width - 9, $height - 9, $borderGray);

// header bar
$headerH = 130;
imagefilledrectangle($img, 8, 8, $width - 9, 8 + $headerH, $red);

// subtle darker strip at bottom of header
imagefilledrectangle($img, 8, 8 + $headerH - 20, $width - 9, 8 + $headerH, $darkRed);

// ---------- logo (centered in header) ----------
$logoPath = __DIR__ . '/photo/logo.jpg';
if (!is_file($logoPath)) {
    $logoPath = $avatarFs; // fallback
}

$logoImg = null;
$ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
if ($ext === 'jpg' || $ext === 'jpeg')      $logoImg = @imagecreatefromjpeg($logoPath);
elseif ($ext === 'png')                     $logoImg = @imagecreatefrompng($logoPath);
elseif ($ext === 'gif')                     $logoImg = @imagecreatefromgif($logoPath);

if ($logoImg) {
    $lw = imagesx($logoImg);
    $lh = imagesy($logoImg);
    $target = 90;
    $scale  = min($target / $lw, $target / $lh);
    $nw     = (int)($lw * $scale);
    $nh     = (int)($lh * $scale);

    $cx = (int)($width / 2);
    $cy = 8 + (int)($headerH / 2);
    $dx = $cx - (int)($nw / 2);
    $dy = $cy - (int)($nh / 2);

    imagecopyresampled($img, $logoImg, $dx, $dy, 0, 0, $nw, $nh, $lw, $lh);
    imagedestroy($logoImg);
}

// title under logo
$title = 'RJL FITNESS';
$subtitle = 'RFID MEMBER IDENTIFICATION CARD';
imagestring($img, 5, (int)($width/2 - strlen($title)*5/2), 8 + $headerH - 40, $title, $white);
imagestring($img, 2, (int)($width/2 - strlen($subtitle)*4/2), 8 + $headerH - 22, $subtitle, $lightGray);

// ---------- photo panel ----------
$photoX = 40;
$photoY = 8 + $headerH + 40;
$photoW = 260;
$photoH = 320;

// background panel behind photo
imagefilledrectangle($img, $photoX - 4, $photoY - 4, $photoX + $photoW + 4, $photoY + $photoH + 4, $lightGray);
imagefilledrectangle($img, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, $white);
imagerectangle($img, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, $borderGray);

// load avatar
$src = null;
$ext = strtolower(pathinfo($avatarFs, PATHINFO_EXTENSION));
if ($ext === 'jpg' || $ext === 'jpeg')      $src = @imagecreatefromjpeg($avatarFs);
elseif ($ext === 'png')                     $src = @imagecreatefrompng($avatarFs);
elseif ($ext === 'gif')                     $src = @imagecreatefromgif($avatarFs);

if ($src) {
    $sw = imagesx($src);
    $sh = imagesy($src);
    $scale = min($photoW / $sw, $photoH / $sh);
    $nw = (int)($sw * $scale);
    $nh = (int)($sh * $scale);
    $dx = $photoX + (int)(($photoW - $nw) / 2);
    $dy = $photoY + (int)(($photoH - $nh) / 2);
    imagecopyresampled($img, $src, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh);
    imagedestroy($src);
} else {
    imagestring($img, 3, $photoX + 70, $photoY + (int)($photoH/2) - 7, 'NO PHOTO', $midGray);
}

// label under photo
imagestring($img, 3, $photoX, $photoY + $photoH + 10, '2x2 PROFILE PHOTO', $midGray);

// small red strip under photo panel
imagefilledrectangle(
    $img,
    $photoX - 4,
    $photoY + $photoH + 32,
    $photoX + $photoW + 4,
    $photoY + $photoH + 40,
    $red
);

// ---------- info block (right side) ----------
$infoX  = 360;
$infoY  = 8 + $headerH + 40;
$lineH  = 36;

// divider line
imageline($img, $infoX, $infoY - 20, $width - 40, $infoY - 20, $borderGray);

// NAME (big)
$upperName = strtoupper($fullName);
imagestring($img, 5, $infoX, $infoY, $upperName, $black);
$infoY += $lineH;

// ID + ROLE
imagestring($img, 3, $infoX, $infoY, 'Member ID:', $midGray);
imagestring($img, 4, $infoX + 130, $infoY - 2, $idNumber, $darkRed);
$infoY += $lineH;

imagestring($img, 3, $infoX, $infoY, 'Role:', $midGray);
imagestring($img, 4, $infoX + 130, $infoY - 2, strtoupper($role), $black);
$infoY += $lineH + 6;

// EMAIL
imagestring($img, 3, $infoX, $infoY, 'Email:', $midGray);
$emailLines = explode("\n", wordwrap($email, 30, "\n"));
foreach ($emailLines as $line) {
    imagestring($img, 3, $infoX + 130, $infoY - 2, $line, $black);
    $infoY += 22;
}
$infoY += 4;

// PHONE
imagestring($img, 3, $infoX, $infoY, 'Phone:', $midGray);
imagestring($img, 3, $infoX + 130, $infoY - 2, $phone, $black);
$infoY += $lineH;

// ADDRESS
imagestring($img, 3, $infoX, $infoY, 'Address:', $midGray);
$addrLines = explode("\n", wordwrap($address, 35, "\n"));
foreach ($addrLines as $line) {
    imagestring($img, 3, $infoX + 130, $infoY - 2, $line, $black);
    $infoY += 22;
}

// ---------- footer bar ----------
$footerTop = $height - 80;
imagefilledrectangle($img, 8, $footerTop, $width - 9, $height - 9, $red);

$footerText1 = 'This card is property of RJL Fitness and is non-transferable.';
$footerText2 = 'If found, please return to RJL Fitness front desk.';

imagestring($img, 3,
    (int)($width/2 - strlen($footerText1)*4/2),
    $footerTop + 10,
    $footerText1,
    $white
);
imagestring($img, 2,
    (int)($width/2 - strlen($footerText2)*4/2),
    $footerTop + 32,
    $footerText2,
    $white
);

// ---------- output ----------
header('Content-Type: image/png');
imagepng($img);
imagedestroy($img);
exit;
