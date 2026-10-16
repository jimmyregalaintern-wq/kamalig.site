<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$pendingEmail = $_SESSION['pending_email'] ?? null;
$pendingUsers = [];
$rejectedUsers = [];
if (is_logged_in() && user_role() === 'admin') {
    $pendingUsers = get_pending_users();
    $rejectedUsers = get_rejected_users();
}

// Handle the POST — and its redirect — before includes/header.php is
// loaded below, since that include starts printing HTML immediately; a
// header('Location: ...') call after any output has started fails with
// "headers already sent".
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_id']) && user_role() === 'admin') {
    csrf_verify();
    approve_pending_user($_POST['approve_id'], current_user_id() ?? null);
    header('Location: ' . url('pending-verification/'));
    exit;
}

$title = 'Pending Verification';
$activePage = 'pending-verification';
include __DIR__ . '/../includes/header.php';
?>
<h2>Pending Verification</h2>
<p class="muted">Review account verification status.</p>
<?php if ($pendingEmail && !is_logged_in()): ?>
    <div class="card">
        <p>Your account registration for <strong><?php echo htmlspecialchars($pendingEmail); ?></strong> is pending approval.</p>
        <p>Please check back later when your account is verified.</p>
    </div>
<?php elseif (!empty($pendingUsers)): ?>
    <div class="card">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Email</th><th>Name</th><th>Role</th><th>Company</th><th>Documents</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($pendingUsers as $user): ?>
                <?php
                    $applicantDocs = get_documents($user['id']);
                    $docCount = count($applicantDocs);
                    $flaggedCount = count(array_filter($applicantDocs, fn($d) => !empty($d['aiFlag'])));

                    if ($docCount === 0) {
                        $confirmMessage = 'This applicant has no documents attached. Approve anyway?';
                    } elseif ($flaggedCount > 0) {
                        $confirmMessage = 'This applicant has AI-flagged document(s). Approve anyway?';
                    } else {
                        $confirmMessage = null;
                    }
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><?php echo htmlspecialchars($user['name']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td>
                    <td><?php echo htmlspecialchars($user['company'] ?? '—'); ?></td>
                    <td>
                        <?php if ($docCount === 0): ?>
                            <span class="badge badge-rejected">No documents</span>
                        <?php else: ?>
                            <span class="badge badge-available"><?php echo $docCount; ?> attached</span>
                            <?php if ($flaggedCount > 0): ?>
                                <span class="badge badge-rejected"><?php echo $flaggedCount; ?> flagged</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('pending-verification/review/?id=' . urlencode($user['id']))); ?>">Review</a>
                        <form method="post" style="margin:0;" <?php echo $confirmMessage ? 'data-confirm-modal="' . htmlspecialchars($confirmMessage) . '" data-confirm-title="Quick approve"' : ''; ?>>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="approve_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                            <button class="btn btn-primary" type="submit">Quick Approve</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <p>There are no pending verification items at this time.</p>
    </div>
<?php endif; ?>

<?php if (is_logged_in() && user_role() === 'admin' && !empty($rejectedUsers)): ?>
    <div class="card">
        <h3>Recently Rejected</h3>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Email</th><th>Name</th><th>Role</th><th>Reason</th><th>Reviewed</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($rejectedUsers as $user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><?php echo htmlspecialchars($user['name']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td>
                    <td><?php echo htmlspecialchars($user['rejection_reason'] ?? '—'); ?></td>
                    <td><?php echo $user['reviewed_at'] ? htmlspecialchars(date('Y-m-d', strtotime($user['reviewed_at']))) : '—'; ?></td>
                    <td><a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('pending-verification/review/?id=' . urlencode($user['id']))); ?>">Open</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>

