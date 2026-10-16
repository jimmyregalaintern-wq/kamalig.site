<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$userId = current_user_id() ?? '';
$role = user_role();

// Handle the POST (accept/reject) — and any redirect it triggers — before
// includes/header.php is loaded below, since that include starts printing
// the page's HTML immediately; a header('Location: ...') call after any
// output has already started fails with "headers already sent".
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['requestId']) && !empty($_POST['action'])) {
    csrf_verify();
    $request = get_request_by_id($_POST['requestId']);
    // Only the supplier who owns the request (or an admin) may act on it,
    // and only while it's still pending — without this second check, a
    // duplicate/replayed submission (double-click, browser back + resubmit)
    // on an already-accepted request would decrement the stock a second
    // time, or flip an already-rejected request back to accepted.
    if ($request && ($role === 'admin' || $request['supplierId'] === $userId) && $request['status'] !== 'pending') {
        $_SESSION['flash_error'] = 'This request has already been ' . $request['status'] . ' and cannot be changed again.';
        header('Location: ' . url('procurement/incoming/'));
        exit;
    }
    if ($request && ($role === 'admin' || $request['supplierId'] === $userId)) {
        if ($_POST['action'] === 'accept') {
            // Phase 2: accepting a request RESERVES stock, it doesn't remove
            // it — the goods are still physically on the shelf until they're
            // actually dispatched (see the 'in_transit' transition in
            // procurement/tracking/index.php, which is where the real
            // stock-out movement now happens). This matches the guide's
            // pipeline (Acceptance -> Reservation -> Purchase Order ->
            // Dispatch) and its acceptance criterion "reserved stock does
            // not falsely reduce physical stock" — accepting used to call
            // decrement_stock_quantity() immediately, which understated
            // physical inventory for however long the goods sat waiting to
            // ship.
            // Fix Guide item 3: reserve stock, accept the request, create the
            // order and create its payment record in ONE database transaction.
            // If any step fails everything is rolled back, so there is never
            // reserved stock without an order, or an order without a payment.
            // (The helpers below open their own begin/commit; db.php turns
            // those into savepoints when they run inside this transaction.)
            $reserved = false;
            $newOrder = null;
            $acceptedRequest = null;
            begin_transaction();
            try {
                if (commerce_must_be_ready()) {
                    throw new RuntimeException('Payments are temporarily unavailable, so requests cannot be accepted right now. Please contact an administrator.');
                }
                $result = reserve_stock_quantity($request['stockId'], (float) $request['quantityRequested']);
                if (!$result['success']) {
                    throw new RuntimeException('Could not accept request: ' . $result['error']);
                }
                $reserved = true;
                // The status change is guarded (WHERE status = 'pending') so two
                // near-simultaneous Accepts cannot both win; the loser's stock
                // reservation is rolled back with the rest of its transaction.
                $acceptedRequest = update_request_status($request['id'], 'accepted', 'pending');
                if ($acceptedRequest === null) {
                    throw new RuntimeException('This request was already handled.');
                }
                $newOrder = create_procurement_order($acceptedRequest);
                // Fail closed: when payments are enabled, no payment row means no order.
                $payment = commerce_create_payment_for_order($newOrder, $acceptedRequest['paymentMethod'] ?? null);
                if (commerce_ready() && !$payment) {
                    throw new RuntimeException('The payment record could not be created.');
                }
                commit_transaction();
            } catch (Throwable $e) {
                rollback_transaction();
                if (!database_available() && $reserved) {
                    // Session-fallback mode has no real transaction; undo by hand.
                    release_stock_reservation($request['stockId'], (float) $request['quantityRequested']);
                    update_request_status($request['id'], 'pending', 'accepted');
                }
                error_log('KAMALIG accept failed for request ' . $request['id'] . ': ' . $e->getMessage());
                log_activity($userId, 'request.accept_failed', 'request', $request['id'], 'Accept rolled back: ' . $e->getMessage());
                // PDOException extends RuntimeException: never show SQL text to a user.
                $_SESSION['flash_error'] = (!($e instanceof PDOException) && $e instanceof RuntimeException)
                    ? $e->getMessage()
                    : 'The order could not be created, so nothing was changed. Please try again.';
                header('Location: ' . url('procurement/incoming/'));
                exit;
            }
            $newStatus = 'accepted';
            log_activity($userId, 'request.accept', 'request', $request['id'], $request['quantityRequested'] . ' ' . $request['unit'] . ' of ' . $request['variety'] . ' reserved');
        } else {
            $rejectedRequest = update_request_status($request['id'], 'rejected', 'pending');
            if ($rejectedRequest === null) {
                $_SESSION['flash_error'] = 'This request was already handled.';
                header('Location: ' . url('procurement/incoming/'));
                exit;
            }
            $newStatus = 'rejected';
            log_activity($userId, 'request.reject', 'request', $request['id']);
        }
        create_notification([
            'userId' => $request['requesterId'],
            'type' => 'request_status',
            'title' => 'Request ' . $newStatus,
            'message' => 'Your request for ' . $request['quantityRequested'] . ' ' . $request['unit'] . ' of ' . $request['variety'] . ' was ' . $newStatus . '.',
            'relatedId' => $request['id'],
        ]);
    }
    header('Location: ' . url('procurement/incoming/'));
    exit;
}

