<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('warehouses', ['farmer', 'importer', 'supplier']);

$title = 'Edit Warehouse';
$activePage = 'warehouses';

$warehouseId = trim($_GET['id'] ?? $_POST['id'] ?? '');
$warehouse = $warehouseId !== '' ? get_warehouse_by_id($warehouseId) : null;

if (!$warehouse || $warehouse['userId'] !== current_user_id()) {
    include __DIR__ . '/../../includes/header.php';
    echo '<h2>Warehouse not found</h2><p>This warehouse does not exist or is not yours to edit.</p>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

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
    } elseif ((float) $capacity < (float) $warehouse['capacity'] && (float) $capacity < get_warehouse_capacity_used($warehouseId)) {
        // Lowering capacity below what is already stored would hide an
        // over-capacity warehouse. Move stock out first, or enter a higher value.
        $error = 'Capacity cannot be lower than the stock already stored here (' . number_format(get_warehouse_capacity_used($warehouseId), 2) . ' kg). Move stock out first or enter a higher capacity.';
    } else {
        $oldCapacity = (float) $warehouse['capacity'];
        update_warehouse($warehouseId, [
            'name' => $name,
            'location' => $location !== '' ? $location : null,
            'capacity' => (float) $capacity,
        ]);
        log_activity(current_user_id(), 'warehouse.update', 'warehouse', $warehouseId, $name . ' (capacity ' . $oldCapacity . ' -> ' . (float) $capacity . ' kg)');
        header('Location: ' . url('warehouses/'));
        exit;
    }
    $warehouse = array_merge($warehouse, compact('name', 'location', 'capacity'));
}

include __DIR__ . '/../../includes/header.php';
?>
<h2>Edit Warehouse</h2>
<p class="muted">Update this storage location.</p>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="id" value="<?php echo htmlspecialchars($warehouseId); ?>">
    <div class="form-group">
        <label for="name">Warehouse Name</label>
        <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($warehouse['name']); ?>" required>
    </div>
    <div class="form-group">
        <label for="location">Location</label>
        <input id="location" name="location" type="text" value="<?php echo htmlspecialchars($warehouse['location'] ?? ''); ?>">
    </div>
    <div class="form-group">
        <label for="capacity">Capacity (kg)</label>
        <input id="capacity" name="capacity" type="number" step="0.01" value="<?php echo htmlspecialchars($warehouse['capacity']); ?>" required>
        <p class="muted" style="margin-top: 4px;">Total weight this warehouse can hold, in kilograms.</p>
    </div>
    <button class="btn btn-primary" type="submit">Save Changes</button>
    <a href="<?php echo htmlspecialchars(url('warehouses/')); ?>" class="btn btn-secondary">Cancel</a>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
