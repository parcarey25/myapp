<?php
// id_rfid_card.php
// Portrait student-ID style RFID card for RJL Fitness
// Output: PNG image

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/db.php';

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

/* =========================
   BASIC ACCESS
========================= */
$userId = 0;

if (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0) {
    $userId = (int)$_GET['user_id'];
} elseif (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
}

if ($userId <= 0) {
    header('Content-Type: text/plain; charset=UTF-8');
    exit('User not found.');
}

/* =========================
   HELPERS
========================= */
function htext($v): string
{
    return trim((string)$v);
}

function find_font_file(array $paths): ?string
{
    foreach ($paths as $p) {
        if ($p && is_file($p)) {
            return $p;
        }
    }
    return null;
}

function get_font_regular(): ?string
{
    static $font = null;
    if ($font !== null) return $font;

    $font = find_font_file([
        __DIR__ . '/fonts/arial.ttf',
        __DIR__ . '/fonts/Arial.ttf',
        'C:/Windows/Fonts/arial.ttf',
        'C:/Windows/Fonts/calibri.ttf',
        'C:/Windows/Fonts/segoeui.ttf',
    ]);

    return $font;
}

function get_font_bold(): ?string
{
    static $font = null;
    if ($font !== null) return $font;

    $font = find_font_file([
        __DIR__ . '/fonts/arialbd.ttf',
        __DIR__ . '/fonts/Arial Bold.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/calibrib.ttf',
        'C:/Windows/Fonts/segoeuib.ttf',
    ]);

    return $font ?: get_font_regular();
}

function text_box_size(string $font, float $size, string $text): array
{
    $box = imagettfbbox($size, 0, $font, $text);
    $xs = [$box[0], $box[2], $box[4], $box[6]];
    $ys = [$box[1], $box[3], $box[5], $box[7]];
    $minX = min($xs);
    $maxX = max($xs);
    $minY = min($ys);
    $maxY = max($ys);

    return [
        'width'  => (int)($maxX - $minX),
        'height' => (int)($maxY - $minY),
        'minX'   => (int)$minX,
        'maxY'   => (int)$maxY,
    ];
}

function fit_font_size(string $font, string $text, int $maxWidth, int $start = 28, int $min = 10): int
{
    for ($size = $start; $size >= $min; $size--) {
        $box = text_box_size($font, $size, $text);
        if ($box['width'] <= $maxWidth) {
            return $size;
        }
    }
    return $min;
}

function draw_ttf_text($img, int $size, int $x, int $y, int $color, ?string $font, string $text): void
{
    if ($font && function_exists('imagettftext')) {
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    } else {
        imagestring($img, 3, $x, max(0, $y - 14), $text, $color);
    }
}

function draw_ttf_center($img, int $size, int $centerX, int $y, int $color, ?string $font, string $text): void
{
    if ($font && function_exists('imagettftext')) {
        $box = text_box_size($font, $size, $text);
        $x = (int)($centerX - ($box['width'] / 2) - $box['minX']);
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    } else {
        $w = imagefontwidth(3) * strlen($text);
        imagestring($img, 3, max(0, (int)($centerX - $w / 2)), max(0, $y - 14), $text, $color);
    }
}

function draw_rounded_rect($img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);

    imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

function draw_rounded_outline($img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    imageline($img, $x1 + $radius, $y1, $x2 - $radius, $y1, $color);
    imageline($img, $x1 + $radius, $y2, $x2 - $radius, $y2, $color);
    imageline($img, $x1, $y1 + $radius, $x1, $y2 - $radius, $color);
    imageline($img, $x2, $y1 + $radius, $x2, $y2 - $radius, $color);

    imagearc($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color);
    imagearc($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color);
    imagearc($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color);
    imagearc($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color);
}

function load_image_file(string $path)
{
    if (!is_file($path)) {
        return null;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === 'jpg' || $ext === 'jpeg') {
        return @imagecreatefromjpeg($path);
    }
    if ($ext === 'png') {
        return @imagecreatefrompng($path);
    }
    if ($ext === 'gif') {
        return @imagecreatefromgif($path);
    }
    if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($path);
    }

    return null;
}

