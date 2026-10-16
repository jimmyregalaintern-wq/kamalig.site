<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');

$user = current_user();
$selfTest = null;
$ping = null;
$pickedUser = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'selftest') {
        // Free: no Anthropic or Google call. Uses a stand-in for the model.
        $pickedUser = (string) ($_POST['user_id'] ?? '');
        $target = $pickedUser !== '' ? get_user_by_id($pickedUser) : null;
        $selfTest = ai_selftest_run($target && ($target['role'] ?? '') !== 'admin' ? $target['id'] : null);
    }

    if ($action === 'ping') {
        // One tiny real request (a few tokens) to prove the key, model and network work.
        $started = microtime(true);
        try {
            $r = anthropic_request(['max_tokens' => 8, 'messages' => [['role' => 'user', 'content' => 'Reply with the single word OK.']]], 'selftest', $user['id'], 15);
            $ping = ['ok' => true, 'model' => $r['_model'] ?? '?', 'ms' => (int) ((microtime(true) - $started) * 1000), 'text' => trim((string) ($r['content'][0]['text'] ?? ''))];
        } catch (AiUnavailableException $e) {
            $ping = ['ok' => false, 'reason' => $e->reason, 'detail' => $e->getMessage(), 'ms' => (int) ((microtime(true) - $started) * 1000)];
        }
    }
}

$usage = ai_usage_today();
$circuit = ai_circuit_state();
$errors = ai_recent_errors(24 * 60, 15);
$callLimit = ai_limit('AI_DAILY_CALL_LIMIT', 400);
$tokenLimit = ai_limit('AI_DAILY_TOKEN_LIMIT', 400000);
$chain = ai_model_chain();
$fallbackNotes = array_filter($errors, fn($e) => $e['kind'] === 'model_fallback');
$users = array_filter(get_all_users(), fn($u) => ($u['role'] ?? '') !== 'admin' && !empty($u['verified']));

$statusColors = ['pass' => '#166534', 'fail' => '#b91c1c', 'skip' => '#6b7280'];

$title = 'AI status';
$activePage = 'documents';
include __DIR__ . '/../../includes/header.php';
?>
<h2>AI status</h2>
<p class="muted">The AI only advises; people decide. This page shows what the AI features are doing, what they cost, and lets you test them safely. No key is ever shown.</p>

<?php if ($circuit['open']): ?>
    <div class="alert alert-error"><strong>AI is paused:</strong> <?php echo htmlspecialchars($circuit['reason']); ?>. Calls resume automatically after <?php echo htmlspecialchars((string) $circuit['until']); ?> (the next call is a trial). Users see "the assistant isn't available right now" and staff can still reply.</div>
<?php elseif (!agent_is_configured()): ?>
    <div class="alert alert-error">No Anthropic key is set, so the assistant, the AI document opinion and the AI summary are off. Rule-based checks and OCR still work.</div>
<?php else: ?>
    <div class="alert alert-success">AI is enabled and not paused.</div>
