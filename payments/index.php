<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_auth();

$title = 'Payments';
$activePage = 'payments';
$userId = current_user_id() ?? '';
$role = user_role();

include __DIR__ . '/../includes/header.php';
if (!commerce_ready()) {
    commerce_not_ready_notice();
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$filter = (string) ($_GET['status'] ?? '');
$validFilters = ['pending', 'submitted', 'paid', 'rejected', 'cod_pending', 'cancelled', 'refund_due'];
if (!in_array($filter, $validFilters, true)) {
    $filter = '';
}
$all = payments_for_user($userId, $role);
$payments = $filter === '' ? $all : array_values(array_filter($all, static fn($p) => $p['status'] === $filter));

// Summary tiles
$toPay = $toConfirm = $codDue = $paid = $refunds = 0.0;
$toPayCount = $toConfirmCount = 0;
foreach ($all as $p) {
    $mineAsBuyer = $p['buyerId'] === $userId;
    $mineAsSeller = $p['sellerId'] === $userId;
    $amount = (float) $p['amount'];
    if ($role === 'admin') {
        if (in_array($p['status'], ['pending', 'rejected'], true)) { $toPay += $amount; $toPayCount++; }
        if ($p['status'] === 'submitted') { $toConfirm += $amount; $toConfirmCount++; }
        if ($p['status'] === 'cod_pending') { $codDue += $amount; }
    } else {
        if ($mineAsBuyer && in_array($p['status'], ['pending', 'rejected'], true)) { $toPay += $amount; $toPayCount++; }
        if ($mineAsSeller && $p['status'] === 'submitted') { $toConfirm += $amount; $toConfirmCount++; }
        if ($mineAsBuyer && $p['status'] === 'cod_pending') { $codDue += $amount; }
    }
    if ($p['status'] === 'paid') { $paid += $amount; }
    if ($p['status'] === 'refund_due') { $refunds += $amount; }
}
$isSeller = $role !== 'admin';
?>
<h2>Payments</h2>
<p class="muted">Every order's payment in one place.</p>
<?php commerce_flash_messages(); ?>

<div class="pay-tiles">
    <div class="pay-tile"><span class="pay-tile__label"><?php echo $role === 'admin' ? 'Awaiting payment' : 'You need to pay'; ?></span>
        <strong><?php echo htmlspecialchars(commerce_money($toPay)); ?></strong><small><?php echo $toPayCount; ?> order<?php echo $toPayCount === 1 ? '' : 's'; ?></small></div>
    <div class="pay-tile"><span class="pay-tile__label"><?php echo $role === 'admin' ? 'Awaiting confirmation' : 'To confirm (received)'; ?></span>
        <strong><?php echo htmlspecialchars(commerce_money($toConfirm)); ?></strong><small><?php echo $toConfirmCount; ?> payment<?php echo $toConfirmCount === 1 ? '' : 's'; ?></small></div>
    <div class="pay-tile"><span class="pay-tile__label">Cash on delivery</span>
        <strong><?php echo htmlspecialchars(commerce_money($codDue)); ?></strong><small>to be paid at delivery</small></div>
    <div class="pay-tile pay-tile--ok"><span class="pay-tile__label">Paid</span>
        <strong><?php echo htmlspecialchars(commerce_money($paid)); ?></strong><small><?php echo $refunds > 0 ? htmlspecialchars(commerce_money($refunds)) . ' refund due' : 'all confirmed payments'; ?></small></div>
</div>

<div class="cart-toolbar">
    <form method="get" class="pay-filter">
        <label for="statusFilter" class="muted">Show</label>
        <select id="statusFilter" name="status" onchange="this.form.submit()">
            <option value="">All payments</option>
            <?php foreach ($validFilters as $f): ?>
                <option value="<?php echo $f; ?>" <?php echo $filter === $f ? 'selected' : ''; ?>><?php echo htmlspecialchars(payment_status_info($f)[0]); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($isSeller): ?>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('payments/accounts/')); ?>">My payment details</a>
    <?php endif; ?>
</div>

<div class="card">
    <?php if (empty($payments)): ?>
        <p>No payments yet. A payment appears here as soon as a seller accepts a request.</p>
    <?php else: ?>
        <div class="table-responsive pay-table-wrap">
        <table class="table pay-table">
            <thead><tr><th>Item</th><th>With</th><th>Method</th><th>Amount</th><th>Status</th><th>Order</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <?php
                $youPay = $p['buyerId'] === $userId;
                $with = $role === 'admin'
                    ? $p['requesterName'] . ' → ' . $p['supplierName']
                    : ($youPay ? 'Seller: ' . $p['supplierName'] : 'Buyer: ' . $p['requesterName']);
                ?>
                <tr>
                    <td data-label="Item"><?php echo htmlspecialchars($p['variety']); ?> <span class="muted"><?php echo htmlspecialchars($p['quantity'] . ' ' . $p['unit']); ?></span></td>
                    <td data-label="<?php echo $role === 'admin' ? 'Parties' : ($youPay ? 'Seller' : 'Buyer'); ?>"><?php echo htmlspecialchars($with); ?></td>
                    <td data-label="Method"><?php echo htmlspecialchars(commerce_method_label($p['method'])); ?></td>
                    <td data-label="Amount"><strong><?php echo htmlspecialchars(commerce_money($p['amount'])); ?></strong></td>
                    <td data-label="Status"><?php echo payment_badge($p['status']); ?></td>
                    <td data-label="Order" class="pay-orderid"><?php echo htmlspecialchars($p['orderId']); ?></td>
                    <td class="pay-open"><a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars(url('procurement/tracking/?id=' . urlencode($p['orderId']) . '#payment')); ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
