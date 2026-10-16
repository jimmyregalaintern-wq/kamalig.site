<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');

$title = 'Roles & Permissions';
$activePage = 'adminroles';

// Fixed roles — see migrations/014_add_permissions_table.sql for why this
// isn't an open-ended "create a new role" system.
$roles = ['farmer', 'supplier', 'importer', 'admin'];
$features = known_permission_features();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $oldMatrix = get_permission_matrix();
    $matrix = [];
    foreach (array_keys($features) as $featureKey) {
        $checked = $_POST['roles'][$featureKey] ?? [];
        // Only accept known role names, in case of a tampered request.
        //
        // BUGFIX: 'admin' is deliberately force-included below regardless of
        // whether its checkbox was ticked. require_permission() has no
        // built-in exemption for the admin role — it's a plain
        // in_array($role, $allowed) check against whatever this matrix
        // says. Without this, unchecking "Admin" for a feature (e.g.
        // Inventory) would 403 every admin account out of that feature,
        // including the admin who just saved the change, with no page left
        // to undo it from short of editing the database directly. That
        // directly contradicts this page's own stated principle above that
        // admin access "always stay[s] admin-only" / never gets locked out.
        $matrix[$featureKey] = array_values(array_unique(array_merge(
            array_intersect($checked, $roles),
            ['admin']
        )));
    }

    save_permission_matrix($matrix);
    log_permission_changes($oldMatrix, $matrix, current_user_id());
    header('Location: ' . url('admin/roles/'));
    exit;
}

include __DIR__ . '/../../includes/header.php';

$matrix = get_permission_matrix();
?>
<h2>Roles &amp; Permissions</h2>
<p class="muted">Control which account types can access each feature. Admin-only pages (Admin Accounts, Documents, Reports, etc.) are not listed here and always stay admin-only.</p>
<div class="card">
    <form method="post">
        <?php echo csrf_field(); ?>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Feature</th>
                    <?php foreach ($roles as $role): ?>
                        <th><?php echo htmlspecialchars(ucfirst($role)); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($features as $featureKey => $feature): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($feature['label']); ?></td>
                        <?php foreach ($roles as $role): ?>
                            <td>
                                <?php if ($role === 'admin'): ?>
                                    <?php // Always on — see the POST handler above for why this can't
                                          // actually be turned off. Shown disabled (not hidden) so it's
                                          // clear this is a deliberate floor, not a display bug. ?>
                                    <input type="checkbox" checked disabled title="Admins always retain access to every feature.">
                                <?php else: ?>
                                    <input
                                        type="checkbox"
                                        name="roles[<?php echo htmlspecialchars($featureKey); ?>][]"
                                        value="<?php echo htmlspecialchars($role); ?>"
                                        <?php echo in_array($role, $matrix[$featureKey] ?? [], true) ? 'checked' : ''; ?>
                                    >
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <button class="btn btn-primary" type="submit">Save Permissions</button>
    </form>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
