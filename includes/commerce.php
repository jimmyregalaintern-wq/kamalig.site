<?php
/**
 * includes/commerce.php — Shopping cart, checkout and payments.
 *
 * How it fits the existing procurement flow (nothing there is replaced):
 *
 *   Cart  ->  Checkout  ->  one `request` per cart line (as before)
 *         ->  seller ACCEPTS (as before; stock is reserved, an order is made)
 *         ->  a PAYMENT record is created for that order
 *         ->  buyer pays (bank / GCash / Maya) and submits the reference,
 *             or the order is Cash on Delivery
 *         ->  seller CONFIRMS the payment
 *         ->  seller can DISPATCH  ->  delivered  ->  buyer inspects (as before)
 *
 * Needs database migration 04_cart_and_payments.sql. Until it has been run,
 * commerce_ready() is false, the new pages explain what to do, and the old
 * request/order flow keeps working exactly as it did.
 *
 * .env switches (all optional):
 *   PAYMENT_METHODS=cod,bank_transfer,gcash,maya
 *   PAYMENT_REQUIRED_BEFORE_DISPATCH=true   (non-COD orders only)
 */
require_once __DIR__ . '/env.php';

/* ------------------------------------------------------------------ *
 * Setup / configuration
 * ------------------------------------------------------------------ */

/** True once migration 04 has been applied to the database. */
function commerce_ready(): bool {
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = database_available()
                && query_one("SHOW TABLES LIKE 'cart_items'") !== null
                && query_one("SHOW TABLES LIKE 'checkouts'") !== null
                && query_one("SHOW TABLES LIKE 'payments'") !== null
                && query_one("SHOW TABLES LIKE 'payment_accounts'") !== null
                && query_one("SHOW COLUMNS FROM requests LIKE 'checkoutId'") !== null;
        } catch (Throwable $e) {
            error_log('KAMALIG commerce_ready check failed: ' . $e->getMessage());
            $ready = false;
        }
    }
    return $ready;
}

/**
 * In production, "payments are not set up" is an ERROR, never a reason to let an
 * order through without a payment record. Outside production (local development
 * before the payment tables exist) the old permissive behaviour is kept.
 */
function commerce_must_be_ready(): bool {
    return strtolower((string) getenv('APP_ENV')) === 'production' && !commerce_ready();
}

/** Every payment method the system knows about (used for labels). */
function commerce_all_methods(): array {
    return [
        'cod' => [
            'label' => 'Cash on Delivery',
            'short' => 'COD',
            'desc' => 'Pay the seller in cash when the rice is delivered.',
            'needs_reference' => false,
        ],
        'bank_transfer' => [
            'label' => 'Bank Transfer',
            'short' => 'Bank',
            'desc' => "Transfer to the seller's bank account, then submit your reference number.",
            'needs_reference' => true,
        ],
        'gcash' => [
            'label' => 'GCash',
            'short' => 'GCash',
            'desc' => "Send to the seller's GCash number, then submit the reference number.",
            'needs_reference' => true,
        ],
        'maya' => [
            'label' => 'Maya',
            'short' => 'Maya',
            'desc' => "Send to the seller's Maya number, then submit the reference number.",
            'needs_reference' => true,
        ],
    ];
}

/** Methods currently offered at checkout (PAYMENT_METHODS in .env). */
function commerce_payment_methods(): array {
    $all = commerce_all_methods();
    $configured = (string) (getenv('PAYMENT_METHODS') ?: 'cod,bank_transfer,gcash,maya');
    $enabled = array_filter(array_map('trim', explode(',', $configured)));
    $methods = array_intersect_key($all, array_flip($enabled));
    return $methods ?: ['cod' => $all['cod']];
}

function commerce_method_label(?string $method): string {
    $all = commerce_all_methods();
    return $all[$method ?? '']['label'] ?? 'Not specified';
}

function commerce_money($amount): string {
    return '₱' . number_format((float) $amount, 2);
}

function commerce_new_id(string $prefix): string {
    return $prefix . '_' . date('ymdHis') . '_' . bin2hex(random_bytes(3));
}

function commerce_now(): string {
    return date('Y-m-d H:i:s');
}

/**
 * Payment deadline (Fix Guide item 6). Hours a buyer has to pay a non-COD order
 * before scripts/payment_deadline_cron.php cancels it and releases the stock.
 * Set PAYMENT_DEADLINE_HOURS in .env to change it (default 48).
 */
function commerce_payment_deadline_hours(): int {
    $hours = (int) (getenv('PAYMENT_DEADLINE_HOURS') ?: 48);
    return $hours > 0 ? $hours : 48;
}

/** True once install_update.php has added payments.dueAt. Until then deadlines are simply off. */
function commerce_has_due_column(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = database_available() && query_one("SHOW COLUMNS FROM payments LIKE 'dueAt'") !== null;
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Where payment proofs were kept before they moved to private storage (still searched, until migrated). */
function commerce_legacy_proof_dir(): string {
    return dirname(__DIR__) . '/uploads/payment-proofs';
}

/**
 * Directory NEW payment proofs are written to: private storage, outside the web
 * root when possible (same place as compliance documents). Proofs are only
 * served through payments/proof/ after a permission check.
 */
function commerce_proof_dir(): string {
    try {
        return private_storage_dir(PRIVATE_KIND_PAYMENTS);
    } catch (Throwable $e) {
        error_log('KAMALIG private storage unavailable for payment proofs, using the closed uploads folder: ' . $e->getMessage());
        $dir = commerce_legacy_proof_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $guard = $dir . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "# Payment proofs are private. They are served by payments/proof/ after a permission check.\nRequire all denied\n");
        }
        return $dir;
    }
}

