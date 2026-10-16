<?php
/**
 * scripts/create_admin.php — create the admin account, or reset its password, WITHOUT any
 * password ever being written in a file or chat. CLI only (needs SSH).
 *
 *   php scripts/create_admin.php you@yourdomain.com
 *
 * It asks you to type the password twice (hidden). 12 characters minimum.
 * No SSH? Use "Forgot password" on the sign-in page instead (see HOSTINGER_DEPLOY.md).
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}
require_once __DIR__ . '/../includes/db.php';

$email = strtolower(trim($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/create_admin.php you@yourdomain.com\n");
    exit(1);
}

function ask_hidden(string $prompt): string {
    echo $prompt;
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec');
    if ($hide) {
        @shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($hide) {
        @shell_exec('stty echo');
        echo "\n";
    }
    return rtrim((string) $line, "\r\n");
}

$one = ask_hidden('New password (12+ characters): ');
$two = ask_hidden('Type it again: ');
if ($one !== $two) {
    fwrite(STDERR, "The two entries do not match. Nothing was changed.\n");
    exit(1);
}
if (mb_strlen($one) < 12) {
    fwrite(STDERR, "Too short: use at least 12 characters. Nothing was changed.\n");
    exit(1);
}

try {
    $pdo = db();
    $hash = password_hash($one, PASSWORD_DEFAULT);
    $find = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $find->execute([$email]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $pdo->prepare("UPDATE users SET password = ?, role = 'admin', verified = 1, status = 'approved', otp_verified = 1 WHERE id = ?")
            ->execute([$hash, $existing['id']]);
        echo "Password updated for $email.\n";
    } else {
        $pdo->prepare("INSERT INTO users (id, email, password, name, role, company, verified, status, otp_verified) VALUES (?, ?, ?, 'System Administrator', 'admin', 'KAMALIG', 1, 'approved', 1)")
            ->execute(['admin_' . bin2hex(random_bytes(4)), $email, $hash]);
        echo "Admin account created for $email.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
