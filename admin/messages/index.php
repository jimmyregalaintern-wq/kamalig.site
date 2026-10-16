<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');

$title = 'Support Messages';
$activePage = 'adminmessages';

if (!messaging_is_available()) {
    include __DIR__ . '/../../includes/header.php';
    echo '<h2>Support Messages</h2><div class="card"><p>Messaging requires the database to be configured — run the migrations.</p></div>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$openUserId = trim($_GET['userId'] ?? $_POST['userId'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $openUserId !== '') {
    csrf_verify();
    $body = trim($_POST['body'] ?? '');
    if ($body === '') {
        $error = 'Type a reply first.';
    } else {
        send_admin_reply($openUserId, current_user_id() ?? '', $body);
        header('Location: ' . url('admin/messages/?userId=' . urlencode($openUserId)));
        exit;
    }
}

$queue = get_escalated_conversations();
$resolved = get_recent_resolved_conversations(5);
$openThread = $openUserId !== '' ? get_conversation($openUserId) : [];
$openUser = $openUserId !== '' ? get_user_by_id($openUserId) : null;

include __DIR__ . '/../../includes/header.php';
?>
<h2>Support Messages</h2>
<p class="muted">Conversations the assistant couldn't answer on its own.</p>

<div class="grid grid-2">
    <div class="card">
        <h3>Waiting for a reply (<?php echo count($queue); ?>)</h3>
        <?php if (empty($queue)): ?>
            <p class="muted">Nothing escalated right now.</p>
        <?php else: ?>
            <?php foreach ($queue as $conv): ?>
                <div style="padding:8px 0; border-bottom:1px solid #e2e8f0;">
                    <a href="<?php echo htmlspecialchars(url('admin/messages/?userId=' . urlencode($conv['userId']))); ?>">
                        <strong><?php echo htmlspecialchars($conv['userName']); ?></strong>
                    </a>
                    <span style="display:inline-block; margin-left:8px; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; padding:2px 6px; border-radius:999px; background:#fff7ed; color:#9a4f12;">Pending</span>
                    <div class="muted" style="font-size:0.85rem; margin-top:4px;"><?php echo htmlspecialchars(mb_strimwidth($conv['lastMessage']['body'], 0, 80, '…')); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Resolved recently</h3>
        <?php if (empty($resolved)): ?>
            <p class="muted">No recently resolved conversations.</p>
        <?php else: ?>
            <?php foreach ($resolved as $conv): ?>
                <div style="padding:8px 0; border-bottom:1px solid #e2e8f0;">
                    <a href="<?php echo htmlspecialchars(url('admin/messages/?userId=' . urlencode($conv['userId']))); ?>">
                        <strong><?php echo htmlspecialchars($conv['userName']); ?></strong>
                    </a>
                    <span style="display:inline-block; margin-left:8px; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; padding:2px 6px; border-radius:999px; background:#ecfdf5; color:#166534;">Resolved</span>
                    <div class="muted" style="font-size:0.85rem; margin-top:4px;"><?php echo htmlspecialchars(mb_strimwidth($conv['lastMessage']['body'], 0, 80, '…')); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <?php if (!$openUser): ?>
        <p class="muted">Select a conversation to view and reply.</p>
    <?php else: ?>
        <h3><?php echo htmlspecialchars($openUser['name'] ?? $openUserId); ?></h3>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <div style="display:flex; flex-direction:column; gap:10px; max-height:360px; overflow-y:auto; margin-bottom:16px;">
            <?php foreach ($openThread as $m): ?>
                <?php $label = $m['sender'] === 'user' ? ($openUser['name'] ?? 'User') : ($m['sender'] === 'admin' ? 'You' : 'Assistant'); ?>
                <div>
                    <div class="muted" style="font-size:0.8rem;"><?php echo htmlspecialchars($label); ?> · <?php echo htmlspecialchars(date('M j, g:i A', strtotime($m['createdAt']))); ?></div>
                    <div class="card" style="padding:10px 14px;"><?php echo nl2br(htmlspecialchars($m['body'])); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="userId" value="<?php echo htmlspecialchars($openUserId); ?>">
            <div class="form-group">
                <textarea name="body" rows="3" placeholder="Reply..." required></textarea>
            </div>
            <button class="btn btn-primary" type="submit">Send Reply</button>
        </form>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
