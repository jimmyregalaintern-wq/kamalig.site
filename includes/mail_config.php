<?php
/**
 * Email Configuration for KAMALIG
 *
 * For Gmail:
 * - Create an App Password: https://myaccount.google.com/apppasswords
 * - Set GMAIL_EMAIL and GMAIL_PASSWORD via environment variables (.env / your
 *   host's env config) — never hardcode them here. See OTP_SETUP_GUIDE.md.
 */

// Booleans from getenv() arrive as strings ("false", "0", "" all need to
// resolve to false) — a bare `getenv() ?: true` treats the string "false" as
// truthy and silently re-enables sending, which is the opposite of what a
// dev switching it off expects. filter_var(..., FILTER_VALIDATE_BOOLEAN)
// parses "0"/"false"/"off"/"" as false and everything else sensibly as true.
if (!defined('GMAIL_ENABLED')) {
    $gmailEnabledEnv = getenv('GMAIL_ENABLED');
    define('GMAIL_ENABLED', $gmailEnabledEnv === false ? true : filter_var($gmailEnabledEnv, FILTER_VALIDATE_BOOLEAN));
}

// SECURITY: no hardcoded credential fallback here on purpose. A real Gmail
// App Password was previously committed as the default value for
// GMAIL_PASSWORD in this file. That password must be treated as compromised
// — revoke it now at https://myaccount.google.com/apppasswords and generate
// a fresh one. Set GMAIL_EMAIL and GMAIL_PASSWORD via environment variables
// only. With no env vars set, GMAIL_EMAIL/GMAIL_PASSWORD are left undefined
// on purpose and send_otp_via_phpmailer() below fails closed with a clear
// error_log entry instead of silently pretending to send.
if (!defined('GMAIL_EMAIL')) define('GMAIL_EMAIL', getenv('GMAIL_EMAIL') ?: '');
if (!defined('GMAIL_PASSWORD')) define('GMAIL_PASSWORD', getenv('GMAIL_PASSWORD') ?: '');
// SMTP server. Defaults to Gmail; for a Hostinger mailbox use
// SMTP_HOST=smtp.hostinger.com, SMTP_PORT=465, SMTP_SECURE=ssl and put that
// mailbox's address/password in GMAIL_EMAIL / GMAIL_PASSWORD.
if (!defined('SMTP_HOST')) define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'KAMALIG');

/**
 * OTP Configuration
 */
if (!defined('OTP_LENGTH')) define('OTP_LENGTH', 6);
if (!defined('OTP_EXPIRY_MINUTES')) define('OTP_EXPIRY_MINUTES', 10);
if (!defined('OTP_MAX_ATTEMPTS')) define('OTP_MAX_ATTEMPTS', 5);
// Minimum seconds a user must wait between resend requests — the resend
// button used to have no rate limiting at all (frontend also silently
// failed to trigger it at all — see verify-otp/index.php), which meant an
// applicant could otherwise trigger unlimited emails. resend_otp() enforces
// this server-side; verify-otp/'s countdown UI mirrors it client-side.
if (!defined('OTP_RESEND_COOLDOWN_SECONDS')) define('OTP_RESEND_COOLDOWN_SECONDS', 60);

/**
 * Password reset configuration
 */
if (!defined('PASSWORD_RESET_EXPIRY_MINUTES')) define('PASSWORD_RESET_EXPIRY_MINUTES', 30);
