<?php
/**
 * includes/ai_guard.php — the ONE controlled door to the Anthropic API.
 *
 * Every Claude call in the app (assistant, document check, dashboard summary)
 * goes through anthropic_request(). It adds what the individual features lacked:
 *
 *   - a daily cost cap       calls and tokens per day; over the cap = no call is made
 *   - a circuit breaker      after a billing / auth / unknown-model error, calls stop for
 *                            a few minutes instead of failing (and costing time) on every request
 *   - a model fallback       ANTHROPIC_MODEL, then ANTHROPIC_MODEL_FALLBACK if the first is retired
 *   - usage records          one 'ai.usage' row per call in the activity log (tokens, model, purpose)
 *   - clear failure signal   AiUnavailableException with a reason, so callers can tell the user
 *                            "the assistant is unavailable" instead of pretending to escalate
 *
 * No new database table is needed: counters are read from activity_log.
 * Limits (all optional, set in .env):
 *   AI_DAILY_CALL_LIMIT (400)  AI_DAILY_TOKEN_LIMIT (400000)
 *   ANTHROPIC_BASE_URL (default https://api.anthropic.com; only changed in tests)
 *   AI_DOC_CHECKS_PER_USER_DAY (10)  AI_CIRCUIT_MINUTES (15)  ANTHROPIC_MODEL_FALLBACK
 */

require_once __DIR__ . '/ai_config.php';

class AiUnavailableException extends RuntimeException {
    /** not_configured | budget | circuit | provider */
    public string $reason;
    public function __construct(string $reason, string $message = '') {
        parent::__construct($message !== '' ? $message : $reason);
        $this->reason = $reason;
    }
}

/** An error response from the provider, classified. */
class AiHttpError extends RuntimeException {
    /** auth | billing | model | rate | server | network | bad_request */
    public string $kind;
    public int $status;
    public function __construct(string $kind, int $status, string $message) {
        parent::__construct($message);
        $this->kind = $kind;
        $this->status = $status;
    }
}

function ai_limit(string $env, int $default): int {
    $v = getenv($env);
    return ($v !== false && $v !== '' && (int) $v > 0) ? (int) $v : $default;
}

/** Models to try, in order: the configured one, then an optional fallback. */
function ai_model_chain(): array {
    $chain = [ANTHROPIC_MODEL];
    $fallback = trim((string) getenv('ANTHROPIC_MODEL_FALLBACK'));
    if ($fallback !== '' && $fallback !== ANTHROPIC_MODEL) {
        $chain[] = $fallback;
    }
    return $chain;
}

// ---------------------------------------------------------------------
// Usage records and counters (activity_log; no new schema)
// ---------------------------------------------------------------------

function ai_record_usage(string $purpose, string $model, int $in, int $out, ?string $userId = null): void {
    try {
        log_activity($userId, 'ai.usage', 'ai', $purpose, json_encode(['model' => $model, 'in' => $in, 'out' => $out]));
    } catch (Throwable $e) {
        error_log('KAMALIG AI usage log failed: ' . $e->getMessage());
    }
}

function ai_record_error(string $purpose, AiHttpError $e, ?string $kindOverride = null): void {
    try {
        log_activity(null, 'ai.error', 'ai', $purpose, json_encode(['kind' => $kindOverride ?? $e->kind, 'status' => $e->status, 'msg' => mb_substr($e->getMessage(), 0, 160)]));
    } catch (Throwable $x) {
        error_log('KAMALIG AI error log failed: ' . $x->getMessage());
    }
}

/** @return array{calls:int,tokens:int,by_purpose:array<string,int>} */
function ai_usage_today(): array {
    $out = ['calls' => 0, 'tokens' => 0, 'by_purpose' => []];
    if (!database_available()) {
        return $out;
    }
    $rows = query_all(
        "SELECT entityId, details FROM activity_log WHERE action = 'ai.usage' AND createdAt >= ? ORDER BY createdAt DESC LIMIT 5000",
        [date('Y-m-d 00:00:00')]
    );
    foreach ($rows as $r) {
        $d = json_decode((string) $r['details'], true) ?: [];
        $out['calls']++;
        $out['tokens'] += (int) ($d['in'] ?? 0) + (int) ($d['out'] ?? 0);
        $p = (string) ($r['entityId'] ?? 'other');
        $out['by_purpose'][$p] = ($out['by_purpose'][$p] ?? 0) + 1;
    }
    return $out;
}

