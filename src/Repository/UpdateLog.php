<?php

declare(strict_types=1);

namespace Budget\Repository;

use PDO;

/**
 * Idempotencia del webhook.
 *
 * Telegram reintenta los updates que no responden rápido. Sin este
 * registro, un reintento duplica el gasto: es el bug más caro del
 * proyecto y el más barato de prevenir.
 */
final class UpdateLog
{
    private const DIAS_DE_RETENCION = 3;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Reserva el update de forma atómica. Devuelve true sólo la primera
     * vez que se ve ese id, así dos reintentos simultáneos no pueden
     * procesar ambos: el INSERT lo decide la base, no la aplicación.
     */
    public function reservar(int $updateId): bool
    {
        $sentencia = $this->pdo->prepare('INSERT IGNORE INTO updates_seen (update_id) VALUES (?)');
        $sentencia->execute([$updateId]);

        return $sentencia->rowCount() === 1;
    }

    /** La corre el cron: la tabla sólo sirve para la ventana de reintentos. */
    public function purgar(int $dias = self::DIAS_DE_RETENCION): int
    {
        $sentencia = $this->pdo->prepare(
            'DELETE FROM updates_seen WHERE recibido_en < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $sentencia->execute([$dias]);

        return $sentencia->rowCount();
    }
}