function draw_image_cover($dest, $src, int $x, int $y, int $w, int $h): void
{
    $sw = imagesx($src);
    $sh = imagesy($src);

    if ($sw <= 0 || $sh <= 0) return;

    $srcRatio = $sw / $sh;
    $dstRatio = $w / $h;

    if ($srcRatio > $dstRatio) {
        $newW = (int)($sh * $dstRatio);
        $newH = $sh;
        $srcX = (int)(($sw - $newW) / 2);
        $srcY = 0;
    } else {
        $newW = $sw;
        $newH = (int)($sw / $dstRatio);
        $srcX = 0;
        $srcY = (int)(($sh - $newH) / 2);
    }

    imagecopyresampled($dest, $src, $x, $y, $srcX, $srcY, $w, $h, $newW, $newH);
}

function first_non_empty(array $row, array $keys, string $fallback = ''): string
{
    foreach ($keys as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
            return trim((string)$row[$k]);
        }
    }
    return $fallback;
}

function safe_rel_path(string $rel): string
{
    $rel = str_replace('\\', '/', trim($rel));
    $rel = ltrim($rel, '/');
    return $rel;
}

function pseudo_qr($img, int $x, int $y, int $size, string $seed, int $fg, int $bg): void
{
    $grid = 25;
    $quiet = 2;
    $cell = (int)floor($size / ($grid + ($quiet * 2)));
    if ($cell < 2) $cell = 2;

    $realSize = ($grid + ($quiet * 2)) * $cell;
    draw_rounded_rect($img, $x, $y, $x + $realSize, $y + $realSize, 8, $bg);

    // background fill
    imagefilledrectangle($img, $x, $y, $x + $realSize, $y + $realSize, $bg);

    $hash = hash('sha256', $seed . '|RJL');
    $bits = '';
    foreach (str_split($hash) as $c) {
        $bits .= str_pad(base_convert($c, 16, 2), 4, '0', STR_PAD_LEFT);
    }
    while (strlen($bits) < ($grid * $grid)) {
        $hash = hash('sha256', $hash . $seed);
        foreach (str_split($hash) as $c) {
            $bits .= str_pad(base_convert($c, 16, 2), 4, '0', STR_PAD_LEFT);
        }
    }

    // Draw finder patterns
    $drawFinder = function($ox, $oy) use ($img, $x, $y, $cell, $quiet, $fg, $bg) {
        $sx = $x + ($ox + $quiet) * $cell;
        $sy = $y + ($oy + $quiet) * $cell;

        imagefilledrectangle($img, $sx, $sy, $sx + 7*$cell, $sy + 7*$cell, $fg);
        imagefilledrectangle($img, $sx + $cell, $sy + $cell, $sx + 6*$cell, $sy + 6*$cell, $bg);
        imagefilledrectangle($img, $sx + 2*$cell, $sy + 2*$cell, $sx + 5*$cell, $sy + 5*$cell, $fg);
    };

    $drawFinder(0, 0);
    $drawFinder($grid - 7, 0);
    $drawFinder(0, $grid - 7);

    $i = 0;
    for ($r = 0; $r < $grid; $r++) {
        for ($c = 0; $c < $grid; $c++) {
            // skip finder pattern zones
            $inTopLeft = ($r < 7 && $c < 7);
            $inTopRight = ($r < 7 && $c >= $grid - 7);
            $inBottomLeft = ($r >= $grid - 7 && $c < 7);

            if ($inTopLeft || $inTopRight || $inBottomLeft) {
                continue;
            }

            $bit = $bits[$i] ?? '0';
            $i++;

            if ($bit === '1') {
                $sx = $x + ($c + $quiet) * $cell;
                $sy = $y + ($r + $quiet) * $cell;
                imagefilledrectangle($img, $sx, $sy, $sx + $cell - 1, $sy + $cell - 1, $fg);
            }
        }
    }

    imagerectangle($img, $x, $y, $x + $realSize, $y + $realSize, $fg);
}

