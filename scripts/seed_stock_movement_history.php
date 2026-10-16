<?php
// Run this once after importing the database and adding at least a few
// stock listings: php scripts/seed_stock_movement_history.php
//
// Backfills stock_movements with a plausible 90-day history for every
// active (non-archived) stock listing currently in the database, so the
// dashboard's Rice Supply Trend chart has real data for all four ranges
// (7D, 30D, 6M, 1Y) instead of just whatever's accumulated from actual
// day-to-day use.
//
// The chain is self-consistent per stock: movements are generated oldest
// to newest, each one's quantityBefore/quantityAfter following on from the
// last, and the final quantityAfter always lands exactly on that stock's
// real current quantity — so Inventory History (which reads this same
// table) still tells a coherent story, not just isolated demo rows.
//
// Safe to re-run: it only adds new movement rows (each with its own random
// id), it never deletes or edits existing ones. Running it twice just
// gives you a denser history.

// SECURITY: CLI-only — see seed_demo_passwords.php for why this guard
// exists even with scripts/.htaccess in place. This one doesn't touch
// credentials, but it's still an unauthenticated DB write and shouldn't be
// triggerable by anyone who can reach a URL.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../includes/db.php';

const DAYS_OF_HISTORY = 90;
const MIN_MOVEMENTS_PER_STOCK = 12;
const MAX_MOVEMENTS_PER_STOCK = 24;
const REASONS_IN = ['Harvest intake', 'Restock from supplier', 'New shipment received', 'Purchase order fulfilled'];
const REASONS_OUT = ['Sold to buyer', 'Procurement request fulfilled', 'Distribution to warehouse', 'Order shipped out'];
const REASONS_ADJ = ['Recount adjustment', 'Spoilage correction', 'Damage write-off', 'Audit correction'];

function seed_id(): string {
    return 'move_' . time() . '_' . bin2hex(random_bytes(4));
}

try {
    $pdo = db();

    $stocks = $pdo->query("SELECT id, userId, quantity, unit FROM stocks WHERE status <> 'archived'")->fetchAll();
    if (empty($stocks)) {
        echo "No active stock listings found — add some inventory first, then re-run this script.\n";
        exit(0);
    }

    $insert = $pdo->prepare(
        'INSERT INTO stock_movements (id, stockId, userId, type, quantityChange, quantityBefore, quantityAfter, reason, createdAt)
         VALUES (:id, :stockId, :userId, :type, :quantityChange, :quantityBefore, :quantityAfter, :reason, :createdAt)'
    );

    $totalInserted = 0;

    foreach ($stocks as $stock) {
        $currentQty = (float) $stock['quantity'];
        $count = random_int(MIN_MOVEMENTS_PER_STOCK, MAX_MOVEMENTS_PER_STOCK);

        // Pick $count distinct days in the last DAYS_OF_HISTORY, oldest first,
        // each with a random time-of-day so the ticker doesn't look robotic.
        $dayOffsets = (function () use ($count) {
            $days = range(0, DAYS_OF_HISTORY - 1);
            shuffle($days);
            $picked = array_slice($days, 0, min($count, DAYS_OF_HISTORY));
            rsort($picked); // oldest offset (largest number of days ago) first
            return $picked;
        })();

        // Build random deltas first (typically 2-8% of current quantity per
        // movement, roughly 60% incoming / 40% outgoing), then rescale so
        // they net to zero -- that way the walk can start and end on the
        // stock's real current quantity without drifting negative or wildly
        // exceeding it.
        $deltas = [];
        foreach ($dayOffsets as $i) {
            $pct = random_int(2, 8) / 100;
            $magnitude = max(1, round($currentQty * $pct, 2));
            $isIncoming = random_int(1, 100) <= 60;
            $deltas[] = $isIncoming ? $magnitude : -$magnitude;
        }
        $net = array_sum($deltas);
        // Nudge the single largest-magnitude movement so the whole set nets
        // to zero (keeps every individual movement's sign/intent intact).
        if (!empty($deltas)) {
            $adjustIndex = array_keys($deltas, max($deltas))[0];
            $deltas[$adjustIndex] -= $net;
        }

        $runningQty = $currentQty - array_sum($deltas); // starting quantity, DAYS_OF_HISTORY-ish days ago
        if ($runningQty < 0) {
            // If that would start negative (small/new listings), just clamp
            // deltas down proportionally so the walk stays non-negative.
            $shrink = $currentQty > 0 ? ($currentQty * 0.9) / max(array_sum(array_map('abs', $deltas)), 1) : 0;
            $deltas = array_map(fn($d) => round($d * $shrink, 2), $deltas);
            $runningQty = max(0, $currentQty - array_sum($deltas));
        }

        foreach ($dayOffsets as $idx => $daysAgo) {
            $delta = $deltas[$idx];
            $before = round($runningQty, 2);
            $after = round($runningQty + $delta, 2);
            $runningQty = $after;

            $type = $idx === array_key_last($dayOffsets)
                ? 'adjustment' // last one settles the chain onto the exact current quantity
                : ($delta >= 0 ? 'in' : 'adjustment');
            // Roughly alternate in/out so both incoming and outgoing lines
            // on the chart have visible movement, not just adjustments.
            if ($idx % 2 === 0) {
                $type = $delta >= 0 ? 'in' : 'out';
            }
            $reasonPool = $type === 'in' ? REASONS_IN : ($type === 'out' ? REASONS_OUT : REASONS_ADJ);
            $reason = $reasonPool[array_rand($reasonPool)];

            $timestamp = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days") + random_int(0, 23) * 3600 + random_int(0, 59) * 60);

            $insert->execute([
                ':id' => seed_id(),
                ':stockId' => $stock['id'],
                ':userId' => $stock['userId'],
                ':type' => $type,
                ':quantityChange' => $delta,
                ':quantityBefore' => $before,
                ':quantityAfter' => $after,
                ':reason' => $reason,
                ':createdAt' => $timestamp,
            ]);
            $totalInserted++;
        }
        // Force the final row's quantityAfter to match reality exactly,
        // guarding against float rounding drift across the chain.
        $pdo->prepare('UPDATE stock_movements SET quantityAfter = :qty WHERE stockId = :stockId ORDER BY createdAt DESC LIMIT 1')
            ->execute([':qty' => $currentQty, ':stockId' => $stock['id']]);
    }

    echo "Done. Inserted {$totalInserted} stock_movements rows across " . count($stocks) . " stock listing(s), spanning the last " . DAYS_OF_HISTORY . " days.\n";
    echo "Reload the dashboard and try the 7D / 30D / 6M / 1Y range pills on the Rice Supply Trend chart.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Failed: " . $e->getMessage() . "\n");
    exit(1);
}
