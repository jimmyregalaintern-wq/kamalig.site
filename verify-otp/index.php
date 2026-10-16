<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$title = 'Verify Email Address';
$pageClass = 'auth-page';
$error = '';
$success = '';

$otpEmail = $_SESSION['otp_email'] ?? null;
$isRegistration = $_SESSION['otp_registration'] ?? false;

if (!$otpEmail) {
    header('Location: ' . url('landing/') . '#access-register');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
    csrf_verify();
    $otp = trim(preg_replace('/\D/', '', $_POST['otp'] ?? ''));
    
    if (strlen($otp) !== OTP_LENGTH) {
        $error = 'Please enter a valid ' . OTP_LENGTH . '-digit code';
    } else {
        $result = verify_otp($otpEmail, $otp);
        
        if ($result['success']) {
            if ($isRegistration) {
                // IMPORTANT: verify_otp() above already set otp_verified = 1
                // for this email — that is the *only* thing OTP success
                // means. This used to also run
                // `UPDATE users SET verified = 1`, but `verified` is the
                // admin-approval flag (see includes/data.php's
                // approve_pending_user()/reject_pending_user()), not the
                // email-verification flag. Setting it here skipped admin
                // review entirely: anyone who could receive an email could
                // get a fully-approved account. Email verification and
                // admin approval must stay two independent gates — see
                // login_user() in includes/bootstrap.php, which now
                // requires both before issuing a session.
                unset($_SESSION['otp_email']);
                unset($_SESSION['otp_registration']);

                // Compliance gate (Fix Guide item 7): the email is now
                // proven, so sign the new user straight in. They land on a
                // restricted dashboard with the "Complete your Compliance"
                // checklist; trading unlocks once an admin approves their
                // documents. A suspended account never gets a session.
                $newUser = get_user_by_email($otpEmail);
                if ($newUser && ($newUser['status'] ?? '') !== 'suspended') {
                    start_user_session($newUser);
                    log_activity($newUser['id'], 'account.login', 'user', $newUser['id'], 'after email verification');
                    header('Location: ' . url('dashboard/'));
                    exit;
                }

                $_SESSION['registration_success'] = true;
                header('Location: ' . url('landing/') . '#access-login');
                exit;
            } else {
                $success = 'Email verified successfully!';
                unset($_SESSION['otp_email']);
            }
        } else {
            $error = $result['message'];
        }
    }
}

// Handle resend OTP. This form POSTs resend=1 (see the form below) — it
// used to be checked as $_GET['resend'], a mismatch that meant the Resend
// button silently did nothing no matter how many times it was clicked.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    csrf_verify();
    $resendResult = resend_otp($otpEmail);
    if ($resendResult['success']) {
        $success = $resendResult['message'];
    } else {
        $error = $resendResult['message'];
    }
}

// Seconds left before Resend may be used again — drives both the
// server-side disabling of the button below and its live countdown, kept
// in sync with the same cooldown resend_otp() enforces.
$otpUser = get_user_by_email($otpEmail);
$resendSecondsLeft = otp_resend_seconds_remaining($otpUser['otp_last_sent_at'] ?? null);


include __DIR__ . '/../includes/header.php';
?>

<div class="auth-hero auth-page">
    <div class="auth-card">
        <div class="auth-brand">
            <span class="nav__mark"><img src="<?php echo htmlspecialchars(url('assets/logo/kamalig-icon.png')); ?>" alt="KAMALIG"></span>
            <div>
                <div class="auth-brand-name">KAMALIG</div>
                <div class="auth-brand-subtitle">Rice supply transparency platform</div>
            </div>
        </div>
        
        <h1>Verify Your Email</h1>
        <p class="lead">We've sent a verification code to <strong><?php echo htmlspecialchars($otpEmail); ?></strong></p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?php echo csrf_field(); ?>
            
            <div class="form-group">
                <label for="otp">Verification Code</label>
                <input 
                    id="otp" 
                    name="otp" 
                    class="form-control otp-input" 
                    type="text" 
                    placeholder="Enter 6-digit code" 
                    maxlength="6"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    required
                    autocomplete="off"
                >
                <small class="muted">Check your email for the 6-digit verification code</small>
            </div>

            <button class="btn-signin" type="submit">Verify Email</button>
        </form>

        <div class="auth-footer">
            <p class="muted">Didn't receive the code?</p>
            <form method="post" style="display: inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="resend" value="1">
                <button type="submit" id="resendBtn" class="auth-link" <?php echo $resendSecondsLeft > 0 ? 'disabled' : ''; ?>>
                    <span id="resendLabel"><?php echo $resendSecondsLeft > 0 ? "Resend available in {$resendSecondsLeft}s" : 'Resend verification code'; ?></span>
                </button>
            </form>
        </div>

        <div class="auth-footer auth-footer--split">
            <a href="<?php echo htmlspecialchars(url('landing/') . '#access-register'); ?>">Back to registration</a>
            <a href="<?php echo htmlspecialchars(url('landing/') . '#access-login'); ?>" class="auth-footer-link">Already verified?</a>
        </div>
    </div>
</div>

<style>
.otp-input {
    font-size: 24px;
    letter-spacing: 8px;
    text-align: center;
    font-family: 'Courier New', monospace;
    font-weight: bold;
}

.alert {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 14px;
}

.alert-error {
    background-color: #fde8e8;
    color: #d32f2f;
    border: 1px solid #f5c6cb;
}

.alert-success {
    background-color: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #c8e6c9;
}

.auth-link {
    background: none;
    border: none;
    color: #1f4d2e;
    text-decoration: underline;
    cursor: pointer;
    font-size: 14px;
    padding: 0;
}

.auth-link:hover {
    opacity: 0.8;
}

.auth-link:disabled {
    color: #888;
    text-decoration: none;
    cursor: not-allowed;
}
</style>

<script>
// Mirrors the server-side cooldown in resend_otp() (includes/otp.php) so
// the button visibly counts down instead of just failing silently again
// if clicked early — the frontend/backend now agree on both the action
// (POST resend=1) and the timing.
(function () {
    var secondsLeft = <?php echo (int) $resendSecondsLeft; ?>;
    var btn = document.getElementById('resendBtn');
    var label = document.getElementById('resendLabel');
    if (!btn || !label || secondsLeft <= 0) {
        return;
    }
    btn.disabled = true;
    var timer = setInterval(function () {
        secondsLeft--;
        if (secondsLeft <= 0) {
            clearInterval(timer);
            btn.disabled = false;
            label.textContent = 'Resend verification code';
            return;
        }
        label.textContent = 'Resend available in ' + secondsLeft + 's';
    }, 1000);
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
