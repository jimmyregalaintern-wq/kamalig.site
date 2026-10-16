<?php
/**
 * Floating AI Assistant widget (Phase 6). Replaces the old full-page
 * "Messages" nav item for farmer/importer/supplier — admin keeps the
 * separate Support Messages queue (admin/messages/) instead, since that's
 * a different feature (answering escalated threads, not having one).
 *
 * Included from includes/footer.php on every logged-in page. Uses a
 * checkbox-hack CSS toggle (no JS) so open/close works purely client-side;
 * messages/send/ sets $_SESSION['assistant_open'] before its redirect so
 * the panel re-opens showing the just-sent message on the page the user
 * was on, rather than snapping shut on every send.
 */

// The AI assistant reads live marketplace data, so (per the compliance gate)
// it is a full-access feature: hidden until an admin approves the account.
if (!is_logged_in() || user_role() === 'admin' || !messaging_is_available() || !current_user_is_compliant()) {
    return;
}

$assistantUserId = current_user_id() ?? '';
$assistantThread = array_slice(get_conversation($assistantUserId), -8);
$assistantOpen = !empty($_SESSION['assistant_open']);
unset($_SESSION['assistant_open']);
$assistantReturnTo = $_SERVER['REQUEST_URI'] ?? url('dashboard/');
?>
<input type="checkbox" id="assistant-toggle" class="assistant-toggle" <?php echo $assistantOpen ? 'checked' : ''; ?>>
<div class="assistant-widget no-print">
    <div class="assistant-panel">
        <div class="assistant-panel__header">
            <strong>AI Assistant</strong>
            <label for="assistant-toggle" class="assistant-panel__close" aria-label="Close">&times;</label>
        </div>
        <div class="assistant-panel__body" id="assistant-body" aria-live="polite">
            <?php if (empty($assistantThread)): ?>
                <div class="assistant-panel__empty">
                    <p class="muted">Ask about listings, requests, documents, or your account.</p>
                    <div class="assistant-panel__prompt-row">
                        <button type="button" class="assistant-panel__prompt" onclick="const field = document.getElementById('assistant-widget-body'); field.value = 'How do I list stock?'; field.focus();">List stock</button>
                        <button type="button" class="assistant-panel__prompt" onclick="const field = document.getElementById('assistant-widget-body'); field.value = 'How do I request stock?'; field.focus();">Request stock</button>
                        <button type="button" class="assistant-panel__prompt" onclick="const field = document.getElementById('assistant-widget-body'); field.value = 'How do I upload a document?'; field.focus();">Upload doc</button>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($assistantThread as $m): ?>
                    <?php
                        $label = $m['sender'] === 'user' ? 'You' : ($m['sender'] === 'admin' ? 'Support Team' : 'Assistant');
                        $align = $m['sender'] === 'user' ? 'right' : 'left';
                    ?>
                    <div class="assistant-msg assistant-msg--<?php echo htmlspecialchars($align); ?>">
                        <div class="muted assistant-msg__label"><?php echo htmlspecialchars($label); ?></div>
                        <div class="assistant-msg__bubble"><?php echo nl2br(htmlspecialchars($m['body'])); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <form method="post" action="<?php echo htmlspecialchars(url('messages/send/')); ?>" class="assistant-panel__form" id="assistant-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="returnTo" value="<?php echo htmlspecialchars($assistantReturnTo); ?>">
            <textarea id="assistant-widget-body" name="body" rows="2" placeholder="Ask a question..." maxlength="2000" required></textarea>
            <button type="button" class="voice-input-btn" data-voice-input="assistant-widget-body" aria-pressed="false" title="Speak your question">🎤</button>
            <button class="btn btn-primary btn-sm" type="submit">Send</button>
        </form>
        <p class="muted assistant-panel__footer">
            <a href="<?php echo htmlspecialchars(url('messages/')); ?>">Open full conversation &rarr;</a>
        </p>
    </div>
    <label for="assistant-toggle" class="assistant-bubble" aria-label="Open AI Assistant">💬</label>
</div>
