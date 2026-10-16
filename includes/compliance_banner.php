<?php
/**
 * "Complete your Compliance" banner + checklist (Fix Guide item 7).
 *
 * Included by dashboard/index.php and documents/index.php. Renders nothing
 * for admins. For an approved user it renders nothing on the dashboard;
 * set $complianceShowWhenApproved = true before including it (the
 * Documents page does) to show the checklist as a status panel instead.
 */
if (!is_logged_in() || user_role() === 'admin') {
    return;
}

$kgCompliance = current_compliance();
$kgState = $kgCompliance['state'];
if ($kgState === 'approved' && empty($complianceShowWhenApproved)) {
    return;
}

// Step tracker mirrors the 4-state flow: sign up -> documents -> review -> approved.
$kgSteps = [
    ['Sign up & verify email', 'done'],
    ['Submit documents', in_array($kgState, ['under_review', 'approved'], true) ? 'done' : 'current'],
    ['Admin review', $kgState === 'approved' ? 'done' : ($kgState === 'under_review' ? 'current' : 'todo')],
    ['Approved', $kgState === 'approved' ? 'done' : 'todo'],
];
if ($kgState === 'needs_resubmission') {
    $kgSteps[1][1] = 'attention';
}

$kgChipText = [
    'verified' => 'Verified',
    'pending' => 'Under review',
    'expired' => 'Expired',
    'rejected' => 'Rejected',
    'missing' => 'Not submitted',
];
?>
<section class="kg-compliance kg-compliance--<?php echo htmlspecialchars($kgState); ?>" aria-labelledby="kg-compliance-title">
    <div class="kg-compliance__head">
        <div>
            <h3 id="kg-compliance-title">
                <?php echo $kgState === 'approved' ? 'Compliance approved' : 'Complete your Compliance'; ?>
            </h3>
            <p class="kg-compliance__status"><strong><?php echo htmlspecialchars($kgCompliance['label']); ?></strong> &mdash; <?php echo htmlspecialchars($kgCompliance['message']); ?></p>
            <?php if ($kgCompliance['reason'] !== ''): ?>
                <p class="kg-compliance__reason"><strong>Admin note:</strong> <?php echo htmlspecialchars($kgCompliance['reason']); ?></p>
            <?php endif; ?>
        </div>
        <?php if ($kgState !== 'approved'): ?>
            <a class="btn btn-primary" href="<?php echo htmlspecialchars(url('documents/upload/')); ?>">
                <?php echo $kgState === 'needs_resubmission' ? 'Resubmit documents' : 'Upload documents'; ?>
            </a>
        <?php endif; ?>
    </div>

    <ol class="kg-compliance__steps" aria-label="Compliance progress">
        <?php foreach ($kgSteps as $i => [$kgStepLabel, $kgStepState]): ?>
            <li class="kg-compliance__step is-<?php echo htmlspecialchars($kgStepState); ?>">
                <span class="kg-compliance__step-num"><?php echo $kgStepState === 'done' ? '&#10003;' : (int) ($i + 1); ?></span>
                <?php echo htmlspecialchars($kgStepLabel); ?>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php
    // Approved users have no checklist loaded by compliance_evaluate(), so
    // fetch it here for the status panel.
    $kgChecklist = $kgCompliance['checklist'];
    if (empty($kgChecklist)) {
        $kgChecklist = compliance_checklist((string) current_user_id(), user_role());
    }
    ?>
    <ul class="kg-compliance__list">
        <?php foreach ($kgChecklist as $kgItem): ?>
            <li>
                <span class="kg-compliance__chip kg-compliance__chip--<?php echo htmlspecialchars($kgItem['status']); ?>"><?php echo htmlspecialchars($kgChipText[$kgItem['status']] ?? $kgItem['status']); ?></span>
                <span><?php echo htmlspecialchars($kgItem['label']); ?><?php echo $kgItem['required'] ? '' : ' <span class="muted">(optional)</span>'; ?></span>
                <?php if (in_array($kgItem['status'], ['missing', 'rejected', 'expired'], true)): ?>
                    <a href="<?php echo htmlspecialchars(url('documents/upload/?type=' . urlencode($kgItem['types'][0]))); ?>">
                        <?php echo $kgItem['status'] === 'missing' ? 'Upload' : 'Resubmit'; ?>
                    </a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($kgState !== 'approved'): ?>
        <p class="kg-compliance__locked muted">
            Locked until approved: listing stock, warehouses, requests, cart &amp; checkout, payments, tracking, marketplace and the AI assistant.
        </p>
    <?php endif; ?>
</section>
