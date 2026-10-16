<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('procurement.requests.create', ['supplier']);

$title = 'Your Cart';
$activePage = 'cart';
$userId = current_user_id() ?? '';

if (!commerce_ready()) {
    include __DIR__ . '/../includes/header.php';
    commerce_not_ready_notice();
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!empty($_POST['remove'])) {
        cart_remove($userId, (string) $_POST['remove']);
        $_SESSION['flash_success'] = 'Item removed from your cart.';
    } elseif (($_POST['act'] ?? '') === 'clear') {
        cart_clear($userId);
        $_SESSION['flash_success'] = 'Your cart is now empty.';
    } else {
        $problems = 0;
        foreach ((array) ($_POST['qty'] ?? []) as $itemId => $rawQuantity) {
            if (!cart_update_quantity($userId, (string) $itemId, $rawQuantity)['success']) {
                $problems++;
            }
        }
        if ($problems > 0) {
            $_SESSION['flash_error'] = 'Some quantities were not valid and were left unchanged.';
        } else {
            $_SESSION['flash_success'] = 'Cart updated.';
        }
    }
    header('Location: ' . url('cart/'));
    exit;
}

$lines = cart_items_detailed($userId);
$groups = [];
foreach ($lines as $line) {
    $groups[$line['sellerId'] . '|' . $line['sellerName']][] = $line;
}
$hasIssue = false;
foreach ($lines as $line) {
    if ($line['issue'] !== null) {
        $hasIssue = true;
    }
}
$total = cart_total($lines);

include __DIR__ . '/../includes/header.php';
?>
<h2>Your Cart</h2>
<p class="muted">Review what you want to buy. Nothing is ordered or charged until you check out.</p>
<?php commerce_flash_messages(); ?>

<?php if (empty($lines)): ?>
    <div class="card cart-empty">
        <p><strong>Your cart is empty.</strong></p>
        <p class="muted">Browse the marketplace and add rice listings to your cart.</p>
        <a class="btn btn-primary" href="<?php echo htmlspecialchars(url('marketplace/')); ?>">Browse marketplace</a>
    </div>
<?php else: ?>
<form method="post" class="cart-layout">
    <?php echo csrf_field(); ?>
    <div class="cart-lines">
        <?php foreach ($groups as $key => $groupLines): ?>
            <?php [, $sellerName] = explode('|', $key, 2); ?>
            <section class="card cart-group">
                <h4 class="cart-group__title">Seller: <?php echo htmlspecialchars($sellerName); ?></h4>
                <?php foreach ($groupLines as $line): ?>
                    <?php $stock = $line['stock']; ?>
                    <article class="cart-line <?php echo $line['issue'] ? 'cart-line--issue' : ''; ?>">
                        <?php if ($stock): ?>
                            <img class="cart-line__img" src="<?php echo htmlspecialchars(stock_image_src($stock['imageUrl'] ?? null)); ?>" alt="">
                        <?php endif; ?>
                        <div class="cart-line__main">
                            <h5 class="cart-line__title">
                                <?php if ($stock): ?>
                                    <a href="<?php echo htmlspecialchars(url('marketplace/view/?id=' . urlencode($stock['id']))); ?>"><?php echo htmlspecialchars($stock['variety']); ?></a>
                                <?php else: ?>Unavailable listing<?php endif; ?>
                            </h5>
                            <?php if ($stock): ?>
                                <p class="muted"><?php echo htmlspecialchars(commerce_money($line['unitPrice'])); ?> / <?php echo htmlspecialchars($stock['unit']); ?>
                                    <?php if (!empty($stock['batchNumber'])): ?> · Batch <?php echo htmlspecialchars($stock['batchNumber']); ?><?php endif; ?></p>
                            <?php endif; ?>
                            <?php if ($line['issue']): ?><p class="cart-line__issue"><?php echo htmlspecialchars($line['issue']); ?></p><?php endif; ?>
                            <div class="cart-line__controls">
                                <label class="cart-qty">
                                    <span>Quantity<?php echo $stock ? ' (' . htmlspecialchars($stock['unit']) . ')' : ''; ?></span>
                                    <input type="number" name="qty[<?php echo htmlspecialchars($line['id']); ?>]" min="0.01" step="0.01" value="<?php echo htmlspecialchars((string) $line['quantity']); ?>">
                                </label>
                                <button class="btn btn-secondary btn-sm" type="submit" name="remove" value="<?php echo htmlspecialchars($line['id']); ?>" formnovalidate>Remove</button>
                            </div>
                        </div>
                        <div class="cart-line__total"><?php echo htmlspecialchars(commerce_money($line['lineTotal'])); ?></div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
        <div class="cart-toolbar">
            <button class="btn btn-secondary" type="submit" name="act" value="update">Update quantities</button>
            <button class="btn btn-secondary" type="submit" name="act" value="clear" formnovalidate onclick="return confirm('Remove everything from your cart?');">Clear cart</button>
        </div>
    </div>

    <aside class="card cart-summary">
        <h4>Order summary</h4>
        <dl class="cart-summary__rows">
            <dt>Items</dt><dd><?php echo count($lines); ?></dd>
            <dt>Sellers</dt><dd><?php echo count($groups); ?></dd>
            <dt class="cart-summary__total">Estimated total</dt><dd class="cart-summary__total"><?php echo htmlspecialchars(commerce_money($total)); ?></dd>
        </dl>
        <p class="muted cart-summary__note">Each seller accepts your request separately. You pay each seller after they accept, using the method you choose at checkout.</p>
        <?php if ($hasIssue): ?>
            <p class="cart-line__issue">Fix or remove the items marked above to continue.</p>
            <span class="btn btn-primary btn-block is-disabled" aria-disabled="true">Proceed to checkout</span>
        <?php else: ?>
            <a class="btn btn-primary btn-block" href="<?php echo htmlspecialchars(url('checkout/')); ?>">Proceed to checkout</a>
        <?php endif; ?>
        <a class="btn btn-secondary btn-block" href="<?php echo htmlspecialchars(url('marketplace/')); ?>">Continue shopping</a>
    </aside>
</form>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
