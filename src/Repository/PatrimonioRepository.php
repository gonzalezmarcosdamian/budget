<?php

declare(strict_types=1);

namespace Budget\Repository;

use Budget\Support\Money;
use DateTimeImmutable;
use PDO;

/**
 * La serie de fotos del portafolio.
 *
 * Los gastos se miden por flujo; las inversiones por stock y su
 * variación. Sin una foto anterior no hay nada que comparar, así que
 * acá lo que se guarda es la serie.
 */
final class PatrimonioRepository
{
    /**
     * Una inversión se mide por rendimiento; una reserva, por cuánta hay.
     *
     * Los dólares quietos no "rinden": mezclarlos con los CEDEARs licúa
     * el rendimiento con plata que está parada a propósito.
     */
    public const CLASE_INVERSION = 'inversion';
    public const CLASE_RESERVA = 'reserva';

    public const ORIGEN_IOL = 'iol';
    public const ORIGEN_MERCADOPAGO = 'mercadopago';
    public const ORIGEN_MANUAL = 'manual';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Guarda la foto del día, reemplazando la que hubiera.
     *
     * Volver a tomarla el mismo día no duplica: corregir una foto mal
     * tomada tiene que ser posible sin borrar a mano.
     *
     * @param list<array{simbolo:string, descripcion:string, tipo:string,
     *                   origen?:string, clase?:string,
     *                   cantidad:float, precio:float, valor:float}> $posiciones
     */
    public function guardar(
        int $userId,
        DateTimeImmutable $fecha,
        array $posiciones,
        string $fuente = 'iol',
    ): int {
        $total = 0.0;

        foreach ($posiciones as $p) {
            $total += $p['valor'];
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo
                ->prepare('DELETE FROM patrimonio_snapshot WHERE user_id = ? AND fecha = ?')
                ->execute([$userId, $fecha->format('Y-m-d')]);

            $alta = $this->pdo->prepare(
                'INSERT INTO patrimonio_snapshot (user_id, fecha, total_ars, fuente)
                 VALUES (?, ?, ?, ?)'
            );
            $alta->execute([$userId, $fecha->format('Y-m-d'), number_format($total, 2, '.', ''), $fuente]);
            $snapshotId = (int) $this->pdo->lastInsertId();

            $posicion = $this->pdo->prepare(
                'INSERT INTO patrimonio_posicion
                    (snapshot_id, simbolo, descripcion, tipo, origen, clase,
                     cantidad, precio_ars, valor_ars)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($posiciones as $p) {
                $posicion->execute([
                    $snapshotId,
                    $p['simbolo'],
                    mb_substr($p['descripcion'], 0, 120),
                    mb_substr($p['tipo'], 0, 30),
                    $p['origen'] ?? self::ORIGEN_IOL,
                    $p['clase'] ?? self::CLASE_INVERSION,
                    number_format($p['cantidad'], 4, '.', ''),
                    number_format($p['precio'], 4, '.', ''),
                    number_format($p['valor'], 2, '.', ''),
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $snapshotId;
    }

    /** La foto más reciente, o null si no hay ninguna. */
    public function ultimo(int $userId): ?array
    {
        return $this->unoConPosiciones(
            'SELECT * FROM patrimonio_snapshot WHERE user_id = ? ORDER BY fecha DESC LIMIT 1',
            [$userId]
        );
    }

    /**
     * La última foto anterior al mes de una fecha dada.
     *
     * El corte es el mes y no "hace treinta días", que fue el primer
     * intento y se rompía solo: con una foto el 20 de agosto y otra el
     * 15 de septiembre, "hace un mes" cae el 15 de agosto y la de agosto
     * queda afuera. El bot habría dicho "es la primera foto" teniendo
     * una del mes anterior.
     *
     * Tampoco sirve la más cercana en cualquier dirección: comparar el
     * 15 de septiembre contra el 10 de septiembre no es un mes, y daría
     * una variación mensual falsa.
     */
    public function delMesAnterior(int $userId, DateTimeImmutable $fecha): ?array
    {
        $primeroDelMes = $fecha->modify('first day of this month')->setTime(0, 0);

        return $this->unoConPosiciones(
            'SELECT * FROM patrimonio_snapshot
              WHERE user_id = ? AND fecha < ?
              ORDER BY fecha DESC LIMIT 1',
            [$userId, $primeroDelMes->format('Y-m-d')]
        );
    }

    /** @param list<mixed> $parametros */
    private function unoConPosiciones(string $sql, array $parametros): ?array
    {
        $sentencia = $this->pdo->prepare($sql);
        $sentencia->execute($parametros);
        $fila = $sentencia->fetch();

        if ($fila === false) {
            return null;
        }

        $posiciones = $this->pdo->prepare(
            'SELECT * FROM patrimonio_posicion WHERE snapshot_id = ? ORDER BY valor_ars DESC'
        );
        $posiciones->execute([(int) $fila['id']]);

        $porSimbolo = [];

        foreach ($posiciones->fetchAll() as $p) {
            $porSimbolo[(string) $p['simbolo']] = [
                'simbolo' => (string) $p['simbolo'],
                'descripcion' => (string) $p['descripcion'],
                'tipo' => (string) $p['tipo'],
                'origen' => (string) $p['origen'],
                'clase' => (string) $p['clase'],
                'cantidad' => (float) $p['cantidad'],
                'precio' => (float) $p['precio_ars'],
                'valor' => Money::deDecimal((string) $p['valor_ars']),
            ];
        }

        return [
            'id' => (int) $fila['id'],
            'fecha' => new DateTimeImmutable((string) $fila['fecha']),
            'total' => Money::deDecimal((string) $fila['total_ars']),
            'fuente' => (string) $fila['fuente'],
            'posiciones' => $porSimbolo,
        ];
    }
}
