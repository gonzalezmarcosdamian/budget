<?php

declare(strict_types=1);

/**
 * Publica en Telegram el menú de comandos.
 *
 *   php bin/comandos.php
 *
 * Se corre después de agregar un comando. El menú es lo primero que ve
 * el usuario al tocar "/": una función que no aparece ahí, para él no
 * existe. La lista canónica vive en Budget\Telegram\Menu.
 */

use Budget\App;
use Budget\Telegram\Menu;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$app = App::crear(dirname(__DIR__));

try {
    $app->telegram()->fijarComandos(Menu::comandos());
} catch (Throwable $e) {
    fwrite(STDERR, 'No se pudo publicar el menú: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Menú publicado:\n";

foreach (Menu::COMANDOS as $comando => $descripcion) {
    printf("  /%-12s %s\n", $comando, $descripcion);
}
