<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('warehouses', ['farmer', 'importer', 'supplier']);

$title = 'Stock Placement';
$activePage = 'warehouses';

$stockId = trim($_GET['stockId'] ?? $_POST['stockId'] ?? '');
$stock = $stockId !== '' ? get_stock_by_id($stockId) : null;

if (!$stock || $stock['userId'] !== current_user_id()) {
    include __DIR__ . '/../../includes/header.php';
    echo '<h2>Listing not found</h2><p>This stock listing does not exist or is not yours.</p>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$warehouses = get_user_warehouses(current_user_id() ?? '');
$activeWarehouses = array_values(array_filter($warehouses, fn($w) => $w['status'] !== 'archived'));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $toWarehouseId = trim($_POST['warehouseId'] ?? '');
    $toWarehouseId = $toWarehouseId !== '' ? $toWarehouseId : null;
    $reason = trim($_POST['reason'] ?? '');
    $moveQuantity = trim($_POST['moveQuantity'] ?? '');

    // If a destination was chosen, make sure it's actually one of this
    // user's own (active) warehouses before assigning stock into it.
    if ($toWarehouseId !== null) {
        $destination = get_warehouse_by_id($toWarehouseId);
        if (!$destination || $destination['userId'] !== current_user_id() || $destination['status'] === 'archived') {
            $toWarehouseId = null;
            $error = 'Choose a valid warehouse.';
        }
    }

    if ($error === '' && $moveQuantity !== '' && !is_numeric($moveQuantity)) {
        $error = 'Enter a valid quantity.';
    }

    if ($error === '') {
        $fullQuantity = (float) $stock['quantity'];
        $requestedQuantity = $moveQuantity !== '' ? (float) $moveQuantity : $fullQuantity;

        if ($requestedQuantity <= 0 || $requestedQuantity > $fullQuantity) {
            $error = 'Enter a quantity between 0 and ' . $fullQuantity . ' ' . $stock['unit'] . '.';
        } elseif ($requestedQuantity < $fullQuantity) {
            // Partial: split this amount into its own lot and place only
            // that portion (Section 5: "a 1,000 kg lot should be movable
            // as 400 kg to Warehouse A and 600 kg to Warehouse B") — the
            // rest stays behind on the original listing, untouched.
            $result = split_stock_across_warehouse($stockId, $requestedQuantity, $toWarehouseId, $reason ?: null);
            if (!$result['success']) {
                $error = $result['error'];
            } else {
                header('Location: ' . url('inventory/'));
                exit;
            }
        } else {
            // Full amount: an ordinary whole-lot move, no split needed.
            $result = place_stock_in_warehouse($stockId, $toWarehouseId, $reason ?: null);
            if (!$result['success']) {
                $error = $result['error'];
            } else {
                header('Location: ' . url('inventory/'));
                exit;
            }
        }
    }
}

$currentWarehouse = ($stock['warehouseId'] ?? null) ? get_warehouse_by_id($stock['warehouseId']) : null;

include __DIR__ . '/../../includes/header.php';
?>
<h2>Stock Placement</h2>
<p class="muted">
    Move <strong><?php echo htmlspecialchars($stock['variety']); ?></strong>
    (<?php echo htmlspecialchars($stock['quantity']); ?> <?php echo htmlspecialchars($stock['unit']); ?>)
    into a warehouse, or take it out of one.
    Currently: <strong><?php echo htmlspecialchars($currentWarehouse['name'] ?? 'Unassigned'); ?></strong>
</p>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if (empty($activeWarehouses) && !$currentWarehouse): ?>
    <div class="card">
        <p>You don't have any warehouses yet. <a href="<?php echo htmlspecialchars(url('warehouses/add/')); ?>">Add one first</a>.</p>
    </div>
<?php else: ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="stockId" value="<?php echo htmlspecialchars($stockId); ?>">
        <div class="form-group">
            <label for="moveQuantity">Quantity to move (<?php echo htmlspecialchars($stock['unit']); ?>)</label>
            <input id="moveQuantity" name="moveQuantity" type="number" step="0.01" min="0.01" max="<?php echo htmlspecialchars($stock['quantity']); ?>" value="<?php echo htmlspecialchars($stock['quantity']); ?>">
            <p class="muted" style="margin-top: 4px;">Full lot: <?php echo htmlspecialchars($stock['quantity']); ?> <?php echo htmlspecialchars($stock['unit']); ?> (<?php echo htmlspecialchars(stock_available_quantity($stock)); ?> available, unreserved). Enter less to split part of this lot into its own listing at the chosen warehouse, leaving the rest here.</p>
        </div>
        <div class="form-group">
            <label for="warehouseId">Warehouse</label>
            <select id="warehouseId" name="warehouseId">
                <option value="">— Unassigned —</option>
                <?php foreach ($activeWarehouses as $w): ?>
                    <option value="<?php echo htmlspecialchars($w['id']); ?>" <?php echo ($stock['warehouseId'] ?? '') === $w['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($w['name']); ?><?php echo $w['location'] ? ' — ' . htmlspecialchars($w['location']) : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="reason">Note (optional)</label>
            <input id="reason" name="reason" type="text" placeholder="e.g. Moved for cold storage, Consolidating stock">
        </div>
        <button class="btn btn-primary" type="submit">Save Placement</button>
        <a href="<?php echo htmlspecialchars(url('inventory/')); ?>" class="btn btn-secondary">Cancel</a>
    </form>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
