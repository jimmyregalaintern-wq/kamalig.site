<?php
/**
 * includes/private_storage.php — private file storage (Fix Guide, item 1).
 *
 * Compliance documents (permits, IDs, business registrations) and shipment
 * documents used to be saved in public_html/uploads/ under a random name.
 * Random names are not access control: anyone holding or guessing a URL could
 * open someone's ID. Those files now live OUTSIDE the web root and are only
 * reachable through a PHP endpoint that checks who is asking:
 *
 *   documents/file/?id=<documentId>              owner or admin
 *   procurement/tracking/file/?id=<orderId>      buyer, seller or admin
 *
 * Where the files live (first usable location wins):
 *   1. PRIVATE_STORAGE_DIR in .env (absolute path), if you set one
 *   2. <folder above public_html>/kamalig_private      <- normal on Hostinger
 *   3. public_html/uploads/_private  (fallback only: closed by .htaccess,
 *      but anything inside public_html is weaker than something outside it)
 *
 * Stored references look like "private:<32 hex chars>.<pdf|jpg|png>".
 * Marketplace photos (uploads/stock-images/) stay public on purpose.
 */

const PRIVATE_KIND_DOCUMENTS = 'documents';
const PRIVATE_KIND_SHIPMENTS = 'shipments';
const PRIVATE_KIND_PAYMENTS = 'payment-proofs';

/** Candidate storage roots in priority order (not created, just computed). */
function private_storage_candidates(): array {
    $appRoot = dirname(__DIR__);
    $list = [];
    $configured = trim((string) getenv('PRIVATE_STORAGE_DIR'));
    if ($configured !== '') {
        $list[] = rtrim($configured, '/\\');
    }
    $list[] = dirname($appRoot) . '/kamalig_private';
    $list[] = $appRoot . '/uploads/_private';
    return $list;
}

/** Create a storage root (and its guard files) if needed. True when it is usable. */
function private_storage_prepare_root(string $root): bool {
    if (!is_dir($root)) {
        if (!@mkdir($root, 0750, true) && !is_dir($root)) {
            return false;
        }
    }
    if (!is_writable($root)) {
        return false;
    }
    // Belt and braces: if this folder is ever reachable from the web, refuse everything.
    $guard = $root . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "# Private files. Never served directly; see includes/private_storage.php.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $index = $root . '/index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
    }
    return true;
}

/** The root new files are written to. Throws if no location is usable. */
function private_storage_root(): string {
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    foreach (private_storage_candidates() as $candidate) {
        if (private_storage_prepare_root($candidate)) {
            return $root = $candidate;
        }
    }
    throw new RuntimeException('Private file storage is not available. Please contact the administrator.');
}

/** True when files are being kept outside the web root (the secure layout). */
function private_storage_is_outside_web_root(): bool {
    try {
        $root = realpath(private_storage_root());
    } catch (RuntimeException $e) {
        return false;
    }
    $web = realpath(dirname(__DIR__));
    return $root !== false && $web !== false && strpos($root . DIRECTORY_SEPARATOR, $web . DIRECTORY_SEPARATOR) !== 0;
}

/** Folder new files of this kind are written to. */
function private_storage_dir(string $kind): string {
    if (!in_array($kind, [PRIVATE_KIND_DOCUMENTS, PRIVATE_KIND_SHIPMENTS, PRIVATE_KIND_PAYMENTS], true)) {
        throw new InvalidArgumentException('Unknown storage kind.');
    }
    $dir = private_storage_root() . '/' . $kind;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the private storage folder.');
    }
    return $dir;
}

/** Only names our own upload handler could have produced. Blocks traversal outright. */
function private_storage_valid_name(string $name): bool {
    return (bool) preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $name);
}

/** "private:abc.pdf" -> "abc.pdf"; anything else -> null. */
function private_storage_ref_name(?string $reference): ?string {
    if ($reference === null || strncmp($reference, 'private:', 8) !== 0) {
        return null;
    }
    $name = substr($reference, 8);
    return private_storage_valid_name($name) ? $name : null;
}

/**
 * Absolute path of a stored file, or null. Looks in every candidate root (so a
 * file written before a settings change is still found) and, for documents and
 * shipments not yet migrated, in the old public uploads/ folder.
 */
function private_storage_find(string $kind, string $storedName): ?string {
    if (!private_storage_valid_name($storedName)) {
        return null;
    }
    $dirs = [];
    foreach (private_storage_candidates() as $root) {
        $dirs[] = $root . '/' . $kind;
    }
    $dirs[] = dirname(__DIR__) . '/uploads'; // legacy location, until migrated
    foreach ($dirs as $dir) {
        $real = realpath($dir . '/' . $storedName);
        $realDir = realpath($dir);
        if ($real !== false && $realDir !== false && is_file($real)
            && strpos($real, $realDir . DIRECTORY_SEPARATOR) === 0) {
            return $real;
        }
    }
    return null;
}

