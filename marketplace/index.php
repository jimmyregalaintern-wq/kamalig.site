<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_auth();

$title = 'Marketplace';
$activePage = 'marketplace';
include __DIR__ . '/../includes/header.php';

$stocks = get_stocks();
$varieties = get_varieties();
$sourceFilter = get_filter_value('source', 'all');
$varietyFilter = get_filter_value('variety', '');
$keyword = get_filter_value('q', '');
// Blank means "no bound" — previously this defaulted to 0-1000, which
// silently hid every listing priced above ₱1000/unit unless a buyer
// happened to widen Price Max themselves.
$minPriceRaw = get_filter_value('priceMin', '');
$maxPriceRaw = get_filter_value('priceMax', '');
$minPrice = $minPriceRaw !== '' ? (float) $minPriceRaw : null;
$maxPrice = $maxPriceRaw !== '' ? (float) $maxPriceRaw : null;
$sortBy = get_filter_value('sort', 'newest');
// Cap and dedupe: an unbounded comparison list makes the compare table
// (one column per item) unusable, and duplicates can creep in if a user
// edits the URL by hand.
$maxCompare = 4;
$comparisonIds = array_slice(array_unique(array_filter(array_map('trim', explode(',', get_filter_value('ids', ''))))), 0, $maxCompare);

$filtered = array_filter($stocks, function ($stock) use ($sourceFilter, $varietyFilter, $keyword, $minPrice, $maxPrice) {
    if ($stock['status'] !== 'available') {
        return false;
    }
    // A listing can still say status='available' while every unit of it is
    // already reserved for other accepted orders (Phase 2: Reserved
    // Inventory) — status only flips to 'sold_out' once the *physical*
    // quantity hits zero, which reservation alone doesn't do. Hide it from
    // new buyers either way; the owner still sees it in their own
    // Inventory with a Reserved figure.
    if (stock_available_quantity($stock) <= 0) {
        return false;
    }
    if ($sourceFilter !== 'all' && $stock['source'] !== $sourceFilter) {
        return false;
    }
    if ($varietyFilter !== '' && $stock['variety'] !== $varietyFilter) {
        return false;
    }
    if ($minPrice !== null && $stock['unitPrice'] < $minPrice) {
        return false;
    }
    if ($maxPrice !== null && $stock['unitPrice'] > $maxPrice) {
        return false;
    }
    if ($keyword !== '') {
        $haystack = $stock['variety'] . ' ' . ($stock['description'] ?? '') . ' ' . ($stock['supplierName'] ?? '');
        if (stripos($haystack, $keyword) === false) {
            return false;
        }
    }
    return true;
});

usort($filtered, function ($a, $b) use ($sortBy) {
    switch ($sortBy) {
        case 'price_asc':
            return $a['unitPrice'] <=> $b['unitPrice'];
        case 'price_desc':
            return $b['unitPrice'] <=> $a['unitPrice'];
        case 'quantity_asc':
            return $a['quantity'] <=> $b['quantity'];
        case 'quantity_desc':
            return $b['quantity'] <=> $a['quantity'];
        default:
            return strtotime($b['createdAt']) <=> strtotime($a['createdAt']);
    }
});
?>
<h2>Marketplace</h2>
<p class="muted">Browse available rice stocks.</p>
<?php commerce_flash_messages(); ?>
<?php if (commerce_ready() && in_array(user_role(), permission_allowed_roles('procurement.requests.create', ['supplier']), true)): ?>
    <p><a class="btn btn-secondary cart-link" href="<?php echo htmlspecialchars(url('cart/')); ?>">View cart (<?php echo (int) cart_count(current_user_id() ?? ''); ?>)</a></p>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="card" style="border-color: var(--border-danger, #c0392b); color: var(--text-danger, #c0392b);">
        <?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
    </div>
<?php endif; ?>
<form method="get" class="card">
    <?php // Preserve the in-progress comparison selection across filter/sort
          // changes — without this hidden field, applying a filter silently
          // wipes out whatever the user had already added to Compare. ?>
    <input type="hidden" name="ids" value="<?php echo htmlspecialchars(implode(',', $comparisonIds)); ?>">
    <div class="form-group">
        <label for="q">Search</label>
        <input id="q" name="q" type="text" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="Search by variety, description, or supplier">
    </div>
    <div class="grid grid-3">
        <div>
            <label>Source</label>
            <select name="source">
                <option value="all" <?php echo $sourceFilter === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="local" <?php echo $sourceFilter === 'local' ? 'selected' : ''; ?>>Local</option>
                <option value="imported" <?php echo $sourceFilter === 'imported' ? 'selected' : ''; ?>>Imported</option>
            </select>
        </div>
        <div>
            <label>Variety</label>
            <select name="variety">
                <option value="">All varieties</option>
                <?php foreach ($varieties as $variety): ?>
                    <option value="<?php echo htmlspecialchars($variety); ?>" <?php echo $varietyFilter === $variety ? 'selected' : ''; ?>><?php echo htmlspecialchars($variety); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Sort</label>
            <select name="sort">
                <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest</option>
                <option value="price_asc" <?php echo $sortBy === 'price_asc' ? 'selected' : ''; ?>>Price Low to High</option>
                <option value="price_desc" <?php echo $sortBy === 'price_desc' ? 'selected' : ''; ?>>Price High to Low</option>
                <option value="quantity_asc" <?php echo $sortBy === 'quantity_asc' ? 'selected' : ''; ?>>Quantity Low to High</option>
                <option value="quantity_desc" <?php echo $sortBy === 'quantity_desc' ? 'selected' : ''; ?>>Quantity High to Low</option>
            </select>
        </div>
    </div>
    <div class="grid grid-2" style="margin-top: 16px;">
        <div>
            <label>Price Min</label>
            <input name="priceMin" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars($minPriceRaw); ?>" placeholder="No minimum">
        </div>
        <div>
            <label>Price Max</label>
            <input name="priceMax" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars($maxPriceRaw); ?>" placeholder="No maximum">
        </div>
    </div>
    <button class="btn btn-primary" type="submit">Apply Filters</button>
    <a href="<?php echo htmlspecialchars(url('marketplace/')); ?>" class="btn btn-secondary">Clear Filters</a>
