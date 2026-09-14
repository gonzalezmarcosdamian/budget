<?php

declare(strict_types=1);

namespace Budget\Repository;

use PDO;

/**
 * Categorías: las semillas compartidas (user_id = 0) y las propias.
 *
 * También guarda las reglas de comercio aprendidas, que son las que
 * hacen que el bot deje de preguntar lo mismo dos veces.
 */
final class CategoryRepository
{
    private const SEMILLA = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int, nombre:string, emoji:string}> */
    public function disponibles(int $userId): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT id, nombre, emoji
             FROM categories
             WHERE user_id IN (?, ?)
             ORDER BY orden, nombre'
        );
        $sentencia->execute([self::SEMILLA, $userId]);

        return array_map(
            static fn (array $f): array => [
                'id' => (int) $f['id'],
                'nombre' => (string) $f['nombre'],
                'emoji' => (string) $f['emoji'],
            ],
            $sentencia->fetchAll()
        );
    }

    public function idPorNombre(int $userId, string $nombre): ?int
    {
        $sentencia = $this->pdo->prepare(
            'SELECT id FROM categories
             WHERE user_id IN (?, ?) AND nombre = ?
             ORDER BY user_id DESC
             LIMIT 1'
        );
        $sentencia->execute([self::SEMILLA, $userId, $nombre]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : (int) $fila['id'];
    }

    public function emoji(int $categoryId): string
    {
        $sentencia = $this->pdo->prepare('SELECT emoji FROM categories WHERE id = ?');
        $sentencia->execute([$categoryId]);
        $fila = $sentencia->fetch();

        return $fila === false ? '' : (string) $fila['emoji'];
    }

    /**
     * Regla aprendida de una corrección del usuario. A partir de acá ese
     * comercio se resuelve sin gastar una sola llamada de IA.
     */
    public function recordarRegla(int $userId, string $comercio, int $categoryId): void
    {
        $patron = mb_strtolower(trim($comercio));

        if ($patron === '') {
            return;
        }

        $sentencia = $this->pdo->prepare(
            'INSERT INTO merchant_rules (user_id, patron, category_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), aciertos = aciertos + 1'
        );
        $sentencia->execute([$userId, $patron, $categoryId]);
    }

    /** La regla propia del usuario gana sobre cualquier palabra clave general. */
    public function categoriaPorRegla(int $userId, string $comercio): ?int
    {
        $patron = mb_strtolower(trim($comercio));

        if ($patron === '') {
            return null;
        }

        $sentencia = $this->pdo->prepare(
            'SELECT category_id FROM merchant_rules WHERE user_id = ? AND patron = ?'
        );
        $sentencia->execute([$userId, $patron]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : (int) $fila['category_id'];
    }
}
