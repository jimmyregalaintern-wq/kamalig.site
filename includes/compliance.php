<?php
/**
 * includes/compliance.php — the compliance gate (Fix Guide, item 7).
 *
 * Signup is simple (email, password, role, OTP) and the user can log in
 * right away. What they CAN'T do until an admin approves their documents
 * is trade. Four states, derived from data that already exists — no new
 * columns are needed:
 *
 *   incomplete          no usable document yet (or the permit expired)
 *   under_review        every required document is submitted, admin has
 *                       not decided yet
 *   needs_resubmission  admin rejected the account or a document
 *   approved            users.verified = 1 AND users.status = 'approved'
 *
 * Admin approval stays the single source of truth for "approved" (it is
 * the existing Pending Verification / Quick Approve flow). Everything
 * else is derived from the user's documents.
 *
 * ONE deny-by-default check: compliance_enforce() runs inside
 * require_auth() (see bootstrap.php). Every page that already calls
 * require_auth / require_role / require_permission is therefore gated
 * automatically; a page is reachable by a restricted user ONLY if its
 * path is on the allow-list below. A forgotten check on a new page fails
 * closed instead of exposing the page.
 */

/**
 * Paths a not-yet-approved user may open (relative to the app root).
 * Everything else that requires login is blocked until approval.
 */
function compliance_allowed_prefixes(): array {
    $allowed = [
        'dashboard/',      // sees the "Complete your Compliance" banner
        'documents/',      // upload, review status, resubmit
        'notifications/',  // approval / rejection messages arrive here
        'settings/',       // own profile
        'logout/',
    ];
    // Decision to confirm with your adviser: may a restricted user browse
    // the marketplace read-only? Default is NO (it exposes seller data to
    // unverified accounts). Set COMPLIANCE_ALLOW_BROWSE=true in .env to
    // allow read-only browsing; cart / requests / payments stay blocked.
    if (env_bool('COMPLIANCE_ALLOW_BROWSE', false)) {
        $allowed[] = 'marketplace/';
    }
    return $allowed;
}

/** Whether a nav href ("inventory/", "dashboard/#ai-insights") is allowed while restricted. */
function compliance_href_allowed(string $href): bool {
    $href = ltrim($href, '/');
    foreach (compliance_allowed_prefixes() as $prefix) {
        if (str_starts_with($href, $prefix)) {
            return true;
        }
    }
    return false;
}

/** Path of the running script relative to the app root, e.g. "inventory/add/index.php". */
function compliance_current_path(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = defined('BASE_PATH') ? BASE_PATH : '';
    if ($base !== '' && str_starts_with($script, $base)) {
        $script = substr($script, strlen($base));
    }
    return ltrim($script, '/');
}

/**
 * What each role must submit. Each requirement is satisfied by ANY of its
 * listed document types. 'required' => false items are shown as
 * recommended but never block approval.
 */
function compliance_requirements(string $role): array {
    switch ($role) {
        case 'farmer':
            return [
                ['key' => 'identity', 'required' => true,
                 'label' => 'Valid government ID, or cooperative / association certificate',
                 'types' => ['valid_id', 'certificate', 'business_registration']],
            ];
        case 'importer':
            return [
                ['key' => 'permit', 'required' => true,
                 'label' => 'Import permit',
                 'types' => ['permit']],
                ['key' => 'registration', 'required' => true,
                 'label' => 'Business registration or certificate of registration',
                 'types' => ['business_registration', 'certificate']],
                ['key' => 'valid_id', 'required' => false,
                 'label' => 'Valid government ID (recommended, speeds up review)',
                 'types' => ['valid_id']],
            ];
        case 'supplier':
        default:
            return [
                ['key' => 'registration', 'required' => true,
                 'label' => 'Business permit or business registration',
                 'types' => ['business_registration', 'permit', 'certificate']],
                ['key' => 'valid_id', 'required' => false,
                 'label' => 'Valid government ID (recommended, speeds up review)',
                 'types' => ['valid_id']],
            ];
    }
}