/** Recent provider errors, newest first. */
function ai_recent_errors(int $minutes = 60, int $limit = 20): array {
    if (!database_available()) {
        return [];
    }
    $rows = query_all(
        "SELECT createdAt, entityId, details FROM activity_log WHERE action = 'ai.error' AND createdAt >= ? ORDER BY createdAt DESC LIMIT " . (int) $limit,
        [date('Y-m-d H:i:s', time() - $minutes * 60)]
    );
    return array_map(function ($r) {
        $d = json_decode((string) $r['details'], true) ?: [];
        return ['at' => $r['createdAt'], 'purpose' => $r['entityId'], 'kind' => $d['kind'] ?? '?', 'status' => $d['status'] ?? 0, 'msg' => $d['msg'] ?? ''];
    }, $rows);
}

/**
 * Circuit breaker. OPEN means: do not call the provider right now.
 *  - one billing / auth / unknown-model error opens it for AI_CIRCUIT_MINUTES (the
 *    next call after that is a probe; if it fails the circuit opens again);
 *  - 5 temporary errors (rate limit, 5xx, network) in 10 minutes also open it.
 * @return array{open:bool,reason:string,kind:string,until:?string}
 */
function ai_circuit_state(): array {
    $closed = ['open' => false, 'reason' => '', 'kind' => '', 'until' => null];
    if (!database_available()) {
        return $closed;
    }
    $window = ai_limit('AI_CIRCUIT_MINUTES', 15);
    $errors = ai_recent_errors($window, 30);
    foreach ($errors as $e) {
        if (in_array($e['kind'], ['billing', 'auth', 'model'], true)) {
            $labels = ['billing' => 'the Anthropic account has no credit or hit its limit', 'auth' => 'the API key was rejected', 'model' => 'the configured model is not available'];
            return ['open' => true, 'reason' => $labels[$e['kind']], 'kind' => $e['kind'], 'until' => date('Y-m-d H:i:s', strtotime($e['at']) + $window * 60)];
        }
    }
    $recentTemp = array_filter($errors, fn($e) => strtotime($e['at']) >= time() - 600);
    if (count($recentTemp) >= 5) {
        $last = $recentTemp[array_key_first($recentTemp)];
        return ['open' => true, 'reason' => 'the AI service keeps failing', 'kind' => 'unstable', 'until' => date('Y-m-d H:i:s', strtotime($last['at']) + 600)];
    }
    return $closed;
}

/** Throws AiUnavailableException when a call must not be made. */
function ai_check_allowed(string $purpose, ?string $userId = null): void {
    if (!ai_validation_is_configured()) {
        throw new AiUnavailableException('not_configured', 'ANTHROPIC_API_KEY is not set.');
    }
    try {
        $circuit = ai_circuit_state();
        if ($circuit['open']) {
            throw new AiUnavailableException('circuit', 'AI paused: ' . $circuit['reason'] . '.');
        }
        $usage = ai_usage_today();
        if ($usage['calls'] >= ai_limit('AI_DAILY_CALL_LIMIT', 400)) {
            throw new AiUnavailableException('budget', 'Daily AI call limit reached.');
        }
        if ($usage['tokens'] >= ai_limit('AI_DAILY_TOKEN_LIMIT', 400000)) {
            throw new AiUnavailableException('budget', 'Daily AI token limit reached.');
        }
    } catch (AiUnavailableException $e) {
        throw $e;
    } catch (Throwable $e) {
        // Cannot read the counters: fail CLOSED rather than spend money unmetered.
        error_log('KAMALIG AI guard could not read counters: ' . $e->getMessage());
        throw new AiUnavailableException('budget', 'AI usage could not be checked.');
    }
}

// ---------------------------------------------------------------------
// The call itself
// ---------------------------------------------------------------------

