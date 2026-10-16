<?php
/**
 * AI-Assisted Messaging.
 *
 * Every user gets one running conversation thread with "the system."
 * Incoming user messages are answered in this order:
 *   1. Keyword match against a small FAQ table (instant, free, no API call).
 *   2. If no FAQ match and Claude is configured, run the read-only AI agent
 *      (includes/agent.php): it can look up the user's own listings,
 *      requests, orders, documents, warehouses and notifications plus the
 *      public marketplace, and hands off (escalates) anything that needs a
 *      decision or a change to data.
 *   3. If neither applies (no match and no AI configured, or the AI itself
 *      asked to hand off), the reply is a canned "I'll get someone from our
 *      team" message and the exchange is marked escalated.
 *
 * Escalated conversations show up in admin/messages/ for a human to answer;
 * an admin reply on a thread automatically clears it from that queue (see
 * get_escalated_conversations() below).
 */

function messaging_is_available(): bool {
    return database_available();
}

/**
 * Small, static FAQ table. Keys are keywords/phrases checked against the
 * user's message (case-insensitive substring match); the first match wins.
 * This deliberately stays simple — a prototype-scale rule table, not a real
 * NLU/intent classifier — matching how AI is scoped elsewhere in the system
 * (rule-based checks with AI as an assist, not the sole mechanism).
 */
function messaging_faq(): array {
    return [
        'how do i list' => "To list stock: go to Inventory > Add Stock, fill in variety, quantity, unit price, and (for imported stock) country of origin, then Save. It appears in the Marketplace right away.",
        'add stock' => "To list stock: go to Inventory > Add Stock, fill in variety, quantity, unit price, and (for imported stock) country of origin, then Save. It appears in the Marketplace right away.",
        'how do i request' => "To request stock: browse the Marketplace, open a listing, and click \"Request Stock.\" You'll be able to set a quantity up to what's available; the supplier will accept or reject it under their Incoming Requests.",
        'request stock' => "To request stock: browse the Marketplace, open a listing, and click \"Request Stock.\" You'll be able to set a quantity up to what's available; the supplier will accept or reject it under their Incoming Requests.",
        'procurement request' => "Procurement requests are tracked under Requests (sent) and Incoming (received). Status moves from pending to accepted or rejected — accepted requests reduce the supplier's available quantity automatically.",
        'how do i upload' => "To upload a document: go to Documents > Upload Document, choose the document type, attach the file (PDF, JPG, or PNG, up to 8MB), and fill in the business name and permit/reference number. An admin will review and verify it.",
        'upload document' => "To upload a document: go to Documents > Upload Document, choose the document type, attach the file (PDF, JPG, or PNG, up to 8MB), and fill in the business name and permit/reference number. An admin will review and verify it.",
        'document status' => "You can check a document's status on the Documents page — it will show Pending, Verified, or Rejected.",
        'account approv' => "New accounts are reviewed by an admin before they can log in. If you registered recently and can't log in yet, your account is most likely still pending approval — this usually doesn't take long.",
        'pending verification' => "New accounts are reviewed by an admin before they can log in. If you registered recently and can't log in yet, your account is most likely still pending approval — this usually doesn't take long.",
        'forgot my password' => "Use \"Forgot password\" on the login page — you'll get a reset link by email if the account exists.",
        'reset password' => "Use \"Forgot password\" on the login page — you'll get a reset link by email if the account exists.",
        // BUGFIX: was the single key 'variet' relying on messaging_match_faq()'s
        // old plain substring match to catch both "variety" and "varieties" as
        // a prefix. Word-boundary matching (see below) needs whole words, so
        // it's spelled out as both forms here instead.
        'variety' => "The Marketplace lets you filter by rice variety and source (local or imported), and sort by price or quantity. Current varieties are managed by admins under Admin > Varieties.",
        'varieties' => "The Marketplace lets you filter by rice variety and source (local or imported), and sort by price or quantity. Current varieties are managed by admins under Admin > Varieties.",
        'low stock' => "Low-stock alerts appear automatically under Notifications once a listing's quantity (converted to kilograms) falls to the configured threshold — no action needed to turn them on.",
        'hello' => "Hi! I can help with how listings, requests, documents, and accounts work on KAMALIG. What do you need?",
        'hi' => "Hi! I can help with how listings, requests, documents, and accounts work on KAMALIG. What do you need?",
    ];
}

