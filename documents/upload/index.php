<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

if (user_role() === 'admin') {
    header('Location: ' . url('admin/documents/'));
    exit;
}

$error = '';
// Optional: this upload is for a specific procurement transaction (e.g. a
// delivery receipt/invoice), passed via ?requestId=... from the requests
// pages. Falls back to null for a normal account-level compliance document.
$relatedRequestId = $_GET['requestId'] ?? $_POST['relatedRequestId'] ?? null;
$relatedRequest = $relatedRequestId ? get_request_by_id($relatedRequestId) : null;

// Ownership check — without this, any logged-in user could pass another
// user's requestId and both see that request's details (variety, quantity)
// and attach their own document to it. Same requesterId/supplierId check
// used in procurement/tracking/index.php: only the two parties actually
// on the request may reference it here. A mismatch is treated exactly like
// "no related request" rather than an error, since it's just optional
// context for an otherwise-normal document upload — nothing here should
// confirm to the user whether that request id even exists.
if ($relatedRequest) {
    $currentUserId = current_user_id() ?? '';
    if ($relatedRequest['requesterId'] !== $currentUserId && $relatedRequest['supplierId'] !== $currentUserId) {
        $relatedRequest = null;
        $relatedRequestId = null;
    }
}

// Handle the POST — and its redirect on success — before includes/header.php
// is loaded below, since that include starts printing HTML immediately; a
// header('Location: ...') call after any output has started fails with
// "headers already sent".
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $documentType = trim($_POST['documentType'] ?? '');
    $businessName = trim($_POST['businessName'] ?? '');
    $permitNumber = trim($_POST['permitNumber'] ?? '');
    $expiryDate = trim($_POST['expiryDate'] ?? '');
    $taxId = trim($_POST['taxId'] ?? '');

    if (empty($_FILES['document']['name']) || $documentType === '' || $businessName === '' || $permitNumber === '') {
        $error = 'Please complete all required fields and choose a file.';
    } else {
        try {
            $uploadDir = private_storage_dir(PRIVATE_KIND_DOCUMENTS);
            $uploaded = handle_secure_document_upload($_FILES['document'], $uploadDir);

            $claims = [
                'businessName' => $businessName,
                'permitNumber' => $permitNumber,
                'taxId' => $taxId,
                'expiryDate' => $expiryDate,
            ];

            $document = upload_document([
                'userId' => current_user_id() ?? '',
                'relatedRequestId' => $relatedRequest ? $relatedRequest['id'] : null,
                'documentType' => $documentType,
                'fileName' => $uploaded['originalFileName'],
                'storedFileName' => $uploaded['storedFileName'],
                'mimeType' => $uploaded['mimeType'],
                'fileSize' => $uploaded['fileSize'],
                'fileUrl' => $uploaded['fileUrl'],
                'status' => 'pending',
                'ocrData' => $claims,
            ]);

            log_activity(
                current_user_id() ?? null,
                'document.upload',
                'document',
                $document['id'],
                $relatedRequest ? ('for request ' . $relatedRequest['id']) : null
            );

            // Compliance gate (Fix Guide item 7): a user whose account was
            // rejected goes back into the admin's review queue as soon as
            // they submit a new account-level document.
            if (!$relatedRequest) {
                compliance_after_upload(current_user_id() ?? '');
            }

            // Runs OCR (Google Cloud Vision) then AI validation (Claude) and
            // writes the results onto the document row. Safe no-op if the
            // relevant API keys aren't configured yet — see includes/ai_config.php.
            process_document_verification(
                $document['id'],
                $uploadDir . '/' . $uploaded['storedFileName'],
                $uploaded['mimeType'],
                $claims
            );

            header('Location: ' . url('documents/'));
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$title = 'Upload Document';
$activePage = 'documents';
include __DIR__ . '/../../includes/header.php';
?>
<h2>Upload Document</h2>
<?php if ($relatedRequest): ?>
    <p class="muted">Attaching a document to request <strong><?php echo htmlspecialchars($relatedRequest['id']); ?></strong> (<?php echo htmlspecialchars($relatedRequest['variety']); ?>, <?php echo htmlspecialchars($relatedRequest['quantityRequested']); ?> <?php echo htmlspecialchars($relatedRequest['unit']); ?>).</p>
<?php else: ?>
    <p class="muted">Submit required business documents.</p>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <?php if ($relatedRequest): ?>
        <input type="hidden" name="relatedRequestId" value="<?php echo htmlspecialchars($relatedRequest['id']); ?>">
    <?php endif; ?>
    <div class="form-group">
        <label for="documentType">Document Type</label>
        <select id="documentType" name="documentType">
            <?php if ($relatedRequest): ?>
                <option value="delivery_receipt">Delivery Receipt</option>
                <option value="invoice">Invoice</option>
            <?php endif; ?>
            <?php
            // ?type=permit etc. comes from the compliance checklist links.
            $preselectType = $_POST['documentType'] ?? $_GET['type'] ?? '';
            foreach ([
                'permit' => 'Import/Business Permit',
                'certificate' => 'Certificate of Registration',
                'business_registration' => 'Business Registration',
                'tax_id' => 'Tax ID/TIN',
                'valid_id' => 'Valid ID',
                'receipt' => 'Receipt',
            ] as $typeValue => $typeLabel): ?>
                <option value="<?php echo htmlspecialchars($typeValue); ?>"<?php echo $preselectType === $typeValue ? ' selected' : ''; ?>><?php echo htmlspecialchars($typeLabel); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="document">Upload File</label>
        <input id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" required>
        <p class="muted" style="margin:4px 0 0;font-size:.85em;">Photos (JPG/PNG) and PDFs (first 5 pages) may be read automatically to help the reviewer. If a file cannot be read, an administrator checks it by hand. Either way, a person makes the final decision.</p>
    </div>
    <div class="form-group">
        <label for="businessName">Business Name</label>
        <input id="businessName" name="businessName" type="text" value="<?php echo htmlspecialchars($_POST['businessName'] ?? ''); ?>" required>
    </div>
    <div class="form-group">
        <label for="permitNumber">Permit/Reference Number</label>
        <input id="permitNumber" name="permitNumber" type="text" value="<?php echo htmlspecialchars($_POST['permitNumber'] ?? ''); ?>" required>
    </div>
    <div class="form-group">
        <label for="expiryDate">Expiry Date</label>
        <input id="expiryDate" name="expiryDate" type="date" value="<?php echo htmlspecialchars($_POST['expiryDate'] ?? ''); ?>">
    </div>
    <div class="form-group">
        <label for="taxId">Tax ID/TIN</label>
        <input id="taxId" name="taxId" type="text" value="<?php echo htmlspecialchars($_POST['taxId'] ?? ''); ?>">
    </div>
    <button class="btn btn-primary" type="submit">Upload Document</button>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
