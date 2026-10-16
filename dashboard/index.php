<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_auth();

$title = 'Dashboard';
$activePage = 'dashboard';
include __DIR__ . '/../includes/header.php';

$user = current_user();
$role = user_role();
$isAdmin = $role === 'admin';

// =====================================================================
// Shared dashboard — same design/layout for every role (admin included).
// $scopeUserId is the only thing that changes the *data*: null means
// system-wide (admin), a user id means "only what this account owns"
// (farmer/importer/supplier). Every data.php call below accepts that as
// an optional last argument and falls back to its original system-wide
// behavior when it's null, so admin's numbers are unchanged from before
// this redesign.
//
// Every figure is computed from real stocks / warehouses /
// procurement_orders / stock_movements / price_history rows via
// includes/data.php. Nothing here is sample or placeholder data; where
// there isn't enough history for a comparison, the value is null and the
// markup shows "—" instead of a fabricated number.
// =====================================================================

$scopeUserId = $isAdmin ? null : current_user_id();
// Label prefix so KPI/section copy reads "My Inventory" etc. for account
// holders and plain "Total Rice Inventory" etc. for admin's system view.
$mine = $isAdmin ? '' : 'My ';

$stocks = $scopeUserId !== null ? get_user_stocks($scopeUserId) : get_stocks();
$activeStocks = array_values(array_filter($stocks, fn($s) => $s['status'] !== 'archived'));
$availableStocks = array_values(array_filter($stocks, fn($s) => $s['status'] === 'available'));

// BUGFIX: $totalInventory, $availableStockTotal, and $reservedStockTotal all
// used to sum each stock's raw `quantity`/`reservedQuantity` directly —
// mixing kg, sack (50kg), and ton (1000kg) listings together as if they
// were the same unit, then displaying the raw mixed sum under an "MT"
// label with no conversion at all (so even a correctly-summed kg total
// would have shown 1000x too large). This is the same mistake already
// fixed in Marketplace Compare's price display and the Analytics/
// Transparency movement totals — baseQuantityKg/reservedQuantityKg are the
// same kg-normalized columns those fixes rely on. Computed in kg first,
// then divided by 1000 once at the end to match the "MT" these feed
// throughout this page (KPI cards, the supply-chain flow diagram, and the
// analysis panel).
$stockQuantityKg = fn(array $s): float => (float) ($s['baseQuantityKg'] ?? unit_to_kg((float) $s['quantity'], $s['unit'] ?? 'kg'));
$stockReservedKg = fn(array $s): float => (float) ($s['reservedQuantityKg'] ?? unit_to_kg((float) ($s['reservedQuantity'] ?? 0), $s['unit'] ?? 'kg'));

$totalInventoryKg = array_sum(array_map($stockQuantityKg, $activeStocks));
$totalInventory = $totalInventoryKg / 1000;
// "Available" here means truly sellable/requestable — i.e. net of whatever
// is already committed to other accepted orders (Phase 2: Reserved
// Inventory). Before reservations existed, status = 'available' stock was
// entirely uncommitted by definition, so summing raw quantity was
// accurate; now a listing can be 'available' status while some or all of
// its quantity is reserved, so double-counting that as "available" here
// would overstate what's actually still sellable.
$availableStockTotalKg = array_sum(array_map(fn($s) => max(0.0, $stockQuantityKg($s) - $stockReservedKg($s)), $availableStocks));
$availableStockTotal = $availableStockTotalKg / 1000;
$reservedStockTotal = array_sum(array_map($stockReservedKg, $activeStocks)) / 1000;
$availablePct = $totalInventoryKg > 0 ? ($availableStockTotalKg / $totalInventoryKg) * 100 : 0;

$monthlyTrend = get_monthly_supply_trend(6, $scopeUserId);
$inventoryTrendPct = null;
if (count($monthlyTrend) >= 2) {
    $prevAvail = $monthlyTrend[count($monthlyTrend) - 2]['available'];
    $currAvail = $monthlyTrend[count($monthlyTrend) - 1]['available'];
    if ($prevAvail > 0) {
        $inventoryTrendPct = (($currAvail - $prevAvail) / $prevAvail) * 100;
    }
}

$warehouseOverview = get_warehouse_inventory_overview($scopeUserId);
$lowStockLocations = array_values(array_filter($warehouseOverview, fn($w) => in_array($w['status'], ['critical', 'low'], true)));
$nearCapacityLocations = array_values(array_filter($warehouseOverview, fn($w) => $w['status'] !== 'unknown' && $w['pct'] >= warehouse_capacity_alert_threshold()));

$procurementSummary = get_active_procurement_summary($scopeUserId);
$deliverySummary = get_pending_deliveries_summary($scopeUserId);
$priceTrend = get_avg_rice_price_trend(30, $scopeUserId);
$aiInsights = get_extended_ai_insights($scopeUserId);
$aiSummary = get_ai_natural_language_summary($aiInsights, $scopeUserId);
$overviewStats = get_supply_overview_stats($scopeUserId);
$recentOrders = get_recent_procurement_orders_detailed(5, $scopeUserId);
$activityLog = get_activity_log(6, null, null, $scopeUserId);

$movementTotals30 = get_movement_totals(30, $scopeUserId);
// get_movement_totals() returns kg (see its own fix notes); divide by 1000
// to match the "MT" label this feeds further down this page.
$distributed30 = (float) ($movementTotals30['out']['totalQuantity'] ?? 0) / 1000;
$scopedOrders = $scopeUserId !== null ? get_user_procurement_orders($scopeUserId) : get_procurement_orders();
$incomingTransitQty = 0.0;
foreach ($scopedOrders as $o) {
    if ($o['status'] === 'in_transit') {
        // BUGFIX: same unit-mixing mistake as above — procurement_orders
        // has no baseQuantityKg equivalent column, so the per-order unit
        // conversion happens here via unit_to_kg() instead of reading a
        // precomputed column.
        $incomingTransitQty += unit_to_kg((float) $o['quantity'], $o['unit'] ?? 'kg');
    }
}
$incomingTransitQty /= 1000; // MT, matching this page's other "MT" figures
$activeWarehouseCount = count($warehouseOverview);
// First Supply-Chain-Transparency node: admin sees how many supplier/
// farmer/importer accounts exist system-wide; account holders don't have
// a peer count that means anything on their own dashboard, so they see
// their own active listing count instead.
$flowFirstValue = $isAdmin
    ? count_users_by_role('supplier') + count_users_by_role('farmer') + count_users_by_role('importer')
    : count($availableStocks);
$flowFirstLabel = $isAdmin ? 'Suppliers' : 'My Listings';
$flowFirstMeta = $isAdmin ? 'Registered accounts' : 'Available for sale';

$orderStatusLabels = ['ordered' => 'Ordered', 'in_transit' => 'In Transit', 'delivered' => 'Delivered', 'completed' => 'Completed', 'disputed' => 'Disputed', 'cancelled' => 'Cancelled'];

// ---- Small chart-geometry helper (presentation only, not a data.php concern) ----
function kg_chart_points(array $values, int $width, int $height, int $padX = 30, int $padY = 14): array {
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
function kg_points_to_str(array $points): string {
    return implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $points));
}

$chartW = 680;
$chartH = 220;

// Every pill on the "Chart range" control (7D/30D/6M/1Y) now maps to a real
// series pulled from stock_movements -- day-level buckets for 7D/30D via
// get_daily_supply_trend(), month-level buckets for 6M/1Y via the existing
// get_monthly_supply_trend(). All four are computed up front and rendered
// server-side; the range pills just toggle which one is visible client-side
// (see assets/dashboard.js), no extra request needed.
$chartRanges = [
    '7d' => ['label' => '7D', 'trend' => get_daily_supply_trend(7, $scopeUserId)],
    '30d' => ['label' => '30D', 'trend' => get_daily_supply_trend(30, $scopeUserId)],
    '6m' => ['label' => '6M', 'trend' => $monthlyTrend],
    '1y' => ['label' => '1Y', 'trend' => get_monthly_supply_trend(12, $scopeUserId)],
];
$defaultRange = '6m';

foreach ($chartRanges as $rangeKey => &$range) {
    $trend = $range['trend'];
    $availSeries = array_column($trend, 'available');
    $inSeries = array_column($trend, 'incoming');
    $outSeries = array_column($trend, 'outgoing');
    $range['availPts'] = kg_chart_points($availSeries, $chartW, $chartH, 30, 16);
    $range['inPts'] = kg_chart_points($inSeries, $chartW, $chartH, 30, 30);
    $range['outPts'] = kg_chart_points($outSeries, $chartW, $chartH, 30, 30);
    $range['labels'] = array_column($trend, 'label');
    $range['trendPct'] = null;
    if (count($availSeries) >= 2) {
        $prevAvail = $availSeries[count($availSeries) - 2];
        $currAvail = $availSeries[count($availSeries) - 1];
        if ($prevAvail > 0) {
            $range['trendPct'] = (($currAvail - $prevAvail) / $prevAvail) * 100;
        }
    }
}
unset($range);
?>
<?php
/**
 * Renders one range's chart markup (legend + SVG + insight line, or an
 * empty state if there isn't enough history yet). Shared by all four
 * chart-range panels so 7D/30D/6M/1Y stay visually identical.
 */
function kg_render_trend_chart(string $rangeKey, array $range, int $chartW, int $chartH, bool $isAdmin, string $periodPhrase): void {
    $trend = $range['trend'];
    ?>
    <div class="kg-chart-panel<?php echo $rangeKey === '6m' ? ' is-active' : ''; ?>" data-chart-panel="<?php echo htmlspecialchars($rangeKey); ?>">
        <?php if (count($trend) < 2): ?>
            <p class="kg-empty">Not enough stock-movement history yet to plot <?php echo htmlspecialchars($range['label']); ?>.</p>
        <?php else: ?>
            <div class="kg-chart-legend">
                <span><i style="background:var(--kg-green)"></i>Available Stock</span>
                <span><i style="background:var(--kg-blue)"></i>Incoming Supply</span>
                <span><i style="background:var(--kg-gold)"></i>Outgoing Distribution</span>
            </div>
            <svg class="kg-chart-svg" viewBox="0 0 <?php echo $chartW; ?> <?php echo $chartH + 24; ?>" preserveAspectRatio="none">
                <?php
                $availPts = $range['availPts'];
                $areaPath = 'M' . $availPts[0][0] . ',' . $chartH;
                foreach ($availPts as $p) { $areaPath .= ' L' . $p[0] . ',' . $p[1]; }
                $areaPath .= ' L' . $availPts[count($availPts) - 1][0] . ',' . $chartH . ' Z';
                ?>
                <path d="<?php echo $areaPath; ?>" class="kg-area" fill="var(--kg-green)"></path>
                <polyline points="<?php echo kg_points_to_str($range['inPts']); ?>" class="kg-line kg-line-anim" style="--len:1600" stroke="var(--kg-blue)"></polyline>
                <polyline points="<?php echo kg_points_to_str($range['outPts']); ?>" class="kg-line kg-line-anim" style="--len:1600" stroke="var(--kg-gold)"></polyline>
                <polyline points="<?php echo kg_points_to_str($availPts); ?>" class="kg-line kg-line-anim" style="--len:1600" stroke="var(--kg-green)"></polyline>
                <?php foreach ($availPts as $i => $p): ?>
                    <circle cx="<?php echo $p[0]; ?>" cy="<?php echo $p[1]; ?>" r="3.2" fill="var(--kg-green)" class="kg-dot" style="animation-delay:<?php echo 500 + $i * 80; ?>ms"></circle>
                    <text x="<?php echo $p[0]; ?>" y="<?php echo $chartH + 18; ?>" font-size="11" fill="var(--kg-text-secondary)" text-anchor="middle"><?php echo htmlspecialchars($range['labels'][$i]); ?></text>
                <?php endforeach; ?>
            </svg>
            <?php if ($range['trendPct'] !== null): ?>
                <span class="kg-chart-insight">Available supply <?php echo $range['trendPct'] >= 0 ? 'increased' : 'decreased'; ?> by <?php echo number_format(abs($range['trendPct']), 1); ?>% over the previous <?php echo htmlspecialchars($periodPhrase); ?>.</span>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
?>
<?php
$periodPhrases = ['7d' => 'day', '30d' => 'day', '6m' => 'month', '1y' => 'month'];
?>
<div class="kg-dash">

    <?php // Compliance gate (Fix Guide item 7): shows the checklist until an admin approves the account; renders nothing for admins and approved users. ?>
    <?php include __DIR__ . '/../includes/compliance_banner.php'; ?>

    <!-- Welcome -->
    <div class="kg-welcome">
        <div>
            <h2>Good day, <?php echo htmlspecialchars($user['name'] ?? ($isAdmin ? 'Admin User' : 'User')); ?></h2>
            <p><?php echo $isAdmin
                ? 'Monitor rice supply, inventory, procurement, and transparency across the system.'
                : 'Monitor your inventory, warehouses, and procurement activity in one place.'; ?></p>
        </div>
        <span class="kg-live-badge" title="Live figures, current as of now">
            <span class="live-dot"></span>
            <?php echo htmlspecialchars(date('F j, Y')); ?>
        </span>
    </div>

    <!-- KPI grid — ordered per Section 10's priority list: (1) Available
         Stock, Reserved, Incoming, Low Stock get first billing; Total
         Inventory and Active Procurement follow as secondary context.
         Average price used to sit here too, but it isn't one of Section
         10's six named priorities and Transparency > Price Monitoring is
         now the fuller, authoritative home for it (normalized ₱/kg,
         trend chart, per-variety breakdown) — kept off the KPI row so it
         doesn't compete for attention with what this page should answer
         first: what do I have, what's committed, what's arriving, what
         needs attention. -->
    <div class="kg-kpi-grid">
        <div class="kg-kpi">
            <div class="kg-kpi__top">
                <span class="kg-kpi__icon"><?php echo nav_icon_svg('inventory'); ?></span>
                <span class="kg-status healthy">Healthy</span>
            </div>
            <div class="kg-kpi__value"><span class="kg-countup" data-value="<?php echo (float) $availableStockTotal; ?>" data-decimals="0">0</span><span class="unit">MT</span></div>
            <p class="kg-kpi__label"><?php echo $mine; ?>Available Stock</p>
            <p class="kg-kpi__sub"><?php echo number_format($availablePct, 0); ?>% of <?php echo $isAdmin ? 'total' : 'your'; ?> inventory</p>
        </div>

        <div class="kg-kpi">
            <div class="kg-kpi__top">
                <span class="kg-kpi__icon gold"><?php echo nav_icon_svg('pending'); ?></span>
                <?php if ($reservedStockTotal > 0): ?><span class="kg-status low">Committed</span><?php else: ?><span class="kg-status healthy">None</span><?php endif; ?>
            </div>
            <div class="kg-kpi__value"><span class="kg-countup" data-value="<?php echo (float) $reservedStockTotal; ?>" data-decimals="0">0</span><span class="unit">MT</span></div>
            <p class="kg-kpi__label"><?php echo $mine; ?>Reserved</p>
            <p class="kg-kpi__sub">Committed to accepted orders, not yet shipped</p>
        </div>

        <div class="kg-kpi">
            <div class="kg-kpi__top">
                <span class="kg-kpi__icon blue"><?php echo nav_icon_svg('delivery'); ?></span>
            </div>
            <div class="kg-kpi__value"><span class="kg-countup" data-value="<?php echo (int) $deliverySummary['count']; ?>" data-decimals="0">0</span></div>
            <p class="kg-kpi__label"><?php echo $mine; ?>Incoming</p>
            <p class="kg-kpi__sub"><?php echo (int) $deliverySummary['inTransit']; ?> currently in transit</p>
        </div>

        <div class="kg-kpi">
            <div class="kg-kpi__top">
                <span class="kg-kpi__icon gold"><?php echo nav_icon_svg('pending'); ?></span>
                <?php if (count($lowStockLocations) > 0): ?><span class="kg-status low">Warning</span><?php else: ?><span class="kg-status healthy">Clear</span><?php endif; ?>
            </div>
            <div class="kg-kpi__value"><span class="kg-countup" data-value="<?php echo count($lowStockLocations); ?>" data-decimals="0">0</span></div>
            <p class="kg-kpi__label"><?php echo $mine; ?>Low Stock Locations</p>
            <p class="kg-kpi__sub"><?php echo count($lowStockLocations) > 0 ? 'Requires attention' : 'All locations healthy'; ?></p>
        </div>

        <div class="kg-kpi">
            <div class="kg-kpi__top">
                <span class="kg-kpi__icon"><?php echo nav_icon_svg('warehouse'); ?></span>
                <?php if ($inventoryTrendPct !== null): ?>
                    <span class="kg-kpi__trend <?php echo $inventoryTrendPct >= 0 ? 'up' : 'down'; ?>"><?php echo ($inventoryTrendPct >= 0 ? '+' : '') . number_format($inventoryTrendPct, 1); ?>%</span>
                <?php endif; ?>
            </div>
            <div class="kg-kpi__value"><span class="kg-countup" data-value="<?php echo (float) $totalInventory; ?>" data-decimals="0">0</span><span class="unit">MT</span></div>
            <p class="kg-kpi__label"><?php echo $isAdmin ? 'Total Rice Inventory' : $mine . 'Rice Inventory'; ?></p>
            <p class="kg-kpi__sub"><?php echo $inventoryTrendPct !== null ? 'vs last month' : ($isAdmin ? 'Live total across all listings' : 'Live total across your listings'); ?></p>
        </div>

        <div class="kg-kpi">
            <div class="kg-kpi__top">
                <span class="kg-kpi__icon gold"><?php echo nav_icon_svg('coins'); ?></span>
            </div>
            <div class="kg-kpi__value">₱<span class="kg-countup" data-value="<?php echo (float) $procurementSummary['totalValue']; ?>" data-decimals="0">0</span></div>
            <p class="kg-kpi__label"><?php echo $mine; ?>Active Procurement</p>
            <p class="kg-kpi__sub"><?php echo (int) $procurementSummary['count']; ?> active procurement order<?php echo $procurementSummary['count'] === 1 ? '' : 's'; ?></p>
        </div>
    </div>

    <!-- Supply Trend + Warehouse Capacity (priority 2 + priority 3) -->
    <div class="kg-row-2">
        <div class="kg-card kg-reveal">
            <div class="kg-card__head">
                <div>
                    <h3><?php echo $isAdmin ? 'Rice Supply Trend' : 'My Supply Trend'; ?></h3>
                    <p><?php echo $isAdmin
                        ? 'Available stock, incoming supply, and outgoing distribution — last 6 months'
                        : 'Your available stock, stock-in, and stock-out — last 6 months'; ?></p>
                    <p class="kg-kpi__sub" style="margin-top:4px;">
                        Avg. price: ₱<?php echo number_format((float) $priceTrend['current'], 2); ?>/kg
                        <?php if ($priceTrend['changePct'] !== null): ?>
                            (<?php echo ($priceTrend['changePct'] >= 0 ? '+' : '') . number_format($priceTrend['changePct'], 1); ?>% vs 30 days ago)
                        <?php endif; ?>
                        — <a href="<?php echo htmlspecialchars(url('transparency/price-monitoring/')); ?>">full price trends →</a>
                    </p>
                </div>
                <div class="kg-pillbar" role="group" aria-label="Chart range">
                    <?php foreach ($chartRanges as $rangeKey => $range): ?>
                        <button type="button" class="kg-pill<?php echo $rangeKey === $defaultRange ? ' active' : ''; ?>" data-chart-range="<?php echo htmlspecialchars($rangeKey); ?>"<?php echo count($range['trend']) < 2 ? ' disabled title="Not enough stock-movement history yet for this range"' : ''; ?>><?php echo htmlspecialchars($range['label']); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php foreach ($chartRanges as $rangeKey => $range): ?>
                <?php kg_render_trend_chart($rangeKey, $range, $chartW, $chartH, $isAdmin, $periodPhrases[$rangeKey]); ?>
            <?php endforeach; ?>
        </div>

        <!-- Section 10 priority 3 (Warehouse Capacity) pairs with priority 2
             (Supply Trend, left) — was previously paired with AI Insights
             (priority 5), which visually outranked Warehouse Capacity and
             Procurement Overview (priority 4) just by sitting in the first
             row. -->
        <div class="kg-card kg-reveal">
            <div class="kg-card__head">
                <div>
                    <h3><?php echo $isAdmin ? 'Inventory by Location' : 'My Warehouses'; ?></h3>
                    <p>Warehouse stock vs. capacity</p>
                </div>
                <?php // Admin doesn't own warehouses (that page is restricted to
                // farmer/importer/supplier via require_permission), so this used
                // to 403 for admins — transparency/availability/ shows the same
                // system-wide stock picture without that restriction. ?>
                <a href="<?php echo htmlspecialchars(url($isAdmin ? 'transparency/availability/' : 'warehouses/')); ?>" class="kg-link-btn">View All Inventory <?php echo nav_icon_svg('arrow-right'); ?></a>
            </div>
            <?php if (empty($warehouseOverview)): ?>
                <p class="kg-empty"><?php echo $isAdmin ? 'No active warehouses yet.' : 'You have no active warehouses yet.'; ?></p>
            <?php else: ?>
                <?php foreach (array_slice($warehouseOverview, 0, 5) as $w): ?>
                    <div class="kg-location-row">
                        <div class="kg-location-row__top">
                            <span class="kg-location-row__name"><?php echo htmlspecialchars($w['location']); ?></span>
                            <span class="kg-location-row__qty"><?php echo number_format($w['used'], 0); ?> MT</span>
                            <span class="kg-status <?php echo htmlspecialchars($w['status']); ?>"><?php echo htmlspecialchars(ucfirst($w['status'])); ?></span>
                        </div>
                        <div class="kg-bar-track"><div class="kg-bar-fill <?php echo htmlspecialchars($w['status']); ?>" style="width:<?php echo max(3, round($w['pct'])); ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Procurement Overview + AI Intelligence (priority 4 + priority 5) -->
    <div class="kg-row-2">
        <div class="kg-card kg-reveal">
            <div class="kg-card__head">
                <div>
                    <h3><?php echo $isAdmin ? 'Procurement Overview' : 'My Procurement'; ?></h3>
                    <p><?php echo $isAdmin ? 'Active purchase orders by status' : 'Orders you\'ve sent or are fulfilling, by status'; ?></p>
                </div>
            </div>
            <div class="kg-proc-stats">
                <?php foreach ($orderStatusLabels as $key => $label): ?>
                    <div class="kg-proc-stat">
                        <strong><?php echo (int) ($overviewStats['orderStatusCounts'][$key] ?? 0); ?></strong>
                        <span><?php echo htmlspecialchars($label); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($recentOrders)): ?>
                <p class="kg-empty"><?php echo $isAdmin ? 'No procurement orders recorded yet.' : 'No procurement orders for your account yet.'; ?></p>
            <?php else: ?>
                <?php // Wrapped the same way the Active Stock table below is (.kg-table-wrap
                      // scrolls horizontally within the card) — this table was missing it,
                      // so a long order id on a phone-width screen could force the whole
                      // page to scroll horizontally instead of just this table. ?>
                <div class="kg-table-wrap">
                <table class="kg-mini-table">
                    <thead><tr><th>Order</th><th><?php echo $isAdmin ? 'Supplier' : 'With'; ?></th><th>Qty</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentOrders as $o): ?>
                        <?php $counterparty = (!$isAdmin && ($o['requesterId'] ?? null) === $scopeUserId) ? $o['supplierName'] : (!$isAdmin ? $o['requesterName'] : $o['supplierName']); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($o['id']); ?></td>
                            <td><?php echo htmlspecialchars($counterparty); ?></td>
                            <td><?php echo number_format((float) $o['quantity'], 0); ?> <?php echo htmlspecialchars($o['unit']); ?></td>
                            <?php
                            $orderRowStatusClass = 'info';
                            if ($o['status'] === 'completed') {
                                $orderRowStatusClass = 'healthy';
                            } elseif ($o['status'] === 'in_transit') {
                                $orderRowStatusClass = 'moderate';
                            } elseif (in_array($o['status'], ['cancelled', 'disputed'], true)) {
                                $orderRowStatusClass = 'critical';
                            }
                            ?>
                            <td><span class="kg-status <?php echo $orderRowStatusClass; ?>"><?php echo htmlspecialchars($orderStatusLabels[$o['status']] ?? ucfirst($o['status'])); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="kg-card kg-ai-card kg-reveal" id="ai-insights">
            <div class="kg-card__head">
                <div class="kg-ai-title">
                    <span class="kg-ai-icon"><?php echo nav_icon_svg('ai'); ?></span>
                    <div>
                        <h3>AI Supply Intelligence</h3>
                        <p>Rule-based analysis, trend indicators, and AI-assisted checks of current rice supply conditions</p>
                    </div>
                </div>
            </div>
            <div class="kg-ai-summary kg-ai-summary--<?php echo htmlspecialchars($aiSummary['source']); ?>">
                <span class="kg-insight__kind <?php echo htmlspecialchars($aiSummary['source'] === 'ai' ? 'ai_generated' : 'calculated'); ?>">
                    <?php echo $aiSummary['source'] === 'ai' ? 'AI-Generated Summary' : 'Summary'; ?>
                </span>
                <p><?php echo htmlspecialchars($aiSummary['text']); ?></p>
            </div>
            <p class="muted" style="margin:6px 0 12px;font-size:.85em;">These are advisory trend indicators (the last 30 days compared with the 30 days before), not predictions. The AI only advises; people decide.</p>
            <?php foreach ($aiInsights as $insight): ?>
                <div class="kg-insight <?php echo htmlspecialchars($insight['level']); ?>">
                    <span class="kg-insight__dot"></span>
                    <div class="kg-insight__body">
                        <div class="kg-insight__top">
                            <span class="kg-insight__title"><?php echo htmlspecialchars($insight['title']); ?></span>
                            <span class="kg-insight__badges">
                                <span class="kg-insight__kind <?php echo htmlspecialchars($insight['kind']); ?>"><?php echo htmlspecialchars(['calculated' => 'Calculated', 'forecast' => 'Trend indicator', 'ai_generated' => 'AI-Generated'][$insight['kind']] ?? ucfirst($insight['kind'])); ?></span>
                                <?php if ($insight['confidence'] !== null): ?>
                                    <span class="kg-insight__confidence"><?php echo (int) $insight['confidence']; ?>% document match</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <p class="kg-insight__msg"><?php echo htmlspecialchars($insight['message']); ?></p>
                        <?php if (in_array($insight['level'], ['critical', 'warning', 'recommendation'], true)): ?>
                            <button type="button" class="kg-insight__action" data-open-analysis>View Analysis →</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Transparency + Recent Activity -->
    <div class="kg-row-2">
        <div class="kg-card kg-reveal">
            <div class="kg-card__head">
                <div>
                    <h3><?php echo $isAdmin ? 'Supply Chain Transparency' : 'My Supply Chain'; ?></h3>
                    <p><?php echo $isAdmin ? 'Real-time flow from supplier to distribution' : 'Real-time flow of your own stock, start to finish'; ?></p>
                </div>
                <a href="<?php echo htmlspecialchars(url('transparency/')); ?>" class="kg-link-btn">View Transparency Portal <?php echo nav_icon_svg('arrow-right'); ?></a>
            </div>
            <div class="kg-flow">
                <div class="kg-flow-node">
                    <p class="kg-flow-node__label"><?php echo htmlspecialchars($flowFirstLabel); ?></p>
                    <p class="kg-flow-node__value"><?php echo (int) $flowFirstValue; ?></p>
                    <p class="kg-flow-node__meta"><?php echo htmlspecialchars($flowFirstMeta); ?></p>
                </div>
                <div class="kg-flow-arrow"><?php echo nav_icon_svg('flow-arrow'); ?></div>
                <div class="kg-flow-node">
                    <p class="kg-flow-node__label">Procurement</p>
                    <p class="kg-flow-node__value"><?php echo (int) $procurementSummary['count']; ?></p>
                    <p class="kg-flow-node__meta">Active orders</p>
                </div>
                <div class="kg-flow-arrow"><?php echo nav_icon_svg('flow-arrow'); ?></div>
                <div class="kg-flow-node">
                    <p class="kg-flow-node__label">Delivery</p>
                    <p class="kg-flow-node__value"><?php echo number_format($incomingTransitQty, 0); ?> MT</p>
                    <p class="kg-flow-node__meta">In transit</p>
                </div>
                <div class="kg-flow-arrow"><?php echo nav_icon_svg('flow-arrow'); ?></div>
                <div class="kg-flow-node">
                    <p class="kg-flow-node__label">Warehouse</p>
                    <p class="kg-flow-node__value"><?php echo number_format($totalInventory, 0); ?> MT</p>
                    <p class="kg-flow-node__meta"><?php echo (int) $activeWarehouseCount; ?> facilities</p>
                </div>
                <div class="kg-flow-arrow"><?php echo nav_icon_svg('flow-arrow'); ?></div>
                <div class="kg-flow-node">
                    <p class="kg-flow-node__label">Distribution</p>
                    <p class="kg-flow-node__value"><?php echo number_format($distributed30, 0); ?> MT</p>
                    <p class="kg-flow-node__meta">Last 30 days</p>
                </div>
            </div>
        </div>

        <div class="kg-card kg-reveal">
            <div class="kg-card__head">
                <div>
                    <h3>Recent Activity</h3>
                    <p><?php echo $isAdmin ? 'Latest system events' : 'Latest events on your account'; ?></p>
                </div>
            </div>
            <?php if (empty($activityLog)): ?>
                <p class="kg-empty">No activity recorded yet.</p>
            <?php else: ?>
                <?php foreach ($activityLog as $entry): ?>
                    <div class="kg-activity-item">
                        <span class="kg-activity-dot <?php echo str_contains($entry['action'], 'reject') || str_contains($entry['action'], 'low') ? 'warn' : 'ok'; ?>"></span>
                        <div class="kg-activity-text">
                            <?php echo htmlspecialchars(describe_activity_action($entry['action'])); ?>
                            <span class="kg-activity-time"><?php echo htmlspecialchars(time_ago($entry['createdAt'])); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <a href="<?php echo htmlspecialchars(url('admin/activity/')); ?>" class="kg-link-btn" style="margin-top:12px;display:inline-flex;">View Activity Log <?php echo nav_icon_svg('arrow-right'); ?></a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="kg-card kg-reveal">
        <div class="kg-card__head"><div><h3>Quick Actions</h3></div></div>
        <div class="kg-quick-grid">
            <?php if ($isAdmin): ?>
                <?php // Admin doesn't own inventory (require_permission restricts
                // inventory/ to farmer/importer/supplier), so "Add Inventory" used
                // to 403 here — Review Documents is a real admin action instead. ?>
                <a href="<?php echo htmlspecialchars(url('admin/documents/')); ?>" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('documents'); ?></span>Review Documents</a>
                <a href="<?php echo htmlspecialchars(url('procurement/tracking/')); ?>" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('tracking'); ?></span>Create Procurement</a>
                <a href="<?php echo htmlspecialchars(url('procurement/incoming/')); ?>" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('delivery'); ?></span>Record Delivery</a>
                <a href="<?php echo htmlspecialchars(url('admin/accounts/')); ?>" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('accounts'); ?></span>Add Supplier</a>
                <a href="<?php echo htmlspecialchars(url('reports/')); ?>" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('reports'); ?></span>Generate Report</a>
                <a href="#ai-insights" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('ai'); ?></span>View AI Analysis</a>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars(url('inventory/add/')); ?>" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('plus'); ?></span>Add Stock</a>
                <?php if (in_array($role, permission_allowed_roles('procurement.requests.create', ['supplier']), true)): ?>
                    <a href="<?php echo htmlspecialchars(url('marketplace/')); ?>" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('marketplace'); ?></span>Send Request</a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars(url('procurement/incoming/')); ?>" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('delivery'); ?></span>Incoming Requests</a>
                <a href="<?php echo htmlspecialchars(url('procurement/tracking/')); ?>" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('tracking'); ?></span>Track Orders</a>
                <a href="<?php echo htmlspecialchars(url('documents/upload/')); ?>" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('documents'); ?></span>Upload Document</a>
                <a href="#ai-insights" class="kg-quick-btn"><span class="kg-quick-btn__icon"><?php echo nav_icon_svg('ai'); ?></span>View AI Analysis</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Current Inventory Status -->
    <div class="kg-card kg-reveal">
        <div class="kg-card__head">
            <div>
                <h3><?php echo $isAdmin ? 'Current Inventory Status' : 'My Inventory Status'; ?></h3>
                <p><?php echo $isAdmin ? 'Live listing-level stock across the system' : 'Live listing-level stock for your account'; ?></p>
            </div>
            <a href="<?php echo htmlspecialchars(url($isAdmin ? 'transparency/availability/' : 'inventory/')); ?>" class="kg-link-btn">View All <?php echo nav_icon_svg('arrow-right'); ?></a>
        </div>
        <?php if (empty($activeStocks)): ?>
            <p class="kg-empty"><?php echo $isAdmin ? 'No stock listings recorded yet.' : 'You have no stock listings yet.'; ?></p>
        <?php else: ?>
            <div class="kg-table-wrap">
                <table class="kg-table">
                    <thead>
                        <tr><th>Location</th><th>Rice Variety</th><th>Current Stock</th><th>Status</th><th>Last Updated</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($activeStocks, 0, 8) as $stock): ?>
                        <?php
                        $warehouseName = '—';
                        if (!empty($stock['warehouseId'])) {
                            foreach ($warehouseOverview as $w) {
                                if ($w['id'] === $stock['warehouseId']) { $warehouseName = $w['location']; break; }
                            }
                        }
                        $threshold = function_exists('low_stock_threshold') ? low_stock_threshold() : 500;
                        $stockStatus = $stock['status'] === 'sold_out' ? 'critical' : (unit_to_kg((float) $stock['quantity'], (string) ($stock['unit'] ?? 'kg')) <= $threshold ? 'low' : 'healthy');
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($warehouseName); ?></td>
                            <td><?php echo htmlspecialchars($stock['variety']); ?></td>
                            <td><?php echo number_format((float) $stock['quantity'], 0); ?> <?php echo htmlspecialchars($stock['unit'] ?? 'MT'); ?></td>
                            <td><span class="kg-status <?php echo $stockStatus; ?>"><?php echo htmlspecialchars(ucfirst($stockStatus)); ?></span></td>
                            <td><?php echo htmlspecialchars(time_ago($stock['updatedAt'] ?? $stock['createdAt'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- AI Analysis side panel -->
<div class="kg-analysis-overlay" id="kg-analysis-overlay"></div>
<aside class="kg-analysis-panel" id="kg-analysis-panel">
    <div class="kg-analysis-panel__head">
        <div>
            <h3>AI Supply Assessment</h3>
            <p>Rule-based checks, trend indicators, and AI-assisted signals across current conditions</p>
        </div>
        <button type="button" class="kg-analysis-close" id="kg-analysis-close" aria-label="Close"><?php echo nav_icon_svg('x-circle'); ?></button>
    </div>
    <div class="kg-analysis-body">
        <div class="kg-analysis-stat"><span>Current Inventory</span><strong><?php echo number_format($totalInventory, 0); ?> MT</strong></div>
        <div class="kg-analysis-stat"><span>Available Stock</span><strong><?php echo number_format($availableStockTotal, 0); ?> MT</strong></div>
        <div class="kg-analysis-stat"><span>Low/Critical Locations</span><strong><?php echo count($lowStockLocations); ?> of <?php echo count($warehouseOverview); ?></strong></div>
        <div class="kg-analysis-stat"><span>Near-Capacity Locations</span><strong><?php echo count($nearCapacityLocations); ?> of <?php echo count($warehouseOverview); ?></strong></div>
        <div class="kg-analysis-stat"><span>Distribution Pace (30d)</span><strong><?php echo number_format($distributed30, 0); ?> MT</strong></div>
        <?php foreach ($aiInsights as $insight): ?>
            <div class="kg-analysis-reco">
                <strong><?php echo htmlspecialchars($insight['title']); ?></strong>
                <span class="kg-insight__kind <?php echo htmlspecialchars($insight['kind']); ?>"><?php echo htmlspecialchars(['calculated' => 'Calculated', 'forecast' => 'Trend indicator', 'ai_generated' => 'AI-Generated'][$insight['kind']] ?? ucfirst($insight['kind'])); ?></span>
                <br>
                <?php echo htmlspecialchars($insight['message']); ?>
            </div>
        <?php endforeach; ?>
    </div>
</aside>

<?php include __DIR__ . '/../includes/footer.php'; ?>
