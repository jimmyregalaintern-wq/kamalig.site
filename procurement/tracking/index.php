<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$title = 'Procurement Tracking';
$activePage = 'procurementtracking';

$userId = current_user_id() ?? '';
$role = user_role();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['orderId']) && !empty($_POST['cancelAction'])) {
    csrf_verify();
    $order = get_procurement_order_by_id($_POST['orderId']);
    if ($order && ($role === 'admin' || $order['supplierId'] === $userId || $order['requesterId'] === $userId)) {
        $result = cancel_procurement_order($order['id'], $userId);
        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['error'];
        }
    }
    header('Location: ' . url('procurement/tracking/?id=' . urlencode($_POST['orderId'])));
    exit;
}

// Dispatch: 'ordered' -> 'in_transit'. This is the moment goods actually
// leave the supplier's shelf — reserve_stock_quantity() at Accept only
// committed the quantity, it didn't remove it (Phase 2: Reserved
// Inventory). Supports shipping less than the full ordered quantity
// (Phase 2: Partial Fulfillment — Section 6.2's "ordered 1,000kg, shipped
// 800kg" example), and captures the shipment details Purchase Order
// fields call for (Section 6.1): carrier, tracking reference, expected
// delivery date, proof of dispatch.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['orderId']) && ($_POST['step'] ?? '') === 'dispatch') {
    csrf_verify();
    $order = get_procurement_order_by_id($_POST['orderId']);
    $dispatchError = null;
    if (!$order || $order['status'] !== 'ordered' || $order['supplierId'] !== $userId) {
        // Admin is monitor-only on deliveries: only the supplier who holds the
        // stock can dispatch it (dispatch deducts that supplier's inventory).
        $dispatchError = 'This order cannot be dispatched right now.';
    } else {
        $quantityShipped = trim($_POST['quantityShipped'] ?? '');
        if ($quantityShipped === '' || !is_numeric($quantityShipped) || (float) $quantityShipped <= 0) {
            $dispatchError = 'Enter a valid quantity to ship.';
        } elseif ((float) $quantityShipped > (float) $order['quantity']) {
            $dispatchError = 'Cannot ship more than the ordered quantity (' . $order['quantity'] . ' ' . $order['unit'] . ').';
        } else {
            // Payment lock: non-COD orders ship only after the seller has confirmed the payment.
            $dispatchError = commerce_dispatch_blocker($order);
        }
    }

    $proofUrl = null;
    if ($dispatchError === null && !empty($_FILES['proofFile']['name'])) {
        try {
            $uploaded = handle_secure_document_upload($_FILES['proofFile'], private_storage_dir(PRIVATE_KIND_SHIPMENTS));
            $proofUrl = $uploaded['fileUrl'];
        } catch (RuntimeException $e) {
            $dispatchError = $e->getMessage();
        }
    }

    if ($dispatchError !== null) {
        $_SESSION['flash_error'] = $dispatchError;
        header('Location: ' . url('procurement/tracking/?id=' . urlencode($_POST['orderId'])));
        exit;
    }

    // One transaction. The order is CLAIMED first (ordered -> in_transit only if it
    // is still 'ordered'), so a cancel running at the same moment (payment deadline
    // cron, or the buyer) and a dispatch can never both succeed.
    begin_transaction();
    try {
        if (update_procurement_order_status($order['id'], 'in_transit', 'ordered') === null) {
            throw new RuntimeException('This order was cancelled or changed, so it cannot be dispatched.');
        }
        $result = decrement_stock_quantity(
            $order['stockId'],
            (float) $quantityShipped,
            'Dispatched for order ' . $order['id'] . ' to ' . ($order['requesterName'] ?? 'buyer'),
            'procurement_order',
            $order['id']
        );
        if (!$result['success']) {
            throw new RuntimeException('Could not dispatch: ' . $result['error']);
        }
        update_procurement_order_fields($order['id'], [
            'status' => 'in_transit',
            'quantityShipped' => (float) $quantityShipped,
            'carrier' => trim($_POST['carrier'] ?? '') ?: null,
            'trackingReference' => trim($_POST['trackingReference'] ?? '') ?: null,
            'expectedDeliveryDate' => trim($_POST['expectedDeliveryDate'] ?? '') ?: null,
            'proofOfDeliveryUrl' => $proofUrl,
        ]);
        log_activity($userId, 'order.in_transit', 'procurement_order', $order['id'], $quantityShipped . ' ' . $order['unit'] . ' of ' . $order['variety'] . ' dispatched');
        commit_transaction();
    } catch (Throwable $e) {
        rollback_transaction();
        error_log('KAMALIG dispatch failed for order ' . $order['id'] . ': ' . $e->getMessage());
        $_SESSION['flash_error'] = ($e instanceof RuntimeException && !($e instanceof PDOException))
            ? $e->getMessage()
            : 'The order could not be dispatched, so nothing was changed. Please try again.';
        header('Location: ' . url('procurement/tracking/?id=' . urlencode($_POST['orderId'])));
        exit;
    }
    create_notification([
        'userId' => $order['requesterId'],
        'type' => 'order_status',
        'title' => 'Order in transit',
        'message' => $quantityShipped . ' ' . $order['unit'] . ' of ' . $order['variety'] . ' has shipped' . (trim($_POST['expectedDeliveryDate'] ?? '') !== '' ? (', expected ' . trim($_POST['expectedDeliveryDate'])) : '') . '.',
        'relatedId' => $order['id'],
    ]);
    header('Location: ' . url('procurement/tracking/?id=' . urlencode($_POST['orderId'])));
    exit;
}

