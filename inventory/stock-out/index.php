<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('inventory', ['farmer', 'importer', 'supplier']);

$title = 'Stock Out';
$activePage = 'inventory';

$stockId = trim($_GET['stockId'] ?? $_POST['stockId'] ?? '');
$stock = $stockId !== '' ? get_stock_by_id($stockId) : null;

if (!$stock || $stock['userId'] !== current_user_id()) {
    include __DIR__ . '/../../includes/header.php';
    echo '<h2>Listing not found</h2><p>This stock listing does not exist or is not yours.</p>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$error = '';

// Predefined categories (Section 4.4) — when "Procurement Order" is
// picked, offer the specific orders sourced from this exact stock that
// haven't shipped yet, so this manual stock-out can be traced to a real
// order rather than just a free-text note. (The normal path for
// fulfilling an order is Dispatch on the Procurement Tracking page, which
// links the movement automatically — this covers manual/off-system
// corrections instead.)
const STOCK_OUT_REASON_CATEGORIES = [
    'procurement_order' => 'Procurement Order',
    'marketplace_sale' => 'Marketplace Sale (outside the system)',
    'internal_transfer' => 'Internal Transfer',
    'damaged' => 'Damaged',
    'spoilage' => 'Spoilage',
    'sample' => 'Sample',
    'recount' => 'Recount correction (found less than recorded)',
    'other' => 'Other',
];
$linkableOrders = array_values(array_filter(
    get_user_procurement_orders(current_user_id() ?? ''),
    fn($o) => $o['stockId'] === $stockId && in_array($o['status'], ['ordered', 'in_transit'], true)
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $quantity = trim($_POST['quantity'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $reasonCategory = $_POST['reasonCategory'] ?? '';
    $linkedOrderId = $reasonCategory === 'procurement_order' ? trim($_POST['procurementOrderId'] ?? '') : null;

    if ($quantity === '' || !is_numeric($quantity) || (float) $quantity <= 0) {
        $error = 'Enter a quantity greater than zero.';
    } elseif (!array_key_exists($reasonCategory, STOCK_OUT_REASON_CATEGORIES)) {
        $error = 'Choose a reason for this stock-out.';
    } elseif (in_array($reasonCategory, ['recount', 'other'], true) && $reason === '') {
        $error = 'Add a short note explaining this stock-out.';
    } else {
        // A category alone ("Procurement Order") isn't very informative on
        // its own in the movement history — fold it into the free-text
        // reason too, in addition to being stored structured.
        $combinedReason = STOCK_OUT_REASON_CATEGORIES[$reasonCategory] . ($reason !== '' ? (': ' . $reason) : '');
        // A recount correction replaces the old Adjust screen and is stored as
        // an 'adjustment' movement so reports can tell it apart from real sales.
        $movementType = $reasonCategory === 'recount' ? 'adjustment' : 'out';
        $storedCategory = $reasonCategory === 'recount' ? 'adjustment' : $reasonCategory;
        $result = record_stock_movement($stockId, $movementType, -(float) $quantity, $combinedReason, $storedCategory, $linkedOrderId ?: null);
        if (!$result['success']) {
            $error = $result['error'];
        } else {
            if (!empty($result['warning'])) {
                $_SESSION['flash_error'] = $result['warning'];
            }
            header('Location: ' . url('inventory/'));
            exit;
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<h2>Stock Out</h2>
<p class="muted">Log rice removed from <strong><?php echo htmlspecialchars($stock['variety']); ?></strong> (currently <?php echo htmlspecialchars($stock['quantity']); ?> <?php echo htmlspecialchars($stock['unit']); ?>, <?php echo htmlspecialchars(stock_available_quantity($stock)); ?> available).</p>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="stockId" value="<?php echo htmlspecialchars($stockId); ?>">
    <div class="form-group">
        <label for="quantity">Quantity Removed (<?php echo htmlspecialchars($stock['unit']); ?>)</label>
        <input id="quantity" name="quantity" type="number" step="0.01" min="0.01" max="<?php echo htmlspecialchars($stock['quantity']); ?>" required autofocus>
    </div>
    <div class="form-group">
        <label for="reasonCategory">Reason</label>
        <select id="reasonCategory" name="reasonCategory" required onchange="document.getElementById('linkedOrderGroup').style.display = this.value === 'procurement_order' ? '' : 'none';">
            <option value="">— Choose a reason —</option>
            <?php foreach (STOCK_OUT_REASON_CATEGORIES as $key => $label): ?>
                <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" id="linkedOrderGroup" style="display:none;">
        <label for="procurementOrderId">Linked order</label>
        <?php if (empty($linkableOrders)): ?>
            <p class="muted">No open orders (not yet shipped) are sourced from this listing.</p>
        <?php else: ?>
            <select id="procurementOrderId" name="procurementOrderId">
                <option value="">— None / not tracked in the system —</option>
                <?php foreach ($linkableOrders as $o): ?>
                    <option value="<?php echo htmlspecialchars($o['id']); ?>">
                        <?php echo htmlspecialchars($o['id']); ?> — <?php echo htmlspecialchars($o['quantity'] . ' ' . $o['unit']); ?> to <?php echo htmlspecialchars($o['requesterName']); ?> (Batch <?php echo htmlspecialchars($o['batchNumber'] ?? '—'); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <p class="muted" style="margin-top: 4px;">Normally you'd fulfill an order via Dispatch on its Procurement Tracking page instead — use this only for a correction or an order handled outside the system.</p>
    </div>
    <div class="form-group">
        <label for="reason">Notes (required for Recount correction and Other)</label>
        <input id="reason" name="reason" type="text" placeholder="Additional detail">
    </div>
    <button class="btn btn-primary" type="submit">Log Stock Out</button>
    <a href="<?php echo htmlspecialchars(url('inventory/')); ?>" class="btn btn-secondary">Cancel</a>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
