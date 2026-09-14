<?php

declare(strict_types=1);

namespace Budget\Repository;

use DateTimeImmutable;
use PDO;

/**
 * Las cuentas de Mercado Pago vinculadas.
 *
 * Una por usuario. El token viaja cifrado desde antes de llegar acá: este
 * repositorio no sabe descifrarlo y no tiene por qué.
 */
final class MercadoPagoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function vincular(int $userId, int $mpUserId, string $apodo, string $tokenCifrado): void
    {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO mp_cuentas (user_id, mp_user_id, apodo, token_cifrado)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                mp_user_id = VALUES(mp_user_id),
                apodo = VALUES(apodo),
                token_cifrado = VALUES(token_cifrado),
                activo = 1'
        );
        $sentencia->execute([$userId, $mpUserId, $apodo, $tokenCifrado]);
    }

    /** @return array<string,mixed>|null */
    public function porUsuario(int $userId): ?array
    {
        $sentencia = $this->pdo->prepare('SELECT * FROM mp_cuentas WHERE user_id = ? AND activo = 1');
        $sentencia->execute([$userId]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Las cuentas que el cron tiene que sincronizar.
     *
     * @return list<array<string,mixed>>
     */
    public function activas(): array
    {
        $filas = $this->pdo->query('SELECT * FROM mp_cuentas WHERE activo = 1 ORDER BY id')?->fetchAll() ?: [];

        return array_values($filas);
    }

    public function marcarSync(int $id, DateTimeImmutable $cuando): void
    {
        $sentencia = $this->pdo->prepare('UPDATE mp_cuentas SET ultima_sync = ? WHERE id = ?');
        $sentencia->execute([$cuando->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * Apagar la cuenta en vez de borrarla: si el token dejó de servir,
     * conviene saber que existió para poder avisarle al usuario.
     */
    public function desactivar(int $userId): bool
    {
        $sentencia = $this->pdo->prepare('UPDATE mp_cuentas SET activo = 0 WHERE user_id = ?');
        $sentencia->execute([$userId]);

        return $sentencia->rowCount() === 1;
    }
}
