<<<<<<< HEAD
<?php
// test.php — PHPMailer tester (no LOGO_PATH usage)

error_reporting(E_ALL);
ini_set('display_errors','1');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* === CONFIG: CHANGE THESE === */
const SMTP_HOST = 'smtp.gmail.com';
const SMTP_USER = 'parcareyleon25@gmail.com';   // your Gmail
const SMTP_PASS = 'nlhpzoixvrbibhes';     // Gmail App Password
const FROM_EMAIL = 'parcareyleon25@gmail.com';  // should match SMTP_USER
const FROM_NAME  = 'RJL Fitness';

// Public HTTPS URL to your logo (must open in incognito)
const LOGO_URL = 'https://YOUR-DOMAIN.com/photo/logo.jpg';

/* === Load PHPMailer (Composer or src) === */
if (is_file(__DIR__.'/vendor/autoload.php')) {
  require __DIR__.'/vendor/autoload.php';
} else {
  require __DIR__.'/phpmailer/src/Exception.php';
  require __DIR__.'/phpmailer/src/PHPMailer.php';
  require __DIR__.'/phpmailer/src/SMTP.php';
}

/* === Who to send to (edit this or make a tiny form) === */
$toEmail = 'parcareyleon25@gmail.com';   // <-- change to your real address
$toName  = 'Test User';

$mail = new PHPMailer(true);

try {
  // Debug (optional)
  // $mail->SMTPDebug = 2;
  // $mail->Debugoutput = function($s,$l){ error_log("SMTP[$l] $s"); };

  // SMTP
  $mail->isSMTP();
  $mail->Host       = SMTP_HOST;
  $mail->SMTPAuth   = true;
  $mail->Username   = SMTP_USER;
  $mail->Password   = SMTP_PASS;
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // or ENCRYPTION_SMTPS + 465
  $mail->Port       = 587;
  $mail->CharSet    = 'UTF-8';

  // From / To
  $mail->setFrom(FROM_EMAIL, FROM_NAME);
  $mail->clearAllRecipients();
  $mail->addAddress($toEmail, $toName);

  // Logo via public URL (no LOGO_PATH here)
  $logoHtml = '<img src="'.LOGO_URL.'" alt="RJL Fitness" width="80" height="80" style="display:block;margin:0 auto 12px;border-radius:8px;">';

  // Content
  $mail->isHTML(true);
  $mail->Subject = 'Welcome to RJL Fitness';

$logoHtml = '<img src="https://YOUR-DOMAIN.com/photo/logo.jpg" alt="RJL Fitness" width="80" height="80" style="display:block;margin:0 auto 12px;border-radius:8px;">';

$mail->Body = '
  <div style="background:#0b0b0b;color:#f1f1f1;padding:24px;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:560px;margin:0 auto;background:#141414;border:1px solid #262626;border-radius:12px;padding:24px;text-align:center;">
      '.$logoHtml.'
      <h2 style="color:#ff6b6b;margin:0 0 8px;font-weight:700;">Welcome to RJL Fitness!</h2>
      <p style="margin:0 0 12px;font-size:15px;color:#e5e5e5;">You’ve successfully logged in.</p>
      <hr style="border:none;border-top:1px solid #2a2a2a;margin:16px 0;">
      <p style="margin:0;font-size:13px;color:#aaaaaa;">© RJL Fitness • Stay fit. Stay strong.</p>
    </div>
  </div>';

$mail->AltBody = "Welcome to RJL Fitness\nYou’ve successfully logged in.\n";

  $mail->send();
  echo '✅ Sent. Check your inbox.';
} catch (Exception $e) {
  echo '❌ Error: ' . ($mail->ErrorInfo ?: $e->getMessage());

}