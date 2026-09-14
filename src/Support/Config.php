<?php

declare(strict_types=1);

namespace Budget\Support;

use DateTimeZone;

/**
 * Configuración de la aplicación.
 *
 * Lo que toda entrada necesita (base de datos, zona, moneda) se valida
 * al construirla: que falte tiene que romper acá y no tres capas más
 * abajo, en medio de un webhook, con el usuario esperando.
 *
 * Lo que sólo necesitan algunas entradas (token de Telegram, claves de
 * IA) se valida al usarse. Migrar la base no requiere credenciales de
 * Telegram, y exigirlas obligaría a inventar valores falsos en CI para
 * que un comando arranque.
 */
final class Config
{
    private const MODELOS_GEMINI_POR_DEFECTO = 'gemini-3.5-flash-lite';

    public function __construct(
        private readonly Env $env,
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
            env: $env,
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

    public function botToken(): string
    {
        return $this->env->requerido('TELEGRAM_BOT_TOKEN');
    }

    public function webhookSecret(): string
    {
        return $this->env->requerido('TELEGRAM_WEBHOOK_SECRET');
    }

    /** Cadena vacía cuando no está configurada: el proveedor se saltea solo. */
    public function claveIa(string $variable): string
    {
        return $this->env->texto($variable);
    }

    /**
     * Modelos de Gemini en orden de preferencia.
     *
     * La cadena de respaldo no es sólo entre proveedores: en capa
     * gratuita los flash grandes devuelven 503 "high demand" seguido,
     * así que un 503 en el primero tiene que bajar al siguiente modelo
     * del mismo proveedor.
     *
     * @return list<string>
     */
    public function modelosGemini(): array
    {
        $crudo = $this->env->texto('GEMINI_MODELS', self::MODELOS_GEMINI_POR_DEFECTO);

        $modelos = array_values(array_filter(
            array_map(trim(...), explode(',', $crudo)),
            static fn (string $modelo): bool => $modelo !== ''
        ));

        return $modelos === [] ? [self::MODELOS_GEMINI_POR_DEFECTO] : $modelos;
    }
}
