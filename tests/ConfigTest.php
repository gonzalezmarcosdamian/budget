<?php

declare(strict_types=1);

use Budget\Support\Config;
use Budget\Support\Env;

function envMinimo(array $extra = []): Env
{
    return Env::desdeArray(array_merge([
        'DB_NAME' => 'budget',
        'DB_USER' => 'budget',
    ], $extra));
}

prueba('arranca sin credenciales de Telegram', function (): void {
    // bin/migrate.php no necesita hablar con Telegram. Exigir el token
    // acá obligaría a inventar valores falsos en CI para poder migrar.
    $config = Config::desdeEnv(envMinimo());

    esIgual('mysql:host=localhost;port=3306;dbname=budget;charset=utf8mb4', $config->dsn);
});

prueba('exige el token de Telegram recién al usarlo', function (): void {
    $config = Config::desdeEnv(envMinimo());

    lanza(RuntimeException::class, static fn (): string => $config->botToken(), 'sin token');
    lanza(RuntimeException::class, static fn (): string => $config->webhookSecret(), 'sin secreto');
});

prueba('devuelve el token cuando está configurado', function (): void {
    $config = Config::desdeEnv(envMinimo([
        'TELEGRAM_BOT_TOKEN' => '123:abc',
        'TELEGRAM_WEBHOOK_SECRET' => 'secreto',
    ]));

    esIgual('123:abc', $config->botToken());
    esIgual('secreto', $config->webhookSecret());
});

prueba('falla al arrancar si falta la base de datos', function (): void {
    lanza(
        RuntimeException::class,
        static fn (): Config => Config::desdeEnv(Env::desdeArray([])),
        'DB_NAME es obligatoria'
    );
});

prueba('una clave de IA ausente es cadena vacía, no un error', function (): void {
    // El proveedor sin clave se saltea solo en el Router: no tener Groq
    // configurado no puede impedir que el bot arranque.
    esIgual('', Config::desdeEnv(envMinimo())->claveIa('GROQ_API_KEY'));
});

prueba('aplica los valores por defecto de Argentina', function (): void {
    $config = Config::desdeEnv(envMinimo());

    esIgual('America/Argentina/Buenos_Aires', $config->zona->getName());
    esIgual('ARS', $config->monedaBase);
    esIgual(200, $config->cuotaMensualIa);
});

prueba('el entorno del proceso pisa al archivo .env', function (): void {
    // Es lo que permite que Docker y CI corran sin archivo de secretos.
    $_SERVER['DB_NAME'] = 'desde_el_entorno';

    try {
        $env = Env::cargar(null, ['DB_NAME']);
        esIgual('desde_el_entorno', $env->texto('DB_NAME'));
    } finally {
        unset($_SERVER['DB_NAME']);
    }
});