// Mark delivered: 'in_transit' -> 'delivered'. Deliberately does NOT
// auto-advance to 'completed' (Section 2's "Important: Delivered must not
// automatically mean Accepted" — a delivery can be awaiting inspection,
// short, damaged, incorrect, or disputed). Completion now only happens
// through the Receiving/Inspection step below.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['orderId']) && ($_POST['step'] ?? '') === 'mark_delivered') {
    csrf_verify();
    $order = get_procurement_order_by_id($_POST['orderId']);
    if ($order && $order['status'] === 'in_transit' && $order['supplierId'] === $userId) {
        update_procurement_order_fields($order['id'], ['status' => 'delivered', 'actualDeliveryDate' => date('Y-m-d H:i:s')]);
        log_activity($userId, 'order.delivered', 'procurement_order', $order['id'], $order['quantityShipped'] . ' ' . $order['unit'] . ' of ' . $order['variety']);
        create_notification([
            'userId' => $order['requesterId'],
            'type' => 'order_status',
            'title' => 'Order delivered',
            'message' => $order['variety'] . ' has been delivered — please inspect and confirm receipt.',
            'relatedId' => $order['id'],
        ]);
    }
    header('Location: ' . url('procurement/tracking/?id=' . urlencode($_POST['orderId'])));
    exit;
}

