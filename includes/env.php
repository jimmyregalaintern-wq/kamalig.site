<?php
/**
 * includes/env.php — tiny .env loader (no Composer dependency).
 *
 * Shared hosts such as Hostinger don't let you set server environment
 * variables, so the app's getenv() calls would all come back empty. This file
 * reads KEY=VALUE pairs from a .env file and exposes them via putenv()/$_ENV
 * so every existing getenv() call keeps working unchanged.
 *
 * Lookup order (first file found wins):
 *   1. <one level above the app folder>/.env   <- RECOMMENDED on Hostinger:
 *      lives outside public_html, so it can never be downloaded from the web
 *   2. <app folder>/.env                       <- fallback (blocked by .htaccess)
 *
 * Real environment variables always win over the file.
 */
if (!function_exists('kamalig_load_env')) {
    function kamalig_load_env(): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $appRoot = dirname(__DIR__);
        $candidates = [dirname($appRoot) . '/.env', $appRoot . '/.env'];
        $fromFile = []; // keys set by the file itself (later duplicate lines may override these)
        foreach ($candidates as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                    continue;
                }
                if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                    $quote = $value[0];
                    $end = strrpos($value, $quote);
                    $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
                } else {
                    // strip trailing " # comment" on unquoted values
                    $value = trim(preg_replace('/\s+#.*$/', '', $value));
                }
                if (getenv($key) !== false && !isset($fromFile[$key])) {
                    continue; // a real server env var wins over the file
                }
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $fromFile[$key] = true; // duplicate lines: the LAST one wins
            }
            break;
        }

        // Convenience default: derive the Google redirect URI from APP_URL.
        if ((getenv('GOOGLE_REDIRECT_URI') === false || getenv('GOOGLE_REDIRECT_URI') === '')
            && getenv('APP_URL')) {
            putenv('GOOGLE_REDIRECT_URI=' . rtrim(getenv('APP_URL'), '/') . '/login/google/callback/');
        }
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool {
        $v = getenv($key);
        return $v === false || $v === '' ? $default : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}

kamalig_load_env();
