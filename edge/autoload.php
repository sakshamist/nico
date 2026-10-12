<?php

declare(strict_types=1);

/**
 * Hand-written PSR-4 autoloader.
 *
 * cPanel shared hosting has no composer (see ARCH.md section 1), so the edge
 * application carries zero third-party dependencies. This maps Nico\Edge\* to
 * src/* with no vendor/ directory to upload.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Nico\\Edge\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