// Receiving & Quality Inspection: 'delivered' -> 'completed' or 'disputed'.
// The requester (buyer) records what actually arrived and its
// condition — this is the step that decides whether the delivery is
// Accepted, Conditionally accepted, Rejected, or Disputed (Section 2, step
// 17), and it's the only place stock is added to the buyer's own
// inventory (never assumed just because a delivery happened).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['orderId']) && ($_POST['step'] ?? '') === 'inspect') {
    csrf_verify();
    $order = get_procurement_order_by_id($_POST['orderId']);
    $inspectError = null;
    $shippedQty = (float) ($order['quantityShipped'] ?? $order['quantity'] ?? 0);
    if (!$order || $order['status'] !== 'delivered' || $order['requesterId'] !== $userId) {
        $inspectError = 'This order is not awaiting inspection.';
    } else {
        $quantityReceived = trim($_POST['quantityReceived'] ?? '');
        $inspectionStatus = $_POST['inspectionStatus'] ?? '';
        if ($quantityReceived === '' || !is_numeric($quantityReceived) || (float) $quantityReceived < 0) {
            $inspectError = 'Enter a valid received quantity (0 if nothing usable arrived).';
        } elseif ((float) $quantityReceived > $shippedQty) {
            $inspectError = 'Received quantity cannot exceed what was shipped (' . $shippedQty . ' ' . $order['unit'] . ').';
        } elseif (!in_array($inspectionStatus, ['accepted', 'conditional', 'rejected', 'disputed'], true)) {
            $inspectError = 'Choose an inspection outcome.';
        }
    }

    if ($inspectError !== null) {
        $_SESSION['flash_error'] = $inspectError;
        header('Location: ' . url('procurement/tracking/?id=' . urlencode($_POST['orderId'])));
        exit;
    }

    $quantityReceived = (float) $quantityReceived;
    $inspectionNotes = trim($_POST['inspectionNotes'] ?? '');

    // Accepted/conditional with a positive quantity is the only path that
    // creates buyer inventory — a rejected/disputed outcome (or an
    // accepted outcome where nothing usable arrived) must never silently
    // add stock that didn't actually pass inspection.
    if (in_array($inspectionStatus, ['accepted', 'conditional'], true) && $quantityReceived > 0) {
        update_procurement_order_fields($order['id'], [
            'quantityReceived' => $quantityReceived,
            'inspectionStatus' => $inspectionStatus,
            'inspectionNotes' => $inspectionNotes,
            'status' => 'completed',
        ]);
        $order = get_procurement_order_by_id($order['id']);
        receive_procurement_order_stock($order, $quantityReceived);
    } else {
        // Rejected, disputed, or "accepted" with zero usable quantity — no
        // buyer stock is created. The shipped quantity already left the
        // supplier's physical stock at dispatch time; resolving what
        // happens to it (return, discard, replacement shipment) is a
        // manual follow-up via Stock Adjustment for now rather than an
        // automatic reversal, since the guide's acceptance criteria only
        // requires that deliveries CAN be disputed, not that disputes
        // auto-resolve the physical goods.
        update_procurement_order_fields($order['id'], [
            'quantityReceived' => $quantityReceived,
            'inspectionStatus' => $inspectionStatus,
            'inspectionNotes' => $inspectionNotes,
            'status' => 'disputed',
        ]);
    }

    log_activity($userId, 'order.inspected', 'procurement_order', $order['id'], $inspectionStatus . ': ' . $quantityReceived . ' ' . $order['unit'] . ' received');
    create_notification([
        'userId' => $order['supplierId'],
        'type' => 'order_status',
        'title' => 'Delivery inspected: ' . ucfirst($inspectionStatus),
        'message' => $order['variety'] . ' — ' . $quantityReceived . ' ' . $order['unit'] . ' received, marked ' . $inspectionStatus . ($inspectionNotes !== '' ? (': ' . $inspectionNotes) : '.'),
        'relatedId' => $order['id'],
    ]);
    header('Location: ' . url('procurement/tracking/?id=' . urlencode($_POST['orderId'])));
    exit;
}

include __DIR__ . '/../../includes/header.php';

$statusLabels = [
    'ordered' => 'Ordered (Reserved)',
    'in_transit' => 'In Transit',
    'delivered' => 'Delivered — Awaiting Inspection',
    'completed' => 'Completed',
    'partially_fulfilled' => 'Partially Fulfilled',
    'disputed' => 'Disputed',
    'cancelled' => 'Cancelled',
];

function render_tracking_steps(string $currentStatus): void {
    // 'cancelled' and 'disputed' fall outside the linear
    // ordered -> ... -> completed pipeline PROCUREMENT_ORDER_STATUSES
    // models (both are branches off 'delivered', not further stages), so
    // the step loop below (which assumes currentStatus is always one of
    // those 4 stages) would emit an array_search() warning on either —
    // show a banner instead.
    if ($currentStatus === 'cancelled') {
        echo '<p><span class="badge badge-rejected">Order cancelled</span></p>';
        return;
    }
    if ($currentStatus === 'disputed') {
        echo '<p><span class="badge badge-rejected">Delivery disputed — awaiting resolution</span></p>';
        return;
    }
    echo '<div class="tracking-steps">';
    foreach (PROCUREMENT_ORDER_STATUSES as $i => $status) {
        if ($i > 0) {
            $connectorDone = array_search($status, PROCUREMENT_ORDER_STATUSES, true) <= array_search($currentStatus, PROCUREMENT_ORDER_STATUSES, true);
            echo '<div class="tracking-step__connector' . ($connectorDone ? ' is-done' : '') . '"></div>';
        }
        $isDone = array_search($status, PROCUREMENT_ORDER_STATUSES, true) < array_search($currentStatus, PROCUREMENT_ORDER_STATUSES, true);
        $isCurrent = $status === $currentStatus;
        $class = 'tracking-step' . ($isDone ? ' is-done' : '') . ($isCurrent ? ' is-current' : '');
        echo '<div class="' . $class . '"><span class="tracking-step__dot">' . ($isDone ? '✓' : ($i + 1)) . '</span><span class="tracking-step__label">' . htmlspecialchars(ucwords(str_replace('_', ' ', $status))) . '</span></div>';
    }
    echo '</div>';
}

