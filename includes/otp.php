<?php
/**
 * OTP and Email functions for KAMALIG
 */

require_once __DIR__ . '/mail_config.php';

// PHPMailer ships via Composer, not committed to the repo. Load the
// autoloader if `composer install` has been run; if not, class_exists()
// below correctly reports PHPMailer as unavailable instead of throwing a
// fatal "class not found" the first time someone registers.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/**
 * OTP codes are stored HASHED (Fix Guide item 10), never in plaintext. A keyed
 * hash (HMAC-SHA256) is used; set APP_KEY in .env for a dedicated secret, else
 * one is derived from the database credentials.
 */
function otp_secret(): string {
    $key = getenv('APP_KEY');
    if ($key !== false && $key !== '') {
        return (string) $key;
    }
    return hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . '|' . (defined('DB_USER') ? DB_USER : '') . '|' . (defined('DB_PASS') ? DB_PASS : '') . '|kamalig-otp');
}

function otp_hash(string $otp): string {
    return hash_hmac('sha256', $otp, otp_secret());
}

/** Compare a submitted code with the stored HMAC. Plaintext codes are no longer accepted. */
function otp_matches($stored, string $submitted): bool {
    $stored = (string) $stored;
    if ($stored === '' || strlen($stored) !== 64 || !ctype_xdigit($stored)) {
        return false;
    }
    return hash_equals($stored, otp_hash($submitted));
}

/**
 * Generate a random OTP code
 */
function generate_otp_code(int $length = OTP_LENGTH): string {
    return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

/**
 * Create and store OTP for a user
 */
function create_and_send_otp(string $email): bool {
    $otp = generate_otp_code();
    $expiresAt = date('Y-m-d H:i:s', time() + (OTP_EXPIRY_MINUTES * 60));
    
    if (database_available()) {
        $success = query_execute(
            'UPDATE users SET otp_code = ?, otp_expires_at = ?, otp_attempts = 0, otp_last_sent_at = ? WHERE email = ?',
            [otp_hash($otp), $expiresAt, date('Y-m-d H:i:s'), $email]
        );
        
        if ($success) {
            return send_otp_email($email, $otp);
        }
        return false;
    }
    
    // Fallback for session storage
    init_data();
    foreach ($_SESSION['data']['users'] as &$user) {
        if ($user['email'] === $email) {
            $user['otp_code'] = otp_hash($otp);
            $user['otp_expires_at'] = $expiresAt;
            $user['otp_attempts'] = 0;
            $user['otp_last_sent_at'] = date('Y-m-d H:i:s');
            return send_otp_email($email, $otp);
        }
    }
    
    return false;
}

/**
 * Seconds remaining before another OTP may be sent to this email, or 0 if
 * a resend is allowed right now. Backs both the server-side rate limit in
 * resend_otp() and the countdown shown on verify-otp/'s Resend button.
 */
function otp_resend_seconds_remaining(?string $lastSentAt): int {
    if (empty($lastSentAt)) {
        return 0;
    }
    $elapsed = time() - strtotime($lastSentAt);
    $remaining = OTP_RESEND_COOLDOWN_SECONDS - $elapsed;
    return $remaining > 0 ? $remaining : 0;
}

/**
 * Send OTP via email using Gmail SMTP
 */
function send_otp_email(string $email, string $otp): bool {
    if (!GMAIL_ENABLED) {
        // Fix Guide item 10: the code is NEVER written to a log, and signup must
        // not pretend it worked. With email sending off, report failure so the
        // user sees an error instead of waiting for a code that will not come.
        error_log("KAMALIG: OTP email NOT sent to $email because GMAIL_ENABLED=false. Turn email sending on (GMAIL_ENABLED=true plus the mail settings in .env).");
        return false;
    }

    if (GMAIL_EMAIL === '' || GMAIL_PASSWORD === '') {
        error_log("OTP email NOT sent to $email: GMAIL_EMAIL / GMAIL_PASSWORD are not set. " .
            "Set them as environment variables (see OTP_SETUP_GUIDE.md) or set GMAIL_ENABLED=false to log OTPs during development.");
        return false;
    }

    // Use PHPMailer if available
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return send_otp_via_phpmailer($email, $otp);
    }

    // PHPMailer not installed — this used to silently report "sent" and log
    // the OTP, which is why registrations looked like they worked while no
    // email ever went out. Fail honestly instead so the caller shows the
    // user a real error instead of sending them to "check your inbox".
    error_log("OTP email NOT sent to $email: PHPMailer is not installed. Run `composer require phpmailer/phpmailer` " .
        "(a composer.json is included) and `composer install`, or set GMAIL_ENABLED=false to log OTPs during development.");
    return false;
}

