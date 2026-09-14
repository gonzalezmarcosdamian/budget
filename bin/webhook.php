<?php

declare(strict_types=1);

/**
 * Administra el webhook de Telegram.
 *
 *   php bin/webhook.php set https://tudominio.com.ar/webhook.php
 *   php bin/webhook.php info
 *
 * "info" es la verificación post-despliegue: si Telegram reporta
 * last_error_message, el deploy rompió el bot aunque los tests pasen.
 */

use Budget\App;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$app = App::crear(dirname(__DIR__));
$accion = $argv[1] ?? 'info';

if ($accion === 'set') {
    $url = $argv[2] ?? '';

    if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
        fwrite(STDERR, "Hace falta una URL https válida.\n");
        exit(1);
    }

    $app->telegram()->fijarWebhook($url, $app->config->webhookSecret());
    echo "Webhook apuntado a {$url}\n";
    exit(0);
}

$info = $app->telegram()->infoWebhook();

echo 'URL:               ' . ($info['url'] ?? '(ninguna)') . "\n";
echo 'Pendientes:        ' . ($info['pending_update_count'] ?? 0) . "\n";
echo 'Secreto activo:    ' . (($info['has_custom_certificate'] ?? false) ? 'sí' : 'gestionado por Telegram') . "\n";

$ultimoError = (string) ($info['last_error_message'] ?? '');

if ($ultimoError !== '') {
    echo "Último error:      {$ultimoError}\n";
    exit(1);
}

echo "Sin errores recientes.\n";
