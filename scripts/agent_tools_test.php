<?php
/**
 * scripts/agent_tools_test.php — FREE safety tests of the AI features (no API calls, no tokens).
 *
 *   php scripts/agent_tools_test.php <userId>     a farmer / importer / supplier account
 *
 * Same checks as Admin > AI status. Exit code 0 = all passed, 2 = something failed.
 * Keep the output as evidence for the "AI evaluation" slide.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/../includes/bootstrap.php';

$userId = $argv[1] ?? '';
$results = ai_selftest_run($userId !== '' ? $userId : null);
$counts = ['pass' => 0, 'fail' => 0, 'skip' => 0];
foreach ($results as $r) {
    $counts[$r['status']]++;
    printf("[%s] %s%s\n", strtoupper($r['status']), $r['name'], $r['detail'] !== '' ? "  ({$r['detail']})" : '');
}
echo "\n{$counts['pass']} passed, {$counts['fail']} failed, {$counts['skip']} skipped\n";
exit($counts['fail'] > 0 ? 2 : 0);
