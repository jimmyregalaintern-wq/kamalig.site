<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');

$title = 'Activity Log';
$activePage = 'adminactivity';

// Filter inputs
$filterAction = trim($_GET['action'] ?? '');
$filterEntityType = trim($_GET['entityType'] ?? '');
$filterUserId = trim($_GET['userId'] ?? '');
$searchDetails = trim($_GET['search'] ?? '');
$filterDateFrom = trim($_GET['dateFrom'] ?? '');
$filterDateTo = trim($_GET['dateTo'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

// Build filter conditions for display
$allEntries = get_activity_log(10000); // Fetch larger batch for client-side filtering
if (database_available()) {
    $allEntries = get_activity_log(10000);
} else {
    $allEntries = get_activity_log(10000);
}

$filtered = $allEntries;

if ($filterAction !== '') {
    $filtered = array_filter($filtered, fn($e) => $e['action'] === $filterAction);
}
if ($filterEntityType !== '') {
    $filtered = array_filter($filtered, fn($e) => $e['entityType'] === $filterEntityType);
}
if ($filterUserId !== '') {
    $filtered = array_filter($filtered, fn($e) => $e['userId'] === $filterUserId);
}
if ($searchDetails !== '') {
    $filtered = array_filter($filtered, fn($e) => str_contains(
        strtolower(($e['details'] ?? '') . ' ' . ($e['entityId'] ?? '')),
        strtolower($searchDetails)
    ));
}
if ($filterDateFrom !== '') {
    $cutoff = strtotime($filterDateFrom);
    $filtered = array_filter($filtered, fn($e) => strtotime($e['createdAt']) >= $cutoff);
}
if ($filterDateTo !== '') {
    $cutoff = strtotime($filterDateTo) + 86400; // End of day
    $filtered = array_filter($filtered, fn($e) => strtotime($e['createdAt']) < $cutoff);
}

$filtered = array_values($filtered);
$total = count($filtered);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$entries = array_slice($filtered, $offset, $perPage);

// Get list of unique values for filter dropdowns
$uniqueActions = array_values(array_unique(array_column($allEntries, 'action')));
sort($uniqueActions);
$uniqueEntityTypes = array_values(array_unique(array_column($allEntries, 'entityType')));
sort($uniqueEntityTypes);
$uniqueUserIds = array_values(array_unique(array_column($allEntries, 'userId')));
sort($uniqueUserIds);

$actionLabels = [
    'stock.create' => 'Stock listed',
    'stock.in' => 'Stock in',
    'stock.out' => 'Stock out',
    'stock.archive' => 'Stock archived',
    'stock.split' => 'Stock split',
    'stock.transfer' => 'Warehouse transfer',
    'request.create' => 'Request sent',
    'request.accept' => 'Request accepted',
    'request.reject' => 'Request rejected',
    'order.create' => 'Order created',
    'order.cancel' => 'Order cancelled',
    'order.received' => 'Order received',
    'document.upload' => 'Document uploaded',
    'document.verified' => 'Document verified',
    'document.rejected' => 'Document rejected',
    'document.correct' => 'Document corrected',
    'account.approve' => 'Account approved',
    'account.reject' => 'Account rejected',
    'account.suspend' => 'Account suspended',
    'account.reactivate' => 'Account reactivated',
    'account.role_change' => 'Role changed',
    'settings.update' => 'Settings updated',
    'setting.change' => 'Setting changed',
    'permissions.update' => 'Permissions updated',
    'permissions.bulk_update' => 'Permissions updated',
    'warehouse.create' => 'Warehouse created',
];

include __DIR__ . '/../../includes/header.php';
?>
<h2>Activity Log</h2>
<p class="muted">Centralized record of all platform activity. Timestamps in server timezone. <strong>New granular setting and permission changes</strong> are now logged individually with before→after values.</p>

<div class="card no-print">
    <a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('admin/activity/export/')); ?>">Export Audit Trail (CSV)</a>
    <a class="btn btn-secondary" href="#" onclick="window.print(); return false;">Print / Save as PDF</a>
</div>

<div class="card no-print">
    <h3 style="font-size: 1rem; margin-top: 0;">Filters</h3>
    <form method="get" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; align-items: end;">
        <div class="form-group" style="margin: 0;">
            <label for="search">Search details or ID</label>
            <input type="text" id="search" name="search" placeholder="Enter text to find" value="<?php echo htmlspecialchars($searchDetails); ?>">
        </div>
        <div class="form-group" style="margin: 0;">
            <label for="action">Action type</label>
            <select id="action" name="action">
                <option value="">All actions</option>
                <?php foreach ($uniqueActions as $act): ?>
                    <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $act === $filterAction ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($actionLabels[$act] ?? $act); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin: 0;">
            <label for="entityType">Entity type</label>
            <select id="entityType" name="entityType">
                <option value="">All types</option>
                <?php foreach ($uniqueEntityTypes as $type): ?>
                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $type === $filterEntityType ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(ucfirst($type)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin: 0;">
            <label for="userId">User</label>
            <select id="userId" name="userId">
                <option value="">All users</option>
                <?php foreach ($uniqueUserIds as $uid): ?>
                    <option value="<?php echo htmlspecialchars($uid ?? ''); ?>" <?php echo $uid === $filterUserId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($uid ?? '(system)'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin: 0;">
            <label for="dateFrom">Date from</label>
            <input type="date" id="dateFrom" name="dateFrom" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
        </div>
        <div class="form-group" style="margin: 0;">
            <label for="dateTo">Date to</label>
            <input type="date" id="dateTo" name="dateTo" value="<?php echo htmlspecialchars($filterDateTo); ?>">
        </div>
        <div style="display: flex; gap: 8px;">
            <button class="btn btn-primary" type="submit">Filter</button>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('admin/activity/')); ?>">Clear</a>
        </div>
    </form>
</div>

<div class="card">
    <div style="margin-bottom: 12px; font-size: 0.9rem; color: var(--kg-text-secondary);">
        Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $total); ?> of <?php echo number_format($total); ?> entries
        <?php if ($filterAction !== '' || $filterEntityType !== '' || $filterUserId !== '' || $searchDetails !== '' || $filterDateFrom !== '' || $filterDateTo !== ''): ?>
            <strong>(filtered)</strong>
        <?php endif; ?>
    </div>
    <?php if (empty($entries)): ?>
        <p>No activity matches these filters.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>When</th><th>User</th><th>Action</th><th>Entity Type</th><th>Details</th></tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars(date('M j, Y H:i:s', strtotime($entry['createdAt']))); ?></strong></td>
                        <td><?php echo htmlspecialchars($entry['userId'] ?? 'system'); ?></td>
                        <td><span class="kg-badge-action <?php echo htmlspecialchars(strpos($entry['action'], 'account') !== false ? 'account' : (strpos($entry['action'], 'document') !== false ? 'document' : (strpos($entry['action'], 'setting') !== false || strpos($entry['action'], 'permission') !== false ? 'admin' : 'default'))); ?>"><?php echo htmlspecialchars($actionLabels[$entry['action']] ?? $entry['action']); ?></span></td>
                        <td><?php echo htmlspecialchars(ucfirst($entry['entityType'])); ?></td>
                        <td>
                            <code style="font-size: 0.8rem; background: var(--kg-surface-soft); padding: 2px 4px; border-radius: 3px;">
                                <?php echo htmlspecialchars($entry['entityId'] ?? ''); ?>
                            </code>
                            <?php if ($entry['details']): ?>
                                <div style="margin-top: 4px; font-size: 0.85rem; color: var(--kg-text-secondary); line-height: 1.4;">
                                    <?php echo htmlspecialchars($entry['details']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
    <div class="card no-print" style="text-align: center; padding: 12px;">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
            <?php if ($p === $page): ?>
                <strong><?php echo $p; ?></strong>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars(url('admin/activity/?page=' . $p . ($filterAction ? '&action=' . urlencode($filterAction) : '') . ($filterEntityType ? '&entityType=' . urlencode($filterEntityType) : '') . ($filterUserId ? '&userId=' . urlencode($filterUserId) : '') . ($searchDetails ? '&search=' . urlencode($searchDetails) : '') . ($filterDateFrom ? '&dateFrom=' . urlencode($filterDateFrom) : '') . ($filterDateTo ? '&dateTo=' . urlencode($filterDateTo) : ''))); ?>"><?php echo $p; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<style>
.kg-badge-action {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.kg-badge-action.account {
    background: var(--kg-red-light);
    color: var(--kg-red);
}
.kg-badge-action.document {
    background: var(--kg-gold-light);
    color: var(--kg-gold-dark);
}
.kg-badge-action.admin {
    background: var(--kg-blue-light);
    color: var(--kg-blue);
}
.kg-badge-action.default {
    background: var(--kg-green-light);
    color: var(--kg-green-dark);
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

