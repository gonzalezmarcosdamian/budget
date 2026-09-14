<?php

declare(strict_types=1);

/**
 * Punto de entrada del webhook de Telegram.
 *
 * El orden de estas operaciones no es casual:
 *   1. validar el secreto      -> descarta tráfico falso sin tocar la base
 *   2. reservar el update_id   -> evita duplicar gastos ante un reintento
 *   3. responder 200           -> Telegram deja de esperar
 *   4. recién ahí, procesar    -> puede tardar segundos con IA de por medio
 *
 * Los pasos 1 y 2 van ANTES del 200 a propósito: si la base está caída
 * queremos devolver 500 para que Telegram reintente, no tragarnos el
 * gasto del usuario con un 200 mentiroso.
 */

use Budget\App;
use Budget\Support\Background;
use Budget\Telegram\Update;

require __DIR__ . '/../src/autoload.php';

$raiz = dirname(__DIR__);

if (is_file($raiz . '/maintenance.flag')) {
    http_response_code(503);
    header('Retry-After: 30');
    echo 'actualizando';
    exit;
}

try {
    $app = App::crear($raiz);
} catch (Throwable $e) {
    // Sin configuración no hay log al que escribir.
    http_response_code(500);
    error_log('budget: fallo de arranque: ' . $e->getMessage());
    exit;
}

$secretoRecibido = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if (!hash_equals($app->config->webhookSecret(), (string) $secretoRecibido)) {
    http_response_code(403);
    $app->log->advertencia('webhook con secreto inválido', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
    exit;
}

$cuerpo = file_get_contents('php://input');
$payload = json_decode((string) $cuerpo, true);

if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

$update = Update::desdeArray($payload);

if ($update === null) {
    // Update válido pero de un tipo que no manejamos: 200 y a otra cosa,
    // para que Telegram no lo reintente eternamente.
    http_response_code(200);
    exit;
}

try {
    if (!$app->updates()->reservar($update->updateId)) {
        // Reintento de algo ya procesado.
        http_response_code(200);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    $app->log->excepcion($e, 'reserva del update');
    exit;
}

Background::responderYSeguir();

try {
    $app->dispatcher()->despachar($update);
} catch (Throwable $e) {
    $app->log->excepcion($e, 'despacho del update');
}
