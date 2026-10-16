<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('warehouses', ['farmer', 'importer', 'supplier']);

$title = 'Warehouse Transfers';
$activePage = 'warehouses';

$transfers = get_user_warehouse_transfers(current_user_id() ?? '');
$stocksById = array_column(get_user_stocks(current_user_id() ?? ''), null, 'id');
$warehousesById = array_column(get_user_warehouses(current_user_id() ?? ''), null, 'id');

include __DIR__ . '/../../includes/header.php';
?>
<h2>Warehouse Transfers</h2>
<p class="muted">History of stock moved between warehouses (or in/out of storage).</p>
<?php if (empty($transfers)): ?>
    <div class="card">
        <p>No transfers recorded yet. Moving stock via Stock Placement will show up here.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Date</th><th>Item</th><th>Batch</th><th>From</th><th>To</th><th>Note</th></tr>
            </thead>
            <tbody>
                <?php foreach ($transfers as $t): ?>
                    <?php
                        $itemName = $stocksById[$t['stockId']]['variety'] ?? '—';
                        $fromName = $t['fromWarehouseId'] ? ($warehousesById[$t['fromWarehouseId']]['name'] ?? '—') : 'Unassigned';
                        $toName = $t['toWarehouseId'] ? ($warehousesById[$t['toWarehouseId']]['name'] ?? '—') : 'Unassigned';
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($t['createdAt']); ?></td>
                        <td><?php echo htmlspecialchars($itemName); ?></td>
                        <td><?php echo htmlspecialchars($t['batchNumber'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($fromName); ?></td>
                        <td><?php echo htmlspecialchars($toName); ?></td>
                        <td><?php echo htmlspecialchars($t['reason'] ?? '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
