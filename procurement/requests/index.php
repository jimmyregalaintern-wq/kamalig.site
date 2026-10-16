<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$title = 'Procurement Requests';
$activePage = 'requests';
include __DIR__ . '/../../includes/header.php';

$userId = current_user_id() ?? '';
$role = user_role();
if ($role === 'admin') {
    $requests = get_requests();
} elseif ($role === 'supplier') {
    $requests = get_user_requests($userId, 'sent');
} else {
    $requests = get_user_requests($userId, 'received');
}
?>
<h2>Procurement Requests</h2>
<p class="muted">Track your procurement requests.</p>
<div class="card">
    <?php if (empty($requests)): ?>
        <p>No procurement requests found.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table--idfirst">
            <thead>
                <tr><th>ID</th><th>Variety</th><th>Batch</th><th>Quantity</th><th>Payment</th><th>Status</th><th>Documents</th><th>Order</th></tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <?php
                        $requestDocs = get_documents_for_request($request['id']);
                        $sourceStock = get_stock_by_id($request['stockId']);
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($request['id']); ?></td>
                        <td><?php echo htmlspecialchars($request['variety']); ?></td>
                        <td><?php echo htmlspecialchars($sourceStock['batchNumber'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($request['quantityRequested']); ?> <?php echo htmlspecialchars($request['unit']); ?></td>
                        <td>
                            <?php
                            $reqOrder = $request['status'] === 'accepted' ? get_procurement_order_by_request_id($request['id']) : null;
                            $reqPayment = $reqOrder ? payment_get_by_order($reqOrder['id']) : null;
                            ?>
                            <?php if ($reqPayment): ?>
                                <?php echo payment_badge($reqPayment['status']); ?><br><small class="muted"><?php echo htmlspecialchars(commerce_method_label($reqPayment['method'])); ?></small>
                            <?php elseif (!empty($request['paymentMethod'])): ?>
                                <small><?php echo htmlspecialchars(commerce_method_label($request['paymentMethod'])); ?></small>
                            <?php else: ?>
                                <span class="muted">—</span>
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
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
