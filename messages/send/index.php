<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

// Admin doesn't have its own assistant conversation — Support Messages
// (admin/messages/) is the escalation queue instead, not a place to send
// from — so there's nothing for this endpoint to do for that role.
if (user_role() === 'admin') {
    header('Location: ' . url('admin/messages/'));
    exit;
}

// Hard cap on one message: bounds model cost and the size of stored rows.
const ASSISTANT_MAX_MESSAGE_CHARS = 2000;

/**
 * The widget's JS (assets/assistant.js) calls this endpoint with fetch() and
 * wants JSON back, without a page reload. Plain form posts (JS disabled, or
 * the old flow) keep working exactly as before via the redirect path below.
 */
$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
    || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function assistant_clip_message(string $body): string {
    $body = trim($body);
    if (function_exists('mb_substr')) {
        return mb_substr($body, 0, ASSISTANT_MAX_MESSAGE_CHARS);
    }
    return substr($body, 0, ASSISTANT_MAX_MESSAGE_CHARS);
}

function assistant_json(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($wantsJson) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        assistant_json(405, ['ok' => false, 'error' => 'POST only.']);
    }

    // csrf_verify() prints an HTML 403 page; the JS client needs JSON, so
    // the same check is done inline here.
    $submitted = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        assistant_json(403, ['ok' => false, 'error' => 'Your session expired. Please reload the page.']);
    }

    $body = assistant_clip_message((string) ($_POST['body'] ?? ''));
    if ($body === '') {
        assistant_json(422, ['ok' => false, 'error' => 'Type a message first.']);
    }
    if (!messaging_is_available()) {
        assistant_json(503, ['ok' => false, 'error' => 'The assistant is unavailable right now.']);
    }

    $userId = current_user_id() ?? '';
    // An agent answer can take 10+ seconds. PHP holds a lock on the session
    // file for the whole request, which would freeze every other tab/page
    // this user opens meanwhile. Everything below only READS the session.
    session_write_close();

    try {
        $reply = send_user_message($userId, $body);
        $thread = get_conversation($userId);
        $last = $thread ? end($thread) : null;
        $escalated = $last && ($last['sender'] ?? '') === 'ai' && (int) ($last['escalated'] ?? 0) === 1;
        assistant_json(200, ['ok' => true, 'reply' => $reply, 'escalated' => $escalated]);
    } catch (Throwable $e) {
        error_log('Assistant send failed: ' . $e->getMessage());
        assistant_json(500, ['ok' => false, 'error' => 'Something went wrong. Please try again.']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('dashboard/'));
    exit;
}

csrf_verify();
$returnTo = safe_return_path($_POST['returnTo'] ?? '');
$body = assistant_clip_message((string) ($_POST['body'] ?? ''));

if ($body !== '' && messaging_is_available()) {
    send_user_message(current_user_id() ?? '', $body);
    // Reopen the widget on the page the user was on when they sent this —
    // the no-JS fallback has no client-side state, so this session flag is
    // how it stays open across the redirect.
    $_SESSION['assistant_open'] = true;
}

header('Location: ' . $returnTo);
exit;
