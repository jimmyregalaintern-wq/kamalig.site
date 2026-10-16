<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_auth();

// Shipment documents are private: only the buyer, the seller and admins may open one.
$order = get_procurement_order_by_id(trim((string) ($_GET['id'] ?? '')));
$userId = current_user_id() ?? '';
$allowed = $order && (user_role() === 'admin' || ($order['requesterId'] ?? '') === $userId || ($order['supplierId'] ?? '') === $userId);
$name = $order ? private_storage_ref_name($order['proofOfDeliveryUrl'] ?? null) : null;
if (!$allowed || $name === null) {
    render_error_page(404, 'Document not found', 'This document does not exist or you do not have access to it.');
}

$path = private_storage_find(PRIVATE_KIND_SHIPMENTS, $name);
if ($path === null) {
    render_error_page(404, 'Document not found', 'The file is missing from storage.');
}

private_storage_send($path, 'shipment-' . $order['id']);
