<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

// Payment receipts are private: only the buyer, the seller and admins may open one.
$payment = payment_get(trim((string) ($_GET['id'] ?? '')));
$userId = current_user_id() ?? '';
$allowed = $payment && (user_role() === 'admin' || $payment['buyerId'] === $userId || $payment['sellerId'] === $userId);
if (!$allowed || empty($payment['proofFile'])) {
    render_error_page(404, 'Receipt not found', 'This receipt does not exist or you do not have access to it.');
}

$path = commerce_proof_find((string) $payment['proofFile']);
if ($path === null) {
    render_error_page(404, 'Receipt not found', 'The receipt file is missing.');
}

$safeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
$mime = in_array($payment['proofMime'] ?? '', $safeTypes, true) ? $payment['proofMime'] : 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="receipt-' . preg_replace('/[^A-Za-z0-9_-]/', '', $payment['id']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