function ai_classify_error(int $status, string $message): string {
    $m = strtolower($message);
    if ($status === 401 || $status === 403) return 'auth';
    if ($status === 402 || ($status === 400 && (strpos($m, 'credit balance') !== false || strpos($m, 'billing') !== false))) return 'billing';
    if ($status === 404) return 'model';
    if ($status === 429) return 'rate';
    if ($status >= 500) return 'server';
    return 'bad_request';
}

/** One HTTP POST with up to 2 retries on rate-limit / server / network errors. Throws AiHttpError. */
function ai_http_post(array $payload, int $timeout, ?int $deadline = null): array {
    $attempt = 0;
    while (true) {
        $attempt++;
        $ch = curl_init(rtrim((string) (getenv('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com'), '/') . '/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $networkFail = $raw === false;
        $retryable = $networkFail || in_array($status, [429, 500, 502, 503, 529], true);
        $outOfTime = $deadline !== null && time() >= $deadline;
        if ($retryable && $attempt < 3 && !$outOfTime) {
            usleep(400000 * $attempt);
            continue;
        }
        if ($networkFail) {
            throw new AiHttpError('network', 0, 'Anthropic request failed: ' . $curlError);
        }
        $decoded = json_decode($raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : substr((string) $raw, 0, 160);
            throw new AiHttpError(ai_classify_error($status, $message), $status, "Anthropic HTTP {$status}: " . $message);
        }
        return $decoded;
    }
}

/**
 * Guarded Messages API call. Returns the decoded response (with '_model' added).
 * Throws AiUnavailableException — never an unclassified error — so callers can
 * degrade gracefully (template text, "assistant unavailable", manual review).
 *
 * @param string      $purpose assistant | document_check | summary | selftest
 * @param int|null    $deadline unix time after which no retry is attempted
 */
function anthropic_request(array $payload, string $purpose, ?string $userId = null, int $timeout = 25, ?int $deadline = null): array {
    ai_check_allowed($purpose, $userId);

    $chain = ai_model_chain();
    $lastError = null;
    foreach ($chain as $i => $model) {
        $payload['model'] = $model;
        try {
            $resp = ai_http_post($payload, $timeout, $deadline);
            $usage = $resp['usage'] ?? [];
            ai_record_usage($purpose, $model, (int) ($usage['input_tokens'] ?? 0), (int) ($usage['output_tokens'] ?? 0), $userId);
            $resp['_model'] = $model;
            return $resp;
        } catch (AiHttpError $e) {
            $lastError = $e;
            $hasFallback = $e->kind === 'model' && isset($chain[$i + 1]);
            // A retired primary model with a working fallback is a warning, NOT an outage:
            // it is logged as 'model_fallback', which does not open the circuit breaker.
            ai_record_error($purpose, $e, $hasFallback ? 'model_fallback' : null);
            error_log('KAMALIG AI call failed (' . $purpose . ', ' . $model . '): ' . $e->getMessage());
            if ($hasFallback) {
                continue; // retired / unknown model: try the fallback model
            }
            break;
        }
    }
    throw new AiUnavailableException('provider', $lastError ? $lastError->getMessage() : 'AI request failed.');
}

// ---------------------------------------------------------------------
// Small helpers shared by the AI features
// ---------------------------------------------------------------------

/** Plain, bounded text for putting user-controlled values into a prompt or a stored note. */
function ai_clean_text($value, int $max = 200): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '...' : $s;
}

/** Small file cache in private storage (no schema). Returns null when missing or older than $ttl seconds. */
function ai_cache_get(string $key, int $ttl) {
    try {
        $file = private_storage_root() . '/ai_cache/' . hash('sha256', $key) . '.json';
        if (!is_file($file) || filemtime($file) < time() - $ttl) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ai_cache_put(string $key, array $value): void {
    try {
        $dir = private_storage_root() . '/ai_cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($dir . '/' . hash('sha256', $key) . '.json', json_encode($value), LOCK_EX);
    } catch (Throwable $e) {
        error_log('KAMALIG AI cache write failed: ' . $e->getMessage());
    }
}