/* =========================
   LOAD USER
========================= */
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
if (!$stmt) {
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Database error.');
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
if ($res) $res->free();
$stmt->close();

if (!$user) {
    header('Content-Type: text/plain; charset=UTF-8');
    exit('User not found.');
}

/* =========================
   USER DATA
========================= */
$fullName = first_non_empty($user, ['full_name', 'name'], first_non_empty($user, ['username'], 'MEMBER'));
$username = first_non_empty($user, ['username'], '');
$idNumber = first_non_empty($user, ['id_number'], 'NO-ID');
$role     = strtoupper(first_non_empty($user, ['role'], 'MEMBER'));
$email    = first_non_empty($user, ['email'], '');
$phone    = first_non_empty($user, ['contact_number', 'phone'], '');
$address  = first_non_empty($user, ['address'], 'N/A');
$rfidUid  = first_non_empty($user, ['rfid_uid', 'rfid', 'rfid_number', 'card_uid'], 'NOT SET');

$avatarRel = first_non_empty($user, ['avatar_path', 'avatar', 'profile_pic', 'photo'], '');
$avatarPath = '';
if ($avatarRel !== '') {
    $avatarPath = __DIR__ . '/' . safe_rel_path($avatarRel);
}
if ($avatarPath === '' || !is_file($avatarPath)) {
    $avatarPath = __DIR__ . '/photo/logo.jpg';
}
$logoPath = __DIR__ . '/photo/logo.jpg';
if (!is_file($logoPath)) {
    $logoPath = $avatarPath;
}

/* =========================
   CANVAS
========================= */
$W = 820;
$H = 1320;

$img = imagecreatetruecolor($W, $H);
imageantialias($img, true);

// Colors
$bg           = imagecolorallocate($img, 228, 228, 228);
$white        = imagecolorallocate($img, 255, 255, 255);
$black        = imagecolorallocate($img, 18, 18, 18);
$softBlack    = imagecolorallocate($img, 45, 45, 45);
$red          = imagecolorallocate($img, 206, 24, 30);
$darkRed      = imagecolorallocate($img, 145, 0, 0);
$lightRed     = imagecolorallocate($img, 255, 236, 236);
$gray         = imagecolorallocate($img, 130, 130, 130);
$midGray      = imagecolorallocate($img, 190, 190, 190);
$line         = imagecolorallocate($img, 214, 214, 214);
$light        = imagecolorallocate($img, 246, 246, 246);

imagefilledrectangle($img, 0, 0, $W, $H, $bg);

// shadow
$shadow = imagecolorallocatealpha($img, 0, 0, 0, 110);
draw_rounded_rect($img, 62, 42, 758, 1226, 40, $shadow);

// card
$cx1 = 48;
$cy1 = 28;
$cx2 = 744;
$cy2 = 1212;

draw_rounded_rect($img, $cx1, $cy1, $cx2, $cy2, 40, $white);
draw_rounded_outline($img, $cx1, $cy1, $cx2, $cy2, 40, $midGray);

// slot hole
draw_rounded_rect($img, 330, 62, 462, 100, 18, $bg);
draw_rounded_outline($img, 330, 62, 462, 100, 18, $midGray);

/* =========================
   HEADER
========================= */
draw_rounded_rect($img, $cx1, $cy1, $cx2, 305, 40, $black);
imagefilledrectangle($img, $cx1, 260, $cx2, 305, $black);

// diagonal accent
$poly = [
    $cx1, 392,
    $cx1, 260,
    305, 260,
    228, 305
];
imagefilledpolygon($img, $poly, 4, $black);

$poly2 = [
    $cx1, 412,
    $cx1, 344,
    318, 230,
    372, 230
];
imagefilledpolygon($img, $poly2, 4, $midGray);

$poly3 = [
    225, 305,
    372, 305,
    710, 305,
    710, 260,
    265, 260
];
imagefilledpolygon($img, $poly3, 5, $red);

// logo circle
imagefilledellipse($img, 168, 170, 190, 190, $red);
imagefilledellipse($img, 168, 170, 176, 176, $black);

$logo = load_image_file($logoPath);
if ($logo) {
    draw_image_cover($img, $logo, 102, 104, 132, 132);
    imagedestroy($logo);
}

// brand
$fontRegular = get_font_regular();
$fontBold = get_font_bold();

draw_ttf_text($img, 42, 300, 165, $red, $fontBold, 'RJL');
draw_ttf_text($img, 42, 405, 165, $white, $fontBold, 'FITNESS');
draw_ttf_text($img, 21, 302, 210, $white, $fontRegular, 'STRONGER EVERYDAY');

draw_ttf_text($img, 25, 420, 295, $white, $fontBold, 'RFID MEMBER ID');

/* =========================
   SUBTLE PATTERN
========================= */
$patternColor = imagecolorallocate($img, 236, 236, 236);
for ($row = 0; $row < 4; $row++) {
    for ($col = 0; $col < 4; $col++) {
        $hx = 575 + ($col * 44) + (($row % 2) * 22);
        $hy = 360 + ($row * 38);
        $r = 22;
        $pts = [];
        for ($k = 0; $k < 6; $k++) {
            $angle = deg2rad(60 * $k - 30);
            $pts[] = (int)round($hx + $r * cos($angle));
            $pts[] = (int)round($hy + $r * sin($angle));
        }
        imagepolygon($img, $pts, 6, $patternColor);
    }
}

/* =========================
   PHOTO
========================= */
$photoX = 245;
$photoY = 338;
$photoW = 330;
$photoH = 385;

draw_rounded_rect($img, $photoX - 4, $photoY - 4, $photoX + $photoW + 4, $photoY + $photoH + 4, 18, $red);
draw_rounded_rect($img, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, 16, $white);

$avatar = load_image_file($avatarPath);
if ($avatar) {
    draw_image_cover($img, $avatar, $photoX + 8, $photoY + 8, $photoW - 16, $photoH - 16);
    imagedestroy($avatar);
} else {
    imagefilledrectangle($img, $photoX + 8, $photoY + 8, $photoX + $photoW - 8, $photoY + $photoH - 8, $light);
    draw_ttf_center($img, 18, $photoX + (int)($photoW / 2), $photoY + 200, $gray, $fontBold, 'NO PHOTO');
}

/* =========================
   INFO ROWS
========================= */
$labelX = 164;
$valueX = 357;
$sepX   = 318;
$rowStart = 790;
$rowH = 82;

$drawInfoRow = function($y, $label, $value, $iconType = 'circle') use ($img, $labelX, $valueX, $sepX, $cx1, $cx2, $line, $gray, $black, $red, $fontRegular, $fontBold) {
    imageline($img, 116, $y + 58, $cx2 - 42, $y + 58, $line);

    // icon area
    if ($iconType === 'circle') {
        imagearc($img, 145, $y + 20, 36, 36, 0, 360, $red);
        imagefilledellipse($img, 145, $y + 12, 10, 10, $red);
        imagearc($img, 145, $y + 28, 20, 18, 200, 340, $red);
    } elseif ($iconType === 'id') {
        imagerectangle($img, 124, $y + 2, 166, $y + 36, $red);
        imagefilledrectangle($img, 127, $y + 5, 139, $y + 17, $red);
        imageline($img, 145, $y + 10, 160, $y + 10, $red);
        imageline($img, 145, $y + 18, 160, $y + 18, $red);
        imageline($img, 145, $y + 26, 158, $y + 26, $red);
        imagearc($img, 133, $y + 27, 14, 14, 0, 360, $red);
    } elseif ($iconType === 'shield') {
        $pts = [145, $y + 2, 162, $y + 9, 159, $y + 30, 145, $y + 39, 131, $y + 30, 128, $y + 9];
        imagepolygon($img, $pts, 6, $red);
        imageline($img, 145, $y + 10, 145, $y + 28, $red);
        imageline($img, 136, $y + 19, 154, $y + 19, $red);
    } elseif ($iconType === 'rfid') {
        imagearc($img, 145, $y + 20, 8, 8, 0, 360, $red);
        imagearc($img, 145, $y + 20, 24, 24, 300, 60, $red);
        imagearc($img, 145, $y + 20, 24, 24, 120, 240, $red);
        imagearc($img, 145, $y + 20, 40, 40, 300, 60, $red);
        imagearc($img, 145, $y + 20, 40, 40, 120, 240, $red);
    } elseif ($iconType === 'pin') {
        imagearc($img, 145, $y + 13, 22, 22, 0, 360, $red);
        imagefilledellipse($img, 145, $y + 13, 6, 6, $red);
        imageline($img, 145, $y + 24, 136, $y + 40, $red);
        imageline($img, 145, $y + 24, 154, $y + 40, $red);
    }

    draw_ttf_text($img, 15, $labelX, $y + 24, $softGray = $gray, $fontRegular, strtoupper($label));
    imageline($img, $sepX, $y + 2, $sepX, $y + 42, $line);

    $fontSize = fit_font_size($fontBold, $value, 320, 24, 14);
    draw_ttf_text($img, $fontSize, $valueX, $y + 24, $black, $fontBold, $value);
};

$drawInfoRow($rowStart, 'Full Name', strtoupper($fullName), 'circle');
$drawInfoRow($rowStart + $rowH, 'ID Number', strtoupper($idNumber), 'id');
$drawInfoRow($rowStart + ($rowH * 2), 'Role', strtoupper($role), 'shield');
$drawInfoRow($rowStart + ($rowH * 3), 'RFID UID', strtoupper($rfidUid), 'rfid');

// address row
$y = $rowStart + ($rowH * 4);
imageline($img, 116, $y + 88, $cx2 - 42, $y + 88, $line);

// pin icon
imagearc($img, 145, $y + 18, 22, 22, 0, 360, $red);
imagefilledellipse($img, 145, $y + 18, 6, 6, $red);
imageline($img, 145, $y + 29, 136, $y + 45, $red);
imageline($img, 145, $y + 29, 154, $y + 45, $red);

draw_ttf_text($img, 15, $labelX, $y + 24, $gray, $fontRegular, 'ADDRESS');
imageline($img, $sepX, $y + 2, $sepX, $y + 72, $line);

$address = strtoupper($address);
$address = preg_replace('/\s+/', ' ', $address);
$addrFont = fit_font_size($fontBold, $address, 320, 17, 12);
$wrapped = wordwrap($address, 22, "\n", true);
$lines = explode("\n", $wrapped);
$lines = array_slice($lines, 0, 3);
$yy = $y + 20;
foreach ($lines as $lineTxt) {
    draw_ttf_text($img, $addrFont, $valueX, $yy, $black, $fontBold, $lineTxt);
    $yy += 26;
}

/* =========================
   SIGNATURE + QR
========================= */
$sigBoxX = 116;
$sigBoxY = 1105;
$sigBoxW = 300;

imageline($img, $sigBoxX, $sigBoxY, $sigBoxX + $sigBoxW, $sigBoxY, $black);
draw_ttf_text($img, 18, 160, 1070, $black, $fontRegular, '/s/');
$nameSigSize = fit_font_size($fontRegular, $fullName, 220, 26, 14);
draw_ttf_text($img, $nameSigSize, 172, 1060, $black, $fontRegular, $fullName);
draw_ttf_center($img, 15, $sigBoxX + (int)($sigBoxW / 2), 1140, $softBlack, $fontRegular, 'MEMBER SIGNATURE');

$qrSeed = $idNumber . '|' . $fullName . '|' . $rfidUid;
pseudo_qr($img, 555, 980, 150, $qrSeed, $black, $white);
draw_ttf_center($img, 12, 630, 1150, $gray, $fontRegular, 'MEMBER CODE');

/* =========================
   FOOTER
========================= */
draw_rounded_rect($img, $cx1, 1142, $cx2, $cy2, 0, $black);
$footerPoly = [
    $cx1, 1142,
    245, 1142,
    390, 1172,
    $cx1, 1172
];
imagefilledpolygon($img, $footerPoly, 4, $red);

draw_ttf_text($img, 16, 82, 1194, $white, $fontRegular, 'TRAIN HARD. STAY STRONG.');
draw_ttf_text($img, 15, 520, 1194, $white, $fontRegular, 'www.rjlfitness.com');

/* =========================
   OUTPUT
========================= */
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

imagepng($img);
imagedestroy($img);
exit;