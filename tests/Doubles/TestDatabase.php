<?php

declare(strict_types=1);

namespace Budget\Tests\Doubles;

use Budget\Database\Connection;
use Budget\Database\Migrator;
use Budget\Support\Config;
use Budget\Support\Env;
use PDO;

/**
 * Base real para los tests de integración.
 *
 * Los tests unitarios no tocan MySQL, pero el aislamiento entre usuarios
 * y la idempotencia del webhook dependen de cómo se comporta la base
 * (INSERT IGNORE, rowCount, transacciones). Verificarlos con un doble
 * sería verificar el doble.
 */
final class TestDatabase
{
    private static ?PDO $pdo = null;

    public static function disponible(): bool
    {
        return (string) (getenv('DB_NAME') ?: '') !== '';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $env = Env::cargar(null, ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS']);

        $config = new Config(
            botToken: 'test',
            webhookSecret: 'test',
            dsn: sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $env->texto('DB_HOST', '127.0.0.1'),
                $env->entero('DB_PORT', 3306),
                $env->requerido('DB_NAME')
            ),
            dbUsuario: $env->texto('DB_USER', 'root'),
            dbClave: $env->texto('DB_PASS'),
            zona: new \DateTimeZone('America/Argentina/Buenos_Aires'),
            monedaBase: 'ARS',
            codigoInvitacion: 'test',
            debug: true,
            cuotaMensualIa: 200,
        );

        $pdo = Connection::abrir($config);
        (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrar();

        return self::$pdo = $pdo;
    }

    /**
     * Deja la base como recién migrada. Las categorías semilla
     * (user_id = 0) sobreviven: son parte del esquema, no datos de prueba.
     */
    public static function limpiar(): void
    {
        $pdo = self::pdo();

        foreach (['expenses', 'merchant_rules', 'updates_seen', 'ai_calls', 'budgets', 'recurring', 'users'] as $tabla) {
            $pdo->exec("TRUNCATE TABLE {$tabla}");
        }

        $pdo->exec('DELETE FROM categories WHERE user_id <> 0');
    }
}
