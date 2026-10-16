<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_auth();

$title = 'Search';
$activePage = 'search';

$userId = current_user_id() ?? '';
$role = user_role();
$q = trim($_GET['q'] ?? '');

/**
 * Case-insensitive "does the needle appear in any of these haystacks"
 * check — mirrors how filters elsewhere in the app (Marketplace, etc.)
 * do plain substring matching in PHP rather than a SQL LIKE, so this
 * works identically whether or not a database is configured.
 */
function search_matches(string $needle, array $haystacks): bool {
    foreach ($haystacks as $h) {
        if ($h !== null && $h !== '' && mb_stripos((string) $h, $needle) !== false) {
            return true;
        }
    }
    return false;
}

$results = [
    'marketplace' => [],
    'inventory' => [],
    'warehouses' => [],
    'requests' => [],
    'orders' => [],
];

if ($q !== '') {
    // Marketplace: available listings from any account — same data
    // marketplace/index.php browses, open to every logged-in role.
    foreach (get_stocks() as $stock) {
        if ($stock['status'] !== 'available') {
            continue;
        }
        if (search_matches($q, [$stock['variety'], $stock['description'] ?? '', $stock['supplierName'] ?? '', $stock['countryOfOrigin'] ?? '', $stock['batchNumber'] ?? ''])) {
            $results['marketplace'][] = $stock;
        }
    }

    // My Inventory — only for roles that actually have the Inventory
    // feature (matches the permission check inventory/index.php uses;
    // an admin or a role an admin has toggled off just sees no section).
    $canInventory = in_array($role, permission_allowed_roles('inventory', ['farmer', 'importer', 'supplier']), true);
    if ($canInventory) {
        foreach (get_user_stocks($userId) as $stock) {
            if (search_matches($q, [$stock['variety'], $stock['description'] ?? '', $stock['batchNumber'] ?? ''])) {
                $results['inventory'][] = $stock;
            }
        }
    }

    // My Warehouses — same permission gate warehouses/index.php uses.
    $canWarehouses = in_array($role, permission_allowed_roles('warehouses', ['farmer', 'importer', 'supplier']), true);
    if ($canWarehouses) {
        foreach (get_user_warehouses($userId) as $warehouse) {
            if (search_matches($q, [$warehouse['name'], $warehouse['location'] ?? ''])) {
                $results['warehouses'][] = $warehouse;
            }
        }
    }

    // Procurement requests — admin sees every request (matches
    // procurement/incoming/'s admin view); everyone else sees their own
    // sent + received, deduplicated by id.
    $requests = $role === 'admin' ? get_requests() : array_merge(get_user_requests($userId, 'sent'), get_user_requests($userId, 'received'));
    $seenRequestIds = [];
    foreach ($requests as $request) {
        if (isset($seenRequestIds[$request['id']])) {
            continue;
        }
        if (search_matches($q, [$request['variety'], $request['requesterName'] ?? '', $request['supplierName'] ?? '', $request['id']])) {
            $results['requests'][] = $request;
            $seenRequestIds[$request['id']] = true;
        }
    }

    // Procurement orders — same admin-sees-all pattern as
    // procurement/tracking/.
    $orders = $role === 'admin' ? get_procurement_orders() : get_user_procurement_orders($userId);
    foreach ($orders as $order) {
        if (search_matches($q, [$order['variety'], $order['requesterName'] ?? '', $order['supplierName'] ?? '', $order['id'], $order['batchNumber'] ?? ''])) {
            $results['orders'][] = $order;
        }
    }
}

$totalResults = array_sum(array_map('count', $results));

include __DIR__ . '/../includes/header.php';
?>
<h2>Search</h2>
<?php if ($q === ''): ?>
    <p class="muted">Type something into the search box above and press Enter.</p>