$title = 'Incoming Requests';
$activePage = 'procurementincoming';
include __DIR__ . '/../../includes/header.php';

if ($role === 'admin') {
    $requests = get_requests();
} else {
    $requests = get_user_requests($userId, 'received');
}
?>
<h2>Incoming Requests</h2>
<p class="muted">Review incoming procurement requests.</p>
<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="card" style="border-color: var(--border-danger, #c0392b); color: var(--text-danger, #c0392b);">
        <?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
    </div>
<?php endif; ?>
<div class="card">
    <?php if (empty($requests)): ?>
        <p>No incoming requests at the moment.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table--idfirst">
            <thead>
                <tr><th>ID</th><th>Requester</th><th>Variety</th><th>Batch</th><th>Quantity</th><th>Payment / Delivery</th><th>Status</th><th>Documents</th><th>Order</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <?php
                        $requestDocs = get_documents_for_request($request['id']);
                        $sourceStock = get_stock_by_id($request['stockId']);
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($request['id']); ?></td>
                        <td><?php echo htmlspecialchars($request['requesterName'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($request['variety']); ?></td>
                        <td><?php echo htmlspecialchars($sourceStock['batchNumber'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($request['quantityRequested']); ?> <?php echo htmlspecialchars($request['unit']); ?></td>
                        <td>
                            <?php
                            $reqMethod = $request['paymentMethod'] ?? null;
                            $checkoutInfo = (!empty($request['checkoutId']) && commerce_ready()) ? checkout_get($request['checkoutId']) : null;
                            ?>
                            <?php echo $reqMethod ? htmlspecialchars(commerce_method_label($reqMethod)) : '<span class="muted">—</span>'; ?>
                            <?php if ($request['unitPrice'] ?? null): ?><br><small class="muted"><?php echo htmlspecialchars(commerce_money($request['unitPrice'])); ?> / <?php echo htmlspecialchars($request['unit']); ?></small><?php endif; ?>
                            <?php if ($checkoutInfo): ?><br><small class="muted">Deliver to: <?php echo htmlspecialchars($checkoutInfo['deliveryAddress']); ?> · <?php echo htmlspecialchars($checkoutInfo['contactPhone']); ?></small><?php endif; ?>
                            <?php if ($request['status'] === 'pending' && $reqMethod && $reqMethod !== 'cod' && commerce_ready() && empty(payment_accounts_for_user(current_user_id() ?? '')[$reqMethod])): ?>
                                <br><small><a href="<?php echo htmlspecialchars(url('payments/accounts/')); ?>">Add your <?php echo htmlspecialchars(commerce_method_label($reqMethod)); ?> details</a> so the buyer knows where to pay.</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($request['status']); ?></td>
                        <td>
                            <?php if (empty($requestDocs)): ?>
                                <span class="muted">None</span>
                            <?php else: ?>
                                <?php echo count($requestDocs); ?> attached
                            <?php endif; ?>
                            <?php if ($request['status'] === 'accepted'): ?>
                                <a href="<?php echo htmlspecialchars(url('documents/upload/?requestId=' . urlencode($request['id']))); ?>">Attach</a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $requestOrder = $request['status'] === 'accepted' ? get_procurement_order_by_request_id($request['id']) : null; ?>
                            <?php if ($requestOrder): ?>
                                <a href="<?php echo htmlspecialchars(url('procurement/tracking/?id=' . urlencode($requestOrder['id']))); ?>" class="btn btn-secondary btn-sm">Track</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($request['status'] === 'pending'): ?>
                                <form method="post" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="requestId" value="<?php echo htmlspecialchars($request['id']); ?>">
                                    <button class="btn btn-primary" type="submit" name="action" value="accept">Accept</button>
                                    <button class="btn" type="submit" name="action" value="reject">Reject</button>
                                </form>
                            <?php else: ?>
                                —
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
