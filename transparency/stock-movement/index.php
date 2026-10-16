<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$title = 'Stock Movement';
$activePage = 'transparency';

$varietyFilter = get_filter_value('variety', '');
$typeFilter = get_filter_value('type', '');
$dateFrom = get_filter_value('dateFrom', '');
$dateTo = get_filter_value('dateTo', '');
$filters = ['variety' => $varietyFilter, 'type' => $typeFilter, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo];

$perPage = 50;
$page = max(1, (int) get_filter_value('page', '1'));

$totals = get_movement_totals(30);
$result = get_all_stock_movements($filters, $page, $perPage);
$movements = $result['rows'];
$totalMovements = $result['total'];
$totalPages = max(1, (int) ceil($totalMovements / $perPage));
// A filtered page number can end up past the end of the filtered result
// (e.g. narrowing the date range after paging to page 5) — clamp instead
// of silently rendering an empty table with working-looking pager links.
if ($page > $totalPages) {
    $page = $totalPages;
    $result = get_all_stock_movements($filters, $page, $perPage);
    $movements = $result['rows'];
}

$varieties = get_varieties();

$typeLabels = [
    'in' => ['label' => 'Stock In', 'class' => 'badge-available'],
    'out' => ['label' => 'Stock Out', 'class' => 'badge-sold-out'],
    'adjustment' => ['label' => 'Adjustment', 'class' => 'badge-archived'],
    'transfer' => ['label' => 'Transfer', 'class' => 'badge-info'],
];

// Preserve the current filters on any link that changes something else
// about the query string (page, export) — otherwise clicking "Next" or
// "Export" silently drops whatever the person had just filtered by.
function stock_movement_query(array $overrides, array $filters, int $page): string {
    $params = array_filter(array_merge($filters, ['page' => $page], $overrides), fn($v) => $v !== '' && $v !== null);
    return http_build_query($params);
}

include __DIR__ . '/../../includes/header.php';
?>
<h2>Stock Movement</h2>
<p class="muted">Every stock in, out, adjustment, and warehouse transfer across the system, most recent first.
    <a href="<?php echo htmlspecialchars(url('transparency/')); ?>">← Back to Supply Overview</a>
</p>

<div class="grid grid-2">
    <?php foreach ($typeLabels as $type => $meta): ?>
        <div class="card">
            <h3><span class="badge <?php echo htmlspecialchars($meta['class']); ?>"><?php echo htmlspecialchars($meta['label']); ?></span></h3>
            <div class="stat"><?php echo htmlspecialchars((string) $totals[$type]['movementCount']); ?></div>
            <p class="muted">movements · <?php echo htmlspecialchars(number_format($totals[$type]['totalQuantity'], 2)); ?> kg total, last 30 days</p>
        </div>
    <?php endforeach; ?>
</div>

<form method="get" class="card">
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
            <label for="type">Type</label>
            <select id="type" name="type">
                <option value="">All types</option>
                <?php foreach ($typeLabels as $type => $meta): ?>
                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>><?php echo htmlspecialchars($meta['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div></div>
    </div>
    <div class="grid grid-2" style="margin-top: 16px;">
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
    <a href="<?php echo htmlspecialchars(url('transparency/stock-movement/')); ?>" class="btn btn-secondary" style="margin-top:16px;">Clear Filters</a>
    <a href="<?php echo htmlspecialchars(url('transparency/stock-movement/export/?' . stock_movement_query([], $filters, 1))); ?>" class="btn btn-secondary" style="margin-top:16px;">Export CSV</a>
</form>

<div class="card">
    <h3>Recent Movements</h3>
    <p class="muted" style="margin-top:0;"><?php echo htmlspecialchars((string) $totalMovements); ?> movement<?php echo $totalMovements === 1 ? '' : 's'; ?> match<?php echo $totalMovements === 1 ? 'es' : ''; ?> this filter.</p>
    <?php if (empty($movements)): ?>
        <p>No stock movements match this filter.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Variety</th>
                    <th>Source</th>
                    <th>Supplier</th>
                    <th>Batch</th>
                    <th>Type</th>
                    <th>Change</th>
                    <th>Before → After</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movements as $m): ?>
                    <?php
                        $type = $typeLabels[$m['type']] ?? ['label' => $m['type'], 'class' => 'badge-available'];
                        $sign = (float) $m['quantityChange'] > 0 ? '+' : '';
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($m['createdAt']); ?></td>
                        <td><?php echo htmlspecialchars($m['variety']); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($m['source'])); ?></td>
                        <td><?php echo htmlspecialchars($m['supplierName']); ?></td>
                        <td><?php echo htmlspecialchars($m['batchNumber'] ?? '—'); ?></td>
                        <td><span class="badge <?php echo htmlspecialchars($type['class']); ?>"><?php echo htmlspecialchars($type['label']); ?></span></td>
                        <td><?php echo htmlspecialchars($sign . $m['quantityChange']); ?> <?php echo htmlspecialchars($m['unit']); ?></td>
                        <td><?php echo htmlspecialchars($m['quantityBefore']); ?> → <?php echo htmlspecialchars($m['quantityAfter']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div style="margin-top:16px; display:flex; align-items:center; gap:8px;">
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary btn-sm" href="?<?php echo htmlspecialchars(stock_movement_query([], $filters, $page - 1)); ?>">← Previous</a>
                <?php endif; ?>
                <span class="muted">Page <?php echo htmlspecialchars((string) $page); ?> of <?php echo htmlspecialchars((string) $totalPages); ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-secondary btn-sm" href="?<?php echo htmlspecialchars(stock_movement_query([], $filters, $page + 1)); ?>">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
