<?php
/**
 * login/google/callback/index.php — Google redirects here after consent.
 */
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/google_oauth.php';

function google_login_failed(string $message): void {
    $_SESSION['google_login_error'] = $message;
    header('Location: ' . url('landing/') . '#access-login');
    exit;
}

$state = $_GET['state'] ?? '';
$expectedState = $_SESSION['google_oauth_state'] ?? null;
unset($_SESSION['google_oauth_state']); // one-time use regardless of outcome

if (!$expectedState || !hash_equals($expectedState, $state)) {
    google_login_failed('Google sign-in could not be verified. Please try again.');
}

if (!empty($_GET['error'])) {
    // User cancelled the Google consent screen, or Google itself refused —
    // not an error worth logging, just send them back.
    google_login_failed('Google sign-in was cancelled.');
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    google_login_failed('Google sign-in did not return an authorization code.');
}

$profile = google_oauth_handle_callback($code);
if ($profile === null) {
    google_login_failed('Could not complete Google sign-in. Please try again.');
}

if (!$profile['email_verified']) {
    // Extremely rare (would require a Google account with an unverified
    // address) but if it happens, treat it the same as any other
    // unverified email — do not treat this as proof of email ownership.
    google_login_failed('Your Google account\'s email address is not verified. Please verify it with Google first.');
}

$resolution = google_oauth_resolve_account($profile);

switch ($resolution['action']) {
    case 'login': {
        $user = $resolution['user'];

        // Same account-state checks login_user() applies — duplicated
        // rather than shared because login_user() also does password
        // verification and lockout bookkeeping that don't apply here; see
        // google_login_session()'s doc comment in bootstrap.php for why
        // this isn't factored into one function.
        if (($user['status'] ?? '') === 'suspended') {
            $reason = trim((string) ($user['rejection_reason'] ?? ''));
            google_login_failed($reason !== ''
                ? "Your account has been suspended: $reason"
                : 'Your account has been suspended. Contact support for details.');
        }
        // Compliance gate (Fix Guide item 7): admin approval no longer blocks
        // sign-in, it blocks trading. Pending/rejected users sign in and see
        // the restricted dashboard — see includes/compliance.php.
        google_login_session($user);
        log_activity($user['id'], 'account.login', 'user', $user['id'], 'via Google sign-in');
        header('Location: ' . url('dashboard/'));
        exit;
    }

    case 'link': {
        $user = $resolution['user'];

        if (($user['status'] ?? '') === 'suspended') {
            $reason = trim((string) ($user['rejection_reason'] ?? ''));
            google_login_failed($reason !== ''
                ? "Your account has been suspended: $reason"
                : 'Your account has been suspended. Contact support for details.');
        }

        link_google_account($user['id'], $profile['sub']);
        log_activity($user['id'], 'account.google_link', 'user', $user['id'], 'linked Google sign-in to existing account');

        google_login_session($user);
        header('Location: ' . url('dashboard/'));
        exit;
    }

    case 'register': {
        // No existing account for this email — stash the verified
        // profile and send them to the registration tab to pick a role
        // (documents can be uploaded later, after sign-in). landing/index.php's register
        // POST handler reads $_SESSION['google_pending_profile'] to skip
        // password collection and OTP.
        $_SESSION['google_pending_profile'] = $resolution['profile'];
        header('Location: ' . url('landing/') . '#access-register');
        exit;
    }

    case 'blocked_collision':
    default: {
        // See google_oauth_resolve_account()'s doc comment — this is the
        // exact scenario the linking rule exists to catch, so the message
        // is intentionally specific rather than a generic failure.
        google_login_failed(
            'An account with this email already exists but has not finished email verification yet. '
            . 'Please finish verifying that account first (check for a verification code, or use "Forgot password" '
            . 'to regain access), then try Google sign-in again.'
        );
    }
}
