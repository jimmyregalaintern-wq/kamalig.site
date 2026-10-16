<?php
/**
 * includes/account_delete.php — permanently delete a user account (admin only).
 *
 * Used by Admin > Accounts > Delete (e.g. to remove the sample @example.com
 * accounts before the demo). Everything happens in ONE database transaction, in
 * child-first order so it also works once foreign keys are in place: either the
 * account and everything listed below is gone, or nothing changed.
 *
 * Removed with the account: payments, procurement orders and requests the user
 * is part of (and those on the user's stock listings), stock movements and price
 * history of the user's stock, cart items, checkouts, documents (database rows
 * AND the private files), payment details, notifications, assistant messages,
 * warehouses, stock listings (and their photos). The activity log is KEPT as
 * the audit trail, and gets an "account.delete" entry.
 */

/** "?,?,?" for an IN (...) list. */
function account_delete_in(array $ids): string {
    return implode(',', array_fill(0, count($ids), '?'));
}

function account_delete_ids(string $sql, array $params): array {
    try {
        return array_map('strval', array_column(query_all($sql, $params), 'id'));
    } catch (PDOException $e) {
        return []; // table not present in this install
    }
}

/** Collect the ids of everything that belongs to (or hangs off) this account. */
function account_delete_scope(string $userId): array {
    $stocks = account_delete_ids('SELECT id FROM stocks WHERE userId = ?', [$userId]);
    $requests = account_delete_ids(
        'SELECT id FROM requests WHERE requesterId = ? OR supplierId = ?' . ($stocks ? ' OR stockId IN (' . account_delete_in($stocks) . ')' : ''),
        array_merge([$userId, $userId], $stocks)
    );
    $orders = account_delete_ids(
        'SELECT id FROM procurement_orders WHERE requesterId = ? OR supplierId = ?'
        . ($stocks ? ' OR stockId IN (' . account_delete_in($stocks) . ')' : '')
        . ($requests ? ' OR requestId IN (' . account_delete_in($requests) . ')' : ''),
        array_merge([$userId, $userId], $stocks, $requests)
    );
    return ['stocks' => $stocks, 'requests' => $requests, 'orders' => $orders];
}

/** Counts shown to the admin before they confirm. */
function account_delete_preview(string $userId): array {
    $scope = account_delete_scope($userId);
    $docs = 0;
    try {
        $row = query_one('SELECT COUNT(*) AS n FROM documents WHERE userId = ?', [$userId]);
        $docs = (int) ($row['n'] ?? 0);
    } catch (PDOException $e) {
    }
    $others = 0;
    if ($scope['orders']) {
        try {
            $row = query_one(
                'SELECT COUNT(*) AS n FROM procurement_orders WHERE id IN (' . account_delete_in($scope['orders']) . ') AND (requesterId <> ? OR supplierId <> ?)',
                array_merge($scope['orders'], [$userId, $userId])
            );
            $others = (int) ($row['n'] ?? 0);
        } catch (PDOException $e) {
        }
    }
    return [
        'stocks' => count($scope['stocks']),
        'requests' => count($scope['requests']),
        'orders' => count($scope['orders']),
        'documents' => $docs,
        'records' => count($scope['stocks']) + count($scope['requests']) + count($scope['orders']),
        'shared_orders' => $others,
    ];
}

/** Run one DELETE; a table that does not exist in this install is skipped. */
function account_delete_run(string $sql, array $params): void {
    try {
        db()->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') { // base table not found
            return;
        }
        throw $e;
    }
}

/**
 * @return array{success:bool,error?:string,message?:string}
 */
