<?php
// Secure upload handling for documents.
// Centralizes validation so every upload path (documents, future admin
// re-uploads, etc.) enforces the same rules.

// Allowed document types: real MIME type (server-verified) => safe extension.
const ALLOWED_DOCUMENT_MIME_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg'       => 'jpg',
    'image/png'        => 'png',
];

const MAX_DOCUMENT_UPLOAD_BYTES = 8 * 1024 * 1024; // 8 MB

// Marketplace/inventory listing photos — images only (no PDFs), and a
// smaller cap than compliance documents since these are just product
// photos, not scanned paperwork.
const ALLOWED_STOCK_IMAGE_MIME_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

const MAX_STOCK_IMAGE_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB

/**
 * Validates an uploaded file ($_FILES['field']) and, if valid, moves it to
 * the uploads directory under a random, non-guessable name.
 *
 * Returns an array on success:
 *   ['storedFileName' => ..., 'originalFileName' => ..., 'mimeType' => ..., 'fileSize' => ..., 'fileUrl' => ...]
 * Throws RuntimeException with a user-safe message on any validation failure.
 */
function handle_secure_document_upload(array $file, string $uploadDir): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid file upload.');
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            throw new RuntimeException('No file was selected.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException('File is too large.');
        default:
            throw new RuntimeException('File upload failed. Please try again.');
    }

    if ($file['size'] <= 0 || $file['size'] > MAX_DOCUMENT_UPLOAD_BYTES) {
        $maxMb = MAX_DOCUMENT_UPLOAD_BYTES / (1024 * 1024);
        throw new RuntimeException("File must be between 1 byte and {$maxMb}MB.");
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload — please try again.');
    }

    // Detect the REAL mime type by sniffing file contents — never trust the
    // client-supplied extension or Content-Type header.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset(ALLOWED_DOCUMENT_MIME_TYPES[$realMimeType])) {
        throw new RuntimeException('Unsupported file type. Please upload a PDF, JPG, or PNG.');
    }

    $safeExtension = ALLOWED_DOCUMENT_MIME_TYPES[$realMimeType];

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0750, true);
    }

    // Random, non-guessable stored filename — prevents overwrite collisions,
    // path traversal, and makes uploaded files hard to enumerate/guess.
    $storedFileName = bin2hex(random_bytes(16)) . '.' . $safeExtension;
    $destination = rtrim($uploadDir, '/') . '/' . $storedFileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }

    // Original filename kept only for display — never used to build a path.
    $originalFileName = basename($file['name']);

    return [
        'storedFileName' => $storedFileName,
        'originalFileName' => $originalFileName,
        'mimeType' => $realMimeType,
        'fileSize' => $file['size'],
        // Not a web URL on purpose: documents are private and are opened through
        // documents/file/ (see includes/private_storage.php).
        'fileUrl' => 'private:' . $storedFileName,
    ];
}

/**
 * Same validation shape as handle_secure_document_upload() (content-sniffed
 * MIME type, random non-guessable filename, dedicated size cap) but scoped
 * to marketplace/inventory listing photos: images only, no PDFs, stored
 * under uploads/stock-images/ so they stay visually/organizationally
 * separate from compliance documents even though both sit under the same
 * .htaccess-protected uploads/ tree (protection is identical either way —
 * no script execution, ever, regardless of subfolder).
 *
 * Returns ['storedFileName' => ..., 'fileUrl' => ...] on success. Throws
 * RuntimeException with a user-safe message on any validation failure.
 */
function handle_secure_stock_image_upload(array $file, string $uploadDir): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid file upload.');
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            throw new RuntimeException('No file was selected.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException('Image is too large.');
        default:
            throw new RuntimeException('Image upload failed. Please try again.');
    }

    if ($file['size'] <= 0 || $file['size'] > MAX_STOCK_IMAGE_UPLOAD_BYTES) {
        $maxMb = MAX_STOCK_IMAGE_UPLOAD_BYTES / (1024 * 1024);
        throw new RuntimeException("Image must be between 1 byte and {$maxMb}MB.");
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload — please try again.');
    }

    // Detect the REAL mime type by sniffing file contents — never trust the
    // client-supplied extension or Content-Type header.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset(ALLOWED_STOCK_IMAGE_MIME_TYPES[$realMimeType])) {
        throw new RuntimeException('Unsupported image type. Please upload a JPG, PNG, or WEBP.');
    }

    $safeExtension = ALLOWED_STOCK_IMAGE_MIME_TYPES[$realMimeType];

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $storedFileName = bin2hex(random_bytes(16)) . '.' . $safeExtension;
    $destination = rtrim($uploadDir, '/') . '/' . $storedFileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return [
        'storedFileName' => $storedFileName,
        'fileUrl' => '/uploads/stock-images/' . $storedFileName,
    ];
}

/**
 * Delete a previously-uploaded stock image from disk, given the imageUrl
 * stored on the stock row (e.g. '/uploads/stock-images/abc123.jpg'). Called
 * when a listing's photo is replaced or removed, so orphaned files don't
 * pile up in uploads/stock-images/ forever. Best-effort: a missing or
 * already-deleted file is not an error, and this never throws — losing a
 * stray file on disk should never block the stock update itself.
 */
function delete_stock_image_file(?string $imageUrl): void {
    if (!$imageUrl || !str_starts_with($imageUrl, '/uploads/stock-images/')) {
        return;
    }
    $fileName = basename($imageUrl);
    // basename() strips any path traversal componentry, but guard again
    // anyway before touching the filesystem with a stored value.
    if ($fileName === '' || str_contains($fileName, '..')) {
        return;
    }
    $path = __DIR__ . '/../uploads/stock-images/' . $fileName;
    if (is_file($path)) {
        @unlink($path);
    }
}