/**
 * Try the FAQ table first. Returns the canned answer, or null if nothing
 * matched closely enough to answer confidently.
 */
function messaging_match_faq(string $userMessage): ?string {
    $normalized = strtolower($userMessage);
    foreach (messaging_faq() as $keyword => $answer) {
        // BUGFIX: this used to be str_contains(), an unanchored substring
        // check. Short keywords are the problem case — 'hi' is a substring
        // of "history", "this", "shipment", "highlight", "hint", and more,
        // so an ordinary question like "What's the status of this order?"
        // silently matched the canned greeting instead of ever reaching the
        // AI-or-escalate path this file's own docblock describes. Matching
        // on a word boundary means a keyword only fires as a real word/
        // phrase, not wherever its letters happen to appear.
        if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $normalized)) {
            return $answer;
        }
    }
    return null;
}

/**
 * Ask the AI agent (includes/agent.php) to answer. The agent can look up the
 * signed-in user's own data and the public marketplace through read-only
 * tools. Returns null (meaning: escalate) if the AI isn't configured, the
 * call fails, the step budget runs out, or the agent hands off.
 *
 * $history = this user's thread rows from BEFORE the new message was stored.
 */
function messaging_ask_ai(string $userMessage, string $userId = '', array $history = []): ?string {
    if (!function_exists('agent_run')) {
        return null;
    }
    return agent_run($userId, $history, $userMessage);
}

/**
 * Record a user's message, generate a reply (FAQ, then AI, then escalate),
 * record the reply, and notify admins if it escalated. Returns the reply
 * text that was recorded, for immediate display.
 */
function send_user_message(string $userId, string $body): string {
    $body = trim($body);
    if ($body === '') {
        return '';
    }

    // Snapshot the thread BEFORE storing the new message: the agent receives
    // this as conversation context and appends the new message itself.
    $history = array_slice(get_conversation($userId), -AGENT_HISTORY_MESSAGES);

    // Rate limit (per-minute and per-day). Checked before inserting so a
    // flood doesn't also flood the messages table or the Claude bill.
    $limitReason = function_exists('agent_rate_limit_reason') ? agent_rate_limit_reason($userId) : null;
    if ($limitReason !== null) {
        messaging_insert('user', $userId, $body, false, $userId);
        messaging_insert('ai', $userId, $limitReason, false, null);
        return $limitReason;
    }

    messaging_insert('user', $userId, $body, false, $userId);

    $reply = messaging_match_faq($body);
    $escalated = false;

    if ($reply === null) {
        $reply = messaging_ask_ai($body, $userId, $history);
    }

    $notifyAdmins = true;
    if ($reply === null) {
        $why = function_exists('agent_last_status') ? agent_last_status() : 'escalate';
        if (in_array($why, ['not_configured', 'unavailable', 'budget'], true)) {
            // The AI was not able to try: say so, instead of claiming it had no confident answer.
            $reply = "The assistant isn't available right now. Your message has been saved, and our team will reply here.";
        } else {
            $reply = "I don't have a confident answer for that — I'll get someone from our team to follow up with you here.";
        }
        $escalated = true;

        // One notification per person per day is enough. Without this, an outage (or an
        // empty AI balance) would send every admin a notification for every message.
        if (database_available()) {
            $already = query_one(
                "SELECT COUNT(*) AS c FROM messages WHERE userId = ? AND sender = 'ai' AND escalated = 1 AND createdAt >= ?",
                [$userId, date('Y-m-d H:i:s', time() - 86400)]
            );
            $notifyAdmins = ((int) ($already['c'] ?? 0)) === 0;
        }
    }

    messaging_insert('ai', $userId, $reply, $escalated, null);

    if ($escalated && $notifyAdmins) {
        $requester = get_user_by_id($userId);
        foreach (get_all_users() as $admin) {
            if ($admin['role'] !== 'admin') {
                continue;
            }
            create_notification([
                'userId' => $admin['id'],
                'type' => 'message_escalated',
                'title' => 'A user needs help',
                'message' => ($requester['name'] ?? 'A user') . ' asked a question the assistant couldn\'t answer.',
                'relatedId' => $userId,
            ]);
        }
    }

    return $reply;
}

/**
 * Record an admin's reply on a user's thread.
 */
