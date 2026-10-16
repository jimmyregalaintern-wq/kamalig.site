<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_auth();

// Documents (submitting compliance docs) is for account holders, not admin —
// admin reviews documents at admin/documents/ instead. Redirect there rather
// than just hiding the nav link, so direct navigation can't reach this either.
if (user_role() === 'admin') {
    header('Location: ' . url('admin/documents/'));
    exit;
}

$title = 'Documents';
$activePage = 'documents';
include __DIR__ . '/../includes/header.php';

$documents = get_documents(current_user_id() ?? '');
?>
<h2>Documents</h2>
<p class="muted">Upload and review documents for your account.</p>
<?php $complianceShowWhenApproved = true; include __DIR__ . '/../includes/compliance_banner.php'; ?>
<div class="card">
    <a class="btn btn-primary" href="<?php echo htmlspecialchars(url('documents/upload/')); ?>">Upload Document</a>
</div>
<?php if (empty($documents)): ?>
    <div class="card">
        <p>No documents uploaded yet.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>File</th><th>Status</th><th>Uploaded</th><th>Notes</th></tr>
            </thead>
            <tbody>
                <?php foreach ($documents as $doc):
                    $fileLink = !empty($doc['storedFileName']) ? url('documents/file/?id=' . urlencode($doc['id'])) : null;
                ?>
                    <tr>
                        <td>
                            <?php if ($fileLink): ?>
                                <a href="<?php echo htmlspecialchars($fileLink); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($doc['fileName']); ?></a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($doc['fileName']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($doc['status']); ?></td>
                        <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($doc['uploadedAt']))); ?></td>
                        <td>
                            <?php if ($doc['status'] === 'rejected'): ?>
                                <?php if (!empty($doc['aiFlagReason'])): ?>
                                    <span class="muted"><?php echo htmlspecialchars($doc['aiFlagReason']); ?></span><br>
                                <?php endif; ?>
                                <?php if (empty($doc['relatedRequestId'])): ?>
                                    <a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('documents/upload/?type=' . urlencode($doc['documentType']))); ?>">Resubmit</a>
                                <?php endif; ?>
                            <?php elseif ($doc['status'] === 'pending'): ?>
                                <?php
                                $ocrStatus = $doc['ocrStatus'] ?? 'not_processed';
                                $pendingNote = match ($ocrStatus) {
                                    'processing' => 'Being scanned…',
                                    'done' => !empty($doc['aiFlag']) ? 'Under review — a check was flagged for staff to look at.' : 'Scanned, awaiting staff review.',
                                    'failed' => 'Scan didn\'t go through — staff will review the file directly.',
                                    default => 'Awaiting staff review.',
                                };
                                echo htmlspecialchars($pendingNote);
                                ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
