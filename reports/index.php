<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

$title = 'Analytics';
$activePage = 'reports';
include __DIR__ . '/../includes/header.php';

$pendingRequests = count_requests_by_status('pending');
$acceptedRequests = count_requests_by_status('accepted');
$rejectedRequests = count_requests_by_status('rejected');

$localStocks = count_stocks_by_source('local');
$importedStocks = count_stocks_by_source('imported');

$varietyCounts = get_stock_counts_by_variety();

$totalSuppliers = count_users_by_role('supplier');
$totalFarmers = count_users_by_role('farmer');
$totalImporters = count_users_by_role('importer');
?>
<h2>Analytics</h2>
<p class="muted">Operational metrics and supply analytics.</p>

<div class="card no-print">
    <p class="muted" style="margin-top:0;">Export</p>
    <a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('reports/export/?type=requests')); ?>">Procurement Requests (CSV)</a>
    <a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('reports/export/?type=stocks')); ?>">Inventory (CSV)</a>
    <a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('reports/export/?type=accounts')); ?>">Accounts (CSV)</a>
    <a class="btn btn-secondary" href="#" onclick="window.print(); return false;">Print / Save as PDF</a>
</div>

<div class="card">
    <h3>Procurement Requests</h3>
    <div class="grid grid-3">
        <div><p class="muted">Pending</p><div class="stat"><?php echo htmlspecialchars((string) $pendingRequests); ?></div></div>
        <div><p class="muted">Accepted</p><div class="stat"><?php echo htmlspecialchars((string) $acceptedRequests); ?></div></div>
        <div><p class="muted">Rejected</p><div class="stat"><?php echo htmlspecialchars((string) $rejectedRequests); ?></div></div>
    </div>
</div>

<div class="card">
    <h3>Stock by Source</h3>
    <div class="grid grid-2">
        <div><p class="muted">Local listings</p><div class="stat"><?php echo htmlspecialchars((string) $localStocks); ?></div></div>
        <div><p class="muted">Imported listings</p><div class="stat"><?php echo htmlspecialchars((string) $importedStocks); ?></div></div>
    </div>
</div>

<div class="card">
    <h3>Registered Accounts</h3>
    <div class="grid grid-3">
        <div><p class="muted">Suppliers</p><div class="stat"><?php echo htmlspecialchars((string) $totalSuppliers); ?></div></div>
        <div><p class="muted">Farmers</p><div class="stat"><?php echo htmlspecialchars((string) $totalFarmers); ?></div></div>
        <div><p class="muted">Importers</p><div class="stat"><?php echo htmlspecialchars((string) $totalImporters); ?></div></div>
    </div>
</div>

<div class="card">
    <h3>Stock by Variety</h3>
    <?php if (empty($varietyCounts)): ?>
        <p>No stock listings yet.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Variety</th><th>Listings</th><th>Total Quantity (kg)</th></tr></thead>
            <tbody>
                <?php foreach ($varietyCounts as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['variety']); ?></td>
                        <td><?php echo htmlspecialchars((string) $row['listingCount']); ?></td>
                        <td><?php echo htmlspecialchars(number_format($row['totalQuantityKg'], 0)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
