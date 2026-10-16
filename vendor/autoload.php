<?php
// Minimal PSR-4 autoloader for the bundled PHPMailer (so no `composer install`
// is needed on the host). Running `composer install` later will overwrite this
// file with Composer's own autoloader, which is fine.
spl_autoload_register(function (string $class): void {
    $prefix = 'PHPMailer\\PHPMailer\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/phpmailer/phpmailer/src/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
