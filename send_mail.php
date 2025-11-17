<?php
// send_mail.php
// PHPMailer wrapper for RJL Fitness (Gmail SMTP + optional embedded logo)

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ======== LOAD PHPMailer ========
// Option A: Composer (preferred)
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    // Option B: No Composer — use bundled PHPMailer "src" files
    require __DIR__ . '/phpmailer/src/Exception.php';
    require __DIR__ . '/phpmailer/src/PHPMailer.php';
    require __DIR__ . '/phpmailer/src/SMTP.php';
}

// ======== SMTP CONFIG (Gmail) ========
const SMTP_HOST   = 'smtp.gmail.com';
const SMTP_PORT   = 587;
const SMTP_SECURE = PHPMailer::ENCRYPTION_STARTTLS;

// ⚠️ THESE MUST MATCH YOUR GMAIL + APP PASSWORD
const SMTP_USER   = 'parcareyleon25@gmail.com';   // your Gmail
const SMTP_PASS   = 'nlhpzoixvrbibhes';          // your Gmail App Password

// Sender info
const FROM_EMAIL  = 'parcareyleon25@gmail.com';
const FROM_NAME   = 'RJL Fitness';

// Local logo path (optional)
const LOGO_PATH   = __DIR__ . '/photo/logo.jpg';

/**
 * Send "successful login" email to a user.
 *
 * @param string $toEmail Recipient email (from users.email)
 * @param string $toName  Recipient name (full_name or username)
 * @return array ['ok' => bool, 'error' => string|null]
 */
function sendLoginNotification(string $toEmail, string $toName = ''): array
{
    $mail = new PHPMailer(true);

    try {
        // --- SMTP ---
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        // --- From / To ---
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->clearAllRecipients();
        $mail->addAddress($toEmail, $toName);

        // --- Embedded logo (optional) ---
        $logoHtml = '';
        if (is_file(LOGO_PATH)) {
            // embed and use cid:rjl_logo in HTML
            $mail->addEmbeddedImage(LOGO_PATH, 'rjl_logo', basename(LOGO_PATH));
            $logoHtml = '<img src="cid:rjl_logo" alt="RJL Fitness" width="80" height="80" style="display:block;margin:0 auto 12px;border-radius:8px;">';
        }

        // --- Content ---
        $mail->isHTML(true);
        $mail->Subject = 'RJL Fitness Login Notification';

        $loginTime = date('Y-m-d H:i:s');

        $mail->Body = '
        <div style="background:#0b0b0b;color:#f1f1f1;padding:24px;font-family:Arial,Helvetica,sans-serif;">
          <div style="max-width:560px;margin:0 auto;background:#141414;border:1px solid #262626;border-radius:12px;padding:24px;text-align:center;">
            ' . $logoHtml . '
            <h2 style="color:#ff6b6b;margin:0 0 8px;font-weight:700;">Login Successful</h2>
            <p style="margin:0 0 12px;font-size:15px;color:#e5e5e5;">
              Hello '.htmlspecialchars($toName ?: $toEmail).', you have successfully logged in to RJL Fitness on '.$loginTime.'.
            </p>
            <hr style="border:none;border-top:1px solid #2a2a2a;margin:16px 0;">
            <p style="margin:0;font-size:13px;color:#aaaaaa;">If this was not you, please contact the administrator.</p>
            <p style="margin:4px 0 0;font-size:12px;color:#777777;">© RJL Fitness | Stay fit. Stay strong.</p>
          </div>
        </div>';

        $mail->AltBody =
            "Login Successful - RJL Fitness\n\n".
            "Hello ".($toName ?: $toEmail).", you have successfully logged in on {$loginTime}.\n".
            "If this was not you, please contact the administrator.\n";

        $ok = $mail->send();
        return ['ok' => $ok, 'error' => $ok ? null : $mail->ErrorInfo];

    } catch (Exception $e) {
        return ['ok' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
    }
}