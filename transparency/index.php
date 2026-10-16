<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_auth();

$title = 'Supply Overview';
$activePage = 'transparency';
include __DIR__ . '/../includes/header.php';

$stats = get_supply_overview_stats();
$totalQuantity = $stats['localQuantity'] + $stats['importedQuantity'];
$localPct = $totalQuantity > 0 ? round(($stats['localQuantity'] / $totalQuantity) * 100) : 0;
$importedPct = $totalQuantity > 0 ? 100 - $localPct : 0;

$orderLabels = [
    'ordered' => 'Ordered',
    'in_transit' => 'In Transit',
    'delivered' => 'Delivered',
    'completed' => 'Completed',
    'disputed' => 'Disputed',
    'cancelled' => 'Cancelled',
];

// System-wide (no userId) — same figures the dashboard's "Inventory by
// Location" card shows admins, just surfaced here too since Supply
// Overview is where a non-admin buyer/importer would look for a
// region-level view of where stock physically sits.
$warehouseOverview = get_warehouse_inventory_overview();
$complianceSummary = get_supplier_compliance_summary();
$shortageAlerts = get_variety_shortage_alerts();
?>
<h2>Supply Overview</h2>
<p class="muted">A system-wide view of what's in the supply chain right now — across every farmer, importer, and supplier on the platform.</p>

<div class="card">
    <a href="<?php echo htmlspecialchars(url('transparency/stock-movement/')); ?>" class="btn btn-secondary">Stock Movement</a>
    <a href="<?php echo htmlspecialchars(url('transparency/availability/')); ?>" class="btn btn-secondary">Supply Availability</a>
    <a href="<?php echo htmlspecialchars(url('transparency/price-monitoring/')); ?>" class="btn btn-secondary">Price Monitoring</a>
    <a href="<?php echo htmlspecialchars(url('transparency/batch-timeline/')); ?>" class="btn btn-secondary">Batch Timeline</a>
</div>

<?php if (!empty($shortageAlerts)): ?>
<div class="card" style="border-color: var(--kg-gold, #d97706);">
    <h3>⚠ Supply Shortage Alerts</h3>
    <p class="muted" style="margin-top:0;">These varieties have little available supply left system-wide, across every seller combined.</p>
    <ul style="margin: 0; padding-left: 20px;">
        <?php foreach ($shortageAlerts as $alert): ?>
            <li><strong><?php echo htmlspecialchars($alert['variety']); ?></strong> — only <?php echo htmlspecialchars(number_format($alert['availableKg'], 0)); ?> kg available <a href="<?php echo htmlspecialchars(url('transparency/availability/?variety=' . urlencode($alert['variety']))); ?>">(view listings)</a></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <h3>Where does the rice actually stand?</h3>
    <p class="muted" style="margin-top:0;">Every kilogram of physical rice in the system falls into exactly one of these — they always add up to Total Physical (Section 8: distinguishing Total Physical, Available, Reserved, In Transit, and Received rather than one blended "quantity" figure).</p>
    <div class="grid grid-4">
        <div>
            <p class="muted">Total Physical</p>
            <div class="stat"><?php echo htmlspecialchars(number_format($stats['totalPhysicalKg'], 0)); ?> kg</div>
        </div>
        <div>
            <p class="muted">Available</p>
            <div class="stat"><?php echo htmlspecialchars(number_format($stats['availableKg'], 0)); ?> kg</div>
        </div>
        <div>
            <p class="muted">Reserved</p>
            <div class="stat"><?php echo htmlspecialchars(number_format($stats['reservedKg'], 0)); ?> kg</div>
        </div>
        <div>
            <p class="muted">In Transit</p>
            <div class="stat"><?php echo htmlspecialchars(number_format($stats['inTransitKg'], 0)); ?> kg</div>
        </div>
    </div>
    <p class="muted" style="margin-top:12px;">Received into buyer inventory to date (all time): <strong><?php echo htmlspecialchars(number_format($stats['receivedKg'], 0)); ?> kg</strong></p>
