<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('inventory', ['farmer', 'importer', 'supplier']);

$stockId = trim($_GET['id'] ?? $_POST['id'] ?? '');
$stock = $stockId !== '' ? get_stock_by_id($stockId) : null;

// Only the owner can edit their own listing.
if (!$stock || $stock['userId'] !== current_user_id()) {
    http_response_code(404);
    $title = 'Listing not found';
    include __DIR__ . '/../../includes/header.php';
    echo '<h2>Listing not found</h2><p>This stock listing does not exist or is not yours to edit.</p>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$error = '';
$role = user_role();

// Same role-based source logic as Add Stock: farmer stock is always local,
// importer stock is always imported, only supplier gets a manual choice.
$fixedSource = $role === 'farmer' ? 'local' : ($role === 'importer' ? 'imported' : null);

// Handle the POST — and its redirect on success — before includes/header.php
// is loaded below, since that include starts printing HTML immediately; a
// header('Location: ...') call after any output has started fails with
// "headers already sent".
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $variety = trim($_POST['variety'] ?? '');
    $unit = trim($_POST['unit'] ?? 'kg');
    $unitPrice = trim($_POST['unitPrice'] ?? '');
    $source = $fixedSource ?? trim($_POST['source'] ?? 'local');
    $countryOfOrigin = $source === 'imported' ? trim($_POST['countryOfOrigin'] ?? '') : '';
    $description = trim($_POST['description'] ?? '');
    $imageUrl = $stock['imageUrl'] ?? null;
    $imageError = '';
    $removeImage = !empty($_POST['removeImage']);

    if (!empty($_FILES['image']['name'])) {
        try {
            $uploaded = handle_secure_stock_image_upload($_FILES['image'], __DIR__ . '/../../uploads/stock-images');
            // Replacing an existing photo — drop the old file once the new
            // one is safely saved, so uploads/stock-images/ doesn't
            // accumulate orphaned images every time someone re-uploads.
            delete_stock_image_file($stock['imageUrl'] ?? null);
            $imageUrl = $uploaded['fileUrl'];
        } catch (RuntimeException $e) {
            $imageError = $e->getMessage();
        }
    } elseif ($removeImage) {
        delete_stock_image_file($stock['imageUrl'] ?? null);
        $imageUrl = null;
    }

    // Strengthened server-side validation: variety/price presence, unit
    // and source restricted to the values the rest of the system actually
    // understands (a raw POST could previously send anything here, not
    // just what the <select> options offer), and length caps so a huge
    // paste can't bloat a text column unbounded.
    if ($variety === '' || $unitPrice === '') {
        $error = 'Variety and price are required.';
    } elseif (mb_strlen($variety) > 128) {
        $error = 'Variety name is too long (max 128 characters).';
    } elseif (!is_numeric($unitPrice) || (float) $unitPrice <= 0) {
        $error = 'Unit price must be a number greater than zero.';
    } elseif (!in_array($unit, ALLOWED_STOCK_UNITS, true)) {
        $error = 'Please choose a valid unit.';
    } elseif (!in_array($source, ['local', 'imported'], true)) {
        $error = 'Please choose a valid source.';
    } elseif ($source === 'imported' && $countryOfOrigin === '') {
        $error = 'Country of origin is required for imported stock.';
    } elseif (mb_strlen($description) > 2000) {
        $error = 'Description is too long (max 2000 characters).';
    } elseif ($imageError !== '') {
        $error = $imageError;
    } else {
        update_stock($stockId, [
            'variety' => $variety,
            'unit' => $unit,
            'unitPrice' => (float) $unitPrice,
            'source' => $source,
            'countryOfOrigin' => $countryOfOrigin,
            'description' => $description,
            'imageUrl' => $imageUrl,
        ]);
        log_activity(current_user_id(), 'stock.update', 'stock', $stockId, $variety . ' listing details updated');
        header('Location: ' . url('inventory/'));
        exit;
    }
    // Re-render with the submitted values on validation failure.
    $stock = array_merge($stock, compact('variety', 'unit', 'unitPrice', 'source', 'countryOfOrigin', 'description'));
    $stock['imageUrl'] = $imageUrl;
}

