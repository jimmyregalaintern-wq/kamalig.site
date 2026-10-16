<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../includes/account_delete.php';

$title = 'Admin Accounts';
$activePage = 'adminaccounts';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $targetId = trim($_POST['user_id'] ?? '');
    $target = $targetId !== '' ? get_user_by_id($targetId) : null;

    if (!$target) {
        $error = 'That account no longer exists.';
    } elseif ($target['id'] === current_user_id()) {
        $error = "You can't change your own account from this panel.";
    } elseif ($action === 'delete_user') {
        // Permanent delete: the admin must retype the account's email as confirmation.
        if (strcasecmp(trim($_POST['confirm_email'] ?? ''), (string) $target['email']) !== 0) {
            $error = 'The email you typed does not match, so nothing was deleted.';
        } else {
            $result = delete_user_account($target['id'], current_user_id(), !empty($_POST['with_records']));
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['error'];
            }
        }
    } elseif ($action === 'change_role') {
        $newRole = trim($_POST['new_role'] ?? '');
        if (update_user_role($target['id'], $newRole, current_user_id())) {
            $success = "Role updated for {$target['name']}.";
        } else {
            $error = "Couldn't change that account's role.";
        }
    } elseif ($action === 'suspend') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $error = 'Please give a reason for suspending this account.';
        } else {
            suspend_user($target['id'], $reason, current_user_id());
            $success = "{$target['name']} has been suspended.";
        }
    } elseif ($action === 'reactivate') {
        reactivate_user($target['id'], current_user_id());
        $success = "{$target['name']} has been reactivated.";
    }
}

include __DIR__ . '/../../includes/header.php';

$roleFilter = get_filter_value('role', 'all');
$statusFilter = get_filter_value('status', 'all');
$users = get_all_users();

if ($roleFilter !== 'all') {
    $users = array_values(array_filter($users, fn($user) => $user['role'] === $roleFilter));
}
if ($statusFilter !== 'all') {
    $users = array_values(array_filter($users, fn($user) => ($user['status'] ?? ($user['verified'] ? 'approved' : 'pending')) === $statusFilter));
}

$statusMeta = [
    'approved' => ['label' => 'Active', 'class' => 'available'],
    'pending' => ['label' => 'Pending', 'class' => 'sold-out'],
    'rejected' => ['label' => 'Rejected', 'class' => 'rejected'],
    'suspended' => ['label' => 'Suspended', 'class' => 'archived'],
];
?>
<h2>Admin Accounts</h2>
<p class="muted">All registered accounts on the platform. Change roles, or suspend and reactivate accounts here.</p>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<form method="get" class="card">
    <div class="grid grid-2">
        <div class="form-group" style="margin-bottom:0;">
            <label for="role">Filter by role</label>
            <select id="role" name="role" onchange="this.form.submit()">
                <option value="all" <?php echo $roleFilter === 'all' ? 'selected' : ''; ?>>All roles</option>
                <option value="supplier" <?php echo $roleFilter === 'supplier' ? 'selected' : ''; ?>>Supplier</option>
                <option value="farmer" <?php echo $roleFilter === 'farmer' ? 'selected' : ''; ?>>Farmer</option>
                <option value="importer" <?php echo $roleFilter === 'importer' ? 'selected' : ''; ?>>Importer</option>
                <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label for="status">Filter by status</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Active</option>
                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
            </select>
        </div>
    </div>
</form>

<div class="card">
    <?php if (empty($users)): ?>
        <p>No accounts match this filter.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Company</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <?php
                    $status = $user['status'] ?? ($user['verified'] ? 'approved' : 'pending');
                    $meta = $statusMeta[$status] ?? ['label' => ucfirst($status), 'class' => 'sold-out'];
                    $isSelf = $user['id'] === current_user_id();
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td>
                            <?php if ($user['role'] === 'admin' || $isSelf): ?>
                                <?php echo htmlspecialchars(ucfirst($user['role'])); ?>
                            <?php else: ?>
                                <form method="post" style="margin:0; display:flex; gap:6px;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="change_role">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                    <select name="new_role" onchange="this.form.submit()" style="padding:4px 6px;">
                                        <?php foreach (['farmer', 'supplier', 'importer'] as $r): ?>
                                            <option value="<?php echo $r; ?>" <?php echo $user['role'] === $r ? 'selected' : ''; ?>><?php echo ucfirst($r); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($user['company'] ?? ''); ?></td>
                        <td>
                            <span class="badge badge-<?php echo htmlspecialchars($meta['class']); ?>"><?php echo htmlspecialchars($meta['label']); ?></span>
                            <?php if ($status === 'suspended' && !empty($user['rejection_reason'])): ?>
                                <div class="muted" style="font-size:0.8rem; margin-top:2px;"><?php echo htmlspecialchars($user['rejection_reason']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isSelf): ?>
                                <span class="muted">—</span>
                            <?php elseif ($user['role'] === 'admin'): ?>
                                <span class="muted">Admin</span>
                            <?php elseif ($status === 'pending'): ?>
                                <a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('pending-verification/review/?id=' . urlencode($user['id']))); ?>">Review</a>
                            <?php elseif ($status === 'suspended'): ?>
                                <form method="post" style="margin:0;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="reactivate">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                    <button class="btn btn-primary" type="submit">Reactivate</button>
                                </form>
                            <?php elseif ($status === 'approved'): ?>
                                <details>
                                    <summary class="btn btn-danger" style="display:inline-block; cursor:pointer;">Suspend</summary>
                                    <form method="post" style="margin-top:8px; min-width:220px;" data-confirm-modal="Suspend this account? They will not be able to log in." data-confirm-title="Suspend account">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="suspend">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                        <div class="form-group">
                                            <label>Reason</label>
                                            <textarea name="reason" rows="2" placeholder="Why is this account being suspended?"></textarea>
                                        </div>
                                        <button class="btn btn-danger" type="submit">Confirm Suspend</button>
                                    </form>
                                </details>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                            <?php if (!$isSelf): ?>
                                <?php $preview = account_delete_preview($user['id']); ?>
                                <details style="margin-top:6px;">
                                    <summary class="btn btn-danger" style="display:inline-block; cursor:pointer;">Delete</summary>
                                    <form method="post" style="margin-top:8px; min-width:240px;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                        <p class="muted" style="font-size:0.85rem; margin:0 0 6px;">
                                            Permanently deletes this account and its documents. This cannot be undone.
                                            <?php if ($preview['records'] > 0): ?>
                                                <br><strong>It also has <?php echo (int) $preview['stocks']; ?> stock listing(s), <?php echo (int) $preview['requests']; ?> request(s) and <?php echo (int) $preview['orders']; ?> order(s).</strong>
                                                <?php if ($preview['shared_orders'] > 0): ?><br>⚠ <?php echo (int) $preview['shared_orders']; ?> order(s) involve another account; they disappear for that account too.<?php endif; ?>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($preview['records'] > 0): ?>
                                            <label style="display:block; font-size:0.85rem; margin-bottom:6px;"><input type="checkbox" name="with_records" value="1"> Also delete their stock, requests, orders and payments</label>
                                        <?php endif; ?>
                                        <div class="form-group">
                                            <label>Type <strong><?php echo htmlspecialchars($user['email']); ?></strong> to confirm</label>
                                            <input type="text" name="confirm_email" autocomplete="off" required>
                                        </div>
                                        <button class="btn btn-danger" type="submit">Delete permanently</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<div class="card">
    <p class="muted">Pending applicants (with their submitted documents) are reviewed in detail at <a href="<?php echo htmlspecialchars(url('pending-verification/')); ?>">Pending Verification</a>.</p>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
