<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');

$id = trim($_GET['id'] ?? $_POST['id'] ?? '');
$applicant = $id !== '' ? get_user_by_id($id) : null;

if (!$applicant) {
    http_response_code(404);
    $title = 'Applicant not found';
    include __DIR__ . '/../../includes/header.php';
    echo '<h2>Applicant not found</h2><p>This account no longer exists.</p>';
    echo '<a class="btn btn-secondary" href="' . htmlspecialchars(url('pending-verification/')) . '">Back to Pending Verification</a>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$title = 'Review Applicant';
$activePage = 'pending-verification';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        approve_pending_user($id, current_user_id() ?? null);
        header('Location: ' . url('pending-verification/'));
        exit;
    } elseif ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $error = 'Please give a reason for rejecting this applicant — it\'s recorded and shown to them.';
        } else {
            reject_pending_user($id, $reason, current_user_id() ?? null);
            header('Location: ' . url('pending-verification/'));
            exit;
        }
    }
}

$documents = get_documents($id);

include __DIR__ . '/../../includes/header.php';
?>
<h2>Review Applicant</h2>
<p class="muted">Check the application details and any submitted documents, then approve or reject.</p>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (($applicant['status'] ?? 'pending') === 'rejected'): ?>
    <div class="alert alert-error">
        This applicant was already rejected<?php echo !empty($applicant['reviewed_at']) ? ' on ' . htmlspecialchars(date('Y-m-d', strtotime($applicant['reviewed_at']))) : ''; ?>.
        <?php if (!empty($applicant['rejection_reason'])): ?>
            Reason: <?php echo htmlspecialchars($applicant['rejection_reason']); ?>
        <?php endif; ?>
    </div>
<?php elseif ($applicant['verified']): ?>
    <div class="alert alert-success">This applicant is already approved.</div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <h3>Applicant Details</h3>
        <p><strong>Name:</strong> <?php echo htmlspecialchars($applicant['name']); ?></p>
        <p><strong>Email:</strong> <?php echo htmlspecialchars($applicant['email']); ?></p>
        <p><strong>Account type:</strong> <?php echo htmlspecialchars(ucfirst($applicant['role'])); ?></p>
        <p><strong>Company:</strong> <?php echo htmlspecialchars($applicant['company'] ?? '—'); ?></p>
        <p><strong>Phone:</strong> <?php echo htmlspecialchars($applicant['phone'] ?: '—'); ?></p>
        <p><strong>Location:</strong> <?php echo htmlspecialchars($applicant['location'] ?: '—'); ?></p>
        <p><strong>Email verified (OTP):</strong> <?php echo !empty($applicant['otp_verified']) ? 'Yes' : 'No'; ?></p>
        <?php if (empty($applicant['otp_verified'])): ?>
            <p class="muted">This applicant hasn't completed email verification yet. Approving now is fine — but they still won't be able to log in until they verify their email too, since both are required.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Submitted Documents</h3>
        <?php if (empty($documents)): ?>
            <p class="muted">No documents uploaded yet by this applicant.</p>
        <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Type</th><th>Business name</th><th>Permit #</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($documents as $doc): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($doc['documentType'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($doc['businessName'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($doc['permitNumber'] ?? '—'); ?></td>
                            <td><span class="badge badge-<?php echo htmlspecialchars($doc['status'] === 'verified' ? 'available' : ($doc['status'] === 'rejected' ? 'rejected' : 'sold-out')); ?>"><?php echo htmlspecialchars(ucfirst($doc['status'] ?? 'pending')); ?></span></td>
                            <td><a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('admin/documents/review/?id=' . urlencode($doc['id']))); ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (($applicant['status'] ?? 'pending') === 'pending'): ?>
<div class="card">
    <h3>Decision</h3>
    <div class="grid grid-2">
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($applicant['id']); ?>">
            <p class="muted">Approve if the details and documents check out.</p>
            <button class="btn btn-primary" type="submit" name="action" value="approve">Approve Account</button>
        </form>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($applicant['id']); ?>">
            <div class="form-group">
                <label for="reason">Rejection reason (shown to the applicant)</label>
                <textarea id="reason" name="reason" rows="3" placeholder="e.g. Business permit doesn't match the company name on file"><?php echo htmlspecialchars($_POST['reason'] ?? ''); ?></textarea>
            </div>
            <button class="btn btn-danger" type="submit" name="action" value="reject" data-confirm-modal="Reject this applicant? They will not be able to log in." data-confirm-title="Reject applicant">Reject Account</button>
        </form>
    </div>
</div>
<?php endif; ?>

<p><a class="btn btn-secondary" href="<?php echo htmlspecialchars(url('pending-verification/')); ?>">Back to Pending Verification</a></p>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
