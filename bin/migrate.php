<?php

declare(strict_types=1);

/**
 * Aplica las migraciones pendientes.
 *
 *   php bin/migrate.php          aplica lo que falte
 *   php bin/migrate.php --status muestra qué falta sin tocar nada
 *
 * Corre igual en Docker, en CI y en el hosting. Que sea el mismo comando
 * en los tres lados es lo que hace que un despliegue no sorprenda.
 */

use Budget\App;
use Budget\Database\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$app = App::crear(dirname(__DIR__));
$migrator = new Migrator($app->pdo(), $app->migrationsDir());

if (in_array('--status', $argv, true)) {
    $pendientes = $migrator->pendientes();

    if ($pendientes === []) {
        echo "Sin migraciones pendientes.\n";
        exit(0);
    }

    echo "Pendientes:\n";

    foreach ($pendientes as $nombre) {
        echo "  - {$nombre}\n";
    }

    exit(0);
}

$aplicadas = $migrator->migrar();

if ($aplicadas === []) {
    echo "Nada que aplicar.\n";
    exit(0);
}

foreach ($aplicadas as $nombre) {
    echo "Aplicada: {$nombre}\n";
}

echo count($aplicadas) . " migraciones aplicadas.\n";
