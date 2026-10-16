<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

// Compliance documents are private: only the person who uploaded one, or an
// admin reviewing the account, may open it (Fix Guide item 1).
$doc = get_document_by_id(trim((string) ($_GET['id'] ?? '')));
$userId = current_user_id() ?? '';
$isAdmin = user_role() === 'admin';
$allowed = $doc && ($isAdmin || ($doc['userId'] ?? '') === $userId);
if (!$allowed || empty($doc['storedFileName'])) {
    render_error_page(404, 'Document not found', 'This document does not exist or you do not have access to it.');
}

$path = private_storage_find(PRIVATE_KIND_DOCUMENTS, (string) $doc['storedFileName']);
if ($path === null) {
    render_error_page(404, 'Document not found', 'The file is missing from storage.');
}

// Audit trail: record when someone other than the owner opens a document.
if (($doc['userId'] ?? '') !== $userId) {
    log_activity($userId, 'document.view', 'document', $doc['id'], 'opened by admin');
}

private_storage_send($path, (string) ($doc['fileName'] ?: 'document'));