$orderId = $_GET['id'] ?? null;

if ($orderId) {
    $order = get_procurement_order_by_id($orderId);
    if (!$order || ($role !== 'admin' && $order['requesterId'] !== $userId && $order['supplierId'] !== $userId)) {
        echo '<h2>Order not found</h2><p class="muted">This order does not exist or you do not have access to it.</p>';
        echo '<a href="' . htmlspecialchars(url('procurement/tracking/')) . '" class="btn btn-secondary">Back to Tracking</a>';
        include __DIR__ . '/../../includes/footer.php';
        exit;
    }
    $canDispatch = $order['status'] === 'ordered' && $order['supplierId'] === $userId;
    $canMarkDelivered = $order['status'] === 'in_transit' && $order['supplierId'] === $userId;
    $canInspect = $order['status'] === 'delivered' && $order['requesterId'] === $userId;
    // Cancel is only meaningful before goods have physically arrived — once
    // 'delivered', the order should be resolved via Inspection instead.
    $canCancel = in_array($order['status'], ['ordered', 'in_transit'], true)
        && ($role === 'admin' || $order['supplierId'] === $userId || $order['requesterId'] === $userId);
    $displayStatus = procurement_order_display_status($order);
    $dispatchBlocker = $canDispatch ? commerce_dispatch_blocker($order) : null;
    ?>
    <h2>Order <?php echo htmlspecialchars($order['id']); ?></h2>
    <p class="muted"><?php echo htmlspecialchars($order['quantity'] . ' ' . $order['unit'] . ' of ' . $order['variety']); ?> ordered — <?php echo htmlspecialchars($order['requesterName']); ?> from <?php echo htmlspecialchars($order['supplierName']); ?></p>
    <p class="muted">Batch/Lot: <strong>
        <?php if (!empty($order['batchNumber'])): ?>
            <a href="<?php echo htmlspecialchars(url('transparency/batch-timeline/?batch=' . urlencode($order['batchNumber']))); ?>"><?php echo htmlspecialchars($order['batchNumber']); ?></a>
        <?php else: ?>
            Not recorded
        <?php endif; ?>
    </strong></p>
    <p class="muted">
        Price at time of order: <strong>₱<?php echo htmlspecialchars(number_format((float) ($order['unitPrice'] ?? 0), 2)); ?> / <?php echo htmlspecialchars($order['unit']); ?></strong>
        — Total: <strong>₱<?php echo htmlspecialchars(number_format((float) ($order['totalPrice'] ?? 0), 2)); ?></strong>
        <br><span style="font-size: 0.85em;">This is fixed to what was agreed when the order was placed and won't change even if the listing's current price does.</span>
    </p>
    <?php if ($order['quantityShipped'] !== null): ?>
        <p class="muted">Shipped: <strong><?php echo htmlspecialchars($order['quantityShipped']); ?> <?php echo htmlspecialchars($order['unit']); ?></strong>
            <?php if ($order['carrier']): ?> via <?php echo htmlspecialchars($order['carrier']); ?><?php endif; ?>
            <?php if ($order['trackingReference']): ?> (Ref: <?php echo htmlspecialchars($order['trackingReference']); ?>)<?php endif; ?>
            <?php if ($order['expectedDeliveryDate']): ?> — expected <?php echo htmlspecialchars($order['expectedDeliveryDate']); ?><?php endif; ?>
            <?php if ($order['proofOfDeliveryUrl']): ?> — <a href="<?php echo htmlspecialchars((string) shipment_document_link($order)); ?>" target="_blank" rel="noopener">View document</a><?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($order['quantityReceived'] !== null): ?>
        <p class="muted">Received: <strong><?php echo htmlspecialchars($order['quantityReceived']); ?> <?php echo htmlspecialchars($order['unit']); ?></strong> — inspection: <strong><?php echo htmlspecialchars(ucfirst($order['inspectionStatus'] ?? '—')); ?></strong>
            <?php if (!empty($order['inspectionNotes'])): ?><br><span style="font-size: 0.9em;">"<?php echo nl2br(htmlspecialchars($order['inspectionNotes'])); ?>"</span><?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="card" style="border-color: var(--border-danger, #c0392b); color: var(--text-danger, #c0392b);">
            <?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
        </div>
    <?php endif; ?>
    <?php render_payment_panel($order, $role, $userId); ?>
    <div class="card">
        <?php render_tracking_steps($order['status']); ?>
        <p class="muted">Last updated <?php echo htmlspecialchars($order['updatedAt']); ?></p>

        <?php if ($canDispatch && $dispatchBlocker): ?>
            <div class="alert alert-error"><strong>Dispatch is locked.</strong> <?php echo htmlspecialchars($dispatchBlocker); ?></div>
        <?php elseif ($canDispatch): ?>
            <form method="post" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="orderId" value="<?php echo htmlspecialchars($order['id']); ?>">
                <input type="hidden" name="step" value="dispatch">
                <h4>Dispatch this order</h4>
                <div class="form-group">
                    <label for="quantityShipped">Quantity to ship (<?php echo htmlspecialchars($order['unit']); ?>)</label>
                    <input id="quantityShipped" name="quantityShipped" type="number" step="0.01" max="<?php echo htmlspecialchars($order['quantity']); ?>" value="<?php echo htmlspecialchars($order['quantity']); ?>" required>
                    <p class="muted" style="margin-top: 4px;">Ship less than the full amount if this is a partial shipment.</p>
                </div>
                <div class="form-group">
                    <label for="carrier">Carrier (optional)</label>
                    <input id="carrier" name="carrier" type="text" placeholder="e.g. Own truck, LBC Cargo">
                </div>
                <div class="form-group">
                    <label for="trackingReference">Tracking reference (optional)</label>
                    <input id="trackingReference" name="trackingReference" type="text">
                </div>
                <div class="form-group">
                    <label for="expectedDeliveryDate">Expected delivery date (optional)</label>
                    <input id="expectedDeliveryDate" name="expectedDeliveryDate" type="date">
                </div>
                <div class="form-group">
                    <label for="proofFile">Shipment document (optional, PDF/JPG/PNG)</label>
                    <input id="proofFile" name="proofFile" type="file" accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <button class="btn btn-primary" type="submit">Dispatch</button>
            </form>
        <?php elseif ($canMarkDelivered): ?>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="orderId" value="<?php echo htmlspecialchars($order['id']); ?>">
                <input type="hidden" name="step" value="mark_delivered">
                <button class="btn btn-primary" type="submit">Mark as Delivered</button>
            </form>
        <?php elseif ($canInspect): ?>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="orderId" value="<?php echo htmlspecialchars($order['id']); ?>">
                <input type="hidden" name="step" value="inspect">
                <h4>Receiving &amp; Quality Inspection</h4>
                <p class="muted">Delivered must not automatically mean accepted — confirm what actually arrived and its condition.</p>
                <div class="form-group">
                    <label for="quantityReceived">Quantity received (<?php echo htmlspecialchars($order['unit']); ?>)</label>
                    <input id="quantityReceived" name="quantityReceived" type="number" step="0.01" max="<?php echo htmlspecialchars($order['quantityShipped'] ?? $order['quantity']); ?>" value="<?php echo htmlspecialchars($order['quantityShipped'] ?? $order['quantity']); ?>" required>
                    <p class="muted" style="margin-top: 4px;">Shipped: <?php echo htmlspecialchars($order['quantityShipped'] ?? $order['quantity']); ?> <?php echo htmlspecialchars($order['unit']); ?>. Enter less if short, damaged, or incorrect.</p>
                </div>
                <div class="form-group">
                    <label for="inspectionStatus">Outcome</label>
                    <select id="inspectionStatus" name="inspectionStatus" required>
                        <option value="accepted">Accepted — matches order, in good condition</option>
                        <option value="conditional">Conditionally accepted — usable but with issues</option>
                        <option value="rejected">Rejected — not usable</option>
                        <option value="disputed">Disputed — needs admin/supplier resolution</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="inspectionNotes">Notes (optional)</label>
                    <textarea id="inspectionNotes" name="inspectionNotes" rows="3" placeholder="e.g. 2 sacks water-damaged, moisture content high"></textarea>
                </div>
                <button class="btn btn-primary" type="submit">Submit Inspection</button>
            </form>
        <?php elseif ($order['status'] === 'completed'): ?>
            <p><span class="badge badge-available"><?php echo htmlspecialchars($statusLabels[$displayStatus] ?? 'Completed'); ?></span></p>
        <?php elseif (in_array($order['status'], ['cancelled', 'disputed'], true)): ?>
            <!-- render_tracking_steps() already showed the banner above -->
        <?php elseif ($order['status'] === 'delivered'): ?>
            <p class="muted">Waiting on the buyer to inspect and confirm receipt.</p>
        <?php else: ?>
            <p class="muted">Waiting on the supplier to update this order's status.</p>
        <?php endif; ?>
        <?php if ($role === 'admin' && in_array($order['status'], ['ordered', 'in_transit', 'delivered'], true)): ?>
            <p class="muted" style="font-size: 0.9em;">Admin view: you can monitor this order and cancel it if it is stuck, but dispatch, delivery and receipt are done by the supplier and buyer.</p>
        <?php endif; ?>

        <?php if ($canCancel): ?>
            <form method="post" style="display:inline;" data-confirm-modal="Cancel this order? <?php echo htmlspecialchars($order['status'] === 'ordered' ? 'The reservation will be released.' : 'The shipped stock will be returned to the supplier.'); ?>" data-confirm-title="Cancel order">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="orderId" value="<?php echo htmlspecialchars($order['id']); ?>">
                <input type="hidden" name="cancelAction" value="1">
                <button class="btn btn-danger" type="submit">Cancel Order</button>
            </form>
        <?php endif; ?>
    </div>
    <a href="<?php echo htmlspecialchars(url('procurement/tracking/')); ?>" class="btn btn-secondary">Back to all orders</a>
    <?php
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$orders = $role === 'admin' ? get_procurement_orders() : get_user_procurement_orders($userId);
?>
<h2>Procurement Tracking</h2>
<p class="muted">Follow accepted orders from ordered through to completed delivery.</p>
<?php
// Admin monitoring: flag open orders that look stuck so admin can follow up
// with the supplier/buyer (admin does not dispatch or confirm receipt).
$attention = [];
if ($role === 'admin' && !empty($orders)) {
    $stallDays = max(1, (int) get_setting('ORDER_STALL_DAYS', '3'));
    $now = time();
    $today = date('Y-m-d');
    foreach ($orders as $o) {
        if (!in_array($o['status'], ['ordered', 'in_transit', 'delivered'], true)) {
            continue;
        }
        $lastTs = strtotime((string) ($o['updatedAt'] ?? $o['createdAt'] ?? ''));
        $idleDays = $lastTs ? (int) floor(($now - $lastTs) / 86400) : 0;
        $reasons = [];
        if ($o['status'] === 'in_transit' && !empty($o['expectedDeliveryDate']) && substr((string) $o['expectedDeliveryDate'], 0, 10) < $today) {
            $reasons[] = 'Past expected delivery date (' . substr((string) $o['expectedDeliveryDate'], 0, 10) . ')';
        }
        if ($idleDays >= $stallDays) {
            if ($o['status'] === 'ordered') {
                $reasons[] = 'Not dispatched, no update for ' . $idleDays . ' days';
            } elseif ($o['status'] === 'in_transit') {
                $reasons[] = 'In transit, tracking not updated for ' . $idleDays . ' days';
            } else {
                $reasons[] = 'Delivered, buyer has not inspected for ' . $idleDays . ' days';
            }
        }
        if ($reasons) {
            $attention[] = ['order' => $o, 'reasons' => $reasons, 'idle' => $idleDays];
        }
    }
    usort($attention, fn($a, $b) => $b['idle'] <=> $a['idle']);
}
?>
<?php if ($role === 'admin'): ?>
<div class="card">
    <h3>Needs attention</h3>
    <?php if (empty($attention)): ?>
        <p class="muted">No stalled deliveries. Open orders are moving normally.</p>
    <?php else: ?>
        <p class="muted"><?php echo count($attention); ?> open order(s) look stuck. Follow up with the supplier or buyer; dispatch and receipt are done by them, not by admin.</p>
        <div class="table-responsive">
        <table class="table table--idfirst">
            <thead>
                <tr><th>ID</th><th>Variety</th><th>Supplier</th><th>Buyer</th><th>Status</th><th>Why flagged</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($attention as $a): $o = $a['order']; ?>
                    <tr>
                        <td><?php echo htmlspecialchars($o['id']); ?></td>
                        <td><?php echo htmlspecialchars($o['variety']); ?></td>
                        <td><?php echo htmlspecialchars($o['supplierName']); ?></td>
                        <td><?php echo htmlspecialchars($o['requesterName']); ?></td>
                        <td><?php echo htmlspecialchars($statusLabels[$o['status']] ?? ucfirst($o['status'])); ?></td>
                        <td><?php echo htmlspecialchars(implode('; ', $a['reasons'])); ?></td>
                        <td><a href="<?php echo htmlspecialchars(url('procurement/tracking/?id=' . urlencode($o['id']))); ?>" class="btn btn-secondary btn-sm">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="card">
    <?php if (empty($orders)): ?>
        <p>No procurement orders yet. Orders appear here automatically once a request is accepted.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table--idfirst">
            <thead>
                <tr><th>ID</th><th>Variety</th><th>Batch</th><th>Quantity</th><th>Total Price</th><th>Requester</th><th>Supplier</th><th>Payment</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <?php $rowDisplayStatus = procurement_order_display_status($order); ?>
                    <tr>
                        <td><?php echo htmlspecialchars($order['id']); ?></td>
                        <td><?php echo htmlspecialchars($order['variety']); ?></td>
                        <td><?php echo htmlspecialchars($order['batchNumber'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($order['quantity']); ?> <?php echo htmlspecialchars($order['unit']); ?></td>
                        <td>₱<?php echo htmlspecialchars(number_format((float) ($order['totalPrice'] ?? 0), 2)); ?></td>
                        <td><?php echo htmlspecialchars($order['requesterName']); ?></td>
                        <td><?php echo htmlspecialchars($order['supplierName']); ?></td>
                        <?php $rowPayment = payment_get_by_order($order['id']); ?>
                        <td><?php echo $rowPayment ? payment_badge($rowPayment['status']) : '<span class="muted">—</span>'; ?></td>
                        <?php
                        $rowBadgeClass = 'badge-info';
                        if ($rowDisplayStatus === 'completed') {
                            $rowBadgeClass = 'badge-available';
                        } elseif (in_array($rowDisplayStatus, ['cancelled', 'disputed'], true)) {
                            $rowBadgeClass = 'badge-rejected';
                        } elseif ($rowDisplayStatus === 'partially_fulfilled') {
                            $rowBadgeClass = 'badge-sold-out';
                        }
                        ?>
                        <td><span class="badge <?php echo $rowBadgeClass; ?>"><?php echo htmlspecialchars($statusLabels[$rowDisplayStatus] ?? ucfirst($rowDisplayStatus)); ?></span></td>
                        <td><a href="<?php echo htmlspecialchars(url('procurement/tracking/?id=' . urlencode($order['id']))); ?>" class="btn btn-secondary btn-sm">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
