<?php
/**
 * scripts/migrate_private_documents.php — one-time move of compliance and
 * shipment documents from public uploads/ into private storage (Fix Guide item 1).
 * CLI only. Same job as the "Move files" button on admin/storage/.
 *
 *   php scripts/migrate_private_documents.php            dry run: report only
 *   php scripts/migrate_private_documents.php --apply    move the files
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}
require_once __DIR__ . '/../includes/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);
$r = private_storage_migrate($apply);
echo "Private storage: " . private_storage_root() . (private_storage_is_outside_web_root() ? " (outside web root)\n" : " (INSIDE web root - fallback)\n");
foreach ($r['files'] as $f) {
    echo "  {$f['kind']}: {$f['name']}" . ($f['orphan'] ? '  (no database row)' : '') . "\n";
}
echo "Files found in public uploads/: {$r['found']}\n";
$proofs = commerce_migrate_payment_proofs($apply);
echo "Payment proofs in the old uploads/payment-proofs folder: {$proofs['found']}" . ($apply ? " (moved {$proofs['moved']}, failed {$proofs['failed']})" : '') . "\n";
if ($apply) {
    echo "Moved: {$r['moved']}  Failed: {$r['failed']}\n";
    foreach ($r['errors'] as $e) {
        echo "  ERROR $e\n";
    }
} else {
    echo "Dry run only. Re-run with --apply to move them.\n";
}
