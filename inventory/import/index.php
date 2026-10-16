<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('inventory', ['farmer', 'importer', 'supplier']);

$role = user_role();
// Same rule as the manual Add Stock form: farmers only ever list local
// stock, importers only ever list imported stock. Suppliers can carry
// either, so their CSV rows control their own `source` column.
$fixedSource = $role === 'farmer' ? 'local' : ($role === 'importer' ? 'imported' : null);

const MAX_IMPORT_ROWS = 500;
const MAX_IMPORT_BYTES = 2 * 1024 * 1024; // 2MB — plenty for 500 stock rows
$expectedColumns = ['variety', 'quantity', 'unit', 'unitPrice', 'source', 'countryOfOrigin', 'batchNumber', 'description'];

$error = '';
$results = null; // ['created' => int, 'skipped' => [['row' => n, 'reason' => ...], ...]]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $file = $_FILES['csvFile'] ?? null;
    if (!$file || !isset($file['error']) || is_array($file['error'])) {
        $error = 'Please choose a CSV file to upload.';
    } elseif ($file['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose a CSV file to upload.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload failed. Please try again.';
    } elseif ($file['size'] <= 0 || $file['size'] > MAX_IMPORT_BYTES) {
        $error = 'File must be between 1 byte and ' . (MAX_IMPORT_BYTES / (1024 * 1024)) . 'MB.';
    } elseif (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid upload — please try again.';
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $error = 'Please upload a .csv file.';
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            $error = 'Could not read the uploaded file.';
        } else {
            $header = fgetcsv($handle);
            if ($header === false) {
                $error = 'The file appears to be empty.';
            } else {
                // Normalize header names so column order in the file doesn't matter.
                $header = array_map(fn($h) => strtolower(trim((string) $h)), $header);
                $colIndex = array_flip($header);

                $created = 0;
                $skipped = [];
                $rowNum = 1; // header was row 1

                while (($row = fgetcsv($handle)) !== false) {
                    $rowNum++;
                    if ($rowNum - 1 > MAX_IMPORT_ROWS) {
                        $skipped[] = ['row' => $rowNum, 'reason' => 'Import limit of ' . MAX_IMPORT_ROWS . ' rows reached — remaining rows were not processed.'];
                        break;
                    }
                    // Skip fully blank lines (common at end of a CSV export).
                    if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                        continue;
                    }

                    $get = fn($col) => isset($colIndex[$col], $row[$colIndex[$col]]) ? trim((string) $row[$colIndex[$col]]) : '';

                    $variety = $get('variety');
                    $quantity = $get('quantity');
                    $unit = $get('unit') ?: 'kg';
                    $unitPrice = $get('unitprice');
                    $source = $fixedSource ?? (strtolower($get('source')) === 'imported' ? 'imported' : 'local');
                    $countryOfOrigin = $source === 'imported' ? $get('countryoforigin') : '';
                    $batchNumber = $get('batchnumber');
                    $description = $get('description');

                    if ($variety === '' || $quantity === '' || $unitPrice === '') {
                        $skipped[] = ['row' => $rowNum, 'reason' => 'Variety, quantity, and unit price are required.'];
                        continue;
                    }
                    if (!is_numeric($quantity) || (float) $quantity <= 0) {
                        $skipped[] = ['row' => $rowNum, 'reason' => 'Quantity must be a number greater than zero.'];
                        continue;
                    }
                    if (!is_numeric($unitPrice) || (float) $unitPrice <= 0) {
                        $skipped[] = ['row' => $rowNum, 'reason' => 'Unit price must be a number greater than zero.'];
                        continue;
                    }
                    if ($source === 'imported' && $countryOfOrigin === '') {
                        $skipped[] = ['row' => $rowNum, 'reason' => 'Country of origin is required for imported stock.'];
                        continue;
                    }

                    if ($batchNumber !== '' && !preg_match('/^[A-Za-z0-9\-_.\/]{1,64}$/', $batchNumber)) {
                        $skipped[] = ['row' => $rowNum, 'reason' => 'Batch/lot number can only contain letters, numbers, and - _ . / (max 64 characters).'];
                        continue;
                    }

                    try {
                        create_stock([
                            'userId' => current_user_id() ?? '',
                            'variety' => $variety,
                            'quantity' => (float) $quantity,
                            'unit' => in_array($unit, ALLOWED_STOCK_UNITS, true) ? $unit : 'kg',
                            'unitPrice' => (float) $unitPrice,
                            'source' => $source,
                            'countryOfOrigin' => $countryOfOrigin,
                            'description' => $description,
                            'status' => 'available',
                            'batchNumber' => $batchNumber,
                        ]);
                        $created++;
                    } catch (InvalidArgumentException $e) {
                        // create_stock() throws on a duplicate batch/lot number
                        // (or an invalid unit) — skip just this row rather than
                        // aborting the entire import partway through.
                        $skipped[] = ['row' => $rowNum, 'reason' => $e->getMessage()];
                    }
                }
                fclose($handle);

                if ($created === 0 && empty($skipped)) {
                    $error = 'No data rows were found in the file.';
                } else {
                    $results = ['created' => $created, 'skipped' => $skipped];
                    log_activity(current_user_id(), 'stock.bulk_import', 'stock', null, $created . ' listings imported, ' . count($skipped) . ' skipped');
                }
            }
        }
    }
}

