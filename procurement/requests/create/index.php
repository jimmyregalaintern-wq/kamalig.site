<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
// Only the supplier/trader account type sends procurement requests — nav
// only surfaces "Requests" for that role, and farmer/importer accounts are
// stock owners who receive requests, not senders.
require_permission('procurement.requests.create', ['supplier']);

$title = 'Request Stock';
$activePage = 'marketplace';

$stockId = trim($_GET['stockId'] ?? $_POST['stockId'] ?? '');
$stock = $stockId !== '' ? get_stock_by_id($stockId) : null;

$error = '';

if (!$stock) {
    header('Location: ' . url('marketplace/'));
    exit;
}

// Guard against requesting your own stock — the marketplace already hides
// the "Request Stock" button for stock you own, but that's a UI nicety, not
// enforcement. Anyone who reaches this page directly (typed/guessed URL,
// stale bookmark) with their own stockId must still be blocked server-side.
if ($stock['userId'] === (current_user_id() ?? '')) {
    $_SESSION['flash_error'] = 'You cannot send a procurement request for your own stock listing.';
    header('Location: ' . url('marketplace/'));
    exit;
}

$defaults = commerce_ready() ? checkout_defaults(current_user_id() ?? '') : ['phone' => '', 'address' => ''];
$values = [
    'paymentMethod' => array_key_first(commerce_payment_methods()),
    'deliveryAddress' => $defaults['address'],
    'contactPhone' => $defaults['phone'],
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $quantity = trim($_POST['quantity'] ?? '');
    $user = current_user();
    $values = array_merge($values, [
        'paymentMethod' => (string) ($_POST['paymentMethod'] ?? ''),
        'deliveryAddress' => (string) ($_POST['deliveryAddress'] ?? ''),
        'contactPhone' => (string) ($_POST['contactPhone'] ?? ''),
        'notes' => (string) ($_POST['notes'] ?? ''),
    ]);

    if ($quantity === '' || !is_numeric($quantity) || (float) $quantity <= 0) {
        $error = 'Please enter a valid quantity.';
    } elseif (!commerce_ready()) {
        $error = 'Ordering is not set up yet. Ask your administrator to run migration 04.';
    } else {
        // Same path as the cart: one checkout holding this single listing. The seller accepts
        // it as before, and a payment record is created at that point.
        $result = checkout_place_order($user ?? [], [['stockId' => $stock['id'], 'quantity' => (float) $quantity]], $values, false);
        if ($result['success']) {
            header('Location: ' . url('checkout/success/?id=' . urlencode($result['checkoutId'])));
            exit;
        }
        $error = implode(' ', $result['errors']);
    }
}

include __DIR__ . '/../../../includes/header.php';
?>
<h2>Buy Now</h2>
<p class="muted">Order this listing directly. Prefer to buy several items together? Use <strong>Add to Cart</strong> instead.</p>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<div class="card">
    <img class="stock-photo" style="max-width: 220px; border-radius: 10px; margin-bottom: 12px;" src="<?php echo htmlspecialchars(stock_image_src($stock['imageUrl'] ?? null)); ?>" alt="<?php echo htmlspecialchars($stock['variety']); ?>">
    <p><strong>Variety:</strong> <?php echo htmlspecialchars($stock['variety']); ?></p>
    <p><strong>Supplier:</strong> <?php echo htmlspecialchars($stock['supplierName']); ?></p>
    <p><strong>Available:</strong> <?php echo htmlspecialchars(stock_available_quantity($stock)); ?> <?php echo htmlspecialchars($stock['unit']); ?>
        <?php if ((float) $stock['reservedQuantity'] > 0): ?>
            <span class="muted">(<?php echo htmlspecialchars($stock['reservedQuantity']); ?> <?php echo htmlspecialchars($stock['unit']); ?> already reserved for other orders, of <?php echo htmlspecialchars($stock['quantity']); ?> total)</span>
        <?php endif; ?>
    </p>
    <p><strong>Price:</strong> ₱<?php echo htmlspecialchars($stock['unitPrice']); ?>/<?php echo htmlspecialchars($stock['unit']); ?></p>
</div>
<form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="stockId" value="<?php echo htmlspecialchars($stock['id']); ?>">
    <div class="form-group">
        <label for="quantity">Quantity to request (<?php echo htmlspecialchars($stock['unit']); ?>)</label>
        <input id="quantity" name="quantity" type="number" step="0.01" max="<?php echo htmlspecialchars($stock['quantity']); ?>" value="<?php echo htmlspecialchars($_POST['quantity'] ?? '1'); ?>" required>
    </div>
    <?php render_checkout_fields($values); ?>
    <button class="btn btn-primary" type="submit">Place order</button>
</form>
<?php include __DIR__ . '/../../../includes/footer.php'; ?>
