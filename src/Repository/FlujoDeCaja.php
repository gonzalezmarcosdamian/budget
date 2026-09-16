<?php

declare(strict_types=1);

namespace Budget\Repository;

use Budget\Expense\Draft;
use Budget\Support\Money;
use DateTimeImmutable;
use PDO;

/**
 * Qué entró, qué salió y adónde fue, en un período.
 *
 * Vive aparte de `ExpenseRepository` por dos razones. La primera es que
 * ese archivo pasó las 800 líneas que el proyecto se puso como techo. La
 * segunda pesa más: acá adentro está casi toda la sutileza conceptual
 * del bot —qué cuenta como gasto, qué es plata propia moviéndose, qué es
 * una devolución y qué un cobro— y eso merece un archivo que se pueda
 * leer entero de una sentada.
 */
final class FlujoDeCaja
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * El flujo de caja del período, separando propio de terceros.
     *
     * El bot no lleva un estado de resultados, lleva **caja**. Pero no
     * toda la caja que se mueve es plata que entra o sale de verdad:
     * pasar plata de Mercado Pago a tu propio banco no es un gasto, es
     * cambiar de bolsillo. Contarlo infla el total sin que se note.
     *
     * De los cuatro baldes sale el número que importa, el gasto real, y
     * ahí está la sutileza que costó encontrar. La primera versión
     * restaba *todos* los ingresos de terceros:
     *
     *     gasto real = salió a terceros − entró de terceros   <- mal
     *
     * Funciona para una devolución —pagás la cena, te devuelven la
     * parte— pero se rompe con un ingreso genuino: un canon mensual de
     * un cliente bajaría el gasto del mes sin que nadie gastara menos.
     * El error aparece porque una devolución y un cobro son lo mismo
     * para la base: plata que entra de alguien.
     *
     * Lo que los distingue es si a esa persona **también le mandaste**.
     * Por eso el neto se calcula por contraparte y con piso en cero. Ver
     * gastoRealEntre().
     *
     * Los cuatro destinos de la salida particionan el total sin
     * solaparse: fijos + consumo + invertido + aPropio suma exactamente
     * lo que salió, y por eso los porcentajes cierran. Mover plata a una
     * cuenta propia va en su propio balde y no en consumo, que era lo
     * que hacía decir "gastaste $200.000" y "consumiste $5.200.000" en
     * el mismo mensaje.
     *
     * @return array{entroTerceros:Money, entroPropio:Money,
     *               salioTerceros:Money, salioPropio:Money,
     *               gastoReal:int, consumo:Money, fijos:Money,
     *               invertido:Money, aPropio:Money}
     */
    public function calcular(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $sentencia = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN e.tipo = :ingreso AND COALESCE(cp.es_propia,0) = 0
                                  THEN e.monto_ars END), 0) AS entro_terceros,
                COALESCE(SUM(CASE WHEN e.tipo = :ingreso2 AND COALESCE(cp.es_propia,0) = 1
                                  THEN e.monto_ars END), 0) AS entro_propio,
                COALESCE(SUM(CASE WHEN e.tipo <> :ingreso3 AND COALESCE(cp.es_propia,0) = 0
                                   AND e.tipo <> :inversion
                                  THEN e.monto_ars END), 0) AS salio_terceros,
                COALESCE(SUM(CASE WHEN e.tipo <> :ingreso4
                                   AND (COALESCE(cp.es_propia,0) = 1 OR e.tipo = :inversion2)
                                  THEN e.monto_ars END), 0) AS salio_propio,
                COALESCE(SUM(CASE WHEN e.tipo = :inversion3 THEN e.monto_ars END), 0) AS invertido,
                COALESCE(SUM(CASE WHEN e.tipo <> :ingreso5 AND e.tipo <> :inversion4
                                   AND COALESCE(cp.es_propia,0) = 1
                                  THEN e.monto_ars END), 0) AS aPropio,
                COALESCE(SUM(CASE WHEN e.tipo = :gasto AND COALESCE(cp.es_propia,0) = 0
                                   AND e.naturaleza = :fijo THEN e.monto_ars END), 0) AS fijos,
                COALESCE(SUM(CASE WHEN e.tipo = :gasto2 AND COALESCE(cp.es_propia,0) = 0
                                   AND e.naturaleza <> :fijo2 THEN e.monto_ars END), 0) AS consumo
             FROM expenses e
             LEFT JOIN categories c ON c.id = e.category_id
             LEFT JOIN contrapartes cp
                    ON cp.externo = e.contraparte AND cp.user_id = e.user_id
             WHERE e.user_id = :usuario AND e.estado = :estado
               AND e.fecha BETWEEN :desde AND :hasta"
        );

        $sentencia->execute([
            'ingreso' => Draft::TIPO_INGRESO,
            'ingreso2' => Draft::TIPO_INGRESO,
            'ingreso3' => Draft::TIPO_INGRESO,
            'ingreso4' => Draft::TIPO_INGRESO,
            'ingreso5' => Draft::TIPO_INGRESO,
            'inversion' => Draft::TIPO_INVERSION,
            'inversion2' => Draft::TIPO_INVERSION,
            'inversion3' => Draft::TIPO_INVERSION,
            'inversion4' => Draft::TIPO_INVERSION,
            'gasto' => Draft::TIPO_GASTO,
            'gasto2' => Draft::TIPO_GASTO,
            'fijo' => Draft::NATURALEZA_FIJO,
            'fijo2' => Draft::NATURALEZA_FIJO,
            'usuario' => $userId,
            'estado' => ExpenseRepository::ESTADO_CONFIRMADO,
            'desde' => $desde->format('Y-m-d'),
            'hasta' => $hasta->format('Y-m-d'),
        ]);

        $f = $sentencia->fetch() ?: [];

        $plata = static fn (string $clave): Money
            => Money::deDecimal((string) ($f[$clave] ?? '0'));

        return [
            'entroTerceros' => $plata('entro_terceros'),
            'entroPropio' => $plata('entro_propio'),
            'salioTerceros' => $plata('salio_terceros'),
            'salioPropio' => $plata('salio_propio'),
            'gastoReal' => $this->gastoReal($userId, $desde, $hasta),
            'consumo' => $plata('consumo'),
            'fijos' => $plata('fijos'),
            'invertido' => $plata('invertido'),
            'aPropio' => $plata('aPropio'),
        ];
    }

    /**
     * Lo que realmente se gastó: el neto con cada persona, más comercios.
     *
     *     gasto real = SUMA por contraparte + comercios
     *
     * Y la suma por contraparte depende de una cosa que **no se puede
     * deducir del movimiento**: si lo que entró es una devolución o un
     * cobro. Para la base son idénticos, plata que entra de alguien.
     *
     * El primer criterio fue "¿también le mandaste?", con piso en cero:
     * a quien le mandaste $100.000 y te devolvió $70.000 te costó
     * $30.000, y a quien te paga todos los meses sin que le mandes nada
     * el piso lo deja en cero, así su plata no descuenta gasto.
     *
     * Falla en un caso real: una amiga que te devuelve su parte de
     * varias cenas que pagaste **vos en el restaurante**. Nunca recibió
     * una transferencia tuya, así que el piso deja su devolución en cero
     * y esas cenas quedan contadas enteras.
     *
     * Por eso `contrapartes.reintegra`: marcada, su plata entrante resta
     * de verdad, aunque el enviado sea cero. Sin marcar, sigue el piso.
     *
     * @return int centavos
     */
    private function gastoReal(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): int
    {
        $sentencia = $this->pdo->prepare(
            "SELECT COALESCE(SUM(
                        CASE WHEN t.reintegra = 1
                             THEN t.enviado - t.recibido
                             ELSE GREATEST(t.enviado - t.recibido, 0) END), 0) AS neto
               FROM (
                 SELECT e.contraparte,
                        MAX(COALESCE(cp.reintegra, 0)) AS reintegra,
                        SUM(CASE WHEN e.tipo = :gasto THEN e.monto_ars ELSE 0 END) AS enviado,
                        SUM(CASE WHEN e.tipo = :ingreso THEN e.monto_ars ELSE 0 END) AS recibido
                   FROM expenses e
                   LEFT JOIN contrapartes cp
                          ON cp.externo = e.contraparte AND cp.user_id = e.user_id
                  WHERE e.user_id = :usuario AND e.estado = :estado
                    AND e.fecha BETWEEN :desde AND :hasta
                    AND e.contraparte IS NOT NULL
                    AND COALESCE(cp.es_propia, 0) = 0
                  GROUP BY e.contraparte
               ) t"
        );
        $sentencia->execute([
            'gasto' => Draft::TIPO_GASTO,
            'ingreso' => Draft::TIPO_INGRESO,
            'usuario' => $userId,
            'estado' => ExpenseRepository::ESTADO_CONFIRMADO,
            'desde' => $desde->format('Y-m-d'),
            'hasta' => $hasta->format('Y-m-d'),
        ]);
        $conPersonas = Money::deDecimal((string) ($sentencia->fetchColumn() ?: '0'));

        // Los comercios no tienen contraparte y no devuelven nada: van
        // enteros.
        $comercios = $this->pdo->prepare(
            'SELECT COALESCE(SUM(monto_ars), 0) FROM expenses
              WHERE user_id = ? AND estado = ? AND tipo = ?
                AND fecha BETWEEN ? AND ? AND contraparte IS NULL'
        );
        $comercios->execute([
            $userId,
            ExpenseRepository::ESTADO_CONFIRMADO,
            Draft::TIPO_GASTO,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);

        return $conPersonas->centavos
            + Money::deDecimal((string) ($comercios->fetchColumn() ?: '0'))->centavos;
    }
}