$title = 'Bulk Import Inventory';
$activePage = 'inventory';
include __DIR__ . '/../../includes/header.php';
?>
<h2>Bulk Import Inventory</h2>
<p class="muted">Upload a CSV to create multiple stock listings at once.</p>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($results !== null): ?>
    <div class="card">
        <h3>Import complete</h3>
        <p><strong><?php echo (int) $results['created']; ?></strong> listing<?php echo $results['created'] === 1 ? '' : 's'; ?> created.
           <?php if (!empty($results['skipped'])): ?>
               <strong><?php echo count($results['skipped']); ?></strong> row<?php echo count($results['skipped']) === 1 ? '' : 's'; ?> skipped.
           <?php endif; ?>
        </p>
        <?php if (!empty($results['skipped'])): ?>
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Row</th><th>Reason skipped</th></tr></thead>
                <tbody>
                    <?php foreach ($results['skipped'] as $s): ?>
                        <tr><td><?php echo (int) $s['row']; ?></td><td><?php echo htmlspecialchars($s['reason']); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
        <a href="<?php echo htmlspecialchars(url('inventory/')); ?>" class="btn btn-primary">Back to Inventory</a>
        <a href="<?php echo htmlspecialchars(url('inventory/import/')); ?>" class="btn btn-secondary">Import Another File</a>
    </div>
<?php else: ?>
    <div class="card">
        <h3>1. Prepare your CSV</h3>
        <p class="muted">Columns (header row required, any order): <code>variety, quantity, unit, unitPrice<?php echo $fixedSource === null ? ', source, countryOfOrigin' : ''; ?>, batchNumber, description</code></p>
        <ul>
            <li><code>variety</code>, <code>quantity</code>, and <code>unitPrice</code> are required; other columns are optional.</li>
            <li><code>unit</code> accepts <code>kg</code>, <code>sack</code>, or <code>ton</code> (defaults to <code>kg</code>).</li>
            <?php if ($fixedSource === null): ?>
                <li><code>source</code> accepts <code>local</code> or <code>imported</code>; <code>countryOfOrigin</code> is required when <code>source</code> is <code>imported</code>.</li>
            <?php else: ?>
                <li>Every row will be listed as <strong><?php echo $fixedSource === 'local' ? 'local' : 'imported'; ?></strong>, matching your account type.</li>
            <?php endif; ?>
            <li><code>batchNumber</code> auto-generates if left blank.</li>
            <li>Up to <?php echo MAX_IMPORT_ROWS; ?> rows per file.</li>
        </ul>
        <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars(url('inventory/import/?sample=1')); ?>" download="kamalig-inventory-template.csv" onclick="event.preventDefault(); (function(){
            var cols = <?php echo json_encode($fixedSource === null ? $expectedColumns : array_values(array_diff($expectedColumns, ['source']))); ?>;
            var sample = <?php echo json_encode($fixedSource === null
                ? ['Dinorado', '500', 'kg', '52.50', 'local', '', 'LOT-SAMPLE-001', 'Optional description']
                : ['Dinorado', '500', 'kg', '52.50', 'LOT-SAMPLE-001', 'Optional description']); ?>;
            var csv = cols.join(',') + '\n' + sample.join(',') + '\n';
            var blob = new Blob([csv], {type: 'text/csv'});
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'kamalig-inventory-template.csv';
            a.click();
        })();">Download CSV Template</a>
    </div>
    <div class="card">
        <h3>2. Upload</h3>
        <form method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="csvFile">CSV file</label>
                <input id="csvFile" name="csvFile" type="file" accept=".csv,text/csv" required>
            </div>
            <button class="btn btn-primary" type="submit">Import</button>
            <a href="<?php echo htmlspecialchars(url('inventory/')); ?>" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
