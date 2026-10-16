<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_permission('procurement.requests.create', ['supplier']);

$title = 'Order Placed';
$activePage = 'cart';
$userId = current_user_id() ?? '';

$checkout = commerce_ready() ? checkout_get((string) ($_GET['id'] ?? '')) : null;
if (!$checkout || $checkout['buyerId'] !== $userId) {
    header('Location: ' . url('procurement/requests/'));
    exit;
}
$requests = query_all('SELECT * FROM requests WHERE checkoutId = ? ORDER BY createdAt ASC', [$checkout['id']]);

include __DIR__ . '/../../includes/header.php';
?>
<div class="card co-success">
    <p class="co-success__icon" aria-hidden="true">✓</p>
    <h2>Order placed</h2>
    <p class="muted">Your request<?php echo count($requests) === 1 ? ' was' : 's were'; ?> sent to the seller<?php echo count($requests) === 1 ? '' : 's'; ?>.
        Payment method: <strong><?php echo htmlspecialchars(commerce_method_label($checkout['paymentMethod'])); ?></strong>.
        Order reference: <strong><?php echo htmlspecialchars($checkout['id']); ?></strong></p>
</div>

<div class="card">
    <h4>What you ordered</h4>
    <?php foreach ($requests as $request): ?>
        <p class="co-item">
            <span><?php echo htmlspecialchars($request['variety']); ?>
                <small class="muted"><?php echo htmlspecialchars($request['quantityRequested']); ?> <?php echo htmlspecialchars($request['unit']); ?> from <?php echo htmlspecialchars($request['supplierName']); ?></small></span>
            <strong><?php echo htmlspecialchars(commerce_money((float) $request['quantityRequested'] * (float) ($request['unitPrice'] ?? 0))); ?></strong>
        </p>
    <?php endforeach; ?>
    <p class="co-item co-item--total"><span>Estimated total</span><strong><?php echo htmlspecialchars(commerce_money($checkout['subtotal'])); ?></strong></p>
    <p class="muted">Delivering to: <?php echo nl2br(htmlspecialchars($checkout['deliveryAddress'])); ?> · <?php echo htmlspecialchars($checkout['contactPhone']); ?></p>
</div>

<div class="card">
    <h4>What happens next</h4>
    <ol class="co-steps">
        <li><strong>The seller reviews your request</strong> and accepts it. You will get a notification.</li>
        <?php if ($checkout['paymentMethod'] === 'cod'): ?>
            <li><strong>The seller ships your order.</strong> No payment is needed now.</li>
            <li><strong>Pay in cash on delivery</strong> when the rice arrives, and inspect it.</li>
        <?php else: ?>
            <li><strong>Pay the seller</strong> by <?php echo htmlspecialchars(commerce_method_label($checkout['paymentMethod'])); ?>. The order page shows where to send it. Then enter your reference number.</li>
            <li><strong>The seller confirms your payment</strong> and ships the order.</li>
            <li><strong>Inspect the delivery</strong> and confirm what you received.</li>
        <?php endif; ?>
    </ol>
    <div class="cart-toolbar">
        <a class="btn btn-primary" href="<?php echo htmlspecialchars(url('procurement/requests/')); ?>">View my requests</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('marketplace/')); ?>">Continue shopping</a>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
