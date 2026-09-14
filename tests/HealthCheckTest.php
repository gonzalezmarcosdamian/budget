<?php

declare(strict_types=1);

use Budget\Handler\HealthCheck;

/**
 * El bot puede dejar de recibir mensajes sin producir un solo error en
 * el servidor. Estos tests cubren cada forma en que eso se manifiesta en
 * getWebhookInfo, porque es la única superficie donde se ve.
 */

prueba('un webhook sano no genera aviso', function (): void {
    esNulo(HealthCheck::alerta([
        'url' => 'https://bot.marcosdamiangonzalez.ar/webhook.php',
        'pending_update_count' => 0,
        'last_error_message' => '',
    ]));
});

prueba('unos pocos pendientes son normales entre corridas del cron', function (): void {
    esNulo(HealthCheck::alerta([
        'url' => 'https://bot.marcosdamiangonzalez.ar/webhook.php',
        'pending_update_count' => 3,
    ]));
});

prueba('sin webhook configurado avisa y dice cómo arreglarlo', function (): void {
    $alerta = HealthCheck::alerta(['url' => '']);

    noEsNulo($alerta);
    afirmar(str_contains((string) $alerta, 'bin/webhook.php set'), 'incluye el comando');
});

prueba('un timeout se lee como bloqueo del firewall', function (): void {
    // Es el escenario más probable en este hosting y el más difícil de
    // diagnosticar a las tres de la mañana.
    $alerta = HealthCheck::alerta([
        'url' => 'https://bot.marcosdamiangonzalez.ar/webhook.php',
        'last_error_message' => 'Connection timed out',
        'pending_update_count' => 47,
    ]);

    noEsNulo($alerta);
    afirmar(str_contains((string) $alerta, 'firewall'), 'nombra la causa probable');
    afirmar(str_contains((string) $alerta, 'una persona'), 'y que lo destraba un humano');
});

prueba('un problema de certificado se distingue de un bloqueo', function (): void {
    $alerta = (string) HealthCheck::alerta([
        'url' => 'https://bot.marcosdamiangonzalez.ar/webhook.php',
        'last_error_message' => 'SSL error: certificate has expired',
    ]);

    afirmar(str_contains($alerta, 'certificado'), 'apunta al certificado');
    afirmar(!str_contains($alerta, 'firewall'), 'y no confunde con el firewall');
});

prueba('un 404 apunta al despliegue', function (): void {
    $alerta = (string) HealthCheck::alerta([
        'url' => 'https://bot.marcosdamiangonzalez.ar/webhook.php',
        'last_error_message' => 'Wrong response from the webhook: 404 Not Found',
    ]);

    afirmar(str_contains($alerta, 'despliegue'), 'el deploy movió o borró el archivo');
});

prueba('una cola grande avisa aunque no haya error reportado', function (): void {
    $alerta = HealthCheck::alerta([
        'url' => 'https://bot.marcosdamiangonzalez.ar/webhook.php',
        'pending_update_count' => 120,
        'last_error_message' => '',
    ]);

    noEsNulo($alerta, 'el webhook contesta pero algo lo frena');
});

prueba('el umbral de pendientes es configurable', function (): void {
    $info = ['url' => 'https://bot.marcosdamiangonzalez.ar/webhook.php', 'pending_update_count' => 5];

    esNulo(HealthCheck::alerta($info, 10), 'por debajo del umbral');
    noEsNulo(HealthCheck::alerta($info, 5), 'justo en el umbral ya avisa');
});

prueba('escapa el mensaje de error de Telegram', function (): void {
    // El texto viene de afuera y va a un mensaje con parse_mode HTML.
    $alerta = (string) HealthCheck::alerta([
        'url' => 'https://bot.marcosdamiangonzalez.ar/webhook.php',
        'last_error_message' => 'roto <b>falso</b> & raro',
    ]);

    afirmar(!str_contains($alerta, '<b>falso</b>'), 'no deja pasar HTML inyectado');
    afirmar(str_contains($alerta, '&amp;'), 'escapa el ampersand');
});

prueba('un campo ausente no rompe el chequeo', function (): void {
    // getWebhookInfo omite last_error_message cuando nunca hubo error.
    esNulo(HealthCheck::alerta(['url' => 'https://bot.marcosdamiangonzalez.ar/webhook.php']));
});
