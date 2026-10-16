<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('payments/'));
    exit;
}
csrf_verify();

$userId = current_user_id() ?? '';
$role = user_role();
$paymentId = trim((string) ($_POST['paymentId'] ?? ''));
$act = (string) ($_POST['act'] ?? '');
$back = commerce_safe_back((string) ($_POST['back'] ?? ''), 'payments/');

switch ($act) {
    case 'submit':
        $result = payment_submit($paymentId, $userId, (string) ($_POST['referenceNumber'] ?? ''), (string) ($_POST['senderName'] ?? ''), $_FILES['proofFile'] ?? null);
        $okMessage = 'Payment submitted. The seller will confirm it shortly.';
        break;
    case 'confirm':
        $result = payment_confirm($paymentId, $userId, $role);
        $okMessage = 'Payment confirmed. The order can now be dispatched.';
        break;
    case 'reject':
        $result = payment_reject($paymentId, $userId, $role, (string) ($_POST['reason'] ?? ''));
        $okMessage = 'Payment rejected. The buyer has been asked to check it and resubmit.';
        break;
    case 'cod_paid':
        $result = payment_mark_cod_paid($paymentId, $userId, $role);
        $okMessage = 'Cash payment recorded.';
        break;
    default:
        $result = ['success' => false, 'error' => 'Unknown action.'];
        $okMessage = '';
}

if ($result['success']) {
    $_SESSION['flash_success'] = $okMessage;
} else {
    $_SESSION['flash_error'] = $result['error'] ?? 'Something went wrong.';
}
header('Location: ' . url($back) . (strpos($back, '#') === false && strpos($back, 'procurement/tracking') === 0 ? '#payment' : ''));
exit;