/** A verified document whose expiry date has passed no longer counts. */
function compliance_effective_doc_status(array $doc): string {
    $status = (string) ($doc['status'] ?? 'pending');
    if ($status === 'verified' && !empty($doc['expiryDate'])) {
        $expiry = strtotime((string) $doc['expiryDate']);
        if ($expiry !== false && $expiry < strtotime('today')) {
            return 'expired';
        }
    }
    return $status;
}

/**
 * Per-requirement status for one user: verified | pending | expired |
 * rejected | missing, with the document that decided it. Only
 * account-level documents count (relatedRequestId empty) — invoices and
 * delivery receipts say nothing about the account's legitimacy.
 */
function compliance_checklist(string $userId, string $role, ?array $docs = null): array {
    $docs = $docs ?? get_documents($userId);
    $docs = array_values(array_filter($docs, fn($d) => empty($d['relatedRequestId'])));

    $rank = ['verified' => 4, 'pending' => 3, 'expired' => 2, 'rejected' => 1];
    $items = [];

    foreach (compliance_requirements($role) as $req) {
        $best = null;
        $bestRank = 0;
        $bestStatus = 'missing';
        foreach ($docs as $doc) {
            if (!in_array($doc['documentType'] ?? '', $req['types'], true)) {
                continue;
            }
            $effective = compliance_effective_doc_status($doc);
            $r = $rank[$effective] ?? 0;
            if ($r > $bestRank) {
                $best = $doc;
                $bestRank = $r;
                $bestStatus = $effective;
            }
        }
        $items[] = $req + ['status' => $bestStatus, 'doc' => $best];
    }
    return $items;
}

/** Admin-approved account? (the only thing that unlocks trading) */
function compliance_user_is_approved(array $user): bool {
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    return !empty($user['verified'])
        && !in_array($user['status'] ?? '', ['pending', 'rejected', 'suspended'], true);
}

/**
 * Work out one user's compliance state. Returns:
 *   state, label, message, reason (admin's reason when relevant), checklist
 */
function compliance_evaluate(array $user): array {
    $role = (string) ($user['role'] ?? 'supplier');
    $result = ['state' => 'incomplete', 'label' => '', 'message' => '', 'reason' => '', 'checklist' => []];

    if (compliance_user_is_approved($user)) {
        $result['state'] = 'approved';
        $result['label'] = 'Approved';
        $result['message'] = 'Your account is approved. You have full access.';
        return $result;
    }

    $checklist = compliance_checklist((string) ($user['id'] ?? ''), $role);
    $result['checklist'] = $checklist;

    $required = array_values(array_filter($checklist, fn($i) => $i['required']));
    $allSubmitted = true;
    $anyRejected = false;
    $anyExpired = false;
    $rejectedReason = '';
    foreach ($required as $item) {
        if (!in_array($item['status'], ['pending', 'verified'], true)) {
            $allSubmitted = false;
        }
        if ($item['status'] === 'rejected') {
            $anyRejected = true;
            $rejectedReason = $rejectedReason !== '' ? $rejectedReason : trim((string) ($item['doc']['aiFlagReason'] ?? ''));
        }
        if ($item['status'] === 'expired') {
            $anyExpired = true;
        }
    }

    $accountRejected = ($user['status'] ?? '') === 'rejected';

    if ($accountRejected || $anyRejected) {
        $result['state'] = 'needs_resubmission';
        $result['label'] = 'Needs resubmission';
        $result['reason'] = trim((string) ($user['rejection_reason'] ?? '')) ?: $rejectedReason;
        $result['message'] = 'An admin could not approve your documents. Please upload corrected documents; they will be reviewed again.';
    } elseif ($allSubmitted && !empty($required)) {
        $result['state'] = 'under_review';
        $result['label'] = 'Under admin review';
        $result['message'] = 'Your documents are with our admin team. Trading unlocks as soon as they are approved, and you will get a notification.';
    } else {
        $result['state'] = 'incomplete';
        $result['label'] = 'Compliance incomplete';
        $result['message'] = $anyExpired
            ? 'A required document has expired. Upload the renewed document to restore full access.'
            : 'Upload the documents below to unlock trading. Until an admin approves them you can use your dashboard, documents, notifications and settings.';
    }

    return $result;
}

