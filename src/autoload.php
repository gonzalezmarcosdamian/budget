<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 mínimo: Budget\Foo\Bar -> src/Foo/Bar.php
 *
 * El proyecto no tiene dependencias de runtime a propósito. En hosting
 * compartido no hay Composer garantizado, y sin vendor/ el despliegue es
 * una copia de archivos y nada más.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Budget\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
