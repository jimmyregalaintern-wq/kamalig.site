<?php
/**
 * scripts/migrate_schema.php — brings the database up to the structure the code
 * expects. CLI only. Safe to run any number of times: it only adds what is missing.
 *
 *   php scripts/migrate_schema.php            dry run: list what is missing
 *   php scripts/migrate_schema.php --apply    create the missing pieces
 *
 * Why it exists: several protections (per-IP login throttle, payment deadline,
 * stored consent) need these columns/tables. Without them the app used to carry on
 * with the protection quietly OFF. This script makes the hardened structure
 * reproducible from the repository, and Admin > Security check reports anything
 * still missing. It works on MySQL and MariaDB (no "IF NOT EXISTS" on columns).
 *
 * It does NOT add foreign keys: keep those in your schema baseline (mysqldump --no-data).
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}
require_once __DIR__ . '/../includes/db.php';

$apply = in_array('--apply', $argv ?? [], true);
$pdo = db();

$hasTable = function (string $t) use ($pdo): bool {
    $s = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->execute([$t]);
    return (bool) $s->fetchColumn();
};
$hasColumn = function (string $t, string $c) use ($pdo): bool {
    $s = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$t, $c]);
    return (bool) $s->fetchColumn();
};

$steps = [
    ['table login_attempts (per-IP login throttle)', fn() => !$hasTable('login_attempts'),
        'CREATE TABLE login_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ipHash CHAR(64) NOT NULL, attemptedAt DATETIME NOT NULL, KEY idx_login_attempts_ip (ipHash, attemptedAt)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'],
    ['column payments.dueAt (payment deadline)', fn() => $hasTable('payments') && !$hasColumn('payments', 'dueAt'),
        'ALTER TABLE payments ADD COLUMN dueAt DATETIME NULL, ADD KEY idx_payments_due (dueAt)'],
    ['column users.consent_version (privacy consent)', fn() => !$hasColumn('users', 'consent_version'),
        'ALTER TABLE users ADD COLUMN consent_version VARCHAR(20) NULL'],
    ['column users.consent_at (privacy consent)', fn() => !$hasColumn('users', 'consent_at'),
        'ALTER TABLE users ADD COLUMN consent_at DATETIME NULL'],
];

echo ($apply ? 'APPLY' : 'DRY RUN') . ' — schema migration on ' . (getenv('DB_NAME') ?: 'rice_ms') . "\n";
$missing = 0;
foreach ($steps as [$label, $isMissing, $sql]) {
    if (!$isMissing()) {
        echo "  ok       $label\n";
        continue;
    }
    $missing++;
    if (!$apply) {
        echo "  MISSING  $label\n";
        continue;
    }
    try {
        $pdo->exec($sql);
        echo "  created  $label\n";
    } catch (Throwable $e) {
        echo "  FAILED   $label: " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo $missing === 0 ? "Nothing to do: the structure is complete.\n" : ($apply ? "Done.\n" : "$missing item(s) missing. Re-run with --apply to create them.\n");