<?php else: ?>
    <p class="muted">
        <?php echo (int) $totalResults; ?> result<?php echo $totalResults === 1 ? '' : 's'; ?> for
        "<strong><?php echo htmlspecialchars($q); ?></strong>"
    </p>

    <?php if ($totalResults === 0): ?>
        <div class="card">
            <p>No matches in Marketplace, Inventory, Warehouses, Requests, or Orders. Try a different variety, batch number, or name.</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($results['marketplace'])): ?>
        <h3>Marketplace</h3>
        <div class="card">
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Photo</th><th>Variety</th><th>Supplier</th><th>Price</th><th>Quantity</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($results['marketplace'] as $stock): ?>
                        <tr>
                            <td><img class="stock-thumb" src="<?php echo htmlspecialchars(stock_image_src($stock['imageUrl'] ?? null)); ?>" alt="<?php echo htmlspecialchars($stock['variety']); ?>"></td>
                            <td><?php echo htmlspecialchars($stock['variety']); ?></td>
                            <td><?php echo htmlspecialchars($stock['supplierName']); ?></td>
                            <td>₱<?php echo htmlspecialchars($stock['unitPrice']); ?>/kg</td>
                            <td><?php echo htmlspecialchars($stock['quantity']); ?> <?php echo htmlspecialchars($stock['unit']); ?></td>
                            <td><a href="<?php echo htmlspecialchars(url('marketplace/view/?id=' . urlencode($stock['id']))); ?>" class="btn btn-secondary btn-sm">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($results['inventory'])): ?>
        <h3>My Inventory</h3>
        <div class="card">
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Photo</th><th>Variety</th><th>Batch</th><th>Quantity</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($results['inventory'] as $stock): ?>
                        <tr>
                            <td><img class="stock-thumb" src="<?php echo htmlspecialchars(stock_image_src($stock['imageUrl'] ?? null)); ?>" alt="<?php echo htmlspecialchars($stock['variety']); ?>"></td>
                            <td><?php echo htmlspecialchars($stock['variety']); ?></td>
                            <td><?php echo htmlspecialchars($stock['batchNumber'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($stock['quantity']); ?> <?php echo htmlspecialchars($stock['unit']); ?></td>
                            <td><span class="badge badge-<?php echo $stock['status'] === 'available' ? 'available' : ($stock['status'] === 'archived' ? 'archived' : 'sold-out'); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $stock['status']))); ?></span></td>
                            <td><a href="<?php echo htmlspecialchars(url('inventory/edit/?id=' . urlencode($stock['id']))); ?>" class="btn btn-secondary btn-sm">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($results['warehouses'])): ?>
        <h3>My Warehouses</h3>
        <div class="card">
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Name</th><th>Location</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($results['warehouses'] as $warehouse): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($warehouse['name']); ?></td>
                            <td><?php echo htmlspecialchars($warehouse['location'] ?? '—'); ?></td>
                            <td><a href="<?php echo htmlspecialchars(url('warehouses/edit/?id=' . urlencode($warehouse['id']))); ?>" class="btn btn-secondary btn-sm">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($results['requests'])): ?>
        <h3>Procurement Requests</h3>
        <div class="card">
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>ID</th><th>Variety</th><th>Requester</th><th>Supplier</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($results['requests'] as $request): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($request['id']); ?></td>
                            <td><?php echo htmlspecialchars($request['variety']); ?></td>
                            <td><?php echo htmlspecialchars($request['requesterName'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($request['supplierName'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($request['status'])); ?></td>
                            <td><a href="<?php echo htmlspecialchars(url('procurement/incoming/')); ?>" class="btn btn-secondary btn-sm">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($results['orders'])): ?>
        <h3>Procurement Orders</h3>
        <div class="card">
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>ID</th><th>Variety</th><th>Batch</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($results['orders'] as $order): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($order['id']); ?></td>
                            <td><?php echo htmlspecialchars($order['variety']); ?></td>
                            <td><?php echo htmlspecialchars($order['batchNumber'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order['status']))); ?></td>
                            <td><a href="<?php echo htmlspecialchars(url('procurement/tracking/?id=' . urlencode($order['id']))); ?>" class="btn btn-secondary btn-sm">Track</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
