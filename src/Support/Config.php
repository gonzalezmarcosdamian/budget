<?php

declare(strict_types=1);

namespace Budget\Support;

use DateTimeZone;

/**
 * Configuración validada al arranque.
 *
 * Que falte una clave tiene que romper acá y no tres capas más abajo,
 * en medio de un webhook, con el usuario esperando.
 */
final class Config
{
    public function __construct(
        public readonly string $botToken,
        public readonly string $webhookSecret,
        public readonly string $dsn,
        public readonly string $dbUsuario,
        public readonly string $dbClave,
        public readonly DateTimeZone $zona,
        public readonly string $monedaBase,
        public readonly string $codigoInvitacion,
        public readonly bool $debug,
        public readonly int $cuotaMensualIa,
    ) {
    }

    public static function desdeEnv(Env $env): self
    {
        $host = $env->texto('DB_HOST', 'localhost');
        $puerto = $env->entero('DB_PORT', 3306);
        $base = $env->requerido('DB_NAME');

        return new self(
            botToken: $env->requerido('TELEGRAM_BOT_TOKEN'),
            webhookSecret: $env->requerido('TELEGRAM_WEBHOOK_SECRET'),
            dsn: "mysql:host={$host};port={$puerto};dbname={$base};charset=utf8mb4",
            dbUsuario: $env->requerido('DB_USER'),
            dbClave: $env->texto('DB_PASS'),
            zona: new DateTimeZone($env->texto('APP_TIMEZONE', 'America/Argentina/Buenos_Aires')),
            monedaBase: $env->texto('APP_CURRENCY', Money::MONEDA_POR_DEFECTO),
            codigoInvitacion: $env->texto('INVITE_CODE'),
            debug: $env->booleano('APP_DEBUG', false),
            cuotaMensualIa: $env->entero('AI_MONTHLY_QUOTA', 200),
        );
    }
}