$title = 'Edit Stock';
$activePage = 'inventory';
include __DIR__ . '/../../includes/header.php';
?>
<h2>Edit Stock</h2>
<p class="muted">Update this rice inventory listing.</p>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="id" value="<?php echo htmlspecialchars($stockId); ?>">
    <div class="form-group">
        <label for="variety">Rice Variety</label>
        <input id="variety" name="variety" type="text" value="<?php echo htmlspecialchars($stock['variety']); ?>" required>
    </div>
    <div class="form-group">
        <label>Quantity</label>
        <p class="muted" style="margin-top: 4px;">
            <strong><?php echo htmlspecialchars($stock['quantity']); ?> <?php echo htmlspecialchars($stock['unit']); ?></strong>
            — quantity can't be changed here. Use
            <a href="<?php echo htmlspecialchars(url('inventory/stock-in/?stockId=' . urlencode($stockId))); ?>">Stock In</a> or
            <a href="<?php echo htmlspecialchars(url('inventory/stock-out/?stockId=' . urlencode($stockId))); ?>">Stock Out</a>
            so the change is logged with a reason.
        </p>
    </div>
    <div class="form-group">
        <label for="unit">Unit</label>
        <select id="unit" name="unit">
            <option value="kg" <?php echo $stock['unit'] === 'kg' ? 'selected' : ''; ?>>Kilogram (kg)</option>
            <option value="sack" <?php echo $stock['unit'] === 'sack' ? 'selected' : ''; ?>>Sack</option>
            <option value="ton" <?php echo $stock['unit'] === 'ton' ? 'selected' : ''; ?>>Metric Ton</option>
        </select>
    </div>
    <div class="form-group">
        <label for="unitPrice">Unit Price (₱)</label>
        <input id="unitPrice" name="unitPrice" type="number" step="0.01" value="<?php echo htmlspecialchars($stock['unitPrice']); ?>" required>
    </div>
    <?php if ($fixedSource !== null): ?>
        <input type="hidden" name="source" value="<?php echo htmlspecialchars($fixedSource); ?>">
        <div class="form-group">
            <label>Source</label>
            <p class="muted" style="margin-top: 4px;">
                <?php echo $fixedSource === 'local' ? 'Locally grown (farmer stock is always listed as local).' : 'Imported (importer stock is always listed as imported).'; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="form-group">
            <label for="source">Source</label>
            <select id="source" name="source" onchange="document.getElementById('countryOfOriginGroup').style.display = this.value === 'imported' ? '' : 'none';">
                <option value="local" <?php echo $stock['source'] === 'local' ? 'selected' : ''; ?>>Local</option>
                <option value="imported" <?php echo $stock['source'] === 'imported' ? 'selected' : ''; ?>>Imported</option>
            </select>
        </div>
    <?php endif; ?>
    <?php $effectiveSource = $fixedSource ?? $stock['source']; ?>
    <div class="form-group" id="countryOfOriginGroup" <?php echo $effectiveSource !== 'imported' ? 'style="display:none;"' : ''; ?>>
        <label for="countryOfOrigin">Country of Origin</label>
        <input id="countryOfOrigin" name="countryOfOrigin" type="text" value="<?php echo htmlspecialchars($stock['countryOfOrigin'] ?? ''); ?>" placeholder="e.g. Vietnam, Thailand">
    </div>
    <div class="form-group">
        <label>Batch / Lot Number</label>
        <p class="muted" style="margin-top: 4px;">
            <strong><?php echo htmlspecialchars($stock['batchNumber'] ?? 'Not recorded'); ?></strong>
            — fixed at creation so movements, transfers, and orders traced back to this stock stay consistent.
        </p>
    </div>
    <div class="form-group">
        <label for="description">Description</label>
        <textarea id="description" name="description"><?php echo htmlspecialchars($stock['description'] ?? ''); ?></textarea>
    </div>
    <div class="form-group">
        <label for="image">Photo</label>
        <?php if (!empty($stock['imageUrl'])): ?>
            <div style="margin-bottom: 8px;">
                <img src="<?php echo htmlspecialchars(stock_image_src($stock['imageUrl'])); ?>" alt="Current photo" style="max-width: 160px; border-radius: 8px; display: block; margin-bottom: 6px;">
                <label style="font-weight: normal;"><input type="checkbox" name="removeImage" value="1"> Remove current photo</label>
            </div>
        <?php endif; ?>
        <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp">
        <p class="muted" style="margin-top: 4px;">Optional. Uploading a new photo replaces the current one. JPG, PNG, or WEBP, up to 5MB.</p>
    </div>
    <button class="btn btn-primary" type="submit">Save Changes</button>
    <a href="<?php echo htmlspecialchars(url('inventory/')); ?>" class="btn btn-secondary">Cancel</a>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
