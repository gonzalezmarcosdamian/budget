<?php

declare(strict_types=1);

/**
 * Corre el bot en modo polling. SÓLO PARA DESARROLLO.
 *
 *   php bin/polling.php            escucha hasta que lo cortes con Ctrl-C
 *   php bin/polling.php --once     procesa lo pendiente y termina
 *
 * En producción el bot usa webhook: polling necesita un proceso vivo las
 * 24 horas y eso no entra en hosting compartido (decisión 1). Esto
 * existe para poder probar el bot completo desde la máquina de
 * desarrollo, sin dominio, sin certificado y sin haber desplegado nada.
 *
 * Usa el mismo Dispatcher que el webhook, así lo que se prueba acá es el
 * bot de verdad y no una maqueta.
 */

use Budget\App;
use Budget\Telegram\Update;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$app = App::crear(dirname(__DIR__));

// Dos protecciones para que esto no termine corriendo en el servidor.
if (strtolower((string) getenv('APP_ENV')) === 'production') {
    fwrite(STDERR, "polling.php no corre con APP_ENV=production. En producción el bot usa webhook.\n");
    exit(1);
}

$info = $app->telegram()->infoWebhook();

if (trim((string) ($info['url'] ?? '')) !== '') {
    fwrite(STDERR, "Hay un webhook configurado: Telegram no entrega por los dos caminos a la vez.\n");
    fwrite(STDERR, "Para probar en local, primero borralo desde el servidor o con deleteWebhook.\n");
    exit(1);
}

$unaVez = in_array('--once', $argv, true);
$dispatcher = $app->dispatcher();
$updates = $app->updates();
$desde = 0;

echo "Polling activo. Ctrl-C para cortar.\n\n";

do {
    try {
        $lote = $app->telegram()->obtenerUpdates($desde, $unaVez ? 0 : 25);
    } catch (Throwable $e) {
        $app->log->excepcion($e, 'polling: getUpdates');
        fwrite(STDERR, 'Error trayendo updates: ' . $e->getMessage() . "\n");
        exit(1);
    }

    foreach ($lote as $crudo) {
        $desde = max($desde, (int) ($crudo['update_id'] ?? 0) + 1);
        $update = Update::desdeArray($crudo);

        if ($update === null) {
            continue;
        }

        // La misma idempotencia que en producción: si este script se
        // reinicia, no vuelve a cargar gastos ya procesados.
        if (!$updates->reservar($update->updateId)) {
            continue;
        }

        printf(
            "[%s] %-9s chat=%-12d %s\n",
            date('H:i:s'),
            $update->tipo,
            $update->chatId,
            $update->tipo === Update::TIPO_CALLBACK ? $update->callbackData : $update->texto
        );

        try {
            $dispatcher->despachar($update);
        } catch (Throwable $e) {
            $app->log->excepcion($e, 'polling: despacho');
            fwrite(STDERR, '  ! ' . $e->getMessage() . "\n");
        }
    }
} while (!$unaVez);

echo "Listo.\n";
