<?php

declare(strict_types=1);

namespace Budget\Database;

use PDO;
use RuntimeException;

/**
 * Migraciones versionadas en archivos .sql planos.
 *
 * Sin framework ni Composer: un directorio ordenado por nombre y una
 * tabla que recuerda qué se aplicó. Basta para que CI pueda levantar el
 * esquema desde cero y verificar que las migraciones corren limpias.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directorio,
    ) {
    }

    /**
     * @return list<string> nombres de las migraciones aplicadas en esta corrida
     */
    public function migrar(): array
    {
        $this->asegurarTablaDeControl();
        $aplicadas = $this->yaAplicadas();
        $nuevas = [];

        foreach ($this->archivos() as $archivo) {
            $nombre = basename($archivo);

            if (in_array($nombre, $aplicadas, true)) {
                continue;
            }

            $this->aplicar($archivo, $nombre);
            $nuevas[] = $nombre;
        }

        return $nuevas;
    }

    /** @return list<string> */
    public function pendientes(): array
    {
        $this->asegurarTablaDeControl();
        $aplicadas = $this->yaAplicadas();

        $pendientes = [];

        foreach ($this->archivos() as $archivo) {
            $nombre = basename($archivo);

            if (!in_array($nombre, $aplicadas, true)) {
                $pendientes[] = $nombre;
            }
        }

        return $pendientes;
    }

    private function aplicar(string $archivo, string $nombre): void
    {
        $sql = file_get_contents($archivo);

        if ($sql === false) {
            throw new RuntimeException("No se pudo leer la migración {$nombre}");
        }

        foreach (self::separarSentencias($sql) as $sentencia) {
            $this->pdo->exec($sentencia);
        }

        $registro = $this->pdo->prepare('INSERT INTO migrations (nombre) VALUES (?)');
        $registro->execute([$nombre]);
    }

    /**
     * MySQL no acepta varias sentencias por exec() con prepares reales,
     * así que el archivo se parte en el punto y coma de fin de línea.
     *
     * @return list<string>
     */
    public static function separarSentencias(string $sql): array
    {
        $crudas = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
        $sentencias = [];

        foreach ($crudas as $cruda) {
            $limpia = trim(rtrim(trim($cruda), ';'));

            if ($limpia === '' || self::esSoloComentarios($limpia)) {
                continue;
            }

            $sentencias[] = $limpia;
        }

        return $sentencias;
    }

    private static function esSoloComentarios(string $sentencia): bool
    {
        foreach (explode("\n", $sentencia) as $linea) {
            $linea = trim($linea);

            if ($linea !== '' && !str_starts_with($linea, '--')) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function archivos(): array
    {
        $encontrados = glob($this->directorio . '/*.sql') ?: [];
        sort($encontrados);

        return array_values($encontrados);
    }

    /** @return list<string> */
    private function yaAplicadas(): array
    {
        $filas = $this->pdo->query('SELECT nombre FROM migrations')?->fetchAll() ?: [];

        return array_map(static fn (array $fila): string => (string) $fila['nombre'], $filas);
    }

    private function asegurarTablaDeControl(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                nombre     VARCHAR(160) NOT NULL,
                aplicada_en DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_migrations_nombre (nombre)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
