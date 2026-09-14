<?php

declare(strict_types=1);

/**
 * Tareas periódicas. Una corrida por hora alcanza.
 *
 * En cPanel:
 *   0 * * * * /usr/local/bin/php /home/USUARIO/budget/bin/cron.php
 *
 * Hace dos cosas, y la segunda es la que importa: vigilar que el bot
 * siga recibiendo mensajes. Si el firewall del hosting llegara a
 * bloquear a Telegram, el bot deja de funcionar **en silencio** — no hay
 * error ni log, simplemente nadie escribe y uno se entera días después.
 */

use Budget\App;
use Budget\Handler\HealthCheck;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$app = App::crear(dirname(__DIR__));
$silencioso = in_array('--quiet', $argv, true);

function contar(bool $silencioso, string $mensaje): void
{
    if (!$silencioso) {
        echo $mensaje . "\n";
    }
}

// 1. La tabla de idempotencia sólo sirve para la ventana de reintentos
//    de Telegram; más allá de eso es peso muerto.
$purgadas = $app->updates()->purgar();
contar($silencioso, "updates_seen: {$purgadas} filas viejas borradas.");

// 2. Salud del webhook.
try {
    $info = $app->telegram()->infoWebhook();
} catch (Throwable $e) {
    $app->log->excepcion($e, 'cron: consulta de webhook');
    contar($silencioso, 'No se pudo consultar el estado del webhook: ' . $e->getMessage());
    exit(1);
}

$alerta = HealthCheck::alerta($info);

if ($alerta === null) {
    contar($silencioso, 'Webhook sano.');
    exit(0);
}

contar($silencioso, "ALERTA: {$alerta}");
$app->log->advertencia('webhook en mal estado', ['pendientes' => (int) ($info['pending_update_count'] ?? 0)]);

$dueno = $app->config->chatIdDelDueno();

if ($dueno === 0) {
    contar($silencioso, 'Sin OWNER_CHAT_ID configurado: no hay a quién avisarle.');
    exit(1);
}

try {
    // Si el problema es que Telegram no puede entregarnos mensajes, esto
    // igual funciona: el envío sale de nuestro servidor hacia Telegram,
    // que es la dirección contraria a la que está rota.
    $app->telegram()->enviarMensaje($dueno, $alerta);
    contar($silencioso, 'Aviso enviado al dueño.');
} catch (Throwable $e) {
    $app->log->excepcion($e, 'cron: aviso al dueño');
    contar($silencioso, 'No se pudo avisar: ' . $e->getMessage());
}

exit(1);