/** Content type from the stored name's extension (the upload handler forced it from the sniffed MIME type). */
function private_storage_mime(string $storedName): string {
    $ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
    return ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';
}

/** Stream a private file to the browser with safe headers, then stop. */
function private_storage_send(string $path, string $displayName): void {
    $mime = private_storage_mime(basename($path));
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $displayName);
    $safeName = trim((string) $safeName, '._') !== '' ? $safeName : 'document';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $safeName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex, nofollow');
    readfile($path);
    exit;
}

/**
 * Cron heartbeat: each scheduled script records "I ran just now" so the admin
 * Security check can show whether the cron jobs are actually running.
 * Stored next to the private files (outside the web root when possible).
 */
function cron_heartbeat(string $job): void {
    try {
        $dir = private_storage_root() . '/heartbeat';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($dir . '/' . preg_replace('/[^a-z_]/', '', $job) . '.txt', (string) time(), LOCK_EX);
    } catch (Throwable $e) {
        error_log('KAMALIG cron heartbeat failed: ' . $e->getMessage());
    }
}

/** Unix time the job last finished, or null if it never has. */
function cron_last_run(string $job): ?int {
    try {
        $file = private_storage_root() . '/heartbeat/' . preg_replace('/[^a-z_]/', '', $job) . '.txt';
        return is_file($file) ? (int) file_get_contents($file) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Link for a shipment document on an order (new "private:" refs and old public ones). */
function shipment_document_link(array $order): ?string {
    $ref = $order['proofOfDeliveryUrl'] ?? null;
    if (!$ref) {
        return null;
    }
    if (private_storage_ref_name($ref) !== null) {
        return url('procurement/tracking/file/?id=' . urlencode($order['id']));
    }
    return url($ref); // legacy value: still points at uploads/ until the migration is run
}

/**
 * Move compliance and shipment files that still sit in the public uploads/
 * folder into private storage, and repoint the database at them.
 *
 * Safe to run more than once. Returns counts and a per-file list.
 *   $apply = false  report only, change nothing
 */
function private_storage_migrate(bool $apply): array {
    $result = ['found' => 0, 'moved' => 0, 'failed' => 0, 'orphans' => 0, 'rows' => 0, 'files' => [], 'errors' => []];
    $publicDir = realpath(dirname(__DIR__) . '/uploads');
    if ($publicDir === false) {
        return $result;
    }
    $db = function_exists('database_available') && database_available();

    foreach (scandir($publicDir) ?: [] as $name) {
        $full = $publicDir . '/' . $name;
        if (!is_file($full) || !private_storage_valid_name($name)) {
            continue; // skips folders (stock-images, payment-proofs), .htaccess, .last_backup
        }
        $result['found']++;

        // Which feature owns this file?
        $kind = PRIVATE_KIND_DOCUMENTS;
        $isOrphan = false;
        if ($db) {
            $doc = query_one('SELECT id FROM documents WHERE storedFileName = ? LIMIT 1', [$name]);
            $ship = query_one('SELECT id FROM procurement_orders WHERE proofOfDeliveryUrl = ? LIMIT 1', ['/uploads/' . $name]);
            if (!$doc && $ship) {
                $kind = PRIVATE_KIND_SHIPMENTS;
            } elseif (!$doc) {
                $isOrphan = true; // no database row points here; still moved, never left public
            }
        }
        if ($isOrphan) {
            $result['orphans']++;
        }
        $result['files'][] = ['name' => $name, 'kind' => $kind, 'orphan' => $isOrphan];

        if (!$apply) {
            continue;
        }

        try {
            $target = private_storage_dir($kind) . '/' . $name;
            $ok = @rename($full, $target);
            if (!$ok) { // different filesystem: copy, verify size, then remove the original
                $ok = @copy($full, $target) && filesize($target) === filesize($full) && @unlink($full);
            }
            if (!$ok) {
                throw new RuntimeException('could not move the file');
            }
            @chmod($target, 0640);
            $result['moved']++;
            if ($db) {
                if ($kind === PRIVATE_KIND_DOCUMENTS) {
                    query_execute('UPDATE documents SET fileUrl = ? WHERE storedFileName = ?', ['private:' . $name, $name]);
                } else {
                    query_execute('UPDATE procurement_orders SET proofOfDeliveryUrl = ? WHERE proofOfDeliveryUrl = ?', ['private:' . $name, '/uploads/' . $name]);
                }
                $result['rows']++;
            }
        } catch (Throwable $e) {
            $result['failed']++;
            $result['errors'][] = $name . ': ' . $e->getMessage();
        }
    }
    return $result;
}

/** How many sensitive files are still publicly reachable (0 once migrated). */
function private_storage_legacy_count(): int {
    $publicDir = realpath(dirname(__DIR__) . '/uploads');
    $n = 0;
    foreach ($publicDir ? (scandir($publicDir) ?: []) : [] as $name) {
        if (is_file($publicDir . '/' . $name) && private_storage_valid_name($name)) {
            $n++;
        }
    }
    return $n;
}
