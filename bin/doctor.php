<?php

declare(strict_types=1);

/**
 * Diagnóstico del entorno.
 *
 *   php bin/doctor.php
 *
 * Pensado para correr en el servidor, por SSH o por el "Terminal" de
 * cPanel. En hosting compartido no se ve nada de lo que pasa adentro:
 * este comando contesta, de una, todas las preguntas que si no hay que
 * ir adivinando de a una.
 *
 * Sale con código 1 si algo está roto, así sirve también desde un cron.
 */

use Budget\App;
use Budget\Support\Check;
use Budget\Support\Doctor;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$raiz = dirname(__DIR__);

try {
    $app = App::crear($raiz);
} catch (Throwable $e) {
    echo "No se pudo arrancar la aplicación.\n";
    echo '  ' . $e->getMessage() . "\n\n";
    echo "Suele ser el .env: falta el archivo, o falta una variable obligatoria.\n";
    echo "Comparar con .env.example.\n";
    exit(1);
}

// La conexión y Telegram se resuelven acá y se pasan ya resueltos: el
// diagnóstico tiene que poder informar "no conecta" en vez de morirse.
$pdo = null;

try {
    $pdo = $app->pdo();
} catch (Throwable) {
    $pdo = null;
}

$consultaTelegram = static fn (): array => $app->telegram()->infoWebhook();

$resultados = Doctor::diagnosticar($raiz, $app->config, $pdo, $consultaTelegram);

echo "\nDiagnóstico de budget\n";
echo str_repeat('-', 64) . "\n";

$fallas = 0;

foreach ($resultados as $check) {
    printf("%-6s %-28s %s\n", $check->icono(), $check->nombre, $check->detalle);

    if ($check->remedio !== '') {
        echo str_repeat(' ', 7) . '-> ' . $check->remedio . "\n";
    }

    if ($check->fallo()) {
        $fallas++;
    }
}

echo str_repeat('-', 64) . "\n";

if ($fallas === 0) {
    echo "Todo en orden.\n";
    exit(0);
}

printf("%d verificación(es) en falla. El bot no va a funcionar bien hasta resolverlas.\n", $fallas);
exit(1);
