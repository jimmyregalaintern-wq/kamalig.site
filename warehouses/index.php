<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('warehouses', ['farmer', 'importer', 'supplier']);

$title = 'Warehouses';
$activePage = 'warehouses';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['warehouseId']) && !empty($_POST['action'])) {
    csrf_verify();
    $warehouse = get_warehouse_by_id($_POST['warehouseId']);

    // Only the owner can archive/restore their own warehouse.
    if ($warehouse && $warehouse['userId'] === current_user_id()) {
        if ($_POST['action'] === 'archive') {
            update_warehouse($warehouse['id'], ['status' => 'archived']);
            log_activity(current_user_id(), 'warehouse.archive', 'warehouse', $warehouse['id']);
            $message = 'Warehouse archived.';
        } elseif ($_POST['action'] === 'restore') {
            update_warehouse($warehouse['id'], ['status' => 'active']);
            log_activity(current_user_id(), 'warehouse.restore', 'warehouse', $warehouse['id']);
            $message = 'Warehouse restored.';
        }
    }
}

$warehouses = get_user_warehouses(current_user_id() ?? '');

include __DIR__ . '/../includes/header.php';
?>
<h2>Warehouses</h2>
<p class="muted">Manage where your rice stock is stored, and how much capacity is left in each location.</p>
<div class="card">
    <a href="<?php echo htmlspecialchars(url('warehouses/add/')); ?>" class="btn btn-primary">Add Warehouse</a>
    <a href="<?php echo htmlspecialchars(url('warehouses/transfers/')); ?>" class="btn btn-secondary">Warehouse Transfers</a>
</div>
<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if (empty($warehouses)): ?>
    <div class="card">
        <p>No warehouses yet. Add one to start placing stock into it.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Name</th><th>Location</th><th>Capacity Used (kg)</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($warehouses as $warehouse): ?>
                    <?php
                        $used = get_warehouse_capacity_used($warehouse['id']);
                        $capacity = (float) $warehouse['capacity'];
                        $pct = $capacity > 0 ? min(100, round(($used / $capacity) * 100)) : 0;
                        $overCapacity = $used > $capacity;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($warehouse['name']); ?></td>
                        <td><?php echo htmlspecialchars($warehouse['location'] ?? '—'); ?></td>
                        <td>
                            <?php echo htmlspecialchars(number_format($used, 2)); ?> / <?php echo htmlspecialchars(number_format($capacity, 2)); ?> kg
                            <span class="badge <?php echo $overCapacity ? 'badge-sold-out' : 'badge-available'; ?>"><?php echo htmlspecialchars($pct); ?>%</span>
                        </td>
                        <td><span class="badge <?php echo $warehouse['status'] === 'archived' ? 'badge-archived' : 'badge-available'; ?>"><?php echo htmlspecialchars(ucfirst($warehouse['status'])); ?></span></td>
                        <td>
                            <a href="<?php echo htmlspecialchars(url('warehouses/edit/?id=' . urlencode($warehouse['id']))); ?>" class="btn btn-secondary btn-sm">Edit</a>
                            <form method="post" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="warehouseId" value="<?php echo htmlspecialchars($warehouse['id']); ?>">
                                <?php if ($warehouse['status'] === 'archived'): ?>
                                    <button class="btn btn-secondary btn-sm" type="submit" name="action" value="restore">Restore</button>
                                <?php else: ?>
                                    <button class="btn btn-danger btn-sm" type="submit" name="action" value="archive" data-confirm-modal="Archive this warehouse?" data-confirm-title="Archive warehouse">Archive</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
