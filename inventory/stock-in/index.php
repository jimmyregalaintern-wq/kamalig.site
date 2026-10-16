<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('inventory', ['farmer', 'importer', 'supplier']);

$title = 'Stock In';
$activePage = 'inventory';

$stockId = trim($_GET['stockId'] ?? $_POST['stockId'] ?? '');
$stock = $stockId !== '' ? get_stock_by_id($stockId) : null;

// Only the owner can log movements against their own listing.
if (!$stock || $stock['userId'] !== current_user_id()) {
    include __DIR__ . '/../../includes/header.php';
    echo '<h2>Listing not found</h2><p>This stock listing does not exist or is not yours.</p>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

// A reason is now required for every Stock In (same idea as Stock Out).
// "Recount correction" replaces the old Adjust screen: it is stored as an
// 'adjustment' movement so it is not counted as a real delivery in history.
const STOCK_IN_REASON_CATEGORIES = [
    'harvest' => 'Fresh harvest',
    'purchase' => 'Purchased / restock from supplier',
    'return' => 'Return from buyer',
    'internal_transfer' => 'Internal transfer in',
    'recount' => 'Recount correction (found more than recorded)',
    'other' => 'Other',
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $quantity = trim($_POST['quantity'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $reasonCategory = $_POST['reasonCategory'] ?? '';

    if ($quantity === '' || !is_numeric($quantity) || (float) $quantity <= 0) {
        $error = 'Enter a quantity greater than zero.';
    } elseif (!array_key_exists($reasonCategory, STOCK_IN_REASON_CATEGORIES)) {
        $error = 'Choose a reason for this stock-in.';
    } elseif (in_array($reasonCategory, ['recount', 'other'], true) && $reason === '') {
        $error = 'Add a short note explaining this stock-in.';
    } else {
        $combinedReason = STOCK_IN_REASON_CATEGORIES[$reasonCategory] . ($reason !== '' ? (': ' . $reason) : '');
        $movementType = $reasonCategory === 'recount' ? 'adjustment' : 'in';
        // Stored category sticks to values the database already holds (the exact
        // label is kept in the reason text), so no schema change is needed.
        $storedCategory = ['recount' => 'adjustment', 'internal_transfer' => 'internal_transfer'][$reasonCategory] ?? 'other';
        $result = record_stock_movement($stockId, $movementType, (float) $quantity, $combinedReason, $storedCategory);
        if (!$result['success']) {
            // e.g. the warehouse this listing sits in would go over capacity.
            $error = $result['error'];
        } else {
            header('Location: ' . url('inventory/'));
            exit;
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<h2>Stock In</h2>
<p class="muted">Log rice received into <strong><?php echo htmlspecialchars($stock['variety']); ?></strong> (currently <?php echo htmlspecialchars($stock['quantity']); ?> <?php echo htmlspecialchars($stock['unit']); ?>).</p>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="stockId" value="<?php echo htmlspecialchars($stockId); ?>">
    <div class="form-group">
        <label for="quantity">Quantity Received (<?php echo htmlspecialchars($stock['unit']); ?>)</label>
        <input id="quantity" name="quantity" type="number" step="0.01" min="0.01" value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>" required autofocus>
    </div>
    <div class="form-group">
        <label for="reasonCategory">Reason</label>
        <select id="reasonCategory" name="reasonCategory" required>
            <option value="">— Choose a reason —</option>
            <?php foreach (STOCK_IN_REASON_CATEGORIES as $key => $label): ?>
                <option value="<?php echo htmlspecialchars($key); ?>"<?php echo (($_POST['reasonCategory'] ?? '') === $key) ? ' selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="reason">Notes (required for Recount correction and Other)</label>
        <input id="reason" name="reason" type="text" value="<?php echo htmlspecialchars($_POST['reason'] ?? ''); ?>" placeholder="e.g. Supplier name, harvest lot, or what the recount found">
    </div>
    <button class="btn btn-primary" type="submit">Log Stock In</button>
    <a href="<?php echo htmlspecialchars(url('inventory/')); ?>" class="btn btn-secondary">Cancel</a>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
