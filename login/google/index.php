<?php
/**
 * login/google/index.php — starts the Google sign-in flow.
 * Linked from the "Sign in with Google" button on landing/index.php.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/google_oauth.php';

// A random, session-bound `state` value, checked on the way back in
// callback/index.php — this is OAuth's equivalent of a CSRF token,
// stopping an attacker from crafting a callback URL that logs a victim
// into the attacker's own Google-linked account (a known OAuth login
// CSRF pattern when state isn't checked).
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

header('Location: ' . google_oauth_authorize_url($state));
exit;
