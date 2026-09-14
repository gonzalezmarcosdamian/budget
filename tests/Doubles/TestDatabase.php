<?php

declare(strict_types=1);

namespace Budget\Tests\Doubles;

use Budget\Database\Connection;
use Budget\Database\Migrator;
use Budget\Support\Config;
use Budget\Support\Env;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Base real para los tests de integración.
 *
 * Los tests unitarios no tocan MySQL, pero el aislamiento entre usuarios
 * y la idempotencia del webhook dependen de cómo se comporta la base
 * (INSERT IGNORE, rowCount, transacciones). Verificarlos con un doble
 * sería verificar el doble.
 *
 * Corre siempre contra una base propia, nunca contra la de desarrollo:
 * `limpiar()` hace TRUNCATE y borraría los gastos que uno acaba de
 * cargar a mano para probar. Un test que destruye datos de trabajo se
 * deja de correr, y una suite que no se corre no protege nada.
 */
final class TestDatabase
{
    /**
     * Sufijo exigido al nombre de la base. Es el cinturón de seguridad:
     * una suite capaz de vaciar una base de producción es un arma
     * cargada, por más cuidado que se tenga al configurarla.
     */
    private const SUFIJO_OBLIGATORIO = '_test';

    private static ?PDO $pdo = null;

    public static function disponible(): bool
    {
        return self::nombreDeBase() !== '';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $env = Env::cargar(null, ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'TEST_DB_NAME']);
        $base = self::exigirBaseDeTests();

        $config = new Config(
            env: $env,
            dsn: sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $env->texto('DB_HOST', '127.0.0.1'),
                $env->entero('DB_PORT', 3306),
                $base
            ),
            dbUsuario: $env->texto('DB_USER', 'root'),
            dbClave: $env->texto('DB_PASS'),
            zona: new DateTimeZone('America/Argentina/Buenos_Aires'),
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
        self::exigirBaseDeTests();
        $pdo = self::pdo();

        foreach (['expenses', 'merchant_rules', 'updates_seen', 'ai_calls', 'budgets', 'recurring', 'users'] as $tabla) {
            $pdo->exec("TRUNCATE TABLE {$tabla}");
        }

        $pdo->exec('DELETE FROM categories WHERE user_id <> 0');
    }

    /**
     * TEST_DB_NAME manda; DB_NAME sólo se acepta si ya es una base de
     * tests, que es el caso de CI, donde no hay base de desarrollo.
     */
    private static function nombreDeBase(): string
    {
        $env = Env::cargar(null, ['DB_NAME', 'TEST_DB_NAME']);
        $explicita = $env->texto('TEST_DB_NAME');

        if ($explicita !== '') {
            return $explicita;
        }

        $principal = $env->texto('DB_NAME');

        return str_ends_with($principal, self::SUFIJO_OBLIGATORIO) ? $principal : '';
    }

    private static function exigirBaseDeTests(): string
    {
        $base = self::nombreDeBase();

        if ($base === '' || !str_ends_with($base, self::SUFIJO_OBLIGATORIO)) {
            throw new RuntimeException(
                'Los tests de integración sólo corren contra una base terminada en "'
                . self::SUFIJO_OBLIGATORIO . '". Configurar TEST_DB_NAME.'
            );
        }

        return $base;
    }
}
