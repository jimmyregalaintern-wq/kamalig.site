<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();
if (user_role() === 'admin') {
    header('Location: ' . url('payments/'));
    exit;
}

$title = 'My Payment Details';
$activePage = 'payments';
$userId = current_user_id() ?? '';
$methods = array_filter(commerce_payment_methods(), static fn($key) => $key !== 'cod', ARRAY_FILTER_USE_KEY);

if (commerce_ready() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $method = (string) ($_POST['method'] ?? '');
    if (isset($methods[$method])) {
        if (($_POST['act'] ?? '') === 'remove') {
            payment_account_delete($userId, $method);
            $_SESSION['flash_success'] = commerce_method_label($method) . ' details removed.';
        } else {
            $result = payment_account_save($userId, $method, (string) ($_POST['accountName'] ?? ''), (string) ($_POST['accountNumber'] ?? ''), (string) ($_POST['bankName'] ?? ''), (string) ($_POST['notes'] ?? ''));
            $_SESSION[$result['success'] ? 'flash_success' : 'flash_error'] = $result['success'] ? commerce_method_label($method) . ' details saved.' : $result['error'];
        }
    }
    header('Location: ' . url('payments/accounts/'));
    exit;
}

include __DIR__ . '/../../includes/header.php';
if (!commerce_ready()) {
    commerce_not_ready_notice();
    include __DIR__ . '/../../includes/footer.php';
    exit;
}
$accounts = payment_accounts_for_user($userId);
?>
<h2>My Payment Details</h2>
<p class="muted">Where should buyers send your money? These details are shown to a buyer only after you accept their request. Cash on Delivery needs no details.</p>
<?php commerce_flash_messages(); ?>

<?php foreach ($methods as $key => $info): ?>
    <?php $account = $accounts[$key] ?? null; ?>
    <form method="post" class="card pay-account">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="method" value="<?php echo htmlspecialchars($key); ?>">
        <div class="pay-panel__head">
            <h4><?php echo htmlspecialchars($info['label']); ?></h4>
            <span class="pay-badge pay-badge--<?php echo $account ? 'paid' : 'pending'; ?>"><?php echo $account ? 'Set up' : 'Not set up'; ?></span>
        </div>
        <div class="form-group">
            <label for="name_<?php echo $key; ?>">Account name</label>
            <input id="name_<?php echo $key; ?>" name="accountName" type="text" maxlength="255" required value="<?php echo htmlspecialchars($account['accountName'] ?? ''); ?>">
        </div>
        <?php if ($key === 'bank_transfer'): ?>
            <div class="form-group">
                <label for="bank_<?php echo $key; ?>">Bank name</label>
                <input id="bank_<?php echo $key; ?>" name="bankName" type="text" maxlength="128" required value="<?php echo htmlspecialchars($account['bankName'] ?? ''); ?>">
            </div>
        <?php endif; ?>
        <div class="form-group">
            <label for="num_<?php echo $key; ?>"><?php echo $key === 'bank_transfer' ? 'Account number' : 'Mobile number'; ?></label>
            <input id="num_<?php echo $key; ?>" name="accountNumber" type="text" maxlength="40" required value="<?php echo htmlspecialchars($account['accountNumber'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label for="notes_<?php echo $key; ?>">Notes for buyers (optional)</label>
            <textarea id="notes_<?php echo $key; ?>" name="notes" rows="2" maxlength="500"><?php echo htmlspecialchars($account['notes'] ?? ''); ?></textarea>
        </div>
        <div class="cart-toolbar">
            <button class="btn btn-primary" type="submit" name="act" value="save">Save</button>
            <?php if ($account): ?>
                <button class="btn btn-secondary" type="submit" name="act" value="remove" formnovalidate onclick="return confirm('Remove these details?');">Remove</button>
            <?php endif; ?>
        </div>
    </form>
<?php endforeach; ?>
<a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('payments/')); ?>">Back to payments</a>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
