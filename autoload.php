<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for using the SDK WITHOUT Composer (examples, quick tests,
 * drop-in into a non-Composer project). In a Composer project just require
 * 'vendor/autoload.php' instead — this file is only a convenience.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'EppTools\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});


