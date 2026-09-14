<?php

declare(strict_types=1);

namespace Budget\Repository;

use Budget\Expense\Draft;
use Budget\Support\Money;
use DateTimeImmutable;
use PDO;
use PDOException;

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

    /**
     * El lote agrupa los gastos de una misma importación de resumen,
     * para poder confirmarlos o descartarlos juntos.
     */
    public function guardarBorrador(
        int $userId,
        Draft $borrador,
        ?int $categoryId,
        ?string $lote = null,
        ?string $origenExterno = null,
        string $estado = self::ESTADO_BORRADOR,
    ): int {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO expenses
                (user_id, monto, moneda, monto_ars, fecha, comercio, descripcion,
                 category_id, medio_pago, fuente, confianza, modelo, estado, lote, origen_externo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        try {
            $sentencia->execute([
                $userId,
                $borrador->monto->aDecimal(),
                $borrador->monto->moneda,
                // Fase 1 opera en pesos; cuando entre el tipo de cambio,
                // este valor se congela al del día del gasto.
                $borrador->monto->aDecimal(),
                $borrador->fecha->format('Y-m-d'),
                $borrador->comercio,
                mb_substr($borrador->descripcion, 0, 255),
                $categoryId,
                $borrador->medioPago,
                $borrador->fuente,
                $borrador->confianza,
                $borrador->modelo,
                $estado,
                $lote,
                $origenExterno,
            ]);
        } catch (PDOException $e) {
            // Violación de uk_expenses_origen: este movimiento ya se
            // importó. Que lo decida la base y no la aplicación es lo
            // que hace la sincronización segura de repetir.
            if ($e->getCode() === '23000') {
                return 0;
            }

            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ¿Ya hay un gasto confirmado igual ese día?
     *
     * Es la defensa al importar un resumen de tarjeta: lo que el usuario
     * fue anotando a mano durante el mes vuelve a aparecer en el PDF, y
     * cargarlo dos veces arruina el total sin que se note.
     *
     * Sólo cuenta contra gastos confirmados: dos borradores pendientes
     * pueden ser dos consumos distintos por el mismo importe.
     */
    public function yaExiste(int $userId, DateTimeImmutable $fecha, string $montoDecimal): bool
    {
        $sentencia = $this->pdo->prepare(
            'SELECT 1 FROM expenses
             WHERE user_id = ? AND estado = ? AND fecha = ? AND monto = ?
             LIMIT 1'
        );
        $sentencia->execute([$userId, self::ESTADO_CONFIRMADO, $fecha->format('Y-m-d'), $montoDecimal]);

        return $sentencia->fetchColumn() !== false;
    }

    /** @return int cuántos se confirmaron */
    public function confirmarLote(int $userId, string $lote): int
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE expenses SET estado = ?
             WHERE user_id = ? AND lote = ? AND estado = ?'
        );
        $sentencia->execute([self::ESTADO_CONFIRMADO, $userId, $lote, self::ESTADO_BORRADOR]);

        return $sentencia->rowCount();
    }

    /**
     * @return int cuántos se descartaron
     *
     * No filtra por estado a propósito: sirve tanto para rechazar una
     * importación pendiente como para deshacer una que se confirmó sola,
     * que es lo que hace la sincronización de Mercado Pago.
     */
    public function descartarLote(int $userId, string $lote): int
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE expenses SET estado = ?
             WHERE user_id = ? AND lote = ? AND estado <> ?'
        );
        $sentencia->execute([self::ESTADO_DESCARTADO, $userId, $lote, self::ESTADO_DESCARTADO]);

        return $sentencia->rowCount();
    }

    /** @return array{cantidad:int, total:Money} */
    public function resumenDeLote(int $userId, string $lote, string $estado = self::ESTADO_BORRADOR): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT COUNT(*) AS cantidad, COALESCE(SUM(monto_ars), 0) AS total
             FROM expenses
             WHERE user_id = ? AND lote = ? AND estado = ?'
        );
        $sentencia->execute([$userId, $lote, $estado]);
        $fila = $sentencia->fetch();

        return [
            'cantidad' => (int) ($fila['cantidad'] ?? 0),
            'total' => Money::deDecimal((string) ($fila['total'] ?? '0')),
        ];
    }

    /** @return list<array<string,mixed>> los gastos pendientes de un lote */
    public function pendientesDeLote(int $userId, string $lote, int $limite = 60): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT e.id, e.monto, e.moneda, e.fecha, e.comercio,
                    COALESCE(c.emoji, ?) AS emoji
             FROM expenses e
             LEFT JOIN categories c ON c.id = e.category_id
             WHERE e.user_id = ? AND e.lote = ? AND e.estado = ?
             ORDER BY e.fecha, e.id
             LIMIT ' . max(1, min($limite, 200))
        );
        $sentencia->execute(['📦', $userId, $lote, self::ESTADO_BORRADOR]);

        return $sentencia->fetchAll();
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
