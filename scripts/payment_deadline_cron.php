<?php
/**
 * scripts/payment_deadline_cron.php — cancel unpaid orders after their payment
 * deadline (Fix Guide item 6). CLI only (scripts/.htaccess also denies web access).
 *
 *   php scripts/payment_deadline_cron.php            dry run: report only
 *   php scripts/payment_deadline_cron.php --apply    actually cancel overdue orders
 *
 * What it does: finds orders that are still 'ordered' (not yet shipped) whose
 * non-COD payment is still unpaid after payments.dueAt, then for each one
 *   - cancels the order and releases the reserved stock (cancel_procurement_order),
 *   - marks the payment cancelled,
 *   - notifies the buyer and the seller,
 *   - writes an activity-log entry.
 * Orders whose payment was SUBMITTED are never auto-cancelled (the seller is
 * deciding). A payment the seller rejected gets a 24-hour grace period.
 *
 * Hostinger cron (hPanel -> Advanced -> Cron Jobs), e.g. every hour:
 *   0 * * * *  /usr/bin/php /home/USER/domains/YOURSITE/public_html/scripts/payment_deadline_cron.php --apply
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../includes/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);

if (!database_available() || !commerce_ready()) {
    exit("Database or payments tables not available; nothing to do.\n");
}
if (!commerce_has_due_column()) {
    exit("payments.dueAt does not exist yet. Run install_update.php first.\n");
}

echo ($apply ? 'APPLY' : 'DRY RUN') . ' — payment deadline cron ' . date('Y-m-d H:i:s') . "\n";

$now = date('Y-m-d H:i:s');
$graceCutoff = date('Y-m-d H:i:s', time() - 24 * 3600);

$rows = query_all(
    "SELECT p.id AS paymentId, p.dueAt, p.status AS paymentStatus, p.method, p.amount, p.orderId
       FROM payments p
       JOIN procurement_orders o ON o.id = p.orderId
      WHERE p.dueAt IS NOT NULL
        AND p.dueAt < ?
        AND p.method <> 'cod'
        AND o.status = 'ordered'
        AND (p.status = 'pending' OR (p.status = 'rejected' AND p.updatedAt < ?))
      ORDER BY p.dueAt ASC",
    [$now, $graceCutoff]
);

$cancelled = 0;
foreach ($rows as $row) {
    $order = get_procurement_order_by_id($row['orderId']);
    if (!$order) {
        continue;
    }
    echo "  overdue: order {$order['id']} ({$order['quantity']} {$order['unit']} of {$order['variety']}), due {$row['dueAt']}, payment {$row['paymentStatus']}\n";
    if (!$apply) {
        continue;
    }

    begin_transaction();
    try {
        // Re-check at the last moment: the buyer may have submitted or paid since
        // the list above was read. Only unpaid orders are cancelled.
        $fresh = query_one('SELECT status, dueAt, updatedAt FROM payments WHERE id = ? FOR UPDATE', [$row['paymentId']]);
        $stillOverdue = $fresh
            && $fresh['dueAt'] !== null && $fresh['dueAt'] < $now
            && ($fresh['status'] === 'pending' || ($fresh['status'] === 'rejected' && $fresh['updatedAt'] < $graceCutoff));
        if (!$stillOverdue) {
            rollback_transaction();
            echo "    skipped: payment status changed in the meantime\n";
            continue;
        }
        // null actor = the system itself (no fake user id in the activity log).
        $result = cancel_procurement_order($order['id'], null, 'Payment was not received before the deadline');
        if (empty($result['success'])) {
            throw new RuntimeException($result['error'] ?? 'cancel failed');
        }
        commit_transaction();
    } catch (Throwable $e) {
        rollback_transaction();
        error_log('KAMALIG payment deadline: could not cancel order ' . $order['id'] . ': ' . $e->getMessage());
        echo "    FAILED: " . $e->getMessage() . "\n";
        continue;
    }

    // cancel_procurement_order() already told the buyer; tell the seller too.
    try {
        create_notification([
            'userId' => $order['supplierId'],
            'type' => 'order_status',
            'title' => 'Order cancelled: payment not received',
            'message' => 'The buyer did not pay within the deadline, so the order for ' . $order['quantity'] . ' ' . $order['unit']
                . ' of ' . $order['variety'] . ' was cancelled and your stock was released.',
            'relatedId' => $order['id'],
        ]);
    } catch (Throwable $e) {
        error_log('KAMALIG payment deadline: seller notification failed: ' . $e->getMessage());
    }
    log_activity(null, 'order.auto_cancel', 'procurement_order', $order['id'], 'Payment deadline ' . $row['dueAt'] . ' passed');
    $cancelled++;
}

if ($apply) {
    cron_heartbeat('payment_deadline');
}
echo ($apply ? "Cancelled: $cancelled" : 'Would cancel: ' . count($rows)) . "\n";
if (!$apply) {
    echo "Dry run only. Re-run with --apply to make these changes.\n";
}
