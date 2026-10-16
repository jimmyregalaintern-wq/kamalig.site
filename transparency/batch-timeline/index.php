<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$title = 'Batch Timeline';
$activePage = 'transparency';

$batchNumber = trim($_GET['batch'] ?? '');
$timeline = $batchNumber !== '' ? get_batch_timeline($batchNumber) : null;

// Recent batch numbers to make this discoverable without the user having
// to already know an exact code to type in — pulled from the requester's
// own inventory first (most relevant), falling back to the most recently
// created batches system-wide.
$recentBatches = array_values(array_unique(array_filter(array_column(get_stocks(), 'batchNumber'))));
rsort($recentBatches);
$recentBatches = array_slice($recentBatches, 0, 12);

include __DIR__ . '/../../includes/header.php';
?>
<h2>Batch Timeline</h2>
<p class="muted">Trace one batch/lot from listing through to buyer receipt — every stage it's passed through, in order.
    <a href="<?php echo htmlspecialchars(url('transparency/')); ?>">← Back to Supply Overview</a>
</p>

<form method="get" class="card">
    <div class="form-group">
        <label for="batch">Batch / Lot number</label>
        <input id="batch" name="batch" type="text" value="<?php echo htmlspecialchars($batchNumber); ?>" placeholder="e.g. LOT-20260115-A1B2C3" list="recentBatches">
        <datalist id="recentBatches">
            <?php foreach ($recentBatches as $b): ?>
                <option value="<?php echo htmlspecialchars($b); ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>
    <button class="btn btn-primary" type="submit">Trace Batch</button>
</form>

<?php if ($batchNumber !== '' && !$timeline): ?>
    <div class="card">
        <p>No batch found with the number "<?php echo htmlspecialchars($batchNumber); ?>".</p>
    </div>
<?php elseif ($timeline): ?>
    <div class="card">
        <h3>Batch <?php echo htmlspecialchars($timeline['batchNumber']); ?></h3>
        <p class="muted" style="margin-top:0;">
            <?php echo count($timeline['stocks']); ?> stock record<?php echo count($timeline['stocks']) === 1 ? '' : 's'; ?> under this batch (splits and buyer-received copies each get their own) —
            <?php echo count($timeline['events']); ?> event<?php echo count($timeline['events']) === 1 ? '' : 's'; ?> total.
        </p>
    </div>

    <div class="card">
        <h3>Currently held as</h3>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Owner</th><th>Quantity</th><th>Status</th><th>Warehouse</th></tr></thead>
            <tbody>
                <?php foreach ($timeline['stocks'] as $s): ?>
                    <?php $owner = get_user_by_id($s['userId']); ?>
                    <tr>
                        <td><?php echo htmlspecialchars($owner['name'] ?? $s['userId']); ?>
                            <?php if (is_verified_supplier($s['userId'])): ?>
                                <span class="badge badge-available" title="At least one identity document has been admin-verified">✓ Verified</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($s['quantity']); ?> <?php echo htmlspecialchars($s['unit']); ?>
                            <?php if ((float) ($s['reservedQuantity'] ?? 0) > 0): ?><span class="muted">(<?php echo htmlspecialchars($s['reservedQuantity']); ?> reserved)</span><?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $s['status'] === 'available' ? 'badge-available' : 'badge-sold-out'; ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $s['status']))); ?></span></td>
                        <td>
                            <?php if (!empty($s['warehouseId'])): ?>
                                <?php $wh = get_warehouse_by_id($s['warehouseId']); ?>
                                <?php echo htmlspecialchars($wh['name'] ?? '—'); ?>
                            <?php else: ?>
                                Unassigned
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <h3>Timeline</h3>
        <div class="kg-timeline">
            <?php foreach ($timeline['events'] as $event): ?>
                <div class="kg-timeline__item">
                    <div class="kg-timeline__dot"></div>
                    <div class="kg-timeline__body">
                        <p class="kg-timeline__stage"><?php echo htmlspecialchars($event['stage']); ?></p>
                        <p class="kg-timeline__detail"><?php echo htmlspecialchars($event['detail']); ?></p>
                        <p class="muted" style="font-size:0.85em;">
                            <?php echo htmlspecialchars($event['at']); ?>
                            <?php if (!empty($event['orderId'])): ?>
                                — <a href="<?php echo htmlspecialchars(url('procurement/tracking/?id=' . urlencode($event['orderId']))); ?>">View order</a>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <p class="muted">Enter a batch/lot number above, or pick one from the list, to see its full history.</p>
    </div>
<?php endif; ?>
<style>
.kg-timeline { position: relative; padding-left: 24px; }
.kg-timeline__item { position: relative; padding-bottom: 20px; border-left: 2px solid var(--kg-border, #e2e8f0); padding-left: 20px; margin-left: 4px; }
.kg-timeline__item:last-child { border-left-color: transparent; padding-bottom: 0; }
.kg-timeline__dot { position: absolute; left: -7px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: var(--kg-green, #16a34a); border: 2px solid #fff; }
.kg-timeline__stage { font-weight: 600; margin: 0; }
.kg-timeline__detail { margin: 2px 0; }
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
