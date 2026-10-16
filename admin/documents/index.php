<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');

// Handle the POST — and its redirect — before includes/header.php is
// loaded below, since that include starts printing HTML immediately;
// calling header('Location: ...') after any output has started fails
// with "headers already sent".
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['documentId']) && !empty($_POST['action'])) {
    csrf_verify();
    $status = $_POST['action'] === 'verify' ? 'verified' : 'rejected';
    verify_document($_POST['documentId'], $status, current_user_id() ?? '');
    header('Location: ' . url('admin/documents/'));
    exit;
}

$title = 'Compliance';
$activePage = 'admindocuments';
include __DIR__ . '/../../includes/header.php';

$pendingDocs = get_pending_documents();
?>
<h2>Compliance</h2>
<?php if (private_storage_legacy_count() > 0): ?>
    <div class="alert alert-error"><strong>Action needed:</strong> <?php echo (int) private_storage_legacy_count(); ?> document file(s) are still in the public uploads folder. <a href="<?php echo htmlspecialchars(url('admin/storage/')); ?>">Move them to private storage</a>.</div>
<?php endif; ?>
<p class="muted">Review or reject submitted documents. OCR/AI results (once processed) are shown alongside what the submitter typed.</p>
<div class="card">
    <?php if (empty($pendingDocs)): ?>
        <p>No pending documents.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>File</th><th>Owner</th><th>Claimed Business / Permit</th><th>Submitted</th>
                    <th>OCR Status</th><th>Confidence</th><th>AI Flag</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingDocs as $doc): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($doc['fileName']); ?></td>
                        <td><?php echo htmlspecialchars($doc['userId']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($doc['businessName'] ?? '—'); ?> /
                            <?php echo htmlspecialchars($doc['permitNumber'] ?? '—'); ?>
                        </td>
                        <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($doc['uploadedAt']))); ?></td>
                        <td><?php echo htmlspecialchars($doc['ocrStatus'] ?? 'not_processed'); ?></td>
                        <td><?php echo isset($doc['ocrConfidence']) && $doc['ocrConfidence'] !== null ? htmlspecialchars($doc['ocrConfidence']) . '%' : '—'; ?></td>
                        <td>
                            <?php if (!empty($doc['aiFlag'])): ?>
                                <span class="alert alert-error" style="padding:2px 8px;display:inline-block;"><?php echo htmlspecialchars($doc['aiFlagReason'] ?? 'Flagged'); ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars(url('admin/documents/review/?id=' . urlencode($doc['id']))); ?>">Review &amp; Correct</a>
                            <form method="post" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="documentId" value="<?php echo htmlspecialchars($doc['id']); ?>">
                                <button class="btn btn-primary btn-sm" type="submit" name="action" value="verify">Verify</button>
                                <button class="btn btn-sm" type="submit" name="action" value="reject">Reject</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
