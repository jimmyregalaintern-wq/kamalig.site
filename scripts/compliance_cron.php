<?php
/**
 * scripts/compliance_cron.php — daily housekeeping for the compliance gate
 * (Fix Guide, item 7). CLI only (scripts/.htaccess also denies web access).
 *
 *   php scripts/compliance_cron.php            dry run: report only
 *   php scripts/compliance_cron.php --apply    actually make the changes
 *
 * Two jobs:
 *   1. EXPIRY  — an approved user whose required document has expired goes
 *                back to "pending" (compliance state: incomplete) and is
 *                notified. Accounts with no documents on file are never
 *                touched (they were approved by an admin directly).
 *   2. PURGE   — an account that never finished compliance is deleted
 *                COMPLIANCE_PURGE_DAYS (default 30) after it registered,
 *                together with its uploaded files. Accounts that are under
 *                admin review, approved, suspended or admin are never purged:
 *                a slow admin is not the applicant's fault.
 *
 * Hostinger cron (hPanel -> Advanced -> Cron Jobs), once a day, e.g.:
 *   0 2 * * *  /usr/bin/php /home/USER/domains/YOURSITE/public_html/scripts/compliance_cron.php --apply
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/account_delete.php';

$apply = in_array('--apply', $argv ?? [], true);
$purgeDays = max(1, (int) (getenv('COMPLIANCE_PURGE_DAYS') ?: 30));

if (!database_available()) {
    exit("Database is not available; nothing to do.\n");
}

echo ($apply ? 'APPLY' : 'DRY RUN') . " — compliance cron " . date('Y-m-d H:i:s') . "\n";

// ---------------------------------------------------------------------
// 1. Expired documents: approved -> pending/incomplete
// ---------------------------------------------------------------------
$demoted = 0;
$approved = query_all("SELECT * FROM users WHERE role <> 'admin' AND verified = 1 AND status = 'approved'");
foreach ($approved as $user) {
    $checklist = compliance_checklist($user['id'], $user['role']);
    $expiredLabels = [];
    foreach ($checklist as $item) {
        if ($item['required'] && $item['status'] === 'expired') {
            $expiredLabels[] = $item['label'] . ' (expired ' . ($item['doc']['expiryDate'] ?? '') . ')';
        }
    }
    if (empty($expiredLabels)) {
        continue;
    }

    echo "  expire: {$user['email']} — " . implode('; ', $expiredLabels) . "\n";
    $demoted++;
    if ($apply) {
        query_execute(
            "UPDATE users SET verified = 0, status = 'pending', rejection_reason = NULL, reviewed_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), $user['id']]
        );
        log_activity(null, 'account.compliance_expired', 'user', $user['id'], implode('; ', $expiredLabels));
        create_notification([
            'userId' => $user['id'],
            'type' => 'account',
            'title' => 'Document expired — trading paused',
            'message' => 'A required document has expired: ' . implode('; ', $expiredLabels) . '. Upload the renewed document from Documents to restore full access.',
            'relatedId' => $user['id'],
        ]);
    }
}
echo "Expired-document accounts: $demoted\n";

// ---------------------------------------------------------------------
// 2. Purge accounts that never finished compliance
// ---------------------------------------------------------------------
$purged = 0;
$cutoff = strtotime("-$purgeDays days");
$candidates = query_all("SELECT * FROM users WHERE role <> 'admin' AND verified = 0 AND (status IS NULL OR status IN ('pending', 'rejected'))");

foreach ($candidates as $user) {
    // Age comes from the registration entry in the activity log (users has
    // no created-at column). No entry = cannot prove age = leave it alone.
    $reg = query_one(
        "SELECT MIN(createdAt) AS registeredAt FROM activity_log WHERE entityType = 'user' AND entityId = ? AND action = 'account.register'",
        [$user['id']]
    );
    $registeredAt = !empty($reg['registeredAt']) ? strtotime($reg['registeredAt']) : false;
    if ($registeredAt === false || $registeredAt > $cutoff) {
        continue;
    }
    if (compliance_evaluate($user)['state'] === 'under_review') {
        continue; // waiting on an admin, not on the applicant
    }

    echo "  purge: {$user['email']} (registered " . date('Y-m-d', $registeredAt) . ")\n";
    $purged++;
    if (!$apply) {
        continue;
    }

    // Reuse the admin delete: one transaction, child rows first (so foreign keys
    // never block it), files removed only after the commit. null actor = the system.
    $deleted = delete_user_account($user['id'], null, true);
    if (empty($deleted['success'])) {
        echo "    FAILED: " . ($deleted['error'] ?? 'unknown error') . "\n";
        $purged--;
        continue;
    }
    log_activity(null, 'account.purge', 'user', $user['id'], "never completed compliance within $purgeDays days");
}
echo "Accounts to purge: $purged\n";
if ($apply) {
    cron_heartbeat('compliance');
}

if (!$apply) {
    echo "Dry run only. Re-run with --apply to make these changes.\n";
}
