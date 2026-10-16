<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$title = 'Price Monitoring';
$activePage = 'transparency';

$stockId = trim($_GET['stockId'] ?? '');
$stock = $stockId !== '' ? get_stock_by_id($stockId) : null;

$varietyFilter = get_filter_value('variety', '');
$dateFrom = get_filter_value('dateFrom', '');
$dateTo = get_filter_value('dateTo', '');

$priceSummary = get_price_summary_by_variety();
$history = $stock
    ? get_price_history($stockId)
    : get_recent_price_changes(150, ['variety' => $varietyFilter, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]);

$varieties = get_varieties();

// Trend chart controls — independent of the Recent Changes filters above,
// since "which variety's price moved over time" and "which raw change rows
// to list" are different questions a viewer might want different answers
// to at once.
$trendVariety = get_filter_value('trendVariety', '');
$trendDaysOptions = [7, 30, 90];
$trendDays = (int) get_filter_value('trendDays', '30');
if (!in_array($trendDays, $trendDaysOptions, true)) {
    $trendDays = 30;
}
$trendSeries = get_price_trend_series($trendDays, $trendVariety !== '' ? $trendVariety : null);

// ---- Small chart-geometry helpers (presentation only) — mirrors the
// dashboard's kg_chart_points()/kg_points_to_str(), duplicated locally
// rather than shared since it's two lines of SVG-coordinate math specific
// to whichever page is rendering it. ----
function pm_chart_points(array $values, int $width, int $height, int $padX = 24, int $padY = 16): array {
    $n = count($values);
    if ($n === 0) {
        return [];
    }
    $min = min($values);
    $max = max($values);
    if ($max === $min) {
        $max = $min + 1;
    }
    $points = [];
    for ($i = 0; $i < $n; $i++) {
        $x = $n > 1 ? $padX + ($i / ($n - 1)) * ($width - 2 * $padX) : $width / 2;
        $y = $height - $padY - (($values[$i] - $min) / ($max - $min)) * ($height - 2 * $padY);
        $points[] = [round($x, 1), round($y, 1)];
    }
    return $points;
}
function pm_points_to_str(array $points): string {
    return implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $points));
}

$chartW = 680;
$chartH = 200;
$trendValues = array_column($trendSeries, 'avgPrice');
$trendPts = pm_chart_points($trendValues, $chartW, $chartH);
$trendLabels = array_column($trendSeries, 'label');
// Sparse x-axis labels — every point crowds badly past ~10 labels wide.
$labelStep = max(1, (int) ceil(count($trendSeries) / 8));
$trendChangePct = null;
if (count($trendValues) >= 2 && $trendValues[0] > 0) {
    $trendChangePct = (($trendValues[count($trendValues) - 1] - $trendValues[0]) / $trendValues[0]) * 100;
}

include __DIR__ . '/../../includes/header.php';
?>
<h2>Price Monitoring</h2>
<p class="muted">Current price ranges by variety, and every recorded price change across the system.
    <a href="<?php echo htmlspecialchars(url('transparency/')); ?>">← Back to Supply Overview</a>
</p>

