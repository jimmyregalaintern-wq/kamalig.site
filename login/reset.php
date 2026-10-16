<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$title = 'Reset Password';
$pageClass = 'auth-page';
$error = '';
$success = '';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$user = $token !== '' ? get_user_by_reset_token($token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    csrf_verify();
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirmPassword'] ?? '');

    if (strlen($password) < 10) {
        $error = 'Password must be at least 10 characters';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } else {
        update_user_password($user['id'], password_hash($password, PASSWORD_DEFAULT));
        clear_password_reset_token($user['id']);
        log_activity($user['id'], 'auth.password_reset', 'user', $user['id']);
        $success = 'Your password has been reset. You can now sign in.';
        $user = null; // hide the form after a successful reset
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

        <h1>Reset your password</h1>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <div class="auth-footer">
                <a href="<?php echo htmlspecialchars(url('landing/') . '#access-login'); ?>" class="btn-signin" style="display:block;text-align:center;text-decoration:none;">Back to sign in</a>
            </div>
        <?php elseif (!$user): ?>
            <p class="lead">This reset link is invalid or has expired.</p>
            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <div class="auth-footer">
                <a href="<?php echo htmlspecialchars(url('login/forgot.php')); ?>">Request a new reset link</a>
            </div>
        <?php else: ?>
            <p class="lead">Choose a new password for <strong><?php echo htmlspecialchars($user['email']); ?></strong>.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div class="form-group">
                    <label for="password">New password</label>
                    <input class="form-control" id="password" name="password" type="password" required>
                </div>
                <div class="form-group">
                    <label for="confirmPassword">Confirm new password</label>
                    <input class="form-control" id="confirmPassword" name="confirmPassword" type="password" required>
                </div>
                <button class="btn-signin" type="submit">Reset password</button>
            </form>
        <?php endif; ?>

        <div class="auth-footer auth-footer--split">
            <a href="<?php echo htmlspecialchars(url('landing/') . '#access-login'); ?>">Back to sign in</a>
            <a href="<?php echo htmlspecialchars(url('landing/') . '#access-register'); ?>" class="auth-footer-link">Create account</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php';