/** Absolute path of a stored proof file (new location first, then the old one), or null. */
function commerce_proof_find(string $storedName): ?string {
    $storedName = basename($storedName);
    if (!private_storage_valid_name($storedName)) {
        return null;
    }
    $dirs = [];
    foreach (private_storage_candidates() as $root) {
        $dirs[] = $root . '/' . PRIVATE_KIND_PAYMENTS;
    }
    $dirs[] = commerce_legacy_proof_dir();
    foreach ($dirs as $dir) {
        $realDir = realpath($dir);
        $real = $realDir !== false ? realpath($dir . '/' . $storedName) : false;
        if ($real !== false && is_file($real) && strpos($real, $realDir . DIRECTORY_SEPARATOR) === 0) {
            return $real;
        }
    }
    return null;
}

/** Move proofs from the old uploads/payment-proofs folder to private storage. Safe to repeat. */
function commerce_migrate_payment_proofs(bool $apply): array {
    $result = ['found' => 0, 'moved' => 0, 'failed' => 0];
    $legacy = realpath(commerce_legacy_proof_dir());
    if ($legacy === false) {
        return $result;
    }
    foreach (scandir($legacy) ?: [] as $name) {
        if (!is_file($legacy . '/' . $name) || !private_storage_valid_name($name)) {
            continue;
        }
        $result['found']++;
        if (!$apply) {
            continue;
        }
        $target = private_storage_dir(PRIVATE_KIND_PAYMENTS) . '/' . $name;
        $ok = @rename($legacy . '/' . $name, $target);
        if (!$ok) {
            $ok = @copy($legacy . '/' . $name, $target) && filesize($target) === filesize($legacy . '/' . $name) && @unlink($legacy . '/' . $name);
        }
        if ($ok) {
            @chmod($target, 0640);
            $result['moved']++;
        } else {
            $result['failed']++;
        }
    }
    return $result;
}

/* ------------------------------------------------------------------ *
 * Cart
 * ------------------------------------------------------------------ */

function cart_count(string $userId): int {
    if (!commerce_ready() || $userId === '') {
        return 0;
    }
    $row = query_one('SELECT COUNT(*) AS n FROM cart_items WHERE userId = ?', [$userId]);
    return (int) ($row['n'] ?? 0);
}

/** Why a listing can't be bought in this quantity right now, or null if it can. */
function cart_line_issue(?array $stock, float $quantity, string $buyerId): ?string {
    if (!$stock) {
        return 'This listing is no longer available.';
    }
    if (($stock['userId'] ?? '') === $buyerId) {
        return 'This is your own listing.';
    }
    if (in_array($stock['status'] ?? '', ['sold_out', 'inactive', 'unavailable', 'draft'], true)) {
        return 'This listing is sold out.';
    }
    $available = stock_available_quantity($stock);
    if ($available <= 0) {
        return 'This listing has no stock available right now.';
    }
    if ($quantity > $available) {
        return 'Only ' . rtrim(rtrim(number_format($available, 2, '.', ''), '0'), '.') . ' ' . $stock['unit'] . ' available.';
    }
    return null;
}

/** Cart lines with live stock/price data and any problem with each line. */
function cart_items_detailed(string $userId): array {
    if (!commerce_ready()) {
        return [];
    }
    $lines = [];
    foreach (query_all('SELECT * FROM cart_items WHERE userId = ? ORDER BY createdAt ASC', [$userId]) as $row) {
        $stock = get_stock_by_id($row['stockId']);
        $quantity = (float) $row['quantity'];
        $unitPrice = (float) ($stock['unitPrice'] ?? 0);
        $lines[] = [
            'id' => $row['id'],
            'stockId' => $row['stockId'],
            'quantity' => $quantity,
            'stock' => $stock,
            'unitPrice' => $unitPrice,
            'lineTotal' => round($unitPrice * $quantity, 2),
            'sellerId' => $stock['userId'] ?? '',
            'sellerName' => $stock['supplierName'] ?? 'Unknown seller',
            'issue' => cart_line_issue($stock, $quantity, $userId),
        ];
    }
    return $lines;
}

function cart_total(array $lines): float {
    $total = 0.0;
    foreach ($lines as $line) {
        $total += $line['lineTotal'];
    }
    return round($total, 2);
}

