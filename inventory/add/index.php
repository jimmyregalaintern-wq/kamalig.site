<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('inventory', ['farmer', 'importer', 'supplier']);

$error = '';
$role = user_role();

// Farmers only ever list locally-grown stock; importers only ever list
// imported stock. Only suppliers/traders genuinely carry both, so they're
// the only role that gets a manual Source choice — farmer/importer submit
// a fixed, hidden value instead of picking from a dropdown that could be
// set incorrectly (and would then skew the local/imported report split).
$fixedSource = $role === 'farmer' ? 'local' : ($role === 'importer' ? 'imported' : null);

// Handle the POST — and its redirect on success — before includes/header.php
// is loaded below, since that include starts printing HTML immediately; a
// header('Location: ...') call after any output has started fails with
// "headers already sent".
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $variety = trim($_POST['variety'] ?? '');
    $quantity = trim($_POST['quantity'] ?? '');
    $unit = trim($_POST['unit'] ?? 'kg');
    $unitPrice = trim($_POST['unitPrice'] ?? '');
    $source = $fixedSource ?? trim($_POST['source'] ?? 'local');
    // Country of origin only makes sense for imported stock.
    $countryOfOrigin = $source === 'imported' ? trim($_POST['countryOfOrigin'] ?? '') : '';
    $description = trim($_POST['description'] ?? '');
    $batchNumber = trim($_POST['batchNumber'] ?? '');
    $imageUrl = null;
    $imageError = '';

    if (!empty($_FILES['image']['name'])) {
        try {
            $uploaded = handle_secure_stock_image_upload($_FILES['image'], __DIR__ . '/../../uploads/stock-images');
            $imageUrl = $uploaded['fileUrl'];
        } catch (RuntimeException $e) {
            $imageError = $e->getMessage();
        }
    }

    if ($variety === '' || $quantity === '' || $unitPrice === '') {
        $error = 'Variety, quantity, and price are required.';
    } elseif (mb_strlen($variety) > 128) {
        $error = 'Variety name is too long (max 128 characters).';
    } elseif (!is_numeric($quantity) || (float) $quantity <= 0) {
        $error = 'Quantity must be a number greater than zero.';
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
    } elseif ($batchNumber !== '' && !preg_match('/^[A-Za-z0-9\-_.\/]{1,64}$/', $batchNumber)) {
        $error = 'Batch/Lot number can only contain letters, numbers, and - _ . / (max 64 characters).';
    } elseif ($batchNumber !== '' && stock_batch_number_exists($batchNumber, current_user_id() ?? '')) {
        // A batch/lot number is meant to uniquely trace one lot end to end
        // (Critical Improvement: "make batch/lot a real inventory
        // concept") — silently allowing two listings to share one would
        // make that traceability meaningless, since movements/orders
        // referencing the number couldn't tell which lot they belonged to.
        $error = 'That batch/lot number is already in use — choose a different one, or leave it blank to auto-generate.';
    } elseif ($imageError !== '') {
        $error = $imageError;
    } else {
        create_stock([
            'userId' => current_user_id() ?? '',
            'variety' => $variety,
            'quantity' => (float) $quantity,
            'unit' => $unit,
            'unitPrice' => (float) $unitPrice,
            'source' => $source,
            'countryOfOrigin' => $countryOfOrigin,
            'description' => $description,
            'status' => 'available',
            'batchNumber' => $batchNumber,
            'imageUrl' => $imageUrl,
        ]);
        header('Location: ' . url('inventory/'));
        exit;
    }
}

$title = 'Add Stock';
$activePage = 'inventory';
include __DIR__ . '/../../includes/header.php';
?>
<h2>Add Stock</h2>
<p class="muted">Create a new rice inventory listing.</p>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <div class="form-group">
        <label for="variety">Rice Variety</label>
        <input id="variety" name="variety" type="text" value="<?php echo htmlspecialchars($_POST['variety'] ?? ''); ?>" required>
    </div>
    <div class="form-group">
        <label for="quantity">Quantity</label>
        <input id="quantity" name="quantity" type="number" step="0.01" value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>" required>
    </div>
    <div class="form-group">
        <label for="unit">Unit</label>
        <select id="unit" name="unit">
            <option value="kg">Kilogram (kg)</option>
            <option value="sack">Sack</option>
            <option value="ton">Metric Ton</option>
        </select>
    </div>
    <div class="form-group">
        <label for="unitPrice">Unit Price (₱)</label>
        <input id="unitPrice" name="unitPrice" type="number" step="0.01" value="<?php echo htmlspecialchars($_POST['unitPrice'] ?? ''); ?>" required>
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
                <option value="local" <?php echo ($_POST['source'] ?? 'local') === 'local' ? 'selected' : ''; ?>>Local</option>
                <option value="imported" <?php echo ($_POST['source'] ?? '') === 'imported' ? 'selected' : ''; ?>>Imported</option>
            </select>
        </div>
    <?php endif; ?>
    <?php $effectiveSource = $fixedSource ?? ($_POST['source'] ?? 'local'); ?>
    <div class="form-group" id="countryOfOriginGroup" <?php echo $effectiveSource !== 'imported' ? 'style="display:none;"' : ''; ?>>
        <label for="countryOfOrigin">Country of Origin</label>
        <input id="countryOfOrigin" name="countryOfOrigin" type="text" value="<?php echo htmlspecialchars($_POST['countryOfOrigin'] ?? ''); ?>" placeholder="e.g. Vietnam, Thailand">
    </div>
    <div class="form-group">
        <label for="batchNumber">Batch / Lot Number</label>
        <input id="batchNumber" name="batchNumber" type="text" value="<?php echo htmlspecialchars($_POST['batchNumber'] ?? ''); ?>" placeholder="Leave blank to auto-generate">
        <p class="muted" style="margin-top: 4px;">Used to trace this stock through movements, transfers, and orders. Auto-generated if left blank.</p>
    </div>
    <div class="form-group">
        <label for="description">Description</label>
        <textarea id="description" name="description"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
    </div>
    <div class="form-group">
        <label for="image">Photo</label>
        <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp">
        <p class="muted" style="margin-top: 4px;">Optional. A clear photo of this stock helps buyers on the Marketplace. JPG, PNG, or WEBP, up to 5MB.</p>
    </div>
    <button class="btn btn-primary" type="submit">Save Stock</button>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
