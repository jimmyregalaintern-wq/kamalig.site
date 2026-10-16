<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_auth();

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    render_error_page(403, 'Access denied', 'You do not have permission to view this page.');
}

$results = security_check_results();
$fails = count(array_filter($results, fn($r) => $r['status'] === 'fail'));
$warns = count(array_filter($results, fn($r) => $r['status'] === 'warn'));
$colors = ['ok' => '#166534', 'warn' => '#b45309', 'fail' => '#b91c1c'];
$words = ['ok' => 'OK', 'warn' => 'Check', 'fail' => 'Fix now'];

$title = 'Security check';
$activePage = 'documents';
include __DIR__ . '/../../includes/header.php';
?>
<h2>Security check</h2>
<p class="muted">Read-only. Nothing here changes data, and no password or key is ever shown. &middot; <a href="<?php echo htmlspecialchars(url('admin/ai/')); ?>">AI status and self-test</a></p>
<div class="alert <?php echo $fails ? 'alert-error' : 'alert-success'; ?>">
    <?php echo $fails ? ($fails . ' item(s) need fixing now.') : 'No critical problems found.'; ?>
    <?php echo $warns ? ' ' . $warns . ' to review.' : ''; ?>
</div>
<div class="card">
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>Check</th><th>Result</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars($r['label']); ?></td>
                <td><strong style="color:<?php echo $colors[$r['status']]; ?>;"><?php echo $words[$r['status']]; ?></strong></td>
                <td><?php echo htmlspecialchars($r['detail']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<div class="card">
    <h3>Things only you can do</h3>
    <p class="muted">The app cannot check these from the inside.</p>
    <ul>
        <li>Revoke the old Gmail App Password at myaccount.google.com/apppasswords.</li>
        <li>Rotate any key that was ever inside a zip or chat: database password, Anthropic key, Google Vision key, Google client secret, Turnstile secret.</li>
        <li>Delete the <code>kamalig_backups</code> folder (next to public_html) once you are happy with the update.</li>
    </ul>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
