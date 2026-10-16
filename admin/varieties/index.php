<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');

$title = 'Varieties';
$activePage = 'adminvarieties';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!empty($_POST['name']) && ($_POST['action'] ?? '') === 'add') {
        if (!add_variety($_POST['name'])) {
            $error = 'That variety already exists (or the name was blank).';
        } else {
            log_activity(current_user_id() ?? null, 'variety.add', 'variety', null, trim($_POST['name']));
            header('Location: ' . url('admin/varieties/'));
            exit;
        }
    } elseif (!empty($_POST['name']) && ($_POST['action'] ?? '') === 'delete') {
        delete_variety($_POST['name']);
        log_activity(current_user_id() ?? null, 'variety.delete', 'variety', null, trim($_POST['name']));
        header('Location: ' . url('admin/varieties/'));
        exit;
    }
}

include __DIR__ . '/../../includes/header.php';

$varieties = get_varieties();
?>
<h2>Manage Varieties</h2>
<p class="muted">Add or remove entries in the rice variety catalog used across the marketplace filter.</p>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<div class="card">
    <form method="post" class="grid grid-2" style="align-items:end;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add">
        <div class="form-group" style="margin-bottom:0;">
            <label for="name">New variety name</label>
            <input id="name" name="name" type="text" required>
        </div>
        <div>
            <button class="btn btn-primary" type="submit">Add Variety</button>
        </div>
    </form>
</div>
<div class="card">
    <?php if (empty($varieties)): ?>
        <p>No varieties in the catalog yet.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Name</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($varieties as $variety): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($variety); ?></td>
                        <td>
                            <form method="post" style="display:inline;" data-confirm-modal="Remove &quot;<?php echo htmlspecialchars(addslashes($variety)); ?>&quot; from the catalog?" data-confirm-title="Remove variety">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="name" value="<?php echo htmlspecialchars($variety); ?>">
                                <button class="btn" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