</div>

<div class="card">
    <h3>Supplier Compliance</h3>
    <p class="muted" style="margin-top:0;">How many active sellers on the platform have at least one admin-verified identity document (business registration, permit, or certificate) on file.</p>
    <div class="grid grid-3">
        <div>
            <p class="muted">Active Sellers</p>
            <div class="stat"><?php echo htmlspecialchars((string) $complianceSummary['totalSuppliers']); ?></div>
        </div>
        <div>
            <p class="muted">Verified</p>
            <div class="stat" style="color: var(--kg-green-dark, #166534);"><?php echo htmlspecialchars((string) $complianceSummary['verifiedSuppliers']); ?></div>
        </div>
        <div>
            <p class="muted">Not Yet Verified</p>
            <div class="stat" style="color: var(--kg-gold-dark, #92400e);"><?php echo htmlspecialchars((string) $complianceSummary['unverifiedSuppliers']); ?></div>
        </div>
    </div>
    <?php if (user_role() === 'admin'): ?>
        <p class="muted" style="margin-top:12px;"><a href="<?php echo htmlspecialchars(url('admin/documents/')); ?>">Review pending documents →</a></p>
    <?php endif; ?>
</div>

<div class="grid grid-3">
    <div class="card">
        <h3>Active Listings</h3>
        <div class="stat"><?php echo htmlspecialchars((string) $stats['totalListings']); ?></div>
        <p class="muted">across <?php echo htmlspecialchars((string) $stats['totalVarieties']); ?> varieties</p>
    </div>
    <div class="card">
        <h3>Local Supply (kg)</h3>
        <div class="stat"><?php echo htmlspecialchars(number_format($stats['localQuantity'], 0)); ?></div>
        <p class="muted"><?php echo htmlspecialchars((string) $localPct); ?>% of available stock</p>
    </div>
    <div class="card">
        <h3>Imported Supply (kg)</h3>
        <div class="stat"><?php echo htmlspecialchars(number_format($stats['importedQuantity'], 0)); ?></div>
        <p class="muted"><?php echo htmlspecialchars((string) $importedPct); ?>% of available stock</p>
    </div>
</div>

<div class="card">
    <h3>Procurement Pipeline</h3>
    <p class="muted" style="margin-top:0;">Where active orders currently sit, end to end.</p>
    <div class="grid grid-2">
        <?php foreach ($orderLabels as $status => $label): ?>
            <div>
                <p class="muted"><?php echo htmlspecialchars($label); ?></p>
                <div class="stat"><?php echo htmlspecialchars((string) $stats['orderStatusCounts'][$status]); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="muted" style="margin-top:16px;">
        <a href="<?php echo htmlspecialchars(url('procurement/tracking/')); ?>">View Procurement Tracking →</a>
    </p>
</div>

<div class="card">
    <h3>Stock by Warehouse Location</h3>
    <p class="muted" style="margin-top:0;">Where physical stock currently sits, and how full each location is — system-wide, across every account.</p>
    <?php if (empty($warehouseOverview)): ?>
        <p>No active warehouses recorded yet.</p>
    <?php else: ?>
        <?php foreach ($warehouseOverview as $w): ?>
            <div class="kg-location-row">
                <div class="kg-location-row__top">
                    <span class="kg-location-row__name"><?php echo htmlspecialchars($w['location']); ?></span>
                    <span class="kg-location-row__qty"><?php echo htmlspecialchars(number_format($w['used'], 0)); ?><?php echo $w['capacity'] > 0 ? ' / ' . htmlspecialchars(number_format($w['capacity'], 0)) : ''; ?></span>
                    <span class="kg-status <?php echo htmlspecialchars($w['status']); ?>"><?php echo htmlspecialchars(ucfirst($w['status'])); ?></span>
                </div>
                <div class="kg-bar-track"><div class="kg-bar-fill <?php echo htmlspecialchars($w['status']); ?>" style="width:<?php echo max(3, round($w['pct'])); ?>%"></div></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
