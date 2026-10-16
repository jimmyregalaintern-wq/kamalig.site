<?php
/**
 * includes/google_oauth.php
 *
 * Minimal Google OAuth2 (Authorization Code flow) using raw cURL —
 * deliberately no composer dependency (league/oauth2-google etc.) since
 * this is three simple HTTP calls and the current codebase has no
 * composer.json / vendor/ directory to introduce one into.
 *
 * Requires in config/env: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET,
 * GOOGLE_REDIRECT_URI (must exactly match what's registered in Google
 * Cloud Console — see docs/external-setup.md from the rebuild planning).
 */

function google_oauth_authorize_url(string $state): string {
    $params = [
        'client_id' => getenv('GOOGLE_CLIENT_ID'),
        'redirect_uri' => getenv('GOOGLE_REDIRECT_URI'),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'prompt' => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Exchanges an authorization code for tokens, then fetches the user's
 * profile. Returns ['sub' => Google's stable user id, 'email' => string,
 * 'email_verified' => bool, 'name' => string] or null on any failure.
 *
 * email_verified here is Google's own claim, straight from their ID
 * token / userinfo response — this is what makes it safe to skip the
 * OTP step for Google sign-ups: Google already did the equivalent check
 * before issuing this claim.
 */
function google_oauth_handle_callback(string $code): ?array {
    $tokenResponse = google_oauth_curl_post('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => getenv('GOOGLE_CLIENT_ID'),
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => getenv('GOOGLE_REDIRECT_URI'),
        'grant_type' => 'authorization_code',
    ]);

    if ($tokenResponse === null || empty($tokenResponse['access_token'])) {
        error_log('Google OAuth: token exchange failed');
        return null;
    }

    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokenResponse['access_token']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    $profile = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($profile) || empty($profile['sub']) || empty($profile['email'])) {
        error_log('Google OAuth: userinfo fetch failed');
        return null;
    }

    return [
        'sub' => $profile['sub'],
        'email' => $profile['email'],
        'email_verified' => !empty($profile['email_verified']),
        'name' => $profile['name'] ?? $profile['email'],
    ];
}

function google_oauth_curl_post(string $url, array $fields): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : null;
}

/**
 * THE ACCOUNT-LINKING RULE (same one designed for the Next.js version,
 * translated to this schema's fields):
 *
 * - No existing user with this email -> caller should send them to
 *   registration (pre-filled), NOT silently create an account here.
 *   Google sign-in still requires choosing a role + submitting compliance
 *   documents, same as any other registration — Google only replaces the
 *   password + OTP step, not the pending-verification step.
 * - Existing user, google_id already set and matches -> normal login.
 * - Existing user, no google_id yet, but otp_verified = 1 -> SAFE to
 *   link: this account's owner already proved control of the email
 *   through the OTP flow (or a prior Google sign-in), so attaching this
 *   Google identity to it can't be an account takeover.
 * - Existing user, no google_id yet, and otp_verified = 0 -> DO NOT
 *   link. This is exactly the collision an attacker would set up:
 *   register with someone else's email, never finish OTP, then try to
 *   ride in via that email's real Google account. Block and surface a
 *   clear message instead of guessing.
 *
 * Returns one of: ['action' => 'login', 'user' => array]
 *              or ['action' => 'link', 'user' => array]   (caller then UPDATEs google_id)
 *              or ['action' => 'register', 'profile' => array]
 *              or ['action' => 'blocked_collision']
 */
function google_oauth_resolve_account(array $googleProfile): array {
    $existingByGoogleId = get_user_by_google_id($googleProfile['sub']);
    if ($existingByGoogleId) {
        return ['action' => 'login', 'user' => $existingByGoogleId];
    }

    $existingByEmail = get_user_by_email($googleProfile['email']);
    if (!$existingByEmail) {
        return ['action' => 'register', 'profile' => $googleProfile];
    }

    if (!empty($existingByEmail['otp_verified'])) {
        return ['action' => 'link', 'user' => $existingByEmail];
    }

    return ['action' => 'blocked_collision'];
}
