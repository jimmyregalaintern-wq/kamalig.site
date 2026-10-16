<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_auth();

$title = 'Settings';
$activePage = 'settings';

$userId = current_user_id() ?? '';
$user = get_user_by_id($userId);

$profileError = '';
$profileSuccess = '';
$passwordError = '';
$passwordSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $location = trim($_POST['location'] ?? '');

    if ($name === '' || $company === '') {
        $profileError = 'Name and Company are required.';
    } else {
        update_user_profile($userId, [
            'name' => $name,
            'company' => $company,
            'phone' => $phone,
            'location' => $location,
        ]);

        // Keep the session copy in sync so the sidebar/topbar reflect the
        // change immediately without requiring a re-login.
        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['company'] = $company;

        log_activity($userId, 'profile.update', 'user', $userId);

        $user = get_user_by_id($userId);
        $profileSuccess = 'Profile updated.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    csrf_verify();
    $currentPassword = trim($_POST['currentPassword'] ?? '');
    $newPassword = trim($_POST['newPassword'] ?? '');
    $confirmPassword = trim($_POST['confirmPassword'] ?? '');

    if (empty($user['password']) || !password_verify($currentPassword, $user['password'])) {
        $passwordError = 'Current password is incorrect.';
    } elseif (strlen($newPassword) < 10) {
        $passwordError = 'New password must be at least 10 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $passwordError = 'New passwords do not match.';
    } else {
        update_user_password($userId, password_hash($newPassword, PASSWORD_DEFAULT));
        log_activity($userId, 'profile.password_change', 'user', $userId);
        $passwordSuccess = 'Password updated.';
    }
}

include __DIR__ . '/../includes/header.php';
?>
<h2>Settings</h2>
<p class="muted">Manage your profile and preferences.</p>

<div class="card">
    <h3>Profile</h3>
    <?php if ($profileError !== ''): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($profileError); ?></div>
    <?php endif; ?>
    <?php if ($profileSuccess !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($profileSuccess); ?></div>
    <?php endif; ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update_profile">
        <div class="form-group">
            <label for="email">Email address</label>
            <input id="email" type="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" disabled>
        </div>
        <div class="form-group">
            <label for="role">Account type</label>
            <input id="role" type="text" value="<?php echo htmlspecialchars(ucfirst($user['role'] ?? '')); ?>" disabled>
        </div>
        <div class="form-group">
            <label for="name">Full name</label>
            <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label for="company">Company / Group</label>
            <input id="company" name="company" type="text" value="<?php echo htmlspecialchars($user['company'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label for="phone">Phone number</label>
            <input id="phone" name="phone" type="text" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label for="location">Location</label>
            <input id="location" name="location" type="text" value="<?php echo htmlspecialchars($user['location'] ?? ''); ?>">
        </div>
        <button class="btn btn-primary" type="submit">Save Profile</button>
    </form>
</div>

<div class="card">
    <h3>Change Password</h3>
    <?php if ($passwordError !== ''): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($passwordError); ?></div>
    <?php endif; ?>
    <?php if ($passwordSuccess !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($passwordSuccess); ?></div>
    <?php endif; ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-group">
            <label for="currentPassword">Current password</label>
            <input id="currentPassword" name="currentPassword" type="password" required>
        </div>
        <div class="form-group">
            <label for="newPassword">New password</label>
            <input id="newPassword" name="newPassword" type="password" required>
        </div>
        <div class="form-group">
            <label for="confirmPassword">Confirm new password</label>
            <input id="confirmPassword" name="confirmPassword" type="password" required>
        </div>
        <button class="btn btn-primary" type="submit">Update Password</button>
    </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
