<?php

declare(strict_types=1);

namespace Budget\Support;

/**
 * Cierra la respuesta HTTP y sigue trabajando.
 *
 * Telegram reintenta los updates que tardan, así que hay que devolver
 * 200 antes de llamar a ningún modelo. En PHP-FPM eso lo resuelve
 * fastcgi_finish_request(); si el hosting no lo expone, degradamos a
 * procesar con la conexión todavía abierta, que funciona igual pero
 * deja al usuario esperando el tilde de "enviado".
 */
final class Background
{
    public static function responderYSeguir(string $cuerpo = 'ok'): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Length: ' . strlen($cuerpo));
            header('Connection: close');
        }

        echo $cuerpo;

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    public static function disponible(): bool
    {
        return function_exists('fastcgi_finish_request');
    }
}