function cart_normalize_quantity($raw): ?float {
    $raw = trim((string) $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $quantity = round((float) $raw, 2);
    return $quantity > 0 && $quantity <= 99999999 ? $quantity : null;
}

function cart_add(string $userId, string $stockId, $rawQuantity): array {
    if (!commerce_ready()) {
        return ['success' => false, 'error' => 'The cart is not set up yet. Ask your administrator to run migration 04.'];
    }
    $quantity = cart_normalize_quantity($rawQuantity);
    if ($quantity === null) {
        return ['success' => false, 'error' => 'Please enter a valid quantity.'];
    }
    $stock = get_stock_by_id($stockId);
    $existing = query_one('SELECT * FROM cart_items WHERE userId = ? AND stockId = ?', [$userId, $stockId]);
    $newTotal = $quantity + (float) ($existing['quantity'] ?? 0);
    $issue = cart_line_issue($stock, $newTotal, $userId);
    if ($issue !== null) {
        $already = $existing ? ' (you already have ' . rtrim(rtrim(number_format((float) $existing['quantity'], 2, '.', ''), '0'), '.') . ' in your cart)' : '';
        return ['success' => false, 'error' => $issue . $already];
    }
    $now = commerce_now();
    if ($existing) {
        query_execute('UPDATE cart_items SET quantity = ?, updatedAt = ? WHERE id = ?', [$newTotal, $now, $existing['id']]);
    } else {
        query_execute(
            'INSERT INTO cart_items (id, userId, stockId, quantity, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?)',
            [commerce_new_id('cart'), $userId, $stockId, $quantity, $now, $now]
        );
    }
    return ['success' => true, 'variety' => $stock['variety'], 'count' => cart_count($userId)];
}

function cart_update_quantity(string $userId, string $itemId, $rawQuantity): array {
    $quantity = cart_normalize_quantity($rawQuantity);
    if ($quantity === null) {
        return ['success' => false, 'error' => 'Please enter a valid quantity.'];
    }
    $item = query_one('SELECT * FROM cart_items WHERE id = ? AND userId = ?', [$itemId, $userId]);
    if (!$item) {
        return ['success' => false, 'error' => 'That item is no longer in your cart.'];
    }
    // The cart may hold more than is available (stock moved on); it is shown
    // with a warning and blocked at checkout, so only reject silly input here.
    query_execute('UPDATE cart_items SET quantity = ?, updatedAt = ? WHERE id = ?', [$quantity, commerce_now(), $itemId]);
    return ['success' => true];
}

function cart_remove(string $userId, string $itemId): void {
    query_execute('DELETE FROM cart_items WHERE id = ? AND userId = ?', [$itemId, $userId]);
}

function cart_clear(string $userId): void {
    query_execute('DELETE FROM cart_items WHERE userId = ?', [$userId]);
}

/* ------------------------------------------------------------------ *
 * Checkout
 * ------------------------------------------------------------------ */

/** Phone and location saved on the buyer's account, to pre-fill checkout. */
function checkout_defaults(string $userId): array {
    $row = query_one('SELECT phone, location FROM users WHERE id = ?', [$userId]) ?: [];
    return ['phone' => trim((string) ($row['phone'] ?? '')), 'address' => trim((string) ($row['location'] ?? ''))];
}

/**
 * Turns lines into procurement requests (one per listing) under one checkout.
 *
 * @param array $buyer  current_user()
 * @param array $lines  [['stockId' => ..., 'quantity' => float], ...]
 * @param array $input  paymentMethod, deliveryAddress, contactPhone, notes
 * @return array ['success' => bool, 'errors' => string[], 'checkoutId' => string, 'requests' => array[]]
 */
function checkout_place_order(array $buyer, array $lines, array $input, bool $clearCart): array {
    $errors = [];
    $buyerId = (string) ($buyer['id'] ?? '');

    if (!commerce_ready()) {
        return ['success' => false, 'errors' => ['Checkout is not set up yet. Ask your administrator to run migration 04.']];
    }
    if (empty($lines)) {
        return ['success' => false, 'errors' => ['Your cart is empty.']];
    }

    $methods = commerce_payment_methods();
    $method = (string) ($input['paymentMethod'] ?? '');
    if (!isset($methods[$method])) {
        $errors[] = 'Please choose a payment method.';
    }
    $address = trim((string) ($input['deliveryAddress'] ?? ''));
    if (mb_strlen($address) < 8) {
        $errors[] = 'Please enter a complete delivery address.';
    } elseif (mb_strlen($address) > 500) {
        $errors[] = 'The delivery address is too long (500 characters max).';
    }
    $phone = trim((string) ($input['contactPhone'] ?? ''));
    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
        $errors[] = 'Please enter a valid contact phone number.';
    }
    $notes = trim((string) ($input['notes'] ?? ''));
    if (mb_strlen($notes) > 1000) {
        $errors[] = 'Notes are too long (1000 characters max).';
    }

    // Re-check every line against live stock; the cart can be hours old.
    $prepared = [];
    $subtotal = 0.0;
    foreach ($lines as $line) {
        $stock = get_stock_by_id((string) $line['stockId']);
        $quantity = (float) $line['quantity'];
        $issue = cart_line_issue($stock, $quantity, $buyerId);
        if ($issue !== null) {
            $errors[] = ($stock['variety'] ?? 'An item') . ': ' . $issue;
            continue;
        }
        $prepared[] = ['stock' => $stock, 'quantity' => $quantity];
        $subtotal += $quantity * (float) $stock['unitPrice'];
    }
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    $subtotal = round($subtotal, 2);
    $checkoutId = commerce_new_id('co');
    $now = commerce_now();
    $requests = [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        query_execute(
            'INSERT INTO checkouts (id, buyerId, buyerName, paymentMethod, deliveryAddress, contactPhone, notes, subtotal, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$checkoutId, $buyerId, $buyer['name'] ?? '', $method, $address, $phone, $notes, $subtotal, $now]
        );
        foreach ($prepared as $row) {
            $stock = $row['stock'];
            $request = create_request([
                'stockId' => $stock['id'],
                'requesterId' => $buyerId,
                'supplierId' => $stock['userId'],
                'variety' => $stock['variety'],
                'quantityRequested' => $row['quantity'],
                'unit' => $stock['unit'],
                'notes' => $notes,
                'status' => 'pending',
                'requesterName' => $buyer['name'] ?? '',
                'supplierName' => $stock['supplierName'] ?? '',
            ]);
            query_execute(
                'UPDATE requests SET checkoutId = ?, paymentMethod = ?, unitPrice = ? WHERE id = ?',
                [$checkoutId, $method, (float) $stock['unitPrice'], $request['id']]
            );
            $requests[] = $request;
        }
        if ($clearCart) {
            foreach ($prepared as $row) {
                query_execute('DELETE FROM cart_items WHERE userId = ? AND stockId = ?', [$buyerId, $row['stock']['id']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('KAMALIG checkout failed: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['We could not place your order. Nothing was charged. Please try again.']];
    }

    // Notifications happen after the commit so a notification problem can never undo an order.
    foreach ($requests as $i => $request) {
        $stock = $prepared[$i]['stock'];
        try {
            create_notification([
                'userId' => $stock['userId'],
                'type' => 'request_received',
                'title' => 'New procurement request',
                'message' => ($buyer['name'] ?? 'A buyer') . ' requested ' . $prepared[$i]['quantity'] . ' ' . $stock['unit']
                    . ' of ' . $stock['variety'] . ' (' . commerce_method_label($method) . ').',
                'relatedId' => $request['id'],
            ]);
        } catch (Throwable $e) {
            error_log('KAMALIG checkout notification failed: ' . $e->getMessage());
        }
    }
    log_activity($buyerId, 'checkout.place', 'checkout', $checkoutId, count($requests) . ' request(s), ' . commerce_money($subtotal) . ', ' . commerce_method_label($method));

    return ['success' => true, 'errors' => [], 'checkoutId' => $checkoutId, 'requests' => $requests];
}

function checkout_get(string $checkoutId): ?array {
    return query_one('SELECT * FROM checkouts WHERE id = ?', [$checkoutId]);
}

/* ------------------------------------------------------------------ *
 * Payments
 * ------------------------------------------------------------------ */

function payment_status_info(string $status): array {
    $map = [
        'pending' => ['Awaiting payment', 'pending'],
        'submitted' => ['Awaiting confirmation', 'submitted'],
        'paid' => ['Paid', 'paid'],
        'rejected' => ['Payment rejected', 'rejected'],
        'cod_pending' => ['Pay on delivery', 'pending'],
        'cancelled' => ['Cancelled', 'cancelled'],
        'refund_due' => ['Refund due', 'rejected'],
    ];
    return $map[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'pending'];
}

function payment_badge(string $status): string {
    [$label, $class] = payment_status_info($status);
    return '<span class="pay-badge pay-badge--' . htmlspecialchars($class) . '">' . htmlspecialchars($label) . '</span>';
}

function payment_get(string $paymentId): ?array {
    return commerce_ready() ? query_one('SELECT * FROM payments WHERE id = ?', [$paymentId]) : null;
}

function payment_get_by_order(string $orderId): ?array {
    return commerce_ready() ? query_one('SELECT * FROM payments WHERE orderId = ?', [$orderId]) : null;
}

/** Payments the user is part of (admin sees all), newest first. */
function payments_for_user(string $userId, string $role, ?string $status = null): array {
    if (!commerce_ready()) {
        return [];
    }
    $sql = 'SELECT p.*, o.variety, o.quantity, o.unit, o.requesterName, o.supplierName, o.status AS orderStatus
            FROM payments p JOIN procurement_orders o ON o.id = p.orderId WHERE 1=1';
    $params = [];
    if ($role !== 'admin') {
        $sql .= ' AND (p.buyerId = ? OR p.sellerId = ?)';
        $params[] = $userId;
        $params[] = $userId;
    }
    if ($status !== null && $status !== '') {
        $sql .= ' AND p.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY p.createdAt DESC';
    return query_all($sql, $params);
}

/** Called when a seller accepts a request and the order is created. */
function commerce_create_payment_for_order(array $order, ?string $method): ?array {
    if (!commerce_ready()) {
        return null;
    }
    $existing = payment_get_by_order($order['id']);
    if ($existing) {
        return $existing;
    }
    $all = commerce_all_methods();
    // Requests made before the cart existed have no method; treat them as cash on delivery.
    $method = ($method !== null && isset($all[$method])) ? $method : 'cod';
    $status = $method === 'cod' ? 'cod_pending' : 'pending';
    $now = commerce_now();
    $id = commerce_new_id('pay');
    if (commerce_has_due_column()) {
        // Cash on delivery is paid at the door, so it has no deadline.
        $dueAt = $method === 'cod' ? null : date('Y-m-d H:i:s', time() + commerce_payment_deadline_hours() * 3600);
        query_execute(
            'INSERT INTO payments (id, orderId, requestId, buyerId, sellerId, amount, method, status, dueAt, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $order['id'], $order['requestId'] ?? null, $order['requesterId'], $order['supplierId'], (float) ($order['totalPrice'] ?? 0), $method, $status, $dueAt, $now, $now]
        );
    } else {
        query_execute(
            'INSERT INTO payments (id, orderId, requestId, buyerId, sellerId, amount, method, status, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $order['id'], $order['requestId'] ?? null, $order['requesterId'], $order['supplierId'], (float) ($order['totalPrice'] ?? 0), $method, $status, $now, $now]
        );
    }
    try {
        create_notification([
            'userId' => $order['requesterId'],
            'type' => 'order_status',
            'title' => $method === 'cod' ? 'Request accepted: pay on delivery' : 'Request accepted: please pay',
            'message' => $order['supplierName'] . ' accepted your request for ' . $order['quantity'] . ' ' . $order['unit'] . ' of ' . $order['variety']
                . '. Total ' . commerce_money($order['totalPrice'] ?? 0) . ' (' . commerce_method_label($method) . ').'
                . ($method === 'cod' ? ' You will pay the seller in cash on delivery.' : ' Open the order to see where to send your payment. Please pay within ' . commerce_payment_deadline_hours() . ' hours or the order is cancelled automatically.'),
            'relatedId' => $order['id'],
        ]);
    } catch (Throwable $e) {
        error_log('KAMALIG payment notification failed: ' . $e->getMessage());
    }
    return payment_get($id);
}

/** Buyer submits a reference number (and optionally a screenshot) for a transfer. */
function payment_submit(string $paymentId, string $userId, string $reference, string $senderName, ?array $file): array {
    $payment = payment_get($paymentId);
    if (!$payment || $payment['buyerId'] !== $userId) {
        return ['success' => false, 'error' => 'Payment not found.'];
    }
    if ($payment['method'] === 'cod' || !in_array($payment['status'], ['pending', 'rejected'], true)) {
        return ['success' => false, 'error' => 'This payment cannot be updated right now.'];
    }
    $reference = trim($reference);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_ \/]{3,63}$/', $reference)) {
        return ['success' => false, 'error' => 'Enter the reference number from your payment receipt (4 to 64 letters or numbers).'];
    }
    $senderName = mb_substr(trim($senderName), 0, 255);

    $storedName = null;
    $mime = null;
    if ($file && !empty($file['name'])) {
        try {
            $uploaded = handle_secure_document_upload($file, commerce_proof_dir());
            $storedName = $uploaded['storedFileName'];
            $mime = $uploaded['mimeType'] ?? null;
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    $now = commerce_now();
    query_execute(
        'UPDATE payments SET status = ?, referenceNumber = ?, senderName = ?, proofFile = COALESCE(?, proofFile), proofMime = COALESCE(?, proofMime),
         submittedAt = ?, rejectionReason = NULL, updatedAt = ? WHERE id = ? AND status IN (\'pending\', \'rejected\')',
        ['submitted', $reference, $senderName, $storedName, $mime, $now, $now, $paymentId]
    );
    log_activity($userId, 'payment.submit', 'payment', $paymentId, $reference);
    try {
        create_notification([
            'userId' => $payment['sellerId'],
            'type' => 'order_status',
            'title' => 'Payment submitted: please confirm',
            'message' => 'The buyer submitted ' . commerce_money($payment['amount']) . ' via ' . commerce_method_label($payment['method'])
                . ' (ref ' . $reference . '). Check your account, then confirm the payment on the order.',
            'relatedId' => $payment['orderId'],
        ]);
    } catch (Throwable $e) {
        error_log('KAMALIG payment notification failed: ' . $e->getMessage());
    }
    return ['success' => true];
}

function payment_can_manage(array $payment, string $userId, string $role): bool {
    return $role === 'admin' || $payment['sellerId'] === $userId;
}

/** Seller (or admin) confirms the money arrived. */
function payment_confirm(string $paymentId, string $actorId, string $role): array {
    $payment = payment_get($paymentId);
    if (!$payment || !payment_can_manage($payment, $actorId, $role)) {
        return ['success' => false, 'error' => 'Payment not found.'];
    }
    if ($payment['method'] === 'cod' || !in_array($payment['status'], ['pending', 'submitted'], true)) {
        return ['success' => false, 'error' => 'This payment cannot be confirmed right now.'];
    }
    $now = commerce_now();
    query_execute(
        'UPDATE payments SET status = ?, confirmedAt = ?, confirmedBy = ?, rejectionReason = NULL, updatedAt = ? WHERE id = ? AND status IN (\'pending\', \'submitted\')',
        ['paid', $now, $actorId, $now, $paymentId]
    );
    log_activity($actorId, 'payment.confirm', 'payment', $paymentId, commerce_money($payment['amount']));
    try {
        create_notification([
            'userId' => $payment['buyerId'],
            'type' => 'order_status',
            'title' => 'Payment confirmed',
            'message' => 'Your payment of ' . commerce_money($payment['amount']) . ' was confirmed. The seller can now ship your order.',
            'relatedId' => $payment['orderId'],
        ]);
    } catch (Throwable $e) {
        error_log('KAMALIG payment notification failed: ' . $e->getMessage());
    }
    return ['success' => true];
}

function payment_reject(string $paymentId, string $actorId, string $role, string $reason): array {
    $payment = payment_get($paymentId);
    if (!$payment || !payment_can_manage($payment, $actorId, $role)) {
        return ['success' => false, 'error' => 'Payment not found.'];
    }
    if ($payment['status'] !== 'submitted') {
        return ['success' => false, 'error' => 'Only a submitted payment can be rejected.'];
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < 3) {
        return ['success' => false, 'error' => 'Please tell the buyer why the payment was rejected.'];
    }
    query_execute(
        'UPDATE payments SET status = ?, rejectionReason = ?, updatedAt = ? WHERE id = ? AND status = \'submitted\'',
        ['rejected', mb_substr($reason, 0, 1000), commerce_now(), $paymentId]
    );
    log_activity($actorId, 'payment.reject', 'payment', $paymentId, $reason);
    try {
        create_notification([
            'userId' => $payment['buyerId'],
            'type' => 'order_status',
            'title' => 'Payment rejected',
            'message' => 'The seller could not confirm your payment: ' . $reason . ' Please check and submit it again.',
            'relatedId' => $payment['orderId'],
        ]);
    } catch (Throwable $e) {
        error_log('KAMALIG payment notification failed: ' . $e->getMessage());
    }
    return ['success' => true];
}

/** COD: seller collects cash at delivery and records it. */
function payment_mark_cod_paid(string $paymentId, string $actorId, string $role): array {
    $payment = payment_get($paymentId);
    if (!$payment || !payment_can_manage($payment, $actorId, $role)) {
        return ['success' => false, 'error' => 'Payment not found.'];
    }
    if ($payment['method'] !== 'cod' || $payment['status'] !== 'cod_pending') {
        return ['success' => false, 'error' => 'This payment cannot be marked as received.'];
    }
    $order = get_procurement_order_by_id($payment['orderId']);
    if (!$order || !in_array($order['status'], ['delivered', 'completed'], true)) {
        return ['success' => false, 'error' => 'Cash can be marked as received once the order has been delivered.'];
    }
    $now = commerce_now();
    query_execute(
        'UPDATE payments SET status = ?, confirmedAt = ?, confirmedBy = ?, updatedAt = ? WHERE id = ? AND status = \'cod_pending\'',
        ['paid', $now, $actorId, $now, $paymentId]
    );
    log_activity($actorId, 'payment.cod_collected', 'payment', $paymentId, commerce_money($payment['amount']));
    try {
        create_notification([
            'userId' => $payment['buyerId'],
            'type' => 'order_status',
            'title' => 'Cash payment recorded',
            'message' => 'The seller recorded your cash payment of ' . commerce_money($payment['amount']) . '. Thank you!',
            'relatedId' => $payment['orderId'],
        ]);
    } catch (Throwable $e) {
        error_log('KAMALIG payment notification failed: ' . $e->getMessage());
    }
    return ['success' => true];
}

/** Called when an order is cancelled: unpaid payments are cancelled, paid ones are flagged for a refund. */
function commerce_on_order_cancelled(array $order): void {
    if (!commerce_ready()) {
        return;
    }
    $payment = payment_get_by_order($order['id']);
    if (!$payment) {
        return;
    }
    $now = commerce_now();
    if ($payment['status'] === 'paid') {
        query_execute('UPDATE payments SET status = ?, updatedAt = ? WHERE id = ?', ['refund_due', $now, $payment['id']]);
        foreach ([$payment['buyerId'], $payment['sellerId']] as $uid) {
            try {
                create_notification([
                    'userId' => $uid,
                    'type' => 'order_status',
                    'title' => 'Refund due',
                    'message' => 'An order that was already paid (' . commerce_money($payment['amount']) . ') was cancelled. The seller must refund the buyer directly.',
                    'relatedId' => $order['id'],
                ]);
            } catch (Throwable $e) {
                error_log('KAMALIG payment notification failed: ' . $e->getMessage());
            }
        }
    } elseif (in_array($payment['status'], ['pending', 'submitted', 'rejected', 'cod_pending'], true)) {
        query_execute('UPDATE payments SET status = ?, updatedAt = ? WHERE id = ?', ['cancelled', $now, $payment['id']]);
    }
}

/**
 * The dispatch lock: the reason a seller cannot ship yet, or null when they can.
 * Cash on Delivery orders are never locked. Set PAYMENT_REQUIRED_BEFORE_DISPATCH=false to disable.
 * An order with no payment record is never let through: the record is re-created from the
 * original request, and if that fails the order stays locked (fail closed).
 */
function commerce_dispatch_blocker(array $order): ?string {
    if (commerce_must_be_ready()) {
        return 'Payments are temporarily unavailable, so this order cannot be dispatched. Please contact an administrator.';
    }
    if (!commerce_ready()) {
        return null;
    }
    $flag = getenv('PAYMENT_REQUIRED_BEFORE_DISPATCH');
    if ($flag !== false && $flag !== '' && !filter_var($flag, FILTER_VALIDATE_BOOLEAN)) {
        return null;
    }
    $payment = payment_get_by_order($order['id']);
    if (!$payment) {
        // Fail closed: an order with no payment record must not ship. Try to create the missing
        // record from the original request (its payment method, or cash on delivery for requests
        // made before the cart existed), and keep the order locked if that is not possible.
        try {
            $sourceRequest = !empty($order['requestId']) ? get_request_by_id($order['requestId']) : null;
            $payment = commerce_create_payment_for_order($order, $sourceRequest['paymentMethod'] ?? null);
        } catch (Throwable $e) {
            error_log('KAMALIG could not create missing payment for order ' . $order['id'] . ': ' . $e->getMessage());
            $payment = null;
        }
        if (!$payment) {
            return 'This order has no payment record yet, so it cannot be dispatched. Reload this page to retry; if this message stays, contact an administrator.';
        }
    }
    if ($payment['method'] === 'cod' || $payment['status'] === 'paid') {
        return null;
    }
    if ($payment['status'] === 'submitted') {
        return 'The buyer has submitted their payment. Confirm that you received it (in the Payment section above) before dispatching.';
    }
    return 'Waiting for the buyer to pay (' . commerce_method_label($payment['method']) . '). You can dispatch once their payment is confirmed.';
}

/* ------------------------------------------------------------------ *
 * Seller payment details
 * ------------------------------------------------------------------ */

function payment_accounts_for_user(string $userId): array {
    if (!commerce_ready()) {
        return [];
    }
    $out = [];
    foreach (query_all('SELECT * FROM payment_accounts WHERE userId = ?', [$userId]) as $row) {
        $out[$row['method']] = $row;
    }
    return $out;
}

function payment_account_save(string $userId, string $method, string $name, string $number, string $bank, string $notes): array {
    $all = commerce_all_methods();
    if (!isset($all[$method]) || $method === 'cod') {
        return ['success' => false, 'error' => 'Unknown payment method.'];
    }
    $name = trim($name);
    $number = trim($number);
    $bank = trim($bank);
    if ($name === '' || mb_strlen($name) > 255) {
        return ['success' => false, 'error' => 'Enter the account name (the name buyers should see).'];
    }
    if (!preg_match('/^[0-9A-Za-z\-\s+]{4,40}$/', $number)) {
        return ['success' => false, 'error' => 'Enter a valid account or mobile number.'];
    }
    if ($method === 'bank_transfer' && $bank === '') {
        return ['success' => false, 'error' => 'Enter the bank name.'];
    }
    $now = commerce_now();
    query_execute(
        'INSERT INTO payment_accounts (id, userId, method, accountName, accountNumber, bankName, notes, createdAt, updatedAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE accountName = VALUES(accountName), accountNumber = VALUES(accountNumber),
            bankName = VALUES(bankName), notes = VALUES(notes), updatedAt = VALUES(updatedAt)',
        [commerce_new_id('pacc'), $userId, $method, $name, $number, mb_substr($bank, 0, 128), mb_substr(trim($notes), 0, 500), $now, $now]
    );
    return ['success' => true];
}

function payment_account_delete(string $userId, string $method): void {
    query_execute('DELETE FROM payment_accounts WHERE userId = ? AND method = ?', [$userId, $method]);
}

/* ------------------------------------------------------------------ *
 * UI helpers
 * ------------------------------------------------------------------ */

/** A "go back to" path from a form, accepted only if it is a plain relative path inside this app. */
function commerce_safe_back(string $candidate, string $fallback): string {
    $candidate = ltrim(trim($candidate), '/');
    if ($candidate === '' || strlen($candidate) > 300 || strpos($candidate, '//') !== false
        || strpos($candidate, '..') !== false || !preg_match('/^[A-Za-z0-9\-_\/\.\?=&%#+,]+$/', $candidate)) {
        return $fallback;
    }
    return $candidate;
}

function commerce_flash_messages(): void {
    foreach (['flash_success' => 'alert-success', 'flash_error' => 'alert-error'] as $key => $class) {
        if (!empty($_SESSION[$key])) {
            echo '<div class="alert ' . $class . '">' . htmlspecialchars((string) $_SESSION[$key]) . '</div>';
            unset($_SESSION[$key]);
        }
    }
}

/** The page shown to admins if migration 04 has not been run yet. */
function commerce_not_ready_notice(): void {
    echo '<div class="card"><h3>Almost ready</h3>'
        . '<p>The shopping cart and payment tables are not in the database yet. '
        . 'In phpMyAdmin, import <strong>04_cart_and_payments.sql</strong> once, then reload this page.</p></div>';
}

/** Radio cards for choosing a payment method, plus the delivery fields (used by checkout and Request Stock). */
function render_checkout_fields(array $values): void {
    $methods = commerce_payment_methods();
    $selected = $values['paymentMethod'] ?? array_key_first($methods);
    ?>
    <fieldset class="co-section">
        <legend>Payment method</legend>
        <div class="pay-options">
            <?php foreach ($methods as $key => $method): ?>
                <label class="pay-option">
                    <input type="radio" name="paymentMethod" value="<?php echo htmlspecialchars($key); ?>" <?php echo $selected === $key ? 'checked' : ''; ?> required>
                    <span class="pay-option__body">
                        <strong><?php echo htmlspecialchars($method['label']); ?></strong>
                        <small><?php echo htmlspecialchars($method['desc']); ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="muted pay-note">You pay <strong>after the seller accepts your request</strong>, when the final amount is confirmed. Nothing is charged now.</p>
    </fieldset>
    <fieldset class="co-section">
        <legend>Delivery details</legend>
        <div class="form-group">
            <label for="deliveryAddress">Delivery address</label>
            <textarea id="deliveryAddress" name="deliveryAddress" rows="3" maxlength="500" required placeholder="Street, barangay, city, province"><?php echo htmlspecialchars($values['deliveryAddress'] ?? ''); ?></textarea>
        </div>
        <div class="form-group">
            <label for="contactPhone">Contact number</label>
            <input id="contactPhone" name="contactPhone" type="tel" maxlength="20" required value="<?php echo htmlspecialchars($values['contactPhone'] ?? ''); ?>" placeholder="09XX XXX XXXX">
        </div>
        <div class="form-group">
            <label for="notes">Notes for the seller (optional)</label>
            <textarea id="notes" name="notes" rows="2" maxlength="1000"><?php echo htmlspecialchars($values['notes'] ?? ''); ?></textarea>
        </div>
    </fieldset>
    <?php
}

/** The Payment section shown on an order's tracking page. */
function render_payment_panel(array $order, string $role, string $userId): void {
    if (!commerce_ready()) {
        return;
    }
    $payment = payment_get_by_order($order['id']);
    if (!$payment) {
        return;
    }
    $isBuyer = $payment['buyerId'] === $userId;
    $canManage = payment_can_manage($payment, $userId, $role);
    $methodInfo = commerce_all_methods()[$payment['method']] ?? null;
    $status = $payment['status'];
    $actionUrl = url('payments/action/');
    $back = 'procurement/tracking/?id=' . $order['id'];
    $hidden = csrf_field() . '<input type="hidden" name="paymentId" value="' . htmlspecialchars($payment['id']) . '">'
        . '<input type="hidden" name="back" value="' . htmlspecialchars($back) . '">';
    ?>
    <div class="card pay-panel" id="payment">
        <div class="pay-panel__head">
            <h4>Payment</h4>
            <?php echo payment_badge($status); ?>
        </div>
        <p class="pay-panel__amount"><?php echo htmlspecialchars(commerce_money($payment['amount'])); ?>
            <span class="muted">via <?php echo htmlspecialchars(commerce_method_label($payment['method'])); ?></span></p>

        <?php if ($status === 'rejected' && !empty($payment['rejectionReason'])): ?>
            <div class="alert alert-error"><strong>The seller could not confirm this payment:</strong> <?php echo htmlspecialchars($payment['rejectionReason']); ?></div>
        <?php endif; ?>

        <?php if (in_array($status, ['pending', 'rejected'], true) && !empty($payment['dueAt'])): ?>
            <?php $dueTs = strtotime($payment['dueAt']); $secsLeft = $dueTs - time(); ?>
            <div class="alert <?php echo $secsLeft < 6 * 3600 ? 'alert-error' : ''; ?>">
                <strong>Pay before <?php echo htmlspecialchars(date('M j, Y g:i A', $dueTs)); ?></strong>
                <?php if ($secsLeft > 0): ?>
                    (<?php echo $secsLeft >= 3600 ? (int) floor($secsLeft / 3600) . ' hour(s) left' : max(1, (int) floor($secsLeft / 60)) . ' minute(s) left'; ?>).
                <?php else: ?>
                    (overdue).
                <?php endif; ?>
                Unpaid orders are cancelled automatically and the stock is released.
            </div>
        <?php endif; ?>

        <?php if (in_array($status, ['pending', 'rejected'], true)): ?>
            <?php if ($isBuyer): ?>
                <?php $account = payment_accounts_for_user($payment['sellerId'])[$payment['method']] ?? null; ?>
                <div class="pay-instructions">
                    <h5>Step 1: send <?php echo htmlspecialchars(commerce_money($payment['amount'])); ?> to the seller</h5>
                    <?php if ($account): ?>
                        <dl class="pay-dl">
                            <dt>Account name</dt><dd><?php echo htmlspecialchars($account['accountName']); ?></dd>
                            <?php if ($payment['method'] === 'bank_transfer' && !empty($account['bankName'])): ?>
                                <dt>Bank</dt><dd><?php echo htmlspecialchars($account['bankName']); ?></dd>
                            <?php endif; ?>
                            <dt><?php echo $payment['method'] === 'bank_transfer' ? 'Account number' : 'Mobile number'; ?></dt>
                            <dd><strong><?php echo htmlspecialchars($account['accountNumber']); ?></strong></dd>
                        </dl>
                        <?php if (!empty($account['notes'])): ?><p class="muted"><?php echo nl2br(htmlspecialchars($account['notes'])); ?></p><?php endif; ?>
                    <?php else: ?>
                        <p class="muted">The seller has not added their <?php echo htmlspecialchars(commerce_method_label($payment['method'])); ?> details yet. Message them through the assistant or ask them to add it, then come back here.</p>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?php echo htmlspecialchars($actionUrl); ?>" enctype="multipart/form-data" class="pay-form">
                    <?php echo $hidden; ?>
                    <input type="hidden" name="act" value="submit">
                    <h5>Step 2: tell the seller you paid</h5>
                    <div class="form-group">
                        <label for="referenceNumber">Reference number</label>
                        <input id="referenceNumber" name="referenceNumber" type="text" maxlength="64" required placeholder="From your payment receipt" value="<?php echo htmlspecialchars($payment['referenceNumber'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="senderName">Name on the payment (optional)</label>
                        <input id="senderName" name="senderName" type="text" maxlength="255" value="<?php echo htmlspecialchars($payment['senderName'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="proofFile">Screenshot or receipt (optional, JPG/PNG/PDF)</label>
                        <input id="proofFile" name="proofFile" type="file" accept=".jpg,.jpeg,.png,.pdf,image/*,application/pdf">
                    </div>
                    <button class="btn btn-primary" type="submit">I have paid</button>
                </form>
            <?php else: ?>
                <p class="muted">Waiting for the buyer to pay. Orders paid by <?php echo htmlspecialchars(commerce_method_label($payment['method'])); ?> are dispatched after you confirm the payment.</p>
                <?php if ($canManage): ?>
                    <form method="post" action="<?php echo htmlspecialchars($actionUrl); ?>" onsubmit="return confirm('Confirm that you already received this payment?');">
                        <?php echo $hidden; ?>
                        <input type="hidden" name="act" value="confirm">
                        <button class="btn btn-secondary btn-sm" type="submit">I already received this payment</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

        <?php elseif ($status === 'submitted'): ?>
            <dl class="pay-dl">
                <dt>Reference number</dt><dd><strong><?php echo htmlspecialchars($payment['referenceNumber'] ?? ''); ?></strong></dd>
                <?php if (!empty($payment['senderName'])): ?><dt>Name on payment</dt><dd><?php echo htmlspecialchars($payment['senderName']); ?></dd><?php endif; ?>
                <dt>Submitted</dt><dd><?php echo htmlspecialchars($payment['submittedAt'] ?? ''); ?></dd>
                <?php if (!empty($payment['proofFile'])): ?>
                    <dt>Receipt</dt><dd><a href="<?php echo htmlspecialchars(url('payments/proof/?id=' . urlencode($payment['id']))); ?>" target="_blank" rel="noopener">View receipt</a></dd>
                <?php endif; ?>
            </dl>
            <?php if ($canManage): ?>
                <p class="muted">Check that <?php echo htmlspecialchars(commerce_money($payment['amount'])); ?> reached your <?php echo htmlspecialchars(commerce_method_label($payment['method'])); ?> account, then confirm.</p>
                <div class="pay-actions">
                    <form method="post" action="<?php echo htmlspecialchars($actionUrl); ?>">
                        <?php echo $hidden; ?>
                        <input type="hidden" name="act" value="confirm">
                        <button class="btn btn-primary" type="submit">Confirm payment received</button>
                    </form>
                </div>
                <form method="post" action="<?php echo htmlspecialchars($actionUrl); ?>" class="pay-form">
                    <?php echo $hidden; ?>
                    <input type="hidden" name="act" value="reject">
                    <div class="form-group">
                        <label for="rejectReason">Can't find the payment? Tell the buyer why you are rejecting it</label>
                        <textarea id="rejectReason" name="reason" rows="2" maxlength="1000" required placeholder="e.g. Amount does not match or reference number not found"></textarea>
                    </div>
                    <button class="btn btn-secondary btn-sm" type="submit">Reject payment</button>
                </form>
            <?php else: ?>
                <p class="muted">Submitted. The seller will confirm once they see the payment, and then ship your order.</p>
            <?php endif; ?>

        <?php elseif ($status === 'cod_pending'): ?>
            <p class="muted">Cash on Delivery: the buyer pays the seller in cash when the rice arrives.</p>
            <?php if ($canManage): ?>
                <?php if (in_array($order['status'], ['delivered', 'completed'], true)): ?>
                    <form method="post" action="<?php echo htmlspecialchars($actionUrl); ?>" onsubmit="return confirm('Confirm that you collected the cash?');">
                        <?php echo $hidden; ?>
                        <input type="hidden" name="act" value="cod_paid">
                        <button class="btn btn-primary" type="submit">Mark cash received</button>
                    </form>
                <?php else: ?>
                    <p class="muted">You can mark the cash as received once the order is delivered.</p>
                <?php endif; ?>
            <?php endif; ?>

        <?php elseif ($status === 'paid'): ?>
            <p>Paid<?php echo !empty($payment['confirmedAt']) ? ' on ' . htmlspecialchars($payment['confirmedAt']) : ''; ?>.
            <?php if (!empty($payment['referenceNumber'])): ?><span class="muted">Ref <?php echo htmlspecialchars($payment['referenceNumber']); ?></span><?php endif; ?>
            <?php if (!empty($payment['proofFile'])): ?>
                <a href="<?php echo htmlspecialchars(url('payments/proof/?id=' . urlencode($payment['id']))); ?>" target="_blank" rel="noopener">View receipt</a>
            <?php endif; ?></p>

        <?php elseif ($status === 'refund_due'): ?>
            <div class="alert alert-error">This order was cancelled after it was paid. The seller must refund the buyer directly.</div>
        <?php elseif ($status === 'cancelled'): ?>
            <p class="muted">This order was cancelled, so no payment is needed.</p>
        <?php endif; ?>
    </div>
    <?php
}
