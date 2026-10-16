<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('inventory', ['farmer', 'importer', 'supplier']);

$title = 'Inventory History';
$activePage = 'inventory';

$stockId = trim($_GET['stockId'] ?? '');
$stock = $stockId !== '' ? get_stock_by_id($stockId) : null;
// Ignore a stockId filter that isn't actually one of this user's own items.
if ($stock && $stock['userId'] !== current_user_id()) {
    $stock = null;
    $stockId = '';
}

$movements = $stockId !== ''
    ? get_stock_movements($stockId)
    : get_user_stock_movements(current_user_id() ?? '');

$stocksById = array_column(get_user_stocks(current_user_id() ?? ''), null, 'id');

$typeLabels = [
    'in' => ['label' => 'Stock In', 'class' => 'badge-available'],
    'out' => ['label' => 'Stock Out', 'class' => 'badge-sold-out'],
    'adjustment' => ['label' => 'Adjustment', 'class' => 'badge-archived'],
];

include __DIR__ . '/../../includes/header.php';
?>
<h2>Inventory History</h2>
<?php if ($stock): ?>
    <p class="muted">Movement history for <strong><?php echo htmlspecialchars($stock['variety']); ?></strong>.
        <a href="<?php echo htmlspecialchars(url('inventory/history/')); ?>">View all items</a>
    </p>
<?php else: ?>
    <p class="muted">All stock movements across your inventory.</p>
<?php endif; ?>
<?php if (empty($movements)): ?>
    <div class="card">
        <p>No stock movements recorded yet. Stock In, Stock Out, and Adjustments will show up here.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <?php if (!$stock): ?><th>Item</th><?php endif; ?>
                    <th>Batch</th>
                    <th>Type</th>
                    <th>Change</th>
                    <th>Before → After</th>
                    <th>Reason</th>
                    <th>Linked Order</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movements as $m): ?>
                    <?php
                        $type = $typeLabels[$m['type']] ?? ['label' => $m['type'], 'class' => 'badge-available'];
                        $itemName = $stocksById[$m['stockId']]['variety'] ?? '—';
                        $unit = $stocksById[$m['stockId']]['unit'] ?? '';
                        $sign = (float) $m['quantityChange'] > 0 ? '+' : '';
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($m['createdAt']); ?></td>
                        <?php if (!$stock): ?>
                            <td><a href="<?php echo htmlspecialchars(url('inventory/history/?stockId=' . urlencode($m['stockId']))); ?>"><?php echo htmlspecialchars($itemName); ?></a></td>
                        <?php endif; ?>
                        <td><?php echo !empty($m['batchNumber']) ? '<a href="' . htmlspecialchars(url('transparency/batch-timeline/?batch=' . urlencode($m['batchNumber']))) . '">' . htmlspecialchars($m['batchNumber']) . '</a>' : '—'; ?></td>
                        <td><span class="badge <?php echo htmlspecialchars($type['class']); ?>"><?php echo htmlspecialchars($type['label']); ?></span></td>
                        <td><?php echo htmlspecialchars($sign . $m['quantityChange']); ?> <?php echo htmlspecialchars($unit); ?></td>
                        <td><?php echo htmlspecialchars($m['quantityBefore']); ?> → <?php echo htmlspecialchars($m['quantityAfter']); ?></td>
                        <td><?php echo htmlspecialchars($m['reason'] ?? '—'); ?></td>
                        <td>
                            <?php if (!empty($m['procurementOrderId'])): ?>
                                <a href="<?php echo htmlspecialchars(url('procurement/tracking/?id=' . urlencode($m['procurementOrderId']))); ?>"><?php echo htmlspecialchars($m['procurementOrderId']); ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