/**
 * Compliance of the logged-in user, cached for the request. require_auth()
 * seeds it with the freshly loaded user row so there is no second query.
 */
function current_compliance(?array $liveUser = null): array {
    static $cache = null;
    if ($liveUser !== null) {
        $cache = compliance_evaluate($liveUser);
    }
    if ($cache === null) {
        $uid = current_user_id();
        $user = $uid ? get_user_by_id($uid) : null;
        $cache = $user ? compliance_evaluate($user) : ['state' => 'incomplete', 'label' => '', 'message' => '', 'reason' => '', 'checklist' => []];
    }
    return $cache;
}

/** True for admins and approved users; used to hide/disable UI (nav, search, AI assistant). */
function current_user_is_compliant(): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    return current_compliance()['state'] === 'approved';
}

/**
 * The deny-by-default check. Called from require_auth() with the live
 * user row. Admins are never gated. Everyone else must be approved,
 * unless the page is on the allow-list.
 */
function compliance_enforce(array $liveUser): void {
    if (($liveUser['role'] ?? '') === 'admin') {
        return;
    }
    $c = current_compliance($liveUser);
    if ($c['state'] === 'approved') {
        return;
    }
    $path = compliance_current_path();
    foreach (compliance_allowed_prefixes() as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return;
        }
    }
    compliance_block_page($c);
}

/** Friendly 403 inside the normal app layout. */
function compliance_block_page(array $c): void {
    http_response_code(403);
    $title = 'Locked until approved';
    $activePage = 'documents';
    include __DIR__ . '/header.php';
    echo '<h2>This feature is locked</h2>';
    echo '<p class="muted">Trading features unlock after an admin approves your compliance documents.</p>';
    echo '<div class="card"><p><strong>' . htmlspecialchars($c['label']) . '</strong></p>';
    echo '<p>' . htmlspecialchars($c['message']) . '</p>';
    if (($c['reason'] ?? '') !== '') {
        echo '<p><strong>Admin note:</strong> ' . htmlspecialchars($c['reason']) . '</p>';
    }
    echo '<p style="display:flex;gap:8px;flex-wrap:wrap;">'
       . '<a class="btn btn-primary" href="' . htmlspecialchars(url('documents/')) . '">Go to Documents</a>'
       . '<a class="btn btn-secondary" href="' . htmlspecialchars(url('dashboard/')) . '">Back to dashboard</a></p></div>';
    include __DIR__ . '/footer.php';
    exit;
}

/**
 * After a compliance upload: a user whose account was rejected goes back
 * to "pending" so the application re-enters the admin's review queue.
 */
function compliance_after_upload(string $userId): void {
    $user = get_user_by_id($userId);
    if (!$user || ($user['status'] ?? '') !== 'rejected') {
        return;
    }
    if (database_available()) {
        query_execute(
            "UPDATE users SET status = 'pending', verified = 0, rejection_reason = NULL WHERE id = ? AND status = 'rejected'",
            [$userId]
        );
    } else {
        init_data();
        if (isset($_SESSION['data']['users'][$userId])) {
            $_SESSION['data']['users'][$userId]['status'] = 'pending';
            $_SESSION['data']['users'][$userId]['verified'] = false;
            $_SESSION['data']['users'][$userId]['rejection_reason'] = null;
        }
    }
    log_activity($userId, 'account.resubmit', 'user', $userId, 'resubmitted compliance documents after rejection');
}
