<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');

$title = 'System Settings';
$activePage = 'adminsettings';

// Centralised definitions keep the admin screen, validation rules and runtime
// threshold checks in sync as the project grows. See known_setting_fields()
// in includes/data.php.
$fields = known_setting_fields();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $values = [];
    foreach ($fields as $key => $field) {
        $raw = trim($_POST[$key] ?? '');
        $validationError = validate_setting_value($key, $raw);
        if ($validationError !== null) {
            $error = $validationError;
            break;
        }
        $values[$key] = $raw;
    }

    if ($error === '' && !empty($values)) {
        foreach ($values as $key => $value) {
            set_setting($key, $value, current_user_id());
        }
        log_setting_changes($values, current_user_id());
        header('Location: ' . url('admin/settings/'));
        exit;
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<h2>System Settings</h2>
<p class="muted">Admin-editable configuration. These override the environment-variable defaults as soon as they're saved here.</p>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<div class="card">
    <form method="post">
        <?php echo csrf_field(); ?>
        <?php foreach ($fields as $key => $field): ?>
            <div class="form-group">
                <label for="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($field['label']); ?></label>
                <input
                    id="<?php echo htmlspecialchars($key); ?>"
                    name="<?php echo htmlspecialchars($key); ?>"
                    type="number"
                    min="<?php echo htmlspecialchars((string) ($field['min'] ?? 0)); ?>"
                    max="<?php echo htmlspecialchars((string) ($field['max'] ?? '')); ?>"
                    step="<?php echo htmlspecialchars((string) ($field['step'] ?? 'any')); ?>"
                    value="<?php echo htmlspecialchars($_POST[$key] ?? get_setting($key, $field['default'])); ?>"
                    required
                >
                <p class="muted"><?php echo htmlspecialchars($field['help']); ?></p>
            </div>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">Save Settings</button>
    </form>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
