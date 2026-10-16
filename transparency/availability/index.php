<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$title = 'Supply Availability';
$activePage = 'transparency';

$sourceFilter = get_filter_value('source', 'all');
$varietyFilter = get_filter_value('variety', '');

$availability = get_supply_availability($varietyFilter !== '' ? $varietyFilter : null);
if ($sourceFilter !== 'all') {
    $availability = array_values(array_filter($availability, fn($row) => $row['source'] === $sourceFilter));
}

$varieties = get_varieties();
$totalQuantity = array_sum(array_column($availability, 'totalQuantityKg'));

include __DIR__ . '/../../includes/header.php';
?>
<h2>Supply Availability</h2>
<p class="muted">How much rice is currently available, by variety and source — quantities and prices normalized to kg so kg/sack/ton listings compare fairly.
    <a href="<?php echo htmlspecialchars(url('transparency/')); ?>">← Back to Supply Overview</a>
</p>

<form method="get" class="card">
    <div class="grid grid-2">
        <div class="form-group">
            <label for="source">Source</label>
            <select id="source" name="source">
                <option value="all" <?php echo $sourceFilter === 'all' ? 'selected' : ''; ?>>All Sources</option>
                <option value="local" <?php echo $sourceFilter === 'local' ? 'selected' : ''; ?>>Local</option>
                <option value="imported" <?php echo $sourceFilter === 'imported' ? 'selected' : ''; ?>>Imported</option>
            </select>
        </div>
        <div class="form-group">
            <label for="variety">Variety</label>
            <select id="variety" name="variety">
                <option value="">All varieties</option>
                <?php foreach ($varieties as $variety): ?>
                    <option value="<?php echo htmlspecialchars($variety); ?>" <?php echo $varietyFilter === $variety ? 'selected' : ''; ?>><?php echo htmlspecialchars($variety); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <button class="btn btn-primary" type="submit">Apply Filters</button>
    <a href="<?php echo htmlspecialchars(url('transparency/availability/')); ?>" class="btn btn-secondary">Clear Filters</a>
    <a href="<?php echo htmlspecialchars(url('transparency/availability/export/?source=' . urlencode($sourceFilter) . '&variety=' . urlencode($varietyFilter))); ?>" class="btn btn-secondary">Export CSV</a>
</form>

<?php if (empty($availability)): ?>
    <div class="card">
        <p>No available stock matches this filter.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Variety</th>
                    <th>Source</th>
                    <th>Listings</th>
                    <th>Total Quantity (kg)</th>
                    <th>Reserved (kg)</th>
                    <th>Available (kg)</th>
                    <th>Avg. Price (₱/kg)</th>
                    <th>Share</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($availability as $row): ?>
                    <?php
                        $share = $totalQuantity > 0 ? round(($row['totalQuantityKg'] / $totalQuantity) * 100) : 0;
                        $reservedKgForRow = max(0, $row['totalQuantityKg'] - $row['availableQuantityKg']);
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['variety']); ?></td>
                        <td><span class="badge <?php echo $row['source'] === 'imported' ? 'badge-info' : 'badge-available'; ?>"><?php echo htmlspecialchars(ucfirst($row['source'])); ?></span></td>
                        <td><?php echo htmlspecialchars((string) $row['listingCount']); ?></td>
                        <td><?php echo htmlspecialchars(number_format($row['totalQuantityKg'], 2)); ?></td>
                        <td><?php echo $reservedKgForRow > 0 ? htmlspecialchars(number_format($reservedKgForRow, 2)) : '—'; ?></td>
                        <td><?php echo htmlspecialchars(number_format($row['availableQuantityKg'], 2)); ?></td>
                        <td>₱<?php echo htmlspecialchars(number_format($row['avgPricePerKg'], 2)); ?></td>
                        <td><?php echo htmlspecialchars((string) $share); ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2"><strong>Total</strong></td>
                    <td><strong><?php echo htmlspecialchars((string) array_sum(array_column($availability, 'listingCount'))); ?></strong></td>
                    <td><strong><?php echo htmlspecialchars(number_format($totalQuantity, 2)); ?></strong></td>
                    <td><strong><?php echo htmlspecialchars(number_format(array_sum(array_map(fn($r) => max(0, $r['totalQuantityKg'] - $r['availableQuantityKg']), $availability)), 2)); ?></strong></td>
                    <td><strong><?php echo htmlspecialchars(number_format(array_sum(array_column($availability, 'availableQuantityKg')), 2)); ?></strong></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
<?php endif; ?>
<p class="muted">Want to buy? Browse and filter the same listings in the <a href="<?php echo htmlspecialchars(url('marketplace/')); ?>">Marketplace</a>.</p>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