function send_admin_reply(string $userId, string $adminUserId, string $body): void {
    $body = trim($body);
    if ($body === '') {
        return;
    }
    messaging_insert('admin', $userId, $body, false, $adminUserId);

    create_notification([
        'userId' => $userId,
        'type' => 'message_reply',
        'title' => 'New reply from support',
        'message' => 'A team member replied to your question.',
        'relatedId' => $userId,
    ]);
}

function messaging_insert(string $sender, string $userId, string $body, bool $escalated, ?string $senderUserId): void {
    if (!database_available()) {
        return; // Messaging needs the database — no session-fallback store for this feature.
    }
    query_execute(
        'INSERT INTO messages (id, userId, sender, senderUserId, body, escalated, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?)',
        ['msg_' . time() . '_' . bin2hex(random_bytes(4)), $userId, $sender, $senderUserId, $body, $escalated ? 1 : 0, date('Y-m-d H:i:s')]
    );
}

function get_conversation(string $userId): array {
    if (!database_available()) {
        return [];
    }
    return query_all('SELECT * FROM messages WHERE userId = ? ORDER BY createdAt ASC, id ASC', [$userId]);
}

/**
 * Conversations currently waiting on a human: the most recent escalated
 * assistant reply for that user with no admin message after it. Computed in
 * PHP (not SQL) — message volume in this prototype is small enough that
 * walking each thread once is simpler and more obviously correct than a
 * self-join.
 */
function get_escalated_conversations(): array {
    if (!database_available()) {
        return [];
    }

    $rows = query_all('SELECT * FROM messages ORDER BY userId ASC, createdAt ASC, id ASC');
    $byUser = [];
    foreach ($rows as $row) {
        $byUser[$row['userId']][] = $row;
    }

    $queue = [];
    foreach ($byUser as $userId => $thread) {
        $lastEscalatedIndex = null;
        foreach ($thread as $i => $m) {
            if ($m['sender'] === 'ai' && (int) $m['escalated'] === 1) {
                $lastEscalatedIndex = $i;
            }
        }
        if ($lastEscalatedIndex === null) {
            continue;
        }

        $answeredSince = false;
        foreach ($thread as $i => $m) {
            if ($i > $lastEscalatedIndex && $m['sender'] === 'admin') {
                $answeredSince = true;
                break;
            }
        }
        if ($answeredSince) {
            continue;
        }

        $user = get_user_by_id($userId);
        $queue[] = [
            'userId' => $userId,
            'userName' => $user['name'] ?? $userId,
            'lastMessage' => end($thread),
            'thread' => $thread,
            'status' => 'pending',
        ];
    }

    usort($queue, fn($a, $b) => strtotime($b['lastMessage']['createdAt']) <=> strtotime($a['lastMessage']['createdAt']));
    return $queue;
}

function get_recent_resolved_conversations(int $limit = 10): array {
    if (!database_available()) {
        return [];
    }

    $rows = query_all('SELECT * FROM messages ORDER BY userId ASC, createdAt ASC, id ASC');
    $byUser = [];
    foreach ($rows as $row) {
        $byUser[$row['userId']][] = $row;
    }

    $resolved = [];
    foreach ($byUser as $userId => $thread) {
        $lastEscalatedIndex = null;
        foreach ($thread as $i => $m) {
            if ($m['sender'] === 'ai' && (int) $m['escalated'] === 1) {
                $lastEscalatedIndex = $i;
            }
        }
        if ($lastEscalatedIndex === null) {
            continue;
        }

        $answeredSince = false;
        $lastReplyAt = null;
        foreach ($thread as $i => $m) {
            if ($i > $lastEscalatedIndex && $m['sender'] === 'admin') {
                $answeredSince = true;
                $lastReplyAt = $m['createdAt'];
                break;
            }
        }
        if (!$answeredSince) {
            continue;
        }

        $user = get_user_by_id($userId);
        $resolved[] = [
            'userId' => $userId,
            'userName' => $user['name'] ?? $userId,
            'lastMessage' => end($thread),
            'thread' => $thread,
            'status' => 'resolved',
            'lastReplyAt' => $lastReplyAt,
        ];
    }

    usort($resolved, fn($a, $b) => strtotime($b['lastReplyAt'] ?? $b['lastMessage']['createdAt']) <=> strtotime($a['lastReplyAt'] ?? $a['lastMessage']['createdAt']));
    return array_slice($resolved, 0, $limit);
}
