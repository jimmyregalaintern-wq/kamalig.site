<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$title = 'Compare Stocks';
$activePage = 'marketplace';
include __DIR__ . '/../../includes/header.php';

$maxCompare = 4;
$ids = [];
if (!empty($_GET['ids'])) {
    $ids = array_slice(array_unique(array_filter(array_map('trim', explode(',', $_GET['ids'])))), 0, $maxCompare);
}

$stocks = get_stocks();
// Preserve the requested order (array_filter over $stocks would instead
// follow the DB's order) so columns stay where the user put them, and so
// a Remove link that drops one id keeps the rest stable.
$byId = array_column($stocks, null, 'id');
$compare = array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));

$statusLabels = [
    'available' => ['label' => 'Available', 'class' => 'badge-available'],
    'sold_out' => ['label' => 'Sold out', 'class' => 'badge-sold-out'],
    'archived' => ['label' => 'Archived', 'class' => 'badge-archived'],
];
?>
<h2>Compare Stocks</h2>
<p class="muted">Side-by-side comparison of selected stocks (up to <?php echo (int) $maxCompare; ?> at a time).</p>
<?php if (empty($compare)): ?>
    <div class="card">
        <p>No stocks selected for comparison.</p>
        <a href="<?php echo htmlspecialchars(url('marketplace/')); ?>" class="btn btn-secondary">Back to Marketplace</a>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Property</th>
                    <?php foreach ($compare as $stock): ?>
                        <th><a href="<?php echo htmlspecialchars(url('marketplace/view/?id=' . urlencode($stock['id']))); ?>"><?php echo htmlspecialchars($stock['variety']); ?></a></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Photo</td>
                    <?php foreach ($compare as $stock): ?>
                        <td><img class="stock-thumb" src="<?php echo htmlspecialchars(stock_image_src($stock['imageUrl'] ?? null)); ?>" alt="<?php echo htmlspecialchars($stock['variety']); ?>"></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Status</td>
                    <?php foreach ($compare as $stock):
                        $status = $statusLabels[$stock['status']] ?? ['label' => $stock['status'], 'class' => 'badge-available'];
                    ?>
                        <td><span class="badge <?php echo htmlspecialchars($status['class']); ?>"><?php echo htmlspecialchars($status['label']); ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <?php foreach ([
                    ['label' => 'Supplier', 'key' => 'supplierName'],
                    ['label' => 'Source', 'key' => 'source'],
                    ['label' => 'Country', 'key' => 'countryOfOrigin'],
                    ['label' => 'Quantity', 'key' => 'quantity'],
                    ['label' => 'Unit', 'key' => 'unit'],
                    ['label' => 'Description', 'key' => 'description'],
                ] as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['label']); ?></td>
                        <?php foreach ($compare as $stock): ?>
                            <td><?php echo htmlspecialchars($stock[$row['key']]); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td>Price</td>
                    <?php foreach ($compare as $stock): ?>
                        <td>₱<?php echo htmlspecialchars($stock['unitPrice']); ?>/<?php echo htmlspecialchars($stock['unit']); ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>
                        Price/kg
                        <span class="muted" style="font-weight:normal;">(normalized)</span>
                    </td>
                    <?php foreach ($compare as $stock): ?>
                        <?php // BUGFIX: this row previously repeated the raw
                              // unitPrice under a "Price/kg" label with no unit
                              // conversion — comparing a ₱/ton listing against a
                              // ₱/sack listing side by side made the ton listing
                              // look ~47x pricier than it actually is per kg,
                              // inverting the exact comparison this page exists
                              // for. price_per_kg() is the same normalization
                              // Marketplace and the listing detail page already
                              // use for this reason. ?>
                        <td>₱<?php echo htmlspecialchars(number_format(price_per_kg((float) $stock['unitPrice'], $stock['unit']), 2)); ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td></td>
                    <?php foreach ($compare as $stock): ?>
                        <td><a href="<?php echo htmlspecialchars(url('marketplace/compare/?ids=' . implode(',', array_values(array_diff($ids, [$stock['id']]))))); ?>" class="btn btn-secondary btn-sm">Remove</a></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
    <div class="card">
        <a href="<?php echo htmlspecialchars(url('marketplace/?ids=' . implode(',', $ids))); ?>" class="btn btn-secondary">Back to Marketplace</a>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
