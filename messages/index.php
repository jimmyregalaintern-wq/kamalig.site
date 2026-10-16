<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_auth();

if (user_role() === 'admin') {
    header('Location: ' . url('admin/messages/'));
    exit;
}

$title = 'Messages';
$activePage = 'messages';

$userId = current_user_id() ?? '';
$error = '';

if (!messaging_is_available()) {
    include __DIR__ . '/../includes/header.php';
    echo '<h2>Messages</h2><div class="card"><p>Messaging requires the database to be configured — ask an admin to run the migrations.</p></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $body = trim($_POST['body'] ?? '');
    if ($body === '') {
        $error = 'Type a question first.';
    } else {
        send_user_message($userId, $body);
        header('Location: ' . url('messages/'));
        exit;
    }
}

$thread = get_conversation($userId);

include __DIR__ . '/../includes/header.php';
?>
<h2>Messages</h2>
<p class="muted">Ask about listings, procurement requests, documents, or your account. Questions the assistant can't answer are passed to our team.</p>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<div class="card">
    <?php if (empty($thread)): ?>
        <div class="assistant-panel__empty" style="padding: 8px 0;">
            <p class="muted">No messages yet — ask something below.</p>
            <div class="assistant-panel__prompt-row">
                <button type="button" class="assistant-panel__prompt" onclick="const field = document.getElementById('messages-page-body'); field.value = 'How do I list stock?'; field.focus();">List stock</button>
                <button type="button" class="assistant-panel__prompt" onclick="const field = document.getElementById('messages-page-body'); field.value = 'How do I request stock?'; field.focus();">Request stock</button>
                <button type="button" class="assistant-panel__prompt" onclick="const field = document.getElementById('messages-page-body'); field.value = 'How do I upload a document?'; field.focus();">Upload doc</button>
            </div>
        </div>
    <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:10px; max-height:420px; overflow-y:auto; margin-bottom:16px;">
            <?php foreach ($thread as $m): ?>
                <?php
                    $label = $m['sender'] === 'user' ? 'You' : ($m['sender'] === 'admin' ? 'Support Team' : 'Assistant');
                    $align = $m['sender'] === 'user' ? 'right' : 'left';
                ?>
                <div style="text-align:<?php echo $align; ?>;">
                    <div class="muted" style="font-size:0.8rem; margin-bottom:4px;">
                        <?php echo htmlspecialchars($label); ?> · <?php echo htmlspecialchars(date('M j, g:i A', strtotime($m['createdAt']))); ?>
                    </div>
                    <div class="card" style="display:inline-block; text-align:left; max-width:80%; padding:10px 14px; border-radius:12px;">
                        <?php echo nl2br(htmlspecialchars($m['body'])); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <div class="form-group" style="display:flex; gap:8px; align-items:flex-end;">
            <textarea id="messages-page-body" name="body" rows="3" placeholder="Ask a question..." required style="flex:1;"></textarea>
            <button type="button" class="voice-input-btn" data-voice-input="messages-page-body" aria-pressed="false" title="Speak your question">🎤</button>
        </div>
        <button class="btn btn-primary" type="submit">Send</button>
    </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
