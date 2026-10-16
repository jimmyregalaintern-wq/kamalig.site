<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('procurement.requests.create', ['supplier']);

$title = 'Checkout';
$activePage = 'cart';
$userId = current_user_id() ?? '';

if (!commerce_ready()) {
    include __DIR__ . '/../includes/header.php';
    commerce_not_ready_notice();
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$lines = cart_items_detailed($userId);
if (empty($lines)) {
    $_SESSION['flash_error'] = 'Your cart is empty.';
    header('Location: ' . url('cart/'));
    exit;
}
foreach ($lines as $line) {
    if ($line['issue'] !== null) {
        $_SESSION['flash_error'] = ($line['stock']['variety'] ?? 'An item') . ': ' . $line['issue'];
        header('Location: ' . url('cart/'));
        exit;
    }
}

$defaults = checkout_defaults($userId);
$values = [
    'paymentMethod' => array_key_first(commerce_payment_methods()),
    'deliveryAddress' => $defaults['address'],
    'contactPhone' => $defaults['phone'],
    'notes' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $values = array_merge($values, [
        'paymentMethod' => (string) ($_POST['paymentMethod'] ?? ''),
        'deliveryAddress' => (string) ($_POST['deliveryAddress'] ?? ''),
        'contactPhone' => (string) ($_POST['contactPhone'] ?? ''),
        'notes' => (string) ($_POST['notes'] ?? ''),
    ]);
    $submitLines = array_map(static fn($l) => ['stockId' => $l['stockId'], 'quantity' => $l['quantity']], $lines);
    $result = checkout_place_order(current_user() ?? [], $submitLines, $values, true);
    if ($result['success']) {
        header('Location: ' . url('checkout/success/?id=' . urlencode($result['checkoutId'])));
        exit;
    }
    $errors = $result['errors'];
}

$groups = [];
foreach ($lines as $line) {
    $groups[$line['sellerName']][] = $line;
}
$total = cart_total($lines);

include __DIR__ . '/../includes/header.php';
?>
<h2>Checkout</h2>
<p class="muted">Choose how you will pay and where to deliver. You are not charged yet.</p>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endforeach; ?>

<form method="post" class="cart-layout">
    <?php echo csrf_field(); ?>
    <div class="cart-lines">
        <?php render_checkout_fields($values); ?>
    </div>

    <aside class="card cart-summary">
        <h4>Your order</h4>
        <?php foreach ($groups as $sellerName => $groupLines): ?>
            <div class="co-seller">
                <p class="co-seller__name"><?php echo htmlspecialchars($sellerName); ?></p>
                <?php foreach ($groupLines as $line): ?>
                    <p class="co-item">
                        <span><?php echo htmlspecialchars($line['stock']['variety']); ?>
                            <small class="muted"><?php echo htmlspecialchars((string) $line['quantity']); ?> <?php echo htmlspecialchars($line['stock']['unit']); ?> × <?php echo htmlspecialchars(commerce_money($line['unitPrice'])); ?></small></span>
                        <strong><?php echo htmlspecialchars(commerce_money($line['lineTotal'])); ?></strong>
                    </p>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <dl class="cart-summary__rows">
            <dt class="cart-summary__total">Estimated total</dt><dd class="cart-summary__total"><?php echo htmlspecialchars(commerce_money($total)); ?></dd>
        </dl>
        <p class="muted cart-summary__note">Prices are locked at what you see here. Each seller reviews and accepts their part of the order.</p>
        <button class="btn btn-primary btn-block" type="submit">Place order</button>
        <a class="btn btn-secondary btn-block" href="<?php echo htmlspecialchars(url('cart/')); ?>">Back to cart</a>
    </aside>
</form>
<?php include __DIR__ . '/../includes/footer.php'; ?>
