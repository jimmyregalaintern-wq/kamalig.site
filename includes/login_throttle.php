<?php
/**
 * includes/login_throttle.php — per-IP sign-in throttle (Fix Guide item 9).
 *
 * The account lockout in data.php is per ACCOUNT, so anyone could lock any
 * user (including the admin) just by typing wrong passwords for their email.
 * This adds a second, per-IP limit: after LOGIN_IP_MAX_FAILURES failed sign-ins
 * from one address inside LOGIN_IP_WINDOW_MINUTES, that address is paused until
 * the window clears. It is purely time-based (nothing is locked permanently)
 * and failures are never cleared by a successful login, so an attacker cannot
 * reset their own counter.
 *
 * Needs the `login_attempts` table created by install_update.php. If the table
 * is missing the throttle quietly does nothing and the account lockout still
 * applies. The IP is stored only as a SHA-256 hash.
 *
 * Behind a CDN/proxy set TRUST_IP_HEADER in .env (e.g. HTTP_CF_CONNECTING_IP)
 * so visitors are not all counted as one address.
 */
if (!defined('LOGIN_IP_MAX_FAILURES')) define('LOGIN_IP_MAX_FAILURES', 15);
if (!defined('LOGIN_IP_WINDOW_MINUTES')) define('LOGIN_IP_WINDOW_MINUTES', 15);

function login_throttle_ip_hash(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $header = (string) getenv('TRUST_IP_HEADER');
    if ($header !== '' && preg_match('/^HTTP_[A-Z0-9_]+$/', $header) && !empty($_SERVER[$header])) {
        $ip = trim(explode(',', (string) $_SERVER[$header])[0]);
    }
    return hash('sha256', 'kamalig-login|' . $ip);
}

/** Throws a RuntimeException (shown to the user) when this address is currently paused. */
function login_throttle_assert_allowed(): void {
    if (!database_available()) {
        return;
    }
    try {
        $since = date('Y-m-d H:i:s', time() - LOGIN_IP_WINDOW_MINUTES * 60);
        $row = query_one(
            'SELECT COUNT(*) AS n, MIN(attemptedAt) AS oldest FROM login_attempts WHERE ipHash = ? AND attemptedAt >= ?',
            [login_throttle_ip_hash(), $since]
        );
        if ($row && (int) $row['n'] >= LOGIN_IP_MAX_FAILURES) {
            $oldest = strtotime((string) $row['oldest']) ?: time();
            $wait = max(1, (int) ceil(($oldest + LOGIN_IP_WINDOW_MINUTES * 60 - time()) / 60));
            throw new RuntimeException("Too many failed sign-in attempts from this network. Please try again in $wait minute(s).");
        }
    } catch (RuntimeException $e) {
        if ($e instanceof PDOException) {
            error_log('KAMALIG login throttle unavailable: ' . $e->getMessage());
            return;
        }
        throw $e;
    } catch (Throwable $e) {
        error_log('KAMALIG login throttle unavailable: ' . $e->getMessage());
    }
}

function login_throttle_record_failure(): void {
    if (!database_available()) {
        return;
    }
    try {
        query_execute('INSERT INTO login_attempts (ipHash, attemptedAt) VALUES (?, ?)', [login_throttle_ip_hash(), date('Y-m-d H:i:s')]);
        if (random_int(1, 50) === 1) { // housekeeping: forget attempts older than a day
            query_execute('DELETE FROM login_attempts WHERE attemptedAt < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        }
    } catch (Throwable $e) {
        error_log('KAMALIG login throttle could not record a failure: ' . $e->getMessage());
    }
}