/**
 * Send OTP using PHPMailer (preferred method)
 */
function send_otp_via_phpmailer(string $email, string $otp): bool {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        $mail->Username = GMAIL_EMAIL;
        $mail->Password = GMAIL_PASSWORD;
        
        $mail->setFrom(GMAIL_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($email);
        
        $mail->isHTML(true);
        $mail->Subject = 'Your KAMALIG Verification Code';
        $mail->Body = get_otp_email_template($otp);
        $mail->AltBody = "Your OTP is: $otp\nValid for " . OTP_EXPIRY_MINUTES . " minutes.";
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send OTP using PHP mail function with SMTP headers
 */
function send_otp_via_mail(string $email, string $otp): bool {
    // Setup SMTP via php.ini or use mail() with Gmail credentials
    $to = $email;
    $subject = 'Your KAMALIG Verification Code';
    $body = get_otp_email_template($otp);
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . GMAIL_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . GMAIL_EMAIL . "\r\n";
    
    return mail($to, $subject, $body, $headers);
}

/**
 * Get OTP email template HTML
 */
function get_otp_email_template(string $otp): string {
    $expiryMinutes = OTP_EXPIRY_MINUTES;
    $year = date('Y');
    
    return <<<HTML
    <html>
    <head>
        <style>
            body { font-family: 'Inter', Arial, sans-serif; background-color: #f5f5f5; margin: 0; padding: 0; }
            .container { max-width: 500px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background-color: #1f4d2e; color: white; padding: 30px 20px; text-align: center; }
            .content { padding: 30px 20px; }
            .otp-box { background-color: #f0f0f0; border: 2px solid #1f4d2e; border-radius: 8px; padding: 20px; text-align: center; margin: 20px 0; }
            .otp-code { font-size: 32px; font-weight: bold; color: #1f4d2e; letter-spacing: 5px; font-family: 'Courier New', monospace; }
            .footer { background-color: #f9f9f9; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #eee; }
            .warning { color: #d32f2f; font-size: 14px; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>KAMALIG</h1>
                <p>Rice Supply Transparency Platform</p>
            </div>
            <div class="content">
                <p>Hello,</p>
                <p>Your One-Time Password (OTP) for email verification is:</p>
                <div class="otp-box">
                    <div class="otp-code">$otp</div>
                </div>
                <p>This code is valid for <strong>$expiryMinutes minutes</strong>.</p>
                <p>If you didn't request this code, you can safely ignore this email.</p>
                <p class="warning"><strong>Never share this code with anyone.</strong></p>
            </div>
            <div class="footer">
                <p>&copy; {$year} KAMALIG. All rights reserved.</p>
                <p>Rice Supply Transparency Platform</p>
            </div>
        </div>
    </body>
    </html>
    HTML;
}

/**
 * Verify OTP code for a user
 */
function verify_otp(string $email, string $otp): array {
    if (database_available()) {
        $user = query_one('SELECT * FROM users WHERE email = ?', [$email]);
        
        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }
        
        // Check if OTP is expired
        if ($user['otp_expires_at'] && strtotime($user['otp_expires_at']) < time()) {
            return ['success' => false, 'message' => 'OTP has expired'];
        }
        
        // Check max attempts
        if ($user['otp_attempts'] >= OTP_MAX_ATTEMPTS) {
            return ['success' => false, 'message' => 'Too many attempts. Please request a new OTP'];
        }
        
        // Verify OTP. hash_equals() (not !==) so the comparison takes the
        // same amount of time regardless of where the strings first differ
        // — a plain !== leaks timing information an attacker could use to
        // guess the code digit-by-digit. otp_code can be NULL (e.g. no OTP
        // ever issued for this email), so it's cast to a string first;
        // hash_equals() requires string arguments.
        if (!otp_matches($user['otp_code'], (string) $otp)) {
            query_execute(
                'UPDATE users SET otp_attempts = otp_attempts + 1 WHERE email = ?',
                [$email]
            );
            return ['success' => false, 'message' => 'Invalid OTP'];
        }
        
        // Mark as verified and clear OTP
        query_execute(
            'UPDATE users SET otp_verified = 1, otp_code = NULL, otp_expires_at = NULL, otp_attempts = 0 WHERE email = ?',
            [$email]
        );
        
        return ['success' => true, 'message' => 'Email verified successfully'];
    }
    
    // Fallback for session storage
    init_data();
    foreach ($_SESSION['data']['users'] as &$user) {
        if ($user['email'] === $email) {
            if (!isset($user['otp_expires_at']) || strtotime($user['otp_expires_at']) < time()) {
                return ['success' => false, 'message' => 'OTP has expired'];
            }
            
            if (($user['otp_attempts'] ?? 0) >= OTP_MAX_ATTEMPTS) {
                return ['success' => false, 'message' => 'Too many attempts. Please request a new OTP'];
            }
            
            if (!otp_matches($user['otp_code'] ?? '', (string) $otp)) {
                $user['otp_attempts'] = ($user['otp_attempts'] ?? 0) + 1;
                return ['success' => false, 'message' => 'Invalid OTP'];
            }
            
            $user['otp_verified'] = true;
            $user['otp_code'] = null;
            $user['otp_expires_at'] = null;
            $user['otp_attempts'] = 0;
            
            return ['success' => true, 'message' => 'Email verified successfully'];
        }
    }
    
    return ['success' => false, 'message' => 'User not found'];
}

/**
 * Resend OTP to user
 */
function resend_otp(string $email): array {
    if (database_available()) {
        $user = query_one('SELECT * FROM users WHERE email = ?', [$email]);
        
        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }
        
        if ($user['otp_verified']) {
            return ['success' => false, 'message' => 'Email already verified'];
        }

        // Rate limit: without this, the Resend button (once its
        // frontend/backend mismatch is fixed — see verify-otp/index.php)
        // could otherwise be clicked as fast as the page reloads, sending
        // unlimited emails per applicant.
        $secondsLeft = otp_resend_seconds_remaining($user['otp_last_sent_at'] ?? null);
        if ($secondsLeft > 0) {
            return ['success' => false, 'message' => "Please wait {$secondsLeft}s before requesting another code.", 'secondsLeft' => $secondsLeft];
        }
        
        if (create_and_send_otp($email)) {
            return ['success' => true, 'message' => 'A new code has been sent to your email.'];
        }
        
        return ['success' => false, 'message' => 'Failed to send OTP. Please try again'];
    }
    
    init_data();
    foreach ($_SESSION['data']['users'] as &$user) {
        if ($user['email'] === $email) {
            if ($user['otp_verified'] ?? false) {
                return ['success' => false, 'message' => 'Email already verified'];
            }

            $secondsLeft = otp_resend_seconds_remaining($user['otp_last_sent_at'] ?? null);
            if ($secondsLeft > 0) {
                return ['success' => false, 'message' => "Please wait {$secondsLeft}s before requesting another code.", 'secondsLeft' => $secondsLeft];
            }
            
            if (create_and_send_otp($email)) {
                return ['success' => true, 'message' => 'A new code has been sent to your email.'];
            }
            
            return ['success' => false, 'message' => 'Failed to send OTP. Please try again'];
        }
    }
    
    return ['success' => false, 'message' => 'User not found'];
}
