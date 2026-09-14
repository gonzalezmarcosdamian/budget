<?php

declare(strict_types=1);

namespace Budget\Database;

use Budget\Support\Config;
use PDO;

/**
 * Fábrica de la conexión PDO.
 *
 * Prepares reales (no emulados) y excepciones en vez de códigos: las
 * consultas parametrizadas son la única defensa contra inyección SQL y
 * un error silencioso en la base es un gasto perdido.
 */
final class Connection
{
    public static function abrir(Config $config): PDO
    {
        return new PDO(
            $config->dsn,
            $config->dbUsuario,
            $config->dbClave,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );
    }
}
