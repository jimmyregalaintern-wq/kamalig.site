<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$stockId = trim($_GET['id'] ?? '');
$stock = $stockId !== '' ? get_stock_by_id($stockId) : null;
$userId = current_user_id() ?? '';
$role = user_role();
$isOwnListing = $stock && $stock['userId'] === $userId;

// Non-owners (and non-admins) only ever get to see listings that are
// actually 'available' — the same visibility rule the Marketplace grid
// already applies. A sold-out/archived listing isn't "not found", it's
// just not for sale, but there's no reason to expose that distinction (or
// let someone probe stockIds) to a browsing buyer who isn't the owner.
$canView = $stock && ($stock['status'] === 'available' || $isOwnListing || $role === 'admin');

if (!$canView) {
    http_response_code(404);
    $title = 'Listing not found';
    $activePage = 'marketplace';
    include __DIR__ . '/../../includes/header.php';
    echo '<h2>Listing not found</h2><p>This stock listing does not exist or is no longer available.</p>';
    echo '<a href="' . htmlspecialchars(url('marketplace/')) . '" class="btn btn-secondary">Back to Marketplace</a>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$title = htmlspecialchars($stock['variety']) . ' — Marketplace';
$activePage = 'marketplace';
include __DIR__ . '/../../includes/header.php';
commerce_flash_messages();

$statusLabels = [
    'available' => ['label' => 'Available', 'class' => 'badge-available'],
    'sold_out' => ['label' => 'Sold out', 'class' => 'badge-sold-out'],
    'archived' => ['label' => 'Archived', 'class' => 'badge-archived'],
];
$status = $statusLabels[$stock['status']] ?? ['label' => $stock['status'], 'class' => 'badge-info'];
?>
<a href="<?php echo htmlspecialchars(url('marketplace/')); ?>" class="muted">&larr; Back to Marketplace</a>
<div class="grid grid-2" style="margin-top: 12px; align-items: start;">
    <div>
        <img class="stock-photo-lg" src="<?php echo htmlspecialchars(stock_image_src($stock['imageUrl'] ?? null)); ?>" alt="<?php echo htmlspecialchars($stock['variety']); ?>">
    </div>
    <div class="card">
        <h2 style="margin-top: 0;"><?php echo htmlspecialchars($stock['variety']); ?></h2>
        <p>
            <span class="badge <?php echo $stock['source'] === 'imported' ? 'badge-info' : 'badge-available'; ?>"><?php echo $stock['source'] === 'imported' ? 'Imported' : 'Local'; ?></span>
            <?php if ($isOwnListing || $role === 'admin'): ?>
                <span class="badge <?php echo htmlspecialchars($status['class']); ?>"><?php echo htmlspecialchars($status['label']); ?></span>
            <?php endif; ?>
            <?php if ($isOwnListing): ?>
                <span class="badge badge-info">Your listing</span>
            <?php endif; ?>
        </p>
        <p style="font-size: 1.4rem; font-weight: 700;">₱<?php echo htmlspecialchars($stock['unitPrice']); ?> / <?php echo htmlspecialchars($stock['unit']); ?></p>
        <p class="muted">₱<?php echo htmlspecialchars(number_format(price_per_kg((float) $stock['unitPrice'], $stock['unit']), 2)); ?> / kg</p>
        <p><strong>Quantity available:</strong> <?php echo htmlspecialchars(stock_available_quantity($stock)); ?> <?php echo htmlspecialchars($stock['unit']); ?>
            <?php if ((float) $stock['reservedQuantity'] > 0): ?>
                <span class="muted">(<?php echo htmlspecialchars($stock['reservedQuantity']); ?> reserved for other orders)</span>
            <?php endif; ?>
        </p>
        <p><strong>Supplier:</strong> <?php echo htmlspecialchars($stock['supplierName'] ?: 'Not provided'); ?>
            <?php if (is_verified_supplier($stock['userId'])): ?>
                <span class="badge badge-available" title="At least one identity document has been admin-verified">✓ Verified</span>
            <?php endif; ?>
        </p>
        <?php if ($stock['source'] === 'imported' && !empty($stock['countryOfOrigin'])): ?>
            <p><strong>Country of origin:</strong> <?php echo htmlspecialchars($stock['countryOfOrigin']); ?></p>
        <?php endif; ?>
        <p><strong>Batch / Lot:</strong> <?php echo htmlspecialchars($stock['batchNumber'] ?? 'Not recorded'); ?></p>
        <p><strong>Listed:</strong> <?php echo htmlspecialchars($stock['createdAt']); ?></p>
        <?php if (!empty($stock['description'])): ?>
            <p><strong>Description</strong></p>
            <p><?php echo nl2br(htmlspecialchars($stock['description'])); ?></p>
        <?php endif; ?>

        <div style="margin-top: 16px;">
            <?php if ($isOwnListing): ?>
                <a href="<?php echo htmlspecialchars(url('inventory/edit/?id=' . urlencode($stock['id']))); ?>" class="btn btn-primary">Edit Listing</a>
                <a href="<?php echo htmlspecialchars(url('inventory/')); ?>" class="btn btn-secondary">Back to Inventory</a>
            <?php else: ?>
                <?php // Only roles the `permissions` table currently allows for
                      // 'procurement.requests.create' can send procurement requests
                      // (server-enforced in procurement/requests/create/) — reads the
                      // same dynamic table admin/roles/ edits, instead of hardcoding
                      // role === 'supplier'. ?>
                <?php if (in_array($role, permission_allowed_roles('procurement.requests.create', ['supplier']), true) && $stock['status'] === 'available' && stock_available_quantity($stock) > 0): ?>
                    <?php if (commerce_ready()): ?>
                        <form method="post" action="<?php echo htmlspecialchars(url('cart/add/')); ?>" class="add-cart-form" style="margin-bottom: 10px;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="stockId" value="<?php echo htmlspecialchars($stock['id']); ?>">
                            <input type="hidden" name="back" value="<?php echo htmlspecialchars('marketplace/view/?id=' . urlencode($stock['id'])); ?>">
                            <label class="muted" for="addQty">Quantity (<?php echo htmlspecialchars($stock['unit']); ?>)</label>
                            <input id="addQty" class="add-cart-qty" type="number" name="quantity" min="0.01" step="0.01" max="<?php echo htmlspecialchars((string) stock_available_quantity($stock)); ?>" value="1" required>
                            <button class="btn btn-primary" type="submit">Add to Cart</button>
                            <button class="btn btn-secondary" type="submit" name="buyNow" value="1">Buy Now</button>
                        </form>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars(url('procurement/requests/create/?stockId=' . urlencode($stock['id']))); ?>" class="btn btn-primary">Request Stock</a>
                    <?php endif; ?>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars(url('marketplace/compare/?ids=' . urlencode($stock['id']))); ?>" class="btn btn-secondary">Add to Compare</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
