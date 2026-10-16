<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory', ['farmer', 'importer', 'supplier']);

$title = 'Inventory';
$activePage = 'inventory';
include __DIR__ . '/../includes/header.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['stockId']) && !empty($_POST['action'])) {
    csrf_verify();
    $stock = get_stock_by_id($_POST['stockId']);

    // Only the owner can archive/restore their own listing.
    if ($stock && $stock['userId'] === current_user_id()) {
        if ($_POST['action'] === 'archive') {
            archive_stock($stock['id']);
            $message = 'Listing archived.';
        } elseif ($_POST['action'] === 'restore') {
            // Restoring brings it back to available unless it's sitting at
            // zero quantity, in which case sold_out is still the honest state.
            $restoredStatus = (float) $stock['quantity'] > 0 ? 'available' : 'sold_out';
            update_stock($stock['id'], ['status' => $restoredStatus]);
            log_activity(current_user_id(), 'stock.restore', 'stock', $stock['id']);
            $message = 'Listing restored.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'bulk_archive' && !empty($_POST['stockIds'])) {
    // Bulk "delete": these listings are archived (soft-deleted), never hard
    // -deleted — a listing can be the stockId behind past procurement
    // requests/orders and price/movement history, so removing the row
    // outright would break that traceability. Archive is the safe
    // equivalent of delete here, and it's reversible via Restore.
    csrf_verify();
    $archivedCount = 0;
    foreach ((array) $_POST['stockIds'] as $stockId) {
        $stock = get_stock_by_id((string) $stockId);
        if ($stock && $stock['userId'] === current_user_id() && $stock['status'] !== 'archived') {
            archive_stock($stock['id']);
            $archivedCount++;
        }
    }
    $message = $archivedCount > 0
        ? $archivedCount . ' listing' . ($archivedCount === 1 ? '' : 's') . ' archived.'
        : 'No listings were archived.';
}

$stocks = get_user_stocks(current_user_id() ?? '');
$warehousesById = array_column(get_user_warehouses(current_user_id() ?? ''), null, 'id');

// Consistent filter bar (Section 11: "same search/filter UI pattern across
// Inventory, Procurement, Documents, and Transparency" — this page
// previously had none at all, unlike Marketplace/Availability/Stock
// Movement, which already established the pattern this follows).
$searchFilter = trim(get_filter_value('search', ''));
$statusFilter = get_filter_value('status', 'all');
$warehouseFilter = get_filter_value('warehouseId', 'all');

$stocks = array_values(array_filter($stocks, function ($stock) use ($searchFilter, $statusFilter, $warehouseFilter) {
    if ($searchFilter !== '' && stripos($stock['variety'], $searchFilter) === false && stripos($stock['batchNumber'] ?? '', $searchFilter) === false) {
        return false;
    }
    if ($statusFilter !== 'all' && $stock['status'] !== $statusFilter) {
        return false;
    }
    if ($warehouseFilter === 'none' && !empty($stock['warehouseId'])) {
        return false;
    }
    if ($warehouseFilter !== 'all' && $warehouseFilter !== 'none' && ($stock['warehouseId'] ?? null) !== $warehouseFilter) {
        return false;
    }
    return true;
}));

$statusLabels = [
    'available' => ['label' => 'Available', 'class' => 'badge-available'],
    'sold_out' => ['label' => 'Sold out', 'class' => 'badge-sold-out'],
    'archived' => ['label' => 'Archived', 'class' => 'badge-archived'],
];
?>
<h2>Inventory</h2>
<p class="muted">Manage your rice stock listings.</p>
<div class="card">
    <p class="muted">Manage your stock listings and add new rice inventory.</p>
    <a href="<?php echo htmlspecialchars(url('inventory/add/')); ?>" class="btn btn-primary">Add Stock</a>
    <a href="<?php echo htmlspecialchars(url('inventory/import/')); ?>" class="btn btn-secondary">Bulk Import</a>
    <a href="<?php echo htmlspecialchars(url('inventory/history/')); ?>" class="btn btn-secondary">Inventory History</a>
</div>
<form method="get" class="card">
    <div class="grid grid-3">
        <div class="form-group">
            <label for="search">Search</label>
            <input id="search" name="search" type="text" value="<?php echo htmlspecialchars($searchFilter); ?>" placeholder="Variety or batch number">
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                <?php foreach ($statusLabels as $key => $info): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $statusFilter === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($info['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="warehouseId">Warehouse</label>
            <select id="warehouseId" name="warehouseId">
                <option value="all" <?php echo $warehouseFilter === 'all' ? 'selected' : ''; ?>>All Warehouses</option>
                <option value="none" <?php echo $warehouseFilter === 'none' ? 'selected' : ''; ?>>Unassigned</option>
                <?php foreach ($warehousesById as $w): ?>
                    <option value="<?php echo htmlspecialchars($w['id']); ?>" <?php echo $warehouseFilter === $w['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($w['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <button class="btn btn-primary" type="submit">Apply Filters</button>
    <a href="<?php echo htmlspecialchars(url('inventory/')); ?>" class="btn btn-secondary">Clear Filters</a>
</form>
<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>
<?php $hasActiveFilters = $searchFilter !== '' || $statusFilter !== 'all' || $warehouseFilter !== 'all'; ?>
<?php if (empty($stocks)): ?>
    <div class="card kg-empty-state">
        <?php if ($hasActiveFilters): ?>
            <p class="kg-empty-state__title">No listings match your filters</p>
            <p class="muted">Try clearing a filter or searching a different term.</p>
            <a href="<?php echo htmlspecialchars(url('inventory/')); ?>" class="btn btn-secondary">Clear Filters</a>
        <?php else: ?>
            <p class="kg-empty-state__title">No inventory yet</p>
            <p class="muted">Add your first stock listing to start selling or tracking your rice.</p>
            <a href="<?php echo htmlspecialchars(url('inventory/add/')); ?>" class="btn btn-primary">Add Stock</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <form method="post" id="bulk-archive-form" data-bulk-ids-for="inventory-table">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="bulk_archive">
        <div class="kg-bulkbar" data-bulk-bar>
            <span class="kg-bulkbar__count" data-bulk-count>0 selected</span>
            <div class="kg-bulkbar__actions">
                <button type="submit" class="btn btn-danger btn-sm" data-confirm-modal="Archive the selected listings? They will be removed from the marketplace. This can be undone with Restore." data-confirm-title="Archive selected listings">Archive Selected</button>
            </div>
        </div>
    </form>
    <div class="card">
        <div class="table-responsive">
        <table class="table" id="inventory-table" data-bulk-select>
            <thead>
                <tr>
                    <th style="width:1%;"><input type="checkbox" data-bulk-select-all aria-label="Select all listings"></th>
                    <th>Photo</th>
                    <th>Variety</th><th>Batch</th><th>Quantity</th><th>Reserved</th><th>Available</th><th>Price</th><th>Warehouse</th><th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stocks as $stock): ?>
                    <?php
                        $status = $statusLabels[$stock['status']] ?? ['label' => $stock['status'], 'class' => 'badge-available'];
                        $warehouseName = $warehousesById[$stock['warehouseId'] ?? null]['name'] ?? '—';
                        $templateId = 'actions-tmpl-' . $stock['id'];
                    ?>
                    <tr>
                        <td><input type="checkbox" data-bulk-row value="<?php echo htmlspecialchars($stock['id']); ?>" aria-label="Select <?php echo htmlspecialchars($stock['variety']); ?>" <?php echo $stock['status'] === 'archived' ? 'disabled' : ''; ?>></td>
                        <td><img class="stock-thumb" src="<?php echo htmlspecialchars(stock_image_src($stock['imageUrl'] ?? null)); ?>" alt="<?php echo htmlspecialchars($stock['variety']); ?>"></td>
                        <td><?php echo htmlspecialchars($stock['variety']); ?></td>
                        <td><?php echo !empty($stock['batchNumber']) ? '<a href="' . htmlspecialchars(url('transparency/batch-timeline/?batch=' . urlencode($stock['batchNumber']))) . '">' . htmlspecialchars($stock['batchNumber']) . '</a>' : '—'; ?></td>
                        <td><?php echo htmlspecialchars($stock['quantity']); ?> <?php echo htmlspecialchars($stock['unit']); ?></td>
                        <td><?php echo (float) ($stock['reservedQuantity'] ?? 0) > 0 ? htmlspecialchars($stock['reservedQuantity']) . ' ' . htmlspecialchars($stock['unit']) : '—'; ?></td>
                        <td><?php echo htmlspecialchars(stock_available_quantity($stock)); ?> <?php echo htmlspecialchars($stock['unit']); ?></td>
                        <td>₱<?php echo htmlspecialchars($stock['unitPrice']); ?>/kg</td>
                        <td><?php echo htmlspecialchars($warehouseName); ?></td>
                        <td><span class="badge <?php echo htmlspecialchars($status['class']); ?>"><?php echo htmlspecialchars($status['label']); ?></span></td>
                        <td>
                            <button type="button" class="btn btn-secondary btn-sm"
                                data-modal-trigger
                                data-modal-template="<?php echo htmlspecialchars($templateId); ?>"
                                data-modal-title="<?php echo htmlspecialchars($stock['variety']); ?>"
                                data-modal-subtitle="Batch <?php echo htmlspecialchars($stock['batchNumber'] ?? '—'); ?> · <?php echo htmlspecialchars($status['label']); ?>">
                                Actions
                            </button>
                            <template id="<?php echo htmlspecialchars($templateId); ?>">
                                <a href="<?php echo htmlspecialchars(url('inventory/edit/?id=' . urlencode($stock['id']))); ?>" class="btn btn-secondary btn-sm">Edit</a>
                                <?php if ($stock['status'] !== 'archived'): ?>
                                    <a href="<?php echo htmlspecialchars(url('inventory/stock-in/?stockId=' . urlencode($stock['id']))); ?>" class="btn btn-secondary btn-sm">Stock In</a>
                                    <a href="<?php echo htmlspecialchars(url('inventory/stock-out/?stockId=' . urlencode($stock['id']))); ?>" class="btn btn-secondary btn-sm">Stock Out</a>
                                    <a href="<?php echo htmlspecialchars(url('warehouses/placement/?stockId=' . urlencode($stock['id']))); ?>" class="btn btn-secondary btn-sm">Move Warehouse</a>
                                <?php endif; ?>
                                <a href="<?php echo htmlspecialchars(url('inventory/history/?stockId=' . urlencode($stock['id']))); ?>" class="btn btn-secondary btn-sm">History</a>
                                <a href="<?php echo htmlspecialchars(url('transparency/price-monitoring/?stockId=' . urlencode($stock['id']))); ?>" class="btn btn-secondary btn-sm">Price History</a>
                                <form method="post">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="stockId" value="<?php echo htmlspecialchars($stock['id']); ?>">
                                    <?php if ($stock['status'] === 'archived'): ?>
                                        <button class="btn btn-secondary btn-sm" type="submit" name="action" value="restore">Restore</button>
                                    <?php else: ?>
                                        <button class="btn btn-danger btn-sm" type="submit" name="action" value="archive" data-confirm-modal="Archive this listing? It will be removed from the marketplace." data-confirm-title="Archive listing">Archive</button>
                                    <?php endif; ?>
                                </form>
                            </template>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php // Shared modal shell — reused for every row via data-modal-template. ?>
<div class="kg-modal-backdrop" id="kg-action-modal">
    <div class="kg-modal" role="dialog" aria-modal="true" aria-labelledby="kg-action-modal-title">
        <div class="kg-modal__header">
            <div>
                <p class="kg-modal__title" id="kg-action-modal-title">Actions</p>
                <p class="kg-modal__subtitle" id="kg-action-modal-subtitle"></p>
            </div>
            <button type="button" class="kg-modal__close" data-modal-close aria-label="Close">✕</button>
        </div>
        <div class="kg-modal__body" id="kg-action-modal-body"></div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
