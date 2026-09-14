<?php

declare(strict_types=1);

namespace Budget\Repository;

use Budget\Expense\Draft;
use Budget\Support\Money;
use DateTimeImmutable;
use PDO;

/**
 * Persistencia de gastos.
 *
 * Toda consulta filtra por user_id acá adentro y no en el llamador: un
 * solo lugar donde equivocarse, y un test que lo verifica. Es la única
 * garantía real de aislamiento entre usuarios.
 */
final class ExpenseRepository
{
    public const ESTADO_BORRADOR = 'borrador';
    public const ESTADO_CONFIRMADO = 'confirmado';
    public const ESTADO_DESCARTADO = 'descartado';

    private const LIMITE_LISTADO = 10;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function guardarBorrador(int $userId, Draft $borrador, ?int $categoryId): int
    {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO expenses
                (user_id, monto, moneda, monto_ars, fecha, comercio, descripcion,
                 category_id, medio_pago, fuente, confianza, modelo, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $sentencia->execute([
            $userId,
            $borrador->monto->aDecimal(),
            $borrador->monto->moneda,
            // Fase 1 opera en pesos; cuando entre el tipo de cambio, este
            // valor se congela al del día del gasto y no se recalcula.
            $borrador->monto->aDecimal(),
            $borrador->fecha->format('Y-m-d'),
            $borrador->comercio,
            mb_substr($borrador->descripcion, 0, 255),
            $categoryId,
            $borrador->medioPago,
            $borrador->fuente,
            $borrador->confianza,
            $borrador->modelo,
            self::ESTADO_BORRADOR,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function vincularMensaje(int $userId, int $expenseId, int $messageId): void
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE expenses SET telegram_message_id = ? WHERE id = ? AND user_id = ?'
        );
        $sentencia->execute([$messageId, $expenseId, $userId]);
    }

    public function confirmar(int $userId, int $expenseId): bool
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE expenses SET estado = ?
             WHERE id = ? AND user_id = ? AND estado = ?'
        );
        $sentencia->execute([self::ESTADO_CONFIRMADO, $expenseId, $userId, self::ESTADO_BORRADOR]);

        return $sentencia->rowCount() === 1;
    }

    public function descartar(int $userId, int $expenseId): bool
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE expenses SET estado = ? WHERE id = ? AND user_id = ?'
        );
        $sentencia->execute([self::ESTADO_DESCARTADO, $expenseId, $userId]);

        return $sentencia->rowCount() === 1;
    }

    public function recategorizar(int $userId, int $expenseId, int $categoryId): bool
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE expenses SET category_id = ? WHERE id = ? AND user_id = ?'
        );
        $sentencia->execute([$categoryId, $expenseId, $userId]);

        return $sentencia->rowCount() === 1;
    }

    /** @return array<string,mixed>|null */
    public function porId(int $userId, int $expenseId): ?array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT e.*, c.nombre AS categoria, c.emoji
             FROM expenses e
             LEFT JOIN categories c ON c.id = e.category_id
             WHERE e.id = ? AND e.user_id = ?'
        );
        $sentencia->execute([$expenseId, $userId]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : $fila;
    }

    public function totalEntre(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): Money
    {
        $sentencia = $this->pdo->prepare(
            'SELECT COALESCE(SUM(monto_ars), 0) AS total
             FROM expenses
             WHERE user_id = ? AND estado = ? AND fecha BETWEEN ? AND ?'
        );
        $sentencia->execute([
            $userId,
            self::ESTADO_CONFIRMADO,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);
        $fila = $sentencia->fetch();

        return Money::deDecimal((string) ($fila['total'] ?? '0'));
    }

    /** @return list<array{categoria:string, emoji:string, total:Money}> */
    public function totalPorCategoria(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT COALESCE(c.nombre, ?) AS categoria,
                    COALESCE(c.emoji, ?) AS emoji,
                    SUM(e.monto_ars) AS total
             FROM expenses e
             LEFT JOIN categories c ON c.id = e.category_id
             WHERE e.user_id = ? AND e.estado = ? AND e.fecha BETWEEN ? AND ?
             GROUP BY categoria, emoji
             ORDER BY SUM(e.monto_ars) DESC'
        );
        $sentencia->execute([
            'Sin categoría',
            '📦',
            $userId,
            self::ESTADO_CONFIRMADO,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);

        return array_map(
            static fn (array $f): array => [
                'categoria' => (string) $f['categoria'],
                'emoji' => (string) $f['emoji'],
                'total' => Money::deDecimal((string) $f['total']),
            ],
            $sentencia->fetchAll()
        );
    }

    /** @return list<array<string,mixed>> */
    public function ultimos(int $userId, int $limite = self::LIMITE_LISTADO): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT e.id, e.monto, e.moneda, e.fecha, e.comercio,
                    COALESCE(c.emoji, ?) AS emoji
             FROM expenses e
             LEFT JOIN categories c ON c.id = e.category_id
             WHERE e.user_id = ? AND e.estado = ?
             ORDER BY e.fecha DESC, e.id DESC
             LIMIT ' . max(1, min($limite, 50))
        );
        $sentencia->execute(['📦', $userId, self::ESTADO_CONFIRMADO]);

        return $sentencia->fetchAll();
    }
}