<div class="card">
    <h3>Price by Variety <span class="muted" style="font-weight:400;">(available listings, normalized to ₱/kg)</span></h3>
    <?php if (empty($priceSummary)): ?>
        <p>No available listings to price yet.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Variety</th><th>Listings</th><th>Lowest (₱/kg)</th><th>Average (₱/kg)</th><th>Highest (₱/kg)</th></tr></thead>
            <tbody>
                <?php foreach ($priceSummary as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['variety']); ?></td>
                        <td><?php echo htmlspecialchars((string) $row['listingCount']); ?></td>
                        <td>₱<?php echo htmlspecialchars(number_format($row['minPrice'], 2)); ?></td>
                        <td>₱<?php echo htmlspecialchars(number_format($row['avgPrice'], 2)); ?></td>
                        <td>₱<?php echo htmlspecialchars(number_format($row['maxPrice'], 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Price Trend</h3>
    <form method="get" style="margin-bottom:16px;">
        <?php // Preserve the Recent Changes filters below so changing the
              // trend controls doesn't silently wipe them out (two separate
              // <form>s sharing one page each only submit their own fields). ?>
        <input type="hidden" name="variety" value="<?php echo htmlspecialchars($varietyFilter); ?>">
        <input type="hidden" name="dateFrom" value="<?php echo htmlspecialchars($dateFrom); ?>">
        <input type="hidden" name="dateTo" value="<?php echo htmlspecialchars($dateTo); ?>">
        <div class="grid grid-3">
            <div>
                <label for="trendVariety">Variety</label>
                <select id="trendVariety" name="trendVariety" onchange="this.form.submit()">
                    <option value="">All varieties (avg.)</option>
                    <?php foreach ($varieties as $variety): ?>
                        <option value="<?php echo htmlspecialchars($variety); ?>" <?php echo $trendVariety === $variety ? 'selected' : ''; ?>><?php echo htmlspecialchars($variety); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="trendDays">Range</label>
                <select id="trendDays" name="trendDays" onchange="this.form.submit()">
                    <?php foreach ($trendDaysOptions as $opt): ?>
                        <option value="<?php echo $opt; ?>" <?php echo $trendDays === $opt ? 'selected' : ''; ?>><?php echo $opt; ?> days</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>
    <?php if (count($trendSeries) < 2): ?>
        <p class="muted">Not enough available listings to plot a trend for this variety.</p>
    <?php else: ?>
        <svg class="kg-chart-svg" viewBox="0 0 <?php echo $chartW; ?> <?php echo $chartH + 24; ?>" preserveAspectRatio="none">
            <?php
                $areaPath = 'M' . $trendPts[0][0] . ',' . $chartH;
                foreach ($trendPts as $p) { $areaPath .= ' L' . $p[0] . ',' . $p[1]; }
                $areaPath .= ' L' . $trendPts[count($trendPts) - 1][0] . ',' . $chartH . ' Z';
            ?>
            <path d="<?php echo $areaPath; ?>" class="kg-area" fill="var(--kg-blue)"></path>
            <polyline points="<?php echo pm_points_to_str($trendPts); ?>" class="kg-line kg-line-anim" style="--len:1600" stroke="var(--kg-blue)"></polyline>
            <?php foreach ($trendPts as $i => $p): ?>
                <circle cx="<?php echo $p[0]; ?>" cy="<?php echo $p[1]; ?>" r="3" fill="var(--kg-blue)" class="kg-dot" style="animation-delay:<?php echo 400 + $i * 20; ?>ms"></circle>
                <?php if ($i % $labelStep === 0 || $i === count($trendPts) - 1): ?>
                    <text x="<?php echo $p[0]; ?>" y="<?php echo $chartH + 18; ?>" font-size="11" fill="var(--kg-text-secondary)" text-anchor="middle"><?php echo htmlspecialchars($trendLabels[$i]); ?></text>
                <?php endif; ?>
            <?php endforeach; ?>
        </svg>
        <p class="muted" style="margin-top:8px;">
            ₱<?php echo htmlspecialchars(number_format(end($trendValues), 2)); ?>/kg average<?php echo $trendVariety !== '' ? ' for ' . htmlspecialchars($trendVariety) : ' across all varieties'; ?> <span class="muted">(normalized — comparable across kg/sack/ton listings)</span>
            <?php if ($trendChangePct !== null): ?>
                — <?php echo $trendChangePct >= 0 ? 'up' : 'down'; ?> <?php echo htmlspecialchars(number_format(abs($trendChangePct), 1)); ?>% over the last <?php echo $trendDays; ?> days
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>

<div class="card">
    <h3>
        <?php if ($stock): ?>
            Price History — <?php echo htmlspecialchars($stock['variety']); ?> (<?php echo htmlspecialchars($stock['supplierName']); ?>)
        <?php else: ?>
            Recent Price Changes
        <?php endif; ?>
    </h3>
    <?php if ($stock): ?>
        <p class="muted" style="margin-top:0;"><a href="<?php echo htmlspecialchars(url('transparency/price-monitoring/')); ?>">View all recent changes</a></p>
    <?php else: ?>
        <form method="get" class="card" style="margin: 0 0 16px;">
            <?php // Same reasoning as the Price Trend form's hidden fields
                  // above, mirrored so this one doesn't clear the trend
                  // chart's selection either. ?>
            <input type="hidden" name="trendVariety" value="<?php echo htmlspecialchars($trendVariety); ?>">
            <input type="hidden" name="trendDays" value="<?php echo htmlspecialchars((string) $trendDays); ?>">
            <div class="grid grid-3">
                <div>
                    <label for="variety">Variety</label>
                    <select id="variety" name="variety">
                        <option value="">All varieties</option>
                        <?php foreach ($varieties as $variety): ?>
                            <option value="<?php echo htmlspecialchars($variety); ?>" <?php echo $varietyFilter === $variety ? 'selected' : ''; ?>><?php echo htmlspecialchars($variety); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="dateFrom">From</label>
                    <input id="dateFrom" name="dateFrom" type="date" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div>
                    <label for="dateTo">To</label>
                    <input id="dateTo" name="dateTo" type="date" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
            </div>
            <button class="btn btn-primary" type="submit" style="margin-top:16px;">Apply Filters</button>
            <a href="<?php echo htmlspecialchars(url('transparency/price-monitoring/')); ?>" class="btn btn-secondary" style="margin-top:16px;">Clear Filters</a>
            <a href="<?php echo htmlspecialchars(url('transparency/price-monitoring/export/?variety=' . urlencode($varietyFilter) . '&dateFrom=' . urlencode($dateFrom) . '&dateTo=' . urlencode($dateTo))); ?>" class="btn btn-secondary" style="margin-top:16px;">Export CSV</a>
        </form>
    <?php endif; ?>
    <?php if (empty($history)): ?>
        <p>No price changes recorded yet.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <?php if (!$stock): ?><th>Variety</th><th>Supplier</th><?php endif; ?>
                    <th>Old Price</th>
                    <th>New Price</th>
                    <th>Change</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $p): ?>
                    <?php
                        $old = $p['oldPrice'] !== null ? (float) $p['oldPrice'] : null;
                        $new = (float) $p['newPrice'];
                        $diff = $old !== null ? $new - $old : null;
                        $diffPct = ($old !== null && $old > 0) ? ($diff / $old) * 100 : null;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($p['createdAt']); ?></td>
                        <?php if (!$stock): ?>
                            <td><a href="<?php echo htmlspecialchars(url('transparency/price-monitoring/?stockId=' . urlencode($p['stockId']))); ?>"><?php echo htmlspecialchars($p['variety']); ?></a></td>
                            <td><?php echo htmlspecialchars($p['supplierName']); ?></td>
                        <?php endif; ?>
                        <td><?php echo $old !== null ? '₱' . htmlspecialchars(number_format($old, 2)) : '—'; ?><?php echo $old !== null ? '/' . htmlspecialchars($p['unit'] ?? $stock['unit'] ?? '') : ''; ?></td>
                        <td>₱<?php echo htmlspecialchars(number_format($new, 2)); ?>/<?php echo htmlspecialchars($p['unit'] ?? $stock['unit'] ?? ''); ?></td>
                        <td>
                            <?php if ($diff === null): ?>
                                <span class="badge badge-info">Initial price</span>
                            <?php elseif ($diff > 0): ?>
                                <span style="color:#166534;">▲ ₱<?php echo htmlspecialchars(number_format($diff, 2)); ?> (<?php echo htmlspecialchars(number_format($diffPct, 1)); ?>%)</span>
                            <?php elseif ($diff < 0): ?>
                                <span style="color:#c0392b;">▼ ₱<?php echo htmlspecialchars(number_format(abs($diff), 2)); ?> (<?php echo htmlspecialchars(number_format(abs($diffPct), 1)); ?>%)</span>
                            <?php else: ?>
                                <span class="muted">No change</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
