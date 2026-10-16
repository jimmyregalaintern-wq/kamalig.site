<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_auth();

$userId = current_user_id() ?? '';

// Handled before header.php is included (which starts streaming HTML) so
// header('Location: ...') below actually works — the topbar bell dropdown's
// mark-as-read/mark-all forms POST here from whatever page they're
// rendered on, so they carry a returnTo back to that page instead of
// always landing on this one.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $returnTo = isset($_POST['returnTo']) ? safe_return_path($_POST['returnTo']) : url('notifications/');

    if (!empty($_POST['notificationId'])) {
        // Only allow marking the current user's own notifications as read.
        $owned = array_filter(get_notifications($userId), fn($n) => $n['id'] === $_POST['notificationId']);
        if (!empty($owned)) {
            mark_notification_as_read($_POST['notificationId']);
        }
    } elseif (($_POST['action'] ?? '') === 'mark_all') {
        mark_all_notifications_as_read($userId);
    }

    header('Location: ' . $returnTo);
    exit;
}

$title = 'Notifications';
$activePage = 'notifications';
include __DIR__ . '/../includes/header.php';

$notifications = get_notifications($userId);
$unreadCount = count(array_filter($notifications, fn($n) => !$n['read']));
?>
<h2>Notifications</h2>
<p class="muted">Review your latest system updates.</p>
<?php if ($unreadCount > 0): ?>
    <div class="card no-print">
        <form method="post" style="display:inline;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="mark_all">
            <button class="btn btn-secondary" type="submit">Mark all as read</button>
        </form>
    </div>
<?php endif; ?>
<?php if (empty($notifications)): ?>
    <div class="card">
        <p>No notifications at this time.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Title</th><th>Description</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($notifications as $note): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($note['title']); ?></td>
                        <td><?php echo htmlspecialchars($note['message']); ?></td>
                        <td><?php echo $note['read'] ? 'Read' : 'Unread'; ?></td>
                        <td>
                            <?php if (!$note['read']): ?>
                                <form method="post" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="notificationId" value="<?php echo htmlspecialchars($note['id']); ?>">
                                    <button class="btn" type="submit">Mark as read</button>
                                </form>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