</form>
    <?php if (count($comparisonIds) > 0): ?>
    <div class="card">
            <a href="<?php echo htmlspecialchars(url('marketplace/compare/?ids=' . implode(',', $comparisonIds))); ?>" class="btn btn-secondary">Compare (<?php echo count($comparisonIds); ?>)</a>
    </div>
<?php endif; ?>
<?php if (empty($filtered)): ?>
    <div class="card">
        <p>No stocks found.</p>
    </div>
<?php else: ?>
    <div class="grid grid-2">
        <?php foreach ($filtered as $stock):
            $isComparing = in_array($stock['id'], $comparisonIds, true);
            $atCap = count($comparisonIds) >= $maxCompare;
            $isOwnListing = $stock['userId'] === (current_user_id() ?? '');
        ?>
            <div class="card">
                <a href="<?php echo htmlspecialchars(url('marketplace/view/?id=' . urlencode($stock['id']))); ?>">
                    <img class="stock-photo" src="<?php echo htmlspecialchars(stock_image_src($stock['imageUrl'] ?? null)); ?>" alt="<?php echo htmlspecialchars($stock['variety']); ?>">
                </a>
                <?php if ($isOwnListing): ?>
                    <p><span class="badge badge-info">Your listing</span></p>
                <?php endif; ?>
                <h3><a href="<?php echo htmlspecialchars(url('marketplace/view/?id=' . urlencode($stock['id']))); ?>"><?php echo htmlspecialchars($stock['variety']); ?></a></h3>
                <p><?php echo htmlspecialchars($stock['description']); ?></p>
                <p><strong>Supplier:</strong> <?php echo htmlspecialchars($stock['supplierName'] ?: 'Not provided'); ?>
                    <?php if (is_verified_supplier($stock['userId'])): ?>
                        <span class="badge badge-available" title="At least one identity document has been admin-verified">✓ Verified</span>
                    <?php endif; ?>
                </p>
                <p><strong>Price:</strong> ₱<?php echo htmlspecialchars($stock['unitPrice']); ?>/<?php echo htmlspecialchars($stock['unit']); ?> <span class="muted">(₱<?php echo htmlspecialchars(number_format(price_per_kg((float) $stock['unitPrice'], $stock['unit']), 2)); ?>/kg)</span></p>
                <p><strong>Quantity:</strong> <?php echo htmlspecialchars(stock_available_quantity($stock)); ?> <?php echo htmlspecialchars($stock['unit']); ?> available</p>
                <a href="<?php echo htmlspecialchars(url('marketplace/view/?id=' . urlencode($stock['id']))); ?>" class="btn btn-secondary">View Details</a>
                <?php if ($isComparing): ?>
                    <?php // Already selected — offer Remove instead of a no-op "Add" that
                          // looks broken when clicked again. ?>
                    <a href="<?php echo htmlspecialchars(url('marketplace/?' . http_build_query(array_merge($_GET, ['ids' => implode(',', array_values(array_diff($comparisonIds, [$stock['id']])))])))); ?>" class="btn btn-secondary" aria-label="Remove <?php echo htmlspecialchars($stock['variety']); ?> from comparison">✓ Added — Remove</a>
                <?php elseif ($atCap): ?>
                    <button class="btn btn-secondary" type="button" disabled title="You can compare up to <?php echo (int) $maxCompare; ?> items at a time">Add to Compare (max <?php echo (int) $maxCompare; ?>)</button>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars(url('marketplace/compare/?ids=' . implode(',', array_unique(array_merge($comparisonIds, [$stock['id']]))))); ?>" class="btn btn-secondary">Add to Compare</a>
                <?php endif; ?>
                <?php // Only roles the `permissions` table currently allows for
                      // 'procurement.requests.create' can send procurement requests
                      // (server-enforced in procurement/requests/create/) — this used
                      // to hardcode role === 'supplier', so an admin granting/revoking
                      // this permission from admin/roles/ had no effect on whether the
                      // button appeared, only on whether clicking it 403'd. ?>
                <?php if (in_array(user_role(), permission_allowed_roles('procurement.requests.create', ['supplier']), true) && !$isOwnListing): ?>
                    <?php if (commerce_ready()): ?>
                        <form method="post" action="<?php echo htmlspecialchars(url('cart/add/')); ?>" class="add-cart-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="stockId" value="<?php echo htmlspecialchars($stock['id']); ?>">
                            <input type="hidden" name="quantity" value="1">
                            <input type="hidden" name="back" value="<?php echo htmlspecialchars('marketplace/' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')); ?>">
                            <button class="btn btn-primary" type="submit">Add to Cart</button>
                        </form>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars(url('procurement/requests/create/?stockId=' . urlencode($stock['id']))); ?>" class="btn btn-secondary">Buy Now</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
