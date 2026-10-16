<?php
/**
 * Smoke test for the AI agent — run from the project root, CLI only.
 *
 *   Offline (no API key, no cost; fake model that calls two tools):
 *     php scripts/agent_smoke.php --offline <userId>
 *
 *   Live (needs ANTHROPIC_API_KEY set in the environment):
 *     php scripts/agent_smoke.php <userId> "Where is my latest order?"
 *
 * <userId> is a value from the users.id column (e.g. look in phpMyAdmin).
 * The offline run proves the wiring: DB access, tool execution, scoping,
 * the tool_use/tool_result message shape, and activity_log auditing.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$args = array_slice($argv, 1);
$offline = false;
if (($args[0] ?? '') === '--offline') {
    $offline = true;
    array_shift($args);
    // Dummy key so agent_is_configured() passes; the fake transport never calls the network.
    putenv('ANTHROPIC_API_KEY=offline-test-key');
}

$userId = $args[0] ?? '';
$question = $args[1] ?? 'What rice is available and what is the cheapest?';
if ($userId === '') {
    fwrite(STDERR, "Usage: php scripts/agent_smoke.php [--offline] <userId> [\"question\"]\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/ai_config.php';
require_once __DIR__ . '/../includes/agent.php';

$user = get_user_by_id($userId);
if (!$user) {
    fwrite(STDERR, "No user with id '{$userId}'.\n");
    exit(1);
}
echo "User: {$user['name']} ({$user['role']})\nQuestion: {$question}\n\n";

$step = 0;
$transport = null;

if ($offline) {
    // Scripted "model": step 1 asks for two tools, step 2 summarises their results.
    $transport = function (array $payload) use (&$step): array {
        $step++;
        if ($step === 1) {
            return [
                'stop_reason' => 'tool_use',
                'content' => [
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'market_price_summary', 'input' => []],
                    ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'my_orders', 'input' => ['limit' => 3]],
                ],
            ];
        }
        $last = end($payload['messages']);
        echo "--- tool results the model would see ---\n";
        foreach ($last['content'] as $r) {
            echo substr($r['content'], 0, 400) . "\n";
        }
        echo "----------------------------------------\n\n";
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '[offline] Tool loop completed.']]];
    };
} else {
    // Live: wrap the real transport so tool calls are visible.
    $transport = function (array $payload) use (&$step): array {
        $step++;
        $resp = agent_http_post($payload);
        foreach ($resp['content'] ?? [] as $b) {
            if (($b['type'] ?? '') === 'tool_use') {
                echo "step {$step}: tool_use {$b['name']} " . json_encode($b['input'] ?? new stdClass()) . "\n";
            }
        }
        return $resp;
    };
}

$started = microtime(true);
$reply = agent_run($userId, [], $question, $transport, $user['role']);
$elapsed = round(microtime(true) - $started, 2);

echo $reply === null ? "RESULT: null (would escalate to staff)\n" : "RESULT:\n{$reply}\n";
echo "\nModel calls: {$step}   Time: {$elapsed}s\n";
echo "Check activity_log for rows with action='assistant_tool' for this user.\n";
