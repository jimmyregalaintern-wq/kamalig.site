<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    render_error_page(403, 'Access denied', 'You do not have permission to view this page.');
}

$title = 'Warehouse Capacity';
$activePage = 'adminwarehouses';

// Read-only oversight. Owners set their own capacity (the system does not
// impose a limit); admin only watches how full each warehouse is and flags
// the ones that need a look.
$alertPct = warehouse_capacity_alert_threshold();
$reviewKg = max(1.0, (float) get_setting('WAREHOUSE_CAPACITY_REVIEW_KG', '5000000'));

$ownerNames = [];
$rows = [];
foreach (get_all_warehouses() as $wh) {
    if (($wh['status'] ?? 'active') === 'archived') {
        continue;
    }
    $ownerId = (string) ($wh['userId'] ?? '');
    if (!isset($ownerNames[$ownerId])) {
        $owner = $ownerId !== '' ? get_user_by_id($ownerId) : null;
        $ownerNames[$ownerId] = $owner
            ? trim(($owner['company'] ?? '') !== '' ? $owner['company'] : ($owner['name'] ?? 'Unknown'))
            : 'Unknown';
    }
    $capacity = (float) $wh['capacity'];
    $used = get_warehouse_capacity_used($wh['id']);
    $pct = $capacity > 0 ? ($used / $capacity) * 100 : 0.0;

    $flags = [];
    if ($capacity <= 0) {
        $flags[] = ['Capacity not set', 'badge-archived'];
    } elseif ($used > $capacity) {
        $flags[] = ['Over capacity', 'badge-sold-out'];
    } elseif ($pct >= $alertPct) {
        $flags[] = ['Near capacity', 'badge-sold-out'];
    }
    if ($capacity >= $reviewKg) {
        $flags[] = ['Very large capacity - verify', 'badge-info'];
    }

    $rows[] = [
        'id' => $wh['id'],
        'name' => $wh['name'],
        'location' => $wh['location'] ?? '',
        'owner' => $ownerNames[$ownerId],
        'capacity' => $capacity,
        'used' => $used,
        'pct' => $pct,
        'flags' => $flags,
    ];
}
usort($rows, fn($a, $b) => $b['pct'] <=> $a['pct']);

$totalCapacity = array_sum(array_column($rows, 'capacity'));
$totalUsed = array_sum(array_column($rows, 'used'));
$flagged = count(array_filter($rows, fn($r) => !empty($r['flags'])));

include __DIR__ . '/../../includes/header.php';
?>
<h2>Warehouse Capacity</h2>
<p class="muted">How full every user's warehouse is. Owners set their own capacity; this page is read-only and highlights warehouses at or above <?php echo htmlspecialchars((string) round($alertPct)); ?>% (the alert level in Settings) or with an unusually large capacity.</p>

<div class="card">
    <p>
        <strong><?php echo count($rows); ?></strong> active warehouse(s) &middot;
        <strong><?php echo htmlspecialchars(number_format($totalUsed, 0)); ?></strong> of
        <strong><?php echo htmlspecialchars(number_format($totalCapacity, 0)); ?></strong> kg used &middot;
        <strong><?php echo $flagged; ?></strong> need attention
    </p>
</div>

<div class="card">
    <?php if (empty($rows)): ?>
        <p>No warehouses have been registered yet.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Warehouse</th><th>Owner</th><th>Location</th><th>Used / Capacity (kg)</th><th>Usage</th><th>Flags</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['name']); ?></td>
                        <td><?php echo htmlspecialchars($r['owner']); ?></td>
                        <td><?php echo htmlspecialchars($r['location'] !== '' ? $r['location'] : '-'); ?></td>
                        <td><?php echo htmlspecialchars(number_format($r['used'], 2)); ?> / <?php echo htmlspecialchars(number_format($r['capacity'], 2)); ?></td>
                        <td>
                            <div style="background: rgba(128,128,128,0.2); border-radius: 4px; height: 8px; width: 120px; overflow: hidden;">
                                <div style="height: 8px; width: <?php echo (int) min(100, round($r['pct'])); ?>%; background: <?php echo $r['pct'] >= 100 ? '#c0392b' : ($r['pct'] >= $alertPct ? '#d68910' : '#2e8b57'); ?>;"></div>
                            </div>
                            <span class="muted" style="font-size: 0.85em;"><?php echo htmlspecialchars((string) round($r['pct'])); ?>%</span>
                        </td>
                        <td>
                            <?php if (empty($r['flags'])): ?>
                                <span class="muted">-</span>
                            <?php else: ?>
                                <?php foreach ($r['flags'] as $f): ?>
                                    <span class="badge <?php echo htmlspecialchars($f[1]); ?>"><?php echo htmlspecialchars($f[0]); ?></span>
                                <?php endforeach; ?>
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
