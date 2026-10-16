<?php
/**
 * Password reset for KAMALIG.
 *
 * Mirrors includes/otp.php's approach: a token is generated, stored, and
 * emailed via the same Gmail SMTP / PHPMailer path (falling back to logging
 * the link via error_log when mail isn't configured, exactly like OTP does).
 *
 * The token itself is never stored in the database — only its SHA-256 hash
 * is. The raw token only ever exists in memory and in the emailed link, so
 * a leaked database dump can't be used to reset anyone's password.
 */

require_once __DIR__ . '/mail_config.php';

function generate_reset_token(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Create a reset token for the given email and send the reset link.
 * Always returns true/false based on whether the *email step* succeeded —
 * callers should show the same generic message either way (see
 * login/forgot.php) so this can't be used to enumerate registered emails.
 */
function create_and_send_password_reset(string $email, string $resetBaseUrl): bool {
    $user = get_user_by_email($email);
    if (!$user) {
        return false;
    }

    $token = generate_reset_token();
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + (PASSWORD_RESET_EXPIRY_MINUTES * 60));

    if (database_available()) {
        query_execute(
            'UPDATE users SET reset_token = ?, reset_token_expires_at = ? WHERE id = ?',
            [$tokenHash, $expiresAt, $user['id']]
        );
    } else {
        init_data();
        if (isset($_SESSION['data']['users'][$user['id']])) {
            $_SESSION['data']['users'][$user['id']]['reset_token'] = $tokenHash;
            $_SESSION['data']['users'][$user['id']]['reset_token_expires_at'] = $expiresAt;
        }
    }

    $resetUrl = $resetBaseUrl . '?token=' . urlencode($token);

    return send_password_reset_email($email, $resetUrl);
}

/**
 * Look up the user for a raw reset token, if it exists and hasn't expired.
 */
function get_user_by_reset_token(string $token): ?array {
    if ($token === '') {
        return null;
    }

    $tokenHash = hash('sha256', $token);

    if (database_available()) {
        $user = query_one('SELECT * FROM users WHERE reset_token = ?', [$tokenHash]);
    } else {
        init_data();
        $user = null;
        foreach ($_SESSION['data']['users'] as $candidate) {
            if (hash_equals((string) ($candidate['reset_token'] ?? ''), $tokenHash)) {
                $user = $candidate;
                break;
            }
        }
    }

    if (!$user || empty($user['reset_token_expires_at'])) {
        return null;
    }

    if (strtotime($user['reset_token_expires_at']) < time()) {
        return null;
    }

    return $user;
}

/**
 * Invalidate a user's reset token — call this after a successful reset (or
 * you could call it after any login) so a token can't be reused or left
 * live past its one intended use.
 */
function clear_password_reset_token(string $userId): void {
    if (database_available()) {
        query_execute('UPDATE users SET reset_token = NULL, reset_token_expires_at = NULL WHERE id = ?', [$userId]);
        return;
    }

    init_data();
    if (isset($_SESSION['data']['users'][$userId])) {
        $_SESSION['data']['users'][$userId]['reset_token'] = null;
        $_SESSION['data']['users'][$userId]['reset_token_expires_at'] = null;
    }
}

function send_password_reset_email(string $email, string $resetUrl): bool {
    if (!GMAIL_ENABLED) {
        error_log("Password reset link for $email: $resetUrl (expires in " . PASSWORD_RESET_EXPIRY_MINUTES . " minutes)");
        return true;
    }

    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return send_password_reset_via_phpmailer($email, $resetUrl);
    }

    // Same graceful degradation as send_otp_email() in includes/otp.php —
    // PHPMailer isn't installed, so log the link instead of failing the
    // request outright.
    error_log("WARNING: PHPMailer not installed. Password reset link for $email: $resetUrl (expires in " . PASSWORD_RESET_EXPIRY_MINUTES . " minutes)");
    error_log("To enable email sending, install PHPMailer: composer require phpmailer/phpmailer");

    return true;
}

function send_password_reset_via_phpmailer(string $email, string $resetUrl): bool {
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
        $mail->Subject = 'Reset your KAMALIG password';
        $mail->Body = get_password_reset_email_template($resetUrl);
        $mail->AltBody = "Reset your password: $resetUrl\nThis link is valid for " . PASSWORD_RESET_EXPIRY_MINUTES . " minutes. If you didn't request this, you can ignore this email.";

        return $mail->send();
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $e->getMessage());
        return false;
    }
}

function get_password_reset_email_template(string $resetUrl): string {
    $expiryMinutes = PASSWORD_RESET_EXPIRY_MINUTES;
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES);

    return <<<HTML
    <html>
    <head>
        <style>
            body { font-family: 'Inter', Arial, sans-serif; background-color: #f5f5f5; margin: 0; padding: 0; }
            .container { max-width: 500px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background-color: #1f4d2e; color: white; padding: 30px 20px; text-align: center; }
            .content { padding: 30px 20px; }
            .button-box { text-align: center; margin: 24px 0; }
            .button { display: inline-block; background-color: #1f4d2e; color: #ffffff !important; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: bold; }
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
                <p>We received a request to reset your KAMALIG password. Click the button below to choose a new one:</p>
                <div class="button-box">
                    <a class="button" href="$safeUrl">Reset Password</a>
                </div>
                <p>This link is valid for <strong>$expiryMinutes minutes</strong>.</p>
                <p>If you didn't request this, you can safely ignore this email — your password won't be changed.</p>
                <p class="warning"><strong>Never share this link with anyone.</strong></p>
            </div>
            <div class="footer">
                <p>&copy; 2024 KAMALIG. All rights reserved.</p>
                <p>Rice Supply Transparency Platform</p>
            </div>
        </div>
    </body>
    </html>
    HTML;
}
