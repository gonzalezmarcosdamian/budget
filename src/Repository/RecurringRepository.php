<?php

declare(strict_types=1);

namespace Budget\Repository;

use Budget\Support\Money;
use DateTimeImmutable;
use PDO;

/**
 * Gastos que se repiten todos los meses.
 *
 * Existen porque los más grandes son justamente los que el bot no ve:
 * el alquiler se paga por fuera de todo lo que tiene conectado. Esperar
 * a que aparezcan solos no iba a funcionar nunca, así que el bot los
 * anticipa y pregunta el día que toca.
 */
final class RecurringRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function crear(
        int $userId,
        string $comercio,
        Money $montoEsperado,
        int $diaDelMes,
        ?int $categoryId,
        string $naturaleza = 'fijo',
        string $nota = '',
    ): int {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO recurring
                (user_id, comercio, category_id, naturaleza, nota, monto_esperado, dia_del_mes, proximo_aviso)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $sentencia->execute([
            $userId,
            $comercio,
            $categoryId,
            $naturaleza,
            $nota,
            $montoEsperado->aDecimal(),
            $diaDelMes,
            self::proximaFecha($diaDelMes, new DateTimeImmutable())->format('Y-m-d'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Los que hay que recordar hoy.
     *
     * Se comparan por `proximo_aviso` y no por el día del mes, para que
     * un cron que no corrió un día recupere el aviso al día siguiente en
     * vez de saltearlo hasta el mes que viene.
     *
     * @return list<array<string,mixed>>
     */
    public function vencenHasta(DateTimeImmutable $dia): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT r.*, u.telegram_chat_id, c.nombre AS categoria
             FROM recurring r
             JOIN users u ON u.id = r.user_id
             LEFT JOIN categories c ON c.id = r.category_id
             WHERE r.activo = 1 AND r.proximo_aviso IS NOT NULL AND r.proximo_aviso <= ?
             ORDER BY r.id'
        );
        $sentencia->execute([$dia->format('Y-m-d')]);

        return array_values($sentencia->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function porId(int $userId, int $id): ?array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT r.*, c.nombre AS categoria
             FROM recurring r
             LEFT JOIN categories c ON c.id = r.category_id
             WHERE r.id = ? AND r.user_id = ?'
        );
        $sentencia->execute([$id, $userId]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : $fila;
    }

    /** Corre el aviso al mes siguiente, ya sea porque se cargó o porque se salteó. */
    public function posponerAlMesQueViene(int $userId, int $id, DateTimeImmutable $desde): void
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE recurring SET proximo_aviso = ? WHERE id = ? AND user_id = ?'
        );

        $fila = $this->porId($userId, $id);
        $dia = $fila === null ? (int) $desde->format('j') : (int) $fila['dia_del_mes'];

        $sentencia->execute([
            self::proximaFecha($dia, $desde->modify('first day of next month'))->format('Y-m-d'),
            $id,
            $userId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function activos(int $userId): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT r.*, c.nombre AS categoria, c.emoji
             FROM recurring r
             LEFT JOIN categories c ON c.id = r.category_id
             WHERE r.user_id = ? AND r.activo = 1
             ORDER BY r.dia_del_mes'
        );
        $sentencia->execute([$userId]);

        return array_values($sentencia->fetchAll());
    }

    /**
     * El próximo día N a partir de una fecha.
     *
     * Si el mes no tiene ese día —un 31 en febrero— cae al último día
     * que existe, en vez de desbordar al mes siguiente.
     */
    public static function proximaFecha(int $diaDelMes, DateTimeImmutable $desde): DateTimeImmutable
    {
        $base = $desde->modify('first day of this month')->setTime(0, 0);
        $dia = min(max($diaDelMes, 1), (int) $base->format('t'));
        $candidato = $base->setDate((int) $base->format('Y'), (int) $base->format('n'), $dia);

        if ($candidato >= $desde->setTime(0, 0)) {
            return $candidato;
        }

        $siguiente = $base->modify('first day of next month');
        $dia = min(max($diaDelMes, 1), (int) $siguiente->format('t'));

        return $siguiente->setDate((int) $siguiente->format('Y'), (int) $siguiente->format('n'), $dia);
    }
}
