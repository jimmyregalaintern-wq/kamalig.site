<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$title = 'Forgot Password';
$pageClass = 'auth-page';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');

    if (!captcha_check()) {
        $message = CAPTCHA_FAIL_MESSAGE;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        // Always show the same message regardless of whether the email is
        // registered — this line is what actually sends the email; the
        // wording doesn't change based on the outcome so this page can't be
        // used to check which addresses have accounts.
        create_and_send_password_reset($email, absolute_url('login/reset.php'));
        $message = 'If this email is registered, a reset link will be sent shortly.';
    }
}

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

        <h1>Forgot your password?</h1>
        <p class="lead">Enter your email and we’ll send instructions to reset your password.</p>

        <?php if ($message !== ''): ?>
            <div class="alert <?php echo str_starts_with($message, 'If this email') ? 'alert-success' : 'alert-error'; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="email">Email address</label>
                <input class="form-control" id="email" name="email" type="email" placeholder="you@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
            </div>

            <?php echo captcha_widget(); ?>
            <button class="btn-signin" type="submit">Send reset instructions</button>
        </form>

        <div class="auth-footer auth-footer--split">
            <a href="<?php echo htmlspecialchars(url('landing/') . '#access-login'); ?>">Back to sign in</a>
            <a href="<?php echo htmlspecialchars(url('landing/') . '#access-register'); ?>" class="auth-footer-link">Create account</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php';
