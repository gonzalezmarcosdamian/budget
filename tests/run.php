<?php

declare(strict_types=1);

/**
 * Corre toda la suite.
 *
 *   php tests/run.php        unitarios (y de integración si hay base)
 *   php tests/run.php --unit sólo unitarios
 *
 * Los tests de integración se saltean solos cuando no hay MySQL a mano,
 * así que este comando funciona igual en la máquina de desarrollo, en
 * Docker y en CI.
 */

use Budget\Tests\Doubles\TestDatabase;

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/harness.php';

// Dobles primero: los tests los usan al registrarse.
foreach (glob(__DIR__ . '/Doubles/*.php') ?: [] as $doble) {
    require $doble;
}

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $archivo) {
    require $archivo;
}

$soloUnitarios = in_array('--unit', $argv, true);

if (!$soloUnitarios && TestDatabase::disponible()) {
    foreach (glob(__DIR__ . '/Integration/*Test.php') ?: [] as $archivo) {
        require $archivo;
    }
} elseif (!$soloUnitarios) {
    echo "Sin DB_NAME en el entorno: se saltean los tests de integración.\n";
    echo "Para correrlos: docker compose up -d && docker compose exec app php tests/run.php\n\n";
}

exit(correrPruebas());
