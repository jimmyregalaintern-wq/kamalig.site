<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
// Same rule as sending a procurement request: only buyer accounts can use the cart.
require_permission('procurement.requests.create', ['supplier']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('marketplace/'));
    exit;
}
csrf_verify();

$userId = current_user_id() ?? '';
$back = commerce_safe_back($_POST['back'] ?? '', 'marketplace/');
$result = cart_add($userId, trim($_POST['stockId'] ?? ''), $_POST['quantity'] ?? '1');

if ($result['success']) {
    $_SESSION['flash_success'] = $result['variety'] . ' was added to your cart.';
    // "Buy now" skips straight to checkout.
    $back = !empty($_POST['buyNow']) ? 'checkout/' : $back;
} else {
    $_SESSION['flash_error'] = $result['error'];
}
header('Location: ' . url($back));
exit;