function delete_user_account(string $userId, ?string $actorId, bool $withRecords): array {
    if (!database_available()) {
        return ['success' => false, 'error' => 'Deleting accounts needs the database.'];
    }
    $user = get_user_by_id($userId);
    if (!$user) {
        return ['success' => false, 'error' => 'That account no longer exists.'];
    }
    if ($userId === $actorId) {
        return ['success' => false, 'error' => "You can't delete your own account."];
    }
    $admins = query_one("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND id <> ? AND (status IS NULL OR status <> 'suspended')", [$userId]);
    if (($user['role'] ?? '') === 'admin' && (int) ($admins['n'] ?? 0) < 1) {
        return ['success' => false, 'error' => 'At least one active admin must remain.'];
    }

    $scope = account_delete_scope($userId);
    if (!$withRecords && ($scope['stocks'] || $scope['requests'] || $scope['orders'])) {
        return ['success' => false, 'error' => 'This account has stock, requests or orders. Tick "also delete their records" to remove everything together.'];
    }
    $stocks = $scope['stocks'];
    $orders = $scope['orders'];
    $requests = $scope['requests'];

    // File names to remove AFTER the database commit succeeds.
    $docFiles = [];
    $shipFiles = [];
    $proofFiles = [];
    $stockImages = [];
    foreach (query_all('SELECT storedFileName FROM documents WHERE userId = ?' . ($requests ? ' OR relatedRequestId IN (' . account_delete_in($requests) . ')' : ''), array_merge([$userId], $requests)) as $d) {
        if (!empty($d['storedFileName'])) { $docFiles[] = (string) $d['storedFileName']; }
    }
    if ($orders) {
        foreach (query_all('SELECT proofOfDeliveryUrl FROM procurement_orders WHERE id IN (' . account_delete_in($orders) . ')', $orders) as $o) {
            $ref = function_exists('private_storage_ref_name') ? private_storage_ref_name($o['proofOfDeliveryUrl'] ?? null) : null;
            if ($ref) { $shipFiles[] = $ref; }
        }
    }
    try {
        $pq = 'SELECT proofFile FROM payments WHERE (buyerId = ? OR sellerId = ?)' . ($orders ? ' OR orderId IN (' . account_delete_in($orders) . ')' : '');
        foreach (query_all($pq, array_merge([$userId, $userId], $orders)) as $p) {
            if (!empty($p['proofFile'])) { $proofFiles[] = basename((string) $p['proofFile']); }
        }
    } catch (PDOException $e) {
    }
    if ($stocks) {
        foreach (query_all('SELECT imageUrl FROM stocks WHERE id IN (' . account_delete_in($stocks) . ')', $stocks) as $s) {
            if (!empty($s['imageUrl'])) { $stockImages[] = (string) $s['imageUrl']; }
        }
    }

    begin_transaction();
    try {
        // payments -> orders -> (movements, history) -> carts -> documents -> requests -> stocks -> account extras -> user
        account_delete_run('DELETE FROM payments WHERE buyerId = ? OR sellerId = ?' . ($orders ? ' OR orderId IN (' . account_delete_in($orders) . ')' : ''), array_merge([$userId, $userId], $orders));
        if ($orders) {
            account_delete_run('DELETE FROM stock_movements WHERE procurementOrderId IN (' . account_delete_in($orders) . ')', $orders);
            account_delete_run('DELETE FROM procurement_orders WHERE id IN (' . account_delete_in($orders) . ')', $orders);
        }
        account_delete_run('DELETE FROM stock_movements WHERE userId = ?' . ($stocks ? ' OR stockId IN (' . account_delete_in($stocks) . ')' : ''), array_merge([$userId], $stocks));
        account_delete_run('DELETE FROM price_history WHERE userId = ?' . ($stocks ? ' OR stockId IN (' . account_delete_in($stocks) . ')' : ''), array_merge([$userId], $stocks));
        account_delete_run('DELETE FROM cart_items WHERE userId = ?' . ($stocks ? ' OR stockId IN (' . account_delete_in($stocks) . ')' : ''), array_merge([$userId], $stocks));
        account_delete_run('DELETE FROM documents WHERE userId = ?' . ($requests ? ' OR relatedRequestId IN (' . account_delete_in($requests) . ')' : ''), array_merge([$userId], $requests));
        if ($requests) {
            account_delete_run('DELETE FROM requests WHERE id IN (' . account_delete_in($requests) . ')', $requests);
        }
        account_delete_run('DELETE FROM checkouts WHERE buyerId = ?', [$userId]);
        if ($stocks) {
            account_delete_run('DELETE FROM stocks WHERE id IN (' . account_delete_in($stocks) . ')', $stocks);
        }
        account_delete_run('DELETE FROM warehouses WHERE userId = ?', [$userId]);
        account_delete_run('DELETE FROM payment_accounts WHERE userId = ?', [$userId]);
        account_delete_run('DELETE FROM notifications WHERE userId = ?', [$userId]);
        account_delete_run('DELETE FROM messages WHERE userId = ?', [$userId]);
        account_delete_run('DELETE FROM users WHERE id = ?', [$userId]);
        log_activity($actorId, 'account.delete', 'user', $userId, 'Deleted ' . ($user['email'] ?? '') . ' (' . ($user['role'] ?? '') . ')'
            . ($withRecords ? ' with ' . count($stocks) . ' stock, ' . count($requests) . ' request(s), ' . count($orders) . ' order(s)' : ''));
        commit_transaction();
    } catch (Throwable $e) {
        rollback_transaction();
        error_log('KAMALIG delete account ' . $userId . ' failed: ' . $e->getMessage());
        return ['success' => false, 'error' => 'The account could not be deleted, so nothing was changed. (A related record may still reference it.)'];
    }

    // Files are removed only after the database change is safely committed.
    foreach (array_unique($docFiles) as $name) {
        $p = function_exists('private_storage_find') ? private_storage_find(PRIVATE_KIND_DOCUMENTS, $name) : null;
        if ($p) { @unlink($p); }
    }
    foreach (array_unique($shipFiles) as $name) {
        $p = function_exists('private_storage_find') ? private_storage_find(PRIVATE_KIND_SHIPMENTS, $name) : null;
        if ($p) { @unlink($p); }
    }
    foreach (array_unique($proofFiles) as $name) {
        $p = $name !== '' ? commerce_proof_find($name) : null;
        if ($p !== null) { @unlink($p); }
    }
    foreach (array_unique($stockImages) as $img) {
        delete_stock_image_file($img);
    }
    return ['success' => true, 'message' => ($user['name'] ?? 'The account') . ' (' . ($user['email'] ?? '') . ') was deleted.'];
}
