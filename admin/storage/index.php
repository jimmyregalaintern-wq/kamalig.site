<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    render_error_page(403, 'Access denied', 'You do not have permission to view this page.');
}

// One-time job (Fix Guide item 1): move documents that were saved in the public
// uploads/ folder before this update into private storage. Safe to repeat.
$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $report = private_storage_migrate(true);
    log_activity(current_user_id(), 'storage.migrate', 'system', null, $report['moved'] . ' moved, ' . $report['failed'] . ' failed');
}

try {
    $root = private_storage_root();
    $outside = private_storage_is_outside_web_root();
    $storageError = '';
} catch (RuntimeException $e) {
    $root = '';
    $outside = false;
    $storageError = $e->getMessage();
}
$exposed = private_storage_legacy_count();

$title = 'File Storage';
$activePage = 'documents';
include __DIR__ . '/../../includes/header.php';
?>
<h2>Private file storage</h2>
<p class="muted">Compliance and shipment documents are kept out of the public web folder and are opened only after a permission check.</p>

<?php if ($storageError !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($storageError); ?></div>
<?php endif; ?>

<?php if ($report): ?>
    <div class="alert <?php echo $report['failed'] ? 'alert-error' : 'alert-success'; ?>">
        Moved <?php echo (int) $report['moved']; ?> of <?php echo (int) $report['found']; ?> file(s) into private storage<?php echo $report['failed'] ? '; ' . (int) $report['failed'] . ' failed: ' . htmlspecialchars(implode('; ', $report['errors'])) : '.'; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h3>Status</h3>
    <p><strong>Storage location:</strong> <code><?php echo htmlspecialchars($root !== '' ? $root : 'not available'); ?></code></p>
    <p><strong>Outside the public web folder:</strong>
        <?php if ($outside): ?>
            <span style="color:#166534;">Yes (recommended)</span>
        <?php else: ?>
            <span style="color:#b45309;">No</span> &mdash; the folder above public_html was not writable, so a closed folder inside public_html is being used. Ask your host to allow creating <code>kamalig_private</code> next to <code>public_html</code>, or set <code>PRIVATE_STORAGE_DIR</code> in <code>.env</code>.
        <?php endif; ?>
    </p>
    <p><strong>Files still in the public uploads folder:</strong>
        <?php if ($exposed === 0): ?>
            <span style="color:#166534;">0 &mdash; nothing to move</span>
        <?php else: ?>
            <span style="color:#b91c1c;"><?php echo (int) $exposed; ?></span>
        <?php endif; ?>
    </p>
    <?php if ($exposed > 0): ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <button class="btn btn-primary" type="submit">Move <?php echo (int) $exposed; ?> file(s) to private storage</button>
        </form>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
