<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_role('admin');

$id = trim($_GET['id'] ?? $_POST['id'] ?? '');
$doc = $id !== '' ? get_document_by_id($id) : null;

if (!$doc) {
    http_response_code(404);
    $title = 'Document not found';
    include __DIR__ . '/../../../includes/header.php';
    echo '<h2>Document not found</h2><p>This document does not exist.</p>';
    echo '<a class="btn btn-secondary" href="' . htmlspecialchars(url('admin/documents/')) . '">Back to Documents</a>';
    include __DIR__ . '/../../../includes/footer.php';
    exit;
}

$title = 'Review Document';
$activePage = 'admindocuments';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_corrections') {
        $fields = [
            'businessName' => trim($_POST['businessName'] ?? ''),
            'permitNumber' => trim($_POST['permitNumber'] ?? ''),
            'taxId' => trim($_POST['taxId'] ?? '') !== '' ? trim($_POST['taxId']) : null,
            'expiryDate' => trim($_POST['expiryDate'] ?? '') !== '' ? trim($_POST['expiryDate']) : null,
        ];
        correct_document_fields($id, $fields, current_user_id() ?? '');
        $doc = get_document_by_id($id);
        $message = 'Corrections saved.';
    } elseif ($action === 'verify' || $action === 'reject') {
        verify_document($id, $action === 'verify' ? 'verified' : 'rejected', current_user_id() ?? '');
        header('Location: ' . url('admin/documents/'));
        exit;
    }
}

// Re-run the rule-based checks live so the reviewer sees current results
// even if the corrections above just changed what they'd flag.
$duplicate = find_duplicate_document($doc['businessName'] ?? '', $doc['permitNumber'] ?? '', $doc['id']);
$isRegisteredBusiness = is_registered_business_name($doc['businessName'] ?? '');

include __DIR__ . '/../../../includes/header.php';

$fileExtension = strtolower(pathinfo($doc['storedFileName'] ?? $doc['fileName'], PATHINFO_EXTENSION));
$isImage = in_array($fileExtension, ['jpg', 'jpeg', 'png'], true);
$fileLink = $doc['storedFileName'] ? url('documents/file/?id=' . urlencode($doc['id'])) : null;
?>
<h2>Review Document</h2>
<p class="muted">Compare the submitter's claims and the OCR/AI results against the original file, correct anything wrong, then verify or reject.</p>

<?php if ($message !== ''): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <h3>Original File</h3>
        <p><strong>File name:</strong> <?php echo htmlspecialchars($doc['fileName']); ?></p>
        <p><strong>Type:</strong> <?php echo htmlspecialchars($doc['documentType']); ?></p>
        <p><strong>Uploaded:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($doc['uploadedAt']))); ?></p>
        <?php if ($fileLink): ?>
            <?php if ($isImage): ?>
                <a href="<?php echo htmlspecialchars($fileLink); ?>" target="_blank" rel="noopener">
                    <img src="<?php echo htmlspecialchars($fileLink); ?>" alt="Uploaded document" style="max-width:100%; border-radius:8px; border:1px solid #e2e8f0;">
                </a>
            <?php else: ?>
                <a class="btn btn-secondary" href="<?php echo htmlspecialchars($fileLink); ?>" target="_blank" rel="noopener">Open File (<?php echo htmlspecialchars(strtoupper($fileExtension)); ?>)</a>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">No stored file on record for this document.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>OCR / AI Results</h3>
        <p><strong>OCR status:</strong> <?php echo htmlspecialchars($doc['ocrStatus'] ?? 'not_processed'); ?></p>
        <p><strong>OCR confidence:</strong> <?php echo isset($doc['ocrConfidence']) && $doc['ocrConfidence'] !== null ? htmlspecialchars($doc['ocrConfidence']) . '%' : '—'; ?></p>
        <p><strong>AI match score:</strong> <?php echo isset($doc['aiMatchScore']) && $doc['aiMatchScore'] !== null ? htmlspecialchars($doc['aiMatchScore']) . '%' : '—'; ?></p>
        <p><strong>Claimed Tax ID/TIN:</strong> <?php echo htmlspecialchars($doc['taxId'] ?? '—'); ?></p>

        <?php if (!empty($doc['aiFlag'])): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($doc['aiFlagReason'] ?? 'Flagged for review.'); ?></div>
        <?php endif; ?>
        <?php if ($duplicate): ?>
            <div class="alert alert-error">Possible duplicate: matches document <strong><?php echo htmlspecialchars($duplicate['fileName'] ?? $duplicate['id']); ?></strong> (same business name + permit number), uploaded <?php echo htmlspecialchars(date('Y-m-d', strtotime($duplicate['uploadedAt']))); ?>.</div>
        <?php endif; ?>
        <?php if (!$isRegisteredBusiness): ?>
            <div class="alert alert-error">The claimed business name doesn't match any registered account's company name.</div>
        <?php endif; ?>
        <?php if (empty($doc['aiFlag']) && !$duplicate && $isRegisteredBusiness): ?>
            <p class="muted">No issues flagged.</p>
        <?php endif; ?>

        <p style="margin-top:16px;"><strong>OCR extracted text</strong></p>
        <textarea readonly rows="8" style="width:100%; font-family: monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($doc['ocrRawText'] ?? '(no OCR text — file type not supported for OCR, or OCR is not configured)'); ?></textarea>
    </div>
</div>

<div class="card">
    <h3>Submitter's Claims — edit before approving</h3>
    <p class="muted">Compare these against the file on the left and the OCR text above. Save any corrections before you verify or reject.</p>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($doc['id']); ?>">
        <div class="form-group">
            <label for="businessName">Business Name</label>
            <input id="businessName" name="businessName" type="text" value="<?php echo htmlspecialchars($doc['businessName'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label for="permitNumber">Permit/Reference Number</label>
            <input id="permitNumber" name="permitNumber" type="text" value="<?php echo htmlspecialchars($doc['permitNumber'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label for="taxId">Tax ID/TIN</label>
            <input id="taxId" name="taxId" type="text" value="<?php echo htmlspecialchars($doc['taxId'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label for="expiryDate">Expiry Date</label>
            <input id="expiryDate" name="expiryDate" type="date" value="<?php echo htmlspecialchars($doc['expiryDate'] ?? ''); ?>">
        </div>
        <button class="btn btn-secondary" type="submit" name="action" value="save_corrections">Save Corrections</button>
    </form>
</div>

<div class="card">
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($doc['id']); ?>">
        <button class="btn btn-primary" type="submit" name="action" value="verify">Verify</button>
        <button class="btn btn-danger" type="submit" name="action" value="reject" data-confirm-modal="Reject this document?" data-confirm-title="Reject document">Reject</button>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('admin/documents/')); ?>">Back without deciding</a>
    </form>
</div>
<?php include __DIR__ . '/../../../includes/footer.php'; ?>