<?php endif; ?>
<?php if ($fallbackNotes): ?>
    <div class="alert alert-error"><strong>Primary model unavailable:</strong> <code><?php echo htmlspecialchars($chain[0]); ?></code> was rejected as unknown; the fallback model is answering. Set ANTHROPIC_MODEL to a current model (see Anthropic's model list).</div>
<?php elseif (count($chain) === 1): ?>
    <div class="alert">No fallback model is set. If <code><?php echo htmlspecialchars($chain[0]); ?></code> is ever retired, every AI feature stops until you change it. Set <code>ANTHROPIC_MODEL_FALLBACK</code> in .env to a second current model.</div>
<?php endif; ?>

<div class="card">
    <h3>Configuration</h3>
    <div class="table-responsive"><table class="table"><tbody>
        <tr><td>Anthropic key (assistant, AI opinion, summary)</td><td><?php echo agent_is_configured() ? 'Set' : '<strong>Not set</strong>'; ?></td></tr>
        <tr><td>Google Vision key (reads JPG, PNG and PDF documents)</td><td><?php echo (defined('GOOGLE_VISION_API_KEY') && GOOGLE_VISION_API_KEY !== '') ? 'Set' : '<strong>Not set</strong> (documents wait for manual review)'; ?></td></tr>
        <tr><td>Model</td><td><code><?php echo htmlspecialchars($chain[0]); ?></code><?php echo isset($chain[1]) ? ' &rarr; fallback <code>' . htmlspecialchars($chain[1]) . '</code>' : ''; ?></td></tr>
        <tr><td>Per-person limits</td><td><?php echo (int) AGENT_RATE_PER_MINUTE; ?> assistant questions per minute, <?php echo (int) AGENT_RATE_PER_DAY; ?> per day; <?php echo ai_limit('AI_DOC_CHECKS_PER_USER_DAY', 10); ?> document checks per day</td></tr>
    </tbody></table></div>
</div>

<div class="card">
    <h3>Today's usage</h3>
    <div class="table-responsive"><table class="table"><tbody>
        <tr><td>Anthropic calls</td><td><?php echo (int) $usage['calls']; ?> of <?php echo (int) $callLimit; ?></td></tr>
        <tr><td>Tokens (input + output)</td><td><?php echo number_format($usage['tokens']); ?> of <?php echo number_format($tokenLimit); ?></td></tr>
        <tr><td>By purpose</td><td><?php echo $usage['by_purpose'] ? htmlspecialchars(implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($usage['by_purpose']), $usage['by_purpose']))) : 'none yet'; ?></td></tr>
    </tbody></table></div>
    <p class="muted">When a cap is reached the AI stops by itself until tomorrow; nothing is spent. Also set a monthly spend limit in the Anthropic console as the final backstop.</p>
</div>

<div class="card">
    <h3>Safety self-test (free)</h3>
    <p class="muted">Checks, without calling any AI service: the assistant can only see the signed-in person's data, no tool exposes passwords, IDs or files, a model that never stops is cut off, outages and spending caps are reported honestly, text hidden in an uploaded document is detected, and an invented number in the AI summary is rejected. Keep a screenshot as evidence.</p>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="selftest">
        <label for="user_id">Run the assistant checks as</label>
        <select id="user_id" name="user_id">
            <option value="">(document and summary checks only)</option>
            <?php foreach ($users as $u): ?>
                <option value="<?php echo htmlspecialchars($u['id']); ?>" <?php echo $pickedUser === $u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(($u['name'] ?? '') . ' (' . ($u['role'] ?? '') . ')'); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Run self-test</button>
    </form>
    <?php if ($selfTest !== null): ?>
        <div class="table-responsive"><table class="table">
            <thead><tr><th>Check</th><th>Result</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($selfTest as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['name']); ?></td>
                    <td><strong style="color:<?php echo $statusColors[$r['status']]; ?>;"><?php echo strtoupper($r['status']); ?></strong></td>
                    <td><?php echo htmlspecialchars($r['detail']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Live connection test (about 30 tokens)</h3>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="ping">
        <button type="submit" class="btn">Send one test request</button>
    </form>
    <?php if ($ping !== null): ?>
        <div class="alert <?php echo $ping['ok'] ? 'alert-success' : 'alert-error'; ?>">
            <?php if ($ping['ok']): ?>
                Works. Model <code><?php echo htmlspecialchars($ping['model']); ?></code> answered "<?php echo htmlspecialchars($ping['text']); ?>" in <?php echo (int) $ping['ms']; ?> ms.
            <?php else: ?>
                Not working (<?php echo htmlspecialchars($ping['reason']); ?>): <?php echo htmlspecialchars($ping['detail']); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($errors): ?>
<div class="card">
    <h3>Recent AI errors (24 hours)</h3>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>When</th><th>Purpose</th><th>Kind</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($errors as $e): ?>
            <tr><td><?php echo htmlspecialchars($e['at']); ?></td><td><?php echo htmlspecialchars((string) $e['purpose']); ?></td><td><?php echo htmlspecialchars($e['kind']); ?></td><td><?php echo htmlspecialchars($e['msg']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted">billing = no credit or limit reached &middot; auth = key rejected &middot; model = model name not found &middot; rate/server/network = temporary.</p>
</div>
<?php endif; ?>

<p><a href="<?php echo htmlspecialchars(url('admin/security/')); ?>">&larr; Security check</a></p>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
