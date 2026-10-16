<?php
/**
 * scripts/backup_cron.php — scheduled JSON backup (Fix Guide item 13).
 * CLI only. Writes a full system export (the same data as Admin > Backup) to the
 * private storage folder (outside public_html when possible) and keeps the newest
 * BACKUP_KEEP files (default 14).
 *
 *   php scripts/backup_cron.php
 *
 * Hostinger cron (hPanel -> Advanced -> Cron Jobs), once a day, e.g.:
 *   30 2 * * *  /usr/bin/php /home/USER/domains/YOURSITE/public_html/scripts/backup_cron.php
 *
 * A backup that has never been restored is only a hope: restore one on a staging
 * copy (Admin > Backup > Import) and write the steps down.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';

if (!database_available()) {
    fwrite(STDERR, "Database is not available; no backup written.\n");
    exit(1);
}

$dir = private_storage_root() . '/backups';
if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "Could not create $dir\n");
    exit(1);
}

$file = $dir . '/kamalig_backup_' . date('Y-m-d_H-i-s') . '.json';
$json = json_encode(create_system_backup(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write the backup file.\n");
    exit(1);
}
@chmod($file, 0600);

// The JSON holds database rows only. The uploaded files (compliance documents,
// shipment documents, payment proofs) live on disk, so archive them too: a
// restore needs both halves or the database points at files that are gone.
$filesZip = $dir . '/kamalig_files_' . date('Y-m-d_H-i-s') . '.zip';
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($filesZip, ZipArchive::CREATE) === true) {
        $added = 0;
        foreach ([PRIVATE_KIND_DOCUMENTS, PRIVATE_KIND_SHIPMENTS, PRIVATE_KIND_PAYMENTS] as $kind) {
            $folder = private_storage_root() . '/' . $kind;
            foreach (is_dir($folder) ? (scandir($folder) ?: []) : [] as $name) {
                if (private_storage_valid_name($name)) {
                    $zip->addFile($folder . '/' . $name, $kind . '/' . $name);
                    $added++;
                }
            }
        }
        $zip->close();
        if ($added === 0) {
            @unlink($filesZip); // nothing uploaded yet
        } else {
            @chmod($filesZip, 0600);
        }
    } else {
        fwrite(STDERR, "Warning: could not create the files archive.\n");
    }
} else {
    fwrite(STDERR, "Warning: PHP ZipArchive is not available; uploaded files were NOT archived.\n");
}
@file_put_contents(__DIR__ . '/../uploads/.last_backup', (string) time());
cron_heartbeat('backup');

$keep = max(1, (int) (getenv('BACKUP_KEEP') ?: 14));
foreach (['kamalig_backup_*.json', 'kamalig_files_*.zip'] as $pattern) {
    $all = glob($dir . '/' . $pattern) ?: [];
    sort($all);
    foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $old) {
        @unlink($old);
    }
}

echo 'Backup written: ' . $file . ' (' . round(strlen($json) / 1024) . " KB). Keeping the newest $keep.\n";
