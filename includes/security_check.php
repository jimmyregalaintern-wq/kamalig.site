<?php
/**
 * includes/security_check.php — read-only "is this install safe?" report for
 * admins (Fix Guide item 2). Nothing here changes data and no secret value is
 * ever shown: only whether a setting is present or a known-bad one is in use.
 *
 * Each result: ['label' => ..., 'status' => 'ok' | 'warn' | 'fail', 'detail' => ...]
 */

/**
 * SHA-256 fingerprint of the admin password hash that older versions shipped in SQL.
 * Only the fingerprint is kept, so the repository itself no longer contains the
 * old hash. A stored hash is compared by fingerprinting it the same way.
 */
const SHIPPED_ADMIN_HASH_FINGERPRINT = 'c3d4574374e5f863d07b523d2d296097250837ffa01f20b51f383b2d478ff841';

function security_check_results(): array {
    $appRoot = dirname(__DIR__);
    $out = [];
    $add = function (string $label, string $status, string $detail) use (&$out): void {
        $out[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
    };

    // --- Settings ---------------------------------------------------------
    $add('Debug mode is off', env_bool('APP_DEBUG', false) ? 'fail' : 'ok',
        env_bool('APP_DEBUG', false) ? 'APP_DEBUG=true shows error details to visitors. Set it to false in .env.' : 'APP_DEBUG is false.');
    $add('Production mode', strtolower((string) getenv('APP_ENV')) === 'production' ? 'ok' : 'warn',
        strtolower((string) getenv('APP_ENV')) === 'production' ? 'APP_ENV=production.' : 'Set APP_ENV=production in .env on the live site.');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $add('HTTPS enforced', env_bool('ENABLE_HTTPS', false) && $https ? 'ok' : 'warn',
        env_bool('ENABLE_HTTPS', false) ? ($https ? 'ENABLE_HTTPS=true and this page was loaded over HTTPS.' : 'ENABLE_HTTPS=true but this page was not loaded over HTTPS.') : 'Set ENABLE_HTTPS=true in .env once SSL is active.');

    $envAbove = is_file(dirname($appRoot) . '/.env');
    $envInside = is_file($appRoot . '/.env');
    $add('Settings file (.env) is outside the web folder', $envAbove && !$envInside ? 'ok' : 'warn',
        $envAbove && !$envInside ? '.env sits next to public_html.' : ($envInside ? 'A .env file is inside public_html. Move it one level up (next to public_html).' : 'No .env file found; settings must come from the server environment.'));

    $mailReady = GMAIL_EMAIL !== '' && GMAIL_PASSWORD !== '';
    $add('Email credentials come from .env', $mailReady ? 'ok' : 'warn',
        $mailReady ? 'Mail account is configured (value not shown).' : 'No mail account configured, so OTP and reset emails cannot be sent.');

    // --- Accounts ---------------------------------------------------------
    if (database_available()) {
        $users = query_all('SELECT id, email, role, password FROM users');

        $shipped = array_filter($users, fn($u) => hash_equals(SHIPPED_ADMIN_HASH_FINGERPRINT, hash('sha256', (string) ($u['password'] ?? ''))));
        $add('Admin is not using the shipped temporary password', $shipped ? 'fail' : 'ok',
            $shipped ? 'Still using the temporary password from the setup SQL: ' . implode(', ', array_column($shipped, 'email')) . '. Change it now: profile menu > Account & password (or open /settings/).' : 'No account uses the shipped hash.');

        // The old demo password is assembled at run time so it is not written in the
        // source. Only sample/admin accounts are tested (bcrypt is slow on purpose),
        // capped at 40 so this page stays quick.
        $oldDemoPassword = 'demo' . implode('', [1, 2, 3]);
        $candidates = array_filter($users, fn($u) => !empty($u['password'])
            && (($u['role'] ?? '') === 'admin' || preg_match('/@example\.(com|org)$/i', (string) $u['email'])));
        $weak = [];
        foreach (array_slice($candidates, 0, 40) as $u) {
            if (password_verify($oldDemoPassword, $u['password'])) {
                $weak[] = $u['email'];
            }
        }
        $add('No admin or sample account uses the old demo password', $weak ? 'fail' : 'ok',
            $weak ? 'These accounts still accept the old demo password: ' . implode(', ', $weak) . '. Change or delete them.' : 'No admin or sample account accepts the old demo password.');

        $demo = array_filter($users, fn($u) => preg_match('/@example\.com$/i', (string) $u['email']));
        $add('Sample accounts removed', $demo ? 'warn' : 'ok',
            $demo ? count($demo) . ' sample @example.com account(s) still exist (' . implode(', ', array_column($demo, 'email')) . '). Remove them before the demo (item 11).' : 'No @example.com accounts.');

        $admins = array_filter($users, fn($u) => ($u['role'] ?? '') === 'admin' && !empty($u['password']) && !preg_match('/@example\.com$/i', (string) $u['email']));
        $add('A real admin account exists', $admins ? 'ok' : 'fail', $admins ? count($admins) . ' admin account(s) with a password.' : 'No admin with a password and a real email was found.');
    }

    // --- Files left on the server ----------------------------------------
    $leftovers = array_filter(['install_update.php', 'kamalig_update.zip', 'scripts/seed_demo_passwords.php'], fn($f) => is_file($appRoot . '/' . $f));
    $add('No installer or demo-password script left on the server', $leftovers ? 'fail' : 'ok',
        $leftovers ? 'Delete from public_html: ' . implode(', ', $leftovers) . '.' : 'None found.');

    $add('Documents are in private storage', private_storage_is_outside_web_root() && private_storage_legacy_count() === 0 ? 'ok' : 'warn',
        private_storage_legacy_count() > 0 ? private_storage_legacy_count() . ' file(s) still public. Use Admin > Private file storage.' : (private_storage_is_outside_web_root() ? 'Outside the web folder, nothing left in uploads/.' : 'Using the fallback folder inside public_html.'));

    // --- Database structure (a missing migration must be visible, not silent) ---
    if (database_available()) {
        $hasTable = fn(string $t) => query_one('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]) !== null;
        $hasColumn = fn(string $t, string $c) => query_one('SELECT 1 AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]) !== null;
        try {
            $add('Login throttle table exists', $hasTable('login_attempts') ? 'ok' : 'fail',
                $hasTable('login_attempts') ? 'login_attempts is present, so the per-IP throttle works.' : 'login_attempts is missing: the per-IP login throttle is silently OFF. Run: php scripts/migrate_schema.php --apply');
            $due = $hasColumn('payments', 'dueAt');
            $add('Payment deadline column exists', $due ? 'ok' : 'fail',
                $due ? 'payments.dueAt is present.' : 'payments.dueAt is missing: new orders get NO payment deadline. Run: php scripts/migrate_schema.php --apply');
            $consent = $hasColumn('users', 'consent_version') && $hasColumn('users', 'consent_at');
            $add('Consent columns exist', $consent ? 'ok' : 'fail',
                $consent ? 'users.consent_version and users.consent_at are present.' : 'Consent columns are missing: registration cannot record consent. Run: php scripts/migrate_schema.php --apply');
            $fk = query_one('SELECT COUNT(*) AS n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()');
            $fkCount = (int) ($fk['n'] ?? 0);
            $add('Foreign keys are in place', $fkCount >= 8 ? 'ok' : 'warn', $fkCount . ' foreign key(s) found.' . ($fkCount >= 8 ? '' : ' Expected at least 8 (stocks, requests, orders, payments, documents ...).'));
        } catch (Throwable $e) {
            $add('Database structure check', 'warn', 'Could not read the database structure: ' . $e->getMessage());
        }
    }
    $add('Payments are ready', commerce_ready() ? 'ok' : (strtolower((string) getenv('APP_ENV')) === 'production' ? 'fail' : 'warn'),
        commerce_ready() ? 'Cart, checkout and payment tables are present.' : 'Payment tables are missing. In production, accepting orders is blocked until they exist.');

    // --- AI features ---------------------------------------------------------
    $circuit = ai_circuit_state();
    $chain = ai_model_chain();
    $usageToday = ai_usage_today();
    $add('AI is available', $circuit['open'] ? 'fail' : (agent_is_configured() ? 'ok' : 'warn'),
        $circuit['open'] ? 'AI is paused: ' . $circuit['reason'] . '. See Admin > AI status.' : (agent_is_configured() ? 'Anthropic key is set and the circuit breaker is closed.' : 'No Anthropic key: the assistant and AI opinions are off.'));
    $add('A fallback AI model is configured', count($chain) > 1 ? 'ok' : 'warn',
        count($chain) > 1 ? 'Fallback: ' . $chain[1] : 'Only "' . $chain[0] . '" is set. If Anthropic retires it, the AI stops. Set ANTHROPIC_MODEL_FALLBACK.');
    $callLimit = ai_limit('AI_DAILY_CALL_LIMIT', 400);
    $add('AI usage is within today\'s cap', $usageToday['calls'] < $callLimit ? 'ok' : 'warn', $usageToday['calls'] . ' of ' . $callLimit . ' calls today.');

    // --- Scheduled jobs (cron) --------------------------------------------
    $jobs = ['payment_deadline' => ['Payment deadline cron', 3 * 3600, 'every hour'], 'compliance' => ['Compliance cron', 26 * 3600, 'daily'], 'backup' => ['Backup cron', 26 * 3600, 'daily']];
    foreach ($jobs as $key => [$label, $maxAge, $every]) {
        $last = cron_last_run($key);
        $add($label . ' ran recently', $last !== null && (time() - $last) <= $maxAge ? 'ok' : 'warn',
            $last === null ? "Never ran. Add the Hostinger cron job ($every); see README." : 'Last run ' . date('Y-m-d H:i', $last) . (time() - $last <= $maxAge ? '.' : " - older than expected for a job that runs $every."));
    }

    // --- Settings that make features real --------------------------------
    $add('CAPTCHA is on', captcha_enabled() ? 'ok' : 'warn', captcha_enabled() ? 'Turnstile keys are set.' : 'Set TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY in .env so the CAPTCHA is on in production.');
    $add('Dedicated app key (APP_KEY) is set', (string) getenv('APP_KEY') !== '' ? 'ok' : 'warn', (string) getenv('APP_KEY') !== '' ? 'APP_KEY is set (value not shown).' : 'Without APP_KEY, OTP hashing falls back to a key derived from the database login. Set a long random APP_KEY in .env.');
    $contact = function_exists('get_setting') ? trim((string) get_setting('PRIVACY_CONTACT_EMAIL', '')) : '';
    $add('Privacy contact is published', $contact !== '' ? 'ok' : 'warn', $contact !== '' ? 'A privacy contact email is set.' : 'Set PRIVACY_CONTACT_EMAIL (and PRIVACY_OPERATOR_NAME) in Admin settings: RA 10173 requires a named contact.');
    $displayErrors = strtolower((string) ini_get('display_errors'));
    $add('PHP error display is off', in_array($displayErrors, ['', '0', 'off', 'false', 'stderr'], true) ? 'ok' : 'fail', 'display_errors = "' . $displayErrors . '".');
    $errLog = (string) ini_get('error_log');
    $logInside = $errLog !== '' && ($real = realpath(dirname($errLog))) !== false && strpos($real . DIRECTORY_SEPARATOR, realpath($appRoot) . DIRECTORY_SEPARATOR) === 0;
    $add('Error log is outside the web folder', $logInside ? 'warn' : 'ok', $errLog === '' ? 'Using the server default log.' : ($logInside ? 'error_log points inside public_html: move it above public_html.' : 'error_log is outside the web folder.'));
    $lastBackup = @filemtime($appRoot . '/uploads/.last_backup');
    $add('A backup exists and is recent', $lastBackup && (time() - $lastBackup) < 3 * 86400 ? 'ok' : 'warn', $lastBackup ? 'Last backup ' . date('Y-m-d H:i', $lastBackup) . '.' : 'No backup recorded yet.');

    return $out;
}
