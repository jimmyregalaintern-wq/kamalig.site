<?php
/**
 * includes/captcha.php — Cloudflare Turnstile CAPTCHA (free, privacy-friendly).
 *
 * Switched on by setting BOTH keys in .env:
 *   TURNSTILE_SITE_KEY=...     (public, goes in the page)
 *   TURNSTILE_SECRET_KEY=...   (private, used server-side only)
 * If either is empty the CAPTCHA is simply off and every form works as before.
 * That's also your emergency switch: blank the secret key to turn it off.
 *
 * Used on: sign in, register (non-Google), forgot password.
 */
require_once __DIR__ . '/env.php';

function captcha_enabled(): bool {
    return (string) getenv('TURNSTILE_SITE_KEY') !== '' && (string) getenv('TURNSTILE_SECRET_KEY') !== '';
}

/** Renders the widget (and loads Cloudflare's script once per page). */
function captcha_widget(): string {
    if (!captcha_enabled()) {
        return '';
    }
    static $scriptAdded = false;
    $html = '';
    if (!$scriptAdded) {
        $html .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        $scriptAdded = true;
    }
    $html .= '<div class="cf-turnstile" style="margin:12px 0" data-theme="light" data-sitekey="'
        . htmlspecialchars((string) getenv('TURNSTILE_SITE_KEY'), ENT_QUOTES) . '"></div>';
    return $html;
}

if (!function_exists('captcha_http_post')) {
    /** @return array|null decoded JSON, or null if Cloudflare couldn't be reached */
    function captcha_http_post(string $url, array $fields): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : null;
    }
}

/**
 * True when the CAPTCHA is off, or the visitor passed it.
 * Fails CLOSED: if the check can't be completed, the answer is "not verified".
 */
function captcha_check(): bool {
    if (!captcha_enabled()) {
        return true;
    }
    $token = trim((string) ($_POST['cf-turnstile-response'] ?? ''));
    if ($token === '') {
        return false;
    }
    $result = captcha_http_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'secret' => (string) getenv('TURNSTILE_SECRET_KEY'),
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    if ($result === null) {
        error_log('KAMALIG CAPTCHA: could not reach Cloudflare Turnstile');
        return false;
    }
    if (empty($result['success'])) {
        error_log('KAMALIG CAPTCHA: rejected (' . implode(',', (array) ($result['error-codes'] ?? [])) . ')');
        return false;
    }
    return true;
}

const CAPTCHA_FAIL_MESSAGE = 'Please complete the CAPTCHA check and try again.';
