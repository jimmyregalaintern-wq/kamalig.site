<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('warehouses', ['farmer', 'importer', 'supplier']);

$title = 'Add Warehouse';
$activePage = 'warehouses';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $capacity = trim($_POST['capacity'] ?? '');

    if ($name === '' || $capacity === '') {
        $error = 'Name and capacity are required.';
    } elseif ((float) $capacity <= 0) {
        $error = 'Capacity must be greater than zero.';
    } else {
        create_warehouse([
            'userId' => current_user_id() ?? '',
            'name' => $name,
            'location' => $location !== '' ? $location : null,
            'capacity' => (float) $capacity,
        ]);
        header('Location: ' . url('warehouses/'));
        exit;
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<h2>Add Warehouse</h2>
<p class="muted">Register a new storage location for your rice stock.</p>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<form method="post">
    <?php echo csrf_field(); ?>
    <div class="form-group">
        <label for="name">Warehouse Name</label>
        <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" placeholder="e.g. Nueva Ecija Storage" required>
    </div>
    <div class="form-group">
        <label for="location">Location</label>
        <input id="location" name="location" type="text" value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>" placeholder="e.g. Cabanatuan, Nueva Ecija">
    </div>
    <div class="form-group">
        <label for="capacity">Capacity (kg)</label>
        <input id="capacity" name="capacity" type="number" step="0.01" value="<?php echo htmlspecialchars($_POST['capacity'] ?? ''); ?>" required>
        <p class="muted" style="margin-top: 4px;">Total weight this warehouse can hold, in kilograms. Stock stored in sacks or tons is converted to kg automatically when checking capacity.</p>
    </div>
    <button class="btn btn-primary" type="submit">Save Warehouse</button>
    <a href="<?php echo htmlspecialchars(url('warehouses/')); ?>" class="btn btn-secondary">Cancel</a>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
