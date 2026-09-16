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

    /** Para consultas puntuales de otras piezas, sin duplicar la conexión. */
    public function pdo(): PDO
    {
        return $this->pdo;
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
                (user_id, tipo, naturaleza, monto, moneda, monto_ars, fecha, comercio,
                 descripcion, category_id, medio_pago, fuente, confianza, modelo,
                 estado, lote, origen_externo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        try {
            $sentencia->execute([
                $userId,
                $borrador->tipo,
                $borrador->naturaleza,
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

    /**
     * Enviado y recibido con cada contraparte en el período.
     *
     * Es la única forma honesta de mirar transferencias entre personas:
     * mandarle $2.308.000 a alguien que devolvió $1.847.381 no es un
     * gasto de $2.308.000. Sin netear, el total se infla un 80%.
     *
     * @return list<array{contraparte:string, nombre:string, enviado:Money, recibido:Money, neto:int}>
     */
    public function netoPorContraparte(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $sentencia = $this->pdo->prepare(
            "SELECT e.contraparte,
                    MAX(e.comercio) AS nombre,
                    COALESCE(SUM(CASE WHEN e.tipo = 'gasto' THEN e.monto_ars ELSE 0 END), 0) AS enviado,
                    COALESCE(SUM(CASE WHEN e.tipo = 'ingreso' THEN e.monto_ars ELSE 0 END), 0) AS recibido
             FROM expenses e
             LEFT JOIN contrapartes cp
                    ON cp.externo = e.contraparte AND cp.user_id = e.user_id
             WHERE e.user_id = ? AND e.estado = ? AND e.contraparte IS NOT NULL
               AND COALESCE(cp.es_propia, 0) = 0
               AND e.fecha BETWEEN ? AND ?
             GROUP BY e.contraparte
             ORDER BY SUM(CASE WHEN e.tipo = 'gasto' THEN e.monto_ars ELSE -e.monto_ars END) DESC"
        );
        $sentencia->execute([
            $userId,
            self::ESTADO_CONFIRMADO,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);

        return array_map(
            static function (array $f): array {
                $enviado = Money::deDecimal((string) $f['enviado']);
                $recibido = Money::deDecimal((string) $f['recibido']);

                return [
                    'contraparte' => (string) $f['contraparte'],
                    'nombre' => (string) $f['nombre'],
                    'enviado' => $enviado,
                    'recibido' => $recibido,
                    'neto' => $enviado->centavos - $recibido->centavos,
                ];
            },
            $sentencia->fetchAll()
        );
    }

    /**
     * Los gastos más grandes del período, uno por uno.
     *
     * No agrupa por categoría ni por comercio: la pregunta que contesta
     * es "¿qué fue lo más caro que pagué?", y para eso el movimiento
     * suelto es la unidad. Agrupar escondería justamente el que duele.
     *
     * @return list<array<string,mixed>>
     */
    public function mayoresGastos(
        int $userId,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        int $limite = 10,
    ): array {
        $sentencia = $this->pdo->prepare(
            'SELECT e.fecha, e.monto_ars, e.comercio,
                    COALESCE(c.nombre, ?) AS categoria,
                    COALESCE(c.emoji, ?) AS emoji
               FROM expenses e
               LEFT JOIN categories c ON c.id = e.category_id
              WHERE e.user_id = ? AND e.estado = ? AND e.tipo = ?
                AND e.fecha BETWEEN ? AND ?
              ORDER BY e.monto_ars DESC
              LIMIT ' . max(1, min($limite, 50))
        );
        $sentencia->execute([
            'Sin categoría',
            '📦',
            $userId,
            self::ESTADO_CONFIRMADO,
            Draft::TIPO_GASTO,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);

        return array_values($sentencia->fetchAll());
    }

    /**
     * Las contrapartes que más plata movieron en una dirección.
     *
     * En bruto y no en neto, a propósito: el neto contesta "con quién
     * quedé en deuda" y ya lo muestra /mes. Esto contesta "quién me
     * mandó más plata", que es otra pregunta y se arruina al netear.
     *
     * @param string $tipo Draft::TIPO_INGRESO para entrantes, TIPO_GASTO para salientes
     * @return list<array{nombre:string, total:Money, movimientos:int}>
     */
    public function mayoresTransferencias(
        int $userId,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        string $tipo = Draft::TIPO_INGRESO,
        int $limite = 10,
    ): array {
        $sentencia = $this->pdo->prepare(
            'SELECT COALESCE(cp.alias, MAX(e.comercio)) AS nombre,
                    SUM(e.monto_ars) AS total,
                    COUNT(*) AS movimientos
               FROM expenses e
               LEFT JOIN contrapartes cp
                      ON cp.externo = e.contraparte AND cp.user_id = e.user_id
              WHERE e.user_id = ? AND e.estado = ? AND e.tipo = ?
                AND e.contraparte IS NOT NULL
                AND COALESCE(cp.es_propia, 0) = 0
                AND e.fecha BETWEEN ? AND ?
              GROUP BY e.contraparte, cp.alias
              ORDER BY SUM(e.monto_ars) DESC
              LIMIT ' . max(1, min($limite, 50))
        );
        $sentencia->execute([
            $userId,
            self::ESTADO_CONFIRMADO,
            $tipo,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);

        return array_map(
            static fn (array $f): array => [
                'nombre' => (string) $f['nombre'],
                'total' => Money::deDecimal((string) $f['total']),
                'movimientos' => (int) $f['movimientos'],
            ],
            $sentencia->fetchAll()
        );
    }

    /** Cuántos gastos confirmados hay en el período. */
    public function cantidadEntre(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): int
    {
        $sentencia = $this->pdo->prepare(
            'SELECT COUNT(*) FROM expenses
             WHERE user_id = ? AND estado = ? AND tipo = ? AND fecha BETWEEN ? AND ?'
        );
        $sentencia->execute([
            $userId,
            self::ESTADO_CONFIRMADO,
            Draft::TIPO_GASTO,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);

        return (int) $sentencia->fetchColumn();
    }

    /** @return array{comercio:string, monto:Money}|null el gasto más grande del período */
    public function mayorGasto(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): ?array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT comercio, monto_ars FROM expenses
             WHERE user_id = ? AND estado = ? AND tipo = ? AND fecha BETWEEN ? AND ?
             ORDER BY monto_ars DESC LIMIT 1'
        );
        $sentencia->execute([
            $userId,
            self::ESTADO_CONFIRMADO,
            Draft::TIPO_GASTO,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);
        $fila = $sentencia->fetch();

        if ($fila === false) {
            return null;
        }

        return [
            'comercio' => (string) $fila['comercio'],
            'monto' => Money::deDecimal((string) $fila['monto_ars']),
        ];
    }

    /** Lo gastado en una categoría dentro del período, para el acumulado de la tarjeta. */
    public function totalDeCategoria(
        int $userId,
        int $categoryId,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
    ): Money {
        $sentencia = $this->pdo->prepare(
            'SELECT COALESCE(SUM(monto_ars), 0) FROM expenses
             WHERE user_id = ? AND estado = ? AND tipo = ? AND category_id = ?
               AND fecha BETWEEN ? AND ?'
        );
        $sentencia->execute([
            $userId,
            self::ESTADO_CONFIRMADO,
            Draft::TIPO_GASTO,
            $categoryId,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);

        return Money::deDecimal((string) $sentencia->fetchColumn());
    }

    /**
     * Cuánto del gasto del período es fijo y cuánto variable.
     *
     * @return array<string,Money>
     */
    public function gastoPorNaturaleza(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT naturaleza, SUM(monto_ars) AS total
             FROM expenses
             WHERE user_id = ? AND estado = ? AND tipo = ? AND fecha BETWEEN ? AND ?
             GROUP BY naturaleza'
        );
        $sentencia->execute([
            $userId,
            self::ESTADO_CONFIRMADO,
            Draft::TIPO_GASTO,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);

        $porNaturaleza = [];

        foreach ($sentencia->fetchAll() as $fila) {
            $porNaturaleza[(string) $fila['naturaleza']] = Money::deDecimal((string) $fila['total']);
        }

        return $porNaturaleza;
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
            'SELECT e.id, e.monto, e.moneda, e.fecha, e.comercio, e.tipo,
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

    /**
     * Sólo suma gastos. Una compra de CEDEARs o un sueldo que entra no
     * pertenecen al total gastado: mezclarlos hace que el número no
     * signifique nada.
     */
    /**
     * Cuánto de un período es supuesto y no medido.
     *
     * Diez de los dieciocho meses de alquiler se reconstruyeron a partir
     * del IPC, porque se pagaron por otra app. Son la mejor estimación
     * que hay, pero no son un dato: el reporte tiene que poder decirlo
     * en vez de presentarlos como si alguien los hubiera visto.
     */
    public function totalEstimadoEntre(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): Money
    {
        $sentencia = $this->pdo->prepare(
            'SELECT COALESCE(SUM(monto_ars), 0) AS total
             FROM expenses
             WHERE user_id = ? AND estado = ? AND tipo = ? AND fuente = ?
               AND fecha BETWEEN ? AND ?'
        );
        $sentencia->execute([
            $userId,
            self::ESTADO_CONFIRMADO,
            Draft::TIPO_GASTO,
            Draft::FUENTE_ESTIMADO,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);
        $fila = $sentencia->fetch();

        return Money::deDecimal((string) ($fila['total'] ?? '0'));
    }

    /**
     * La categoría a la que van a parar los movimientos que nadie
     * clasificó. No es un destino, es una lista de pendientes.
     */
    public const CATEGORIA_OTROS = 'Otros';

    /**
     * Movimientos confirmados que quedaron sin clasificar de verdad.
     *
     * Los de Mercado Pago entran ya confirmados y sin tarjeta, así que
     * los que el categorizador no supo ubicar quedaban sin categoría o
     * en "Otros" para siempre, sin forma de arreglarlos desde el bot.
     * Son 248 movimientos y $51 millones: el reporte contesta "en qué se
     * te va la plata" con "en Otros", que no es una respuesta.
     *
     * Van de mayor a menor: clasificar el de $850.000 mueve la aguja y
     * el de $400 no, y la paciencia para esto es finita.
     *
     * @return list<array<string,mixed>>
     */
    public function sinCategorizar(int $userId, int $limite = 1): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT e.*
               FROM expenses e
               LEFT JOIN categories c ON c.id = e.category_id
              WHERE e.user_id = ? AND e.estado = ? AND e.tipo = ?
                AND (e.category_id IS NULL OR c.nombre = ?)
              ORDER BY e.monto_ars DESC
              LIMIT ' . max(1, min($limite, 50))
        );
        $sentencia->execute([$userId, self::ESTADO_CONFIRMADO, Draft::TIPO_GASTO, self::CATEGORIA_OTROS]);

        return array_values($sentencia->fetchAll());
    }

    /** @return array{cuantos:int, total:Money} lo que falta clasificar */
    public function cuantoFaltaCategorizar(int $userId): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT COUNT(*) AS cuantos, COALESCE(SUM(e.monto_ars), 0) AS total
               FROM expenses e
               LEFT JOIN categories c ON c.id = e.category_id
              WHERE e.user_id = ? AND e.estado = ? AND e.tipo = ?
                AND (e.category_id IS NULL OR c.nombre = ?)'
        );
        $sentencia->execute([$userId, self::ESTADO_CONFIRMADO, Draft::TIPO_GASTO, self::CATEGORIA_OTROS]);
        $f = $sentencia->fetch() ?: [];

        return [
            'cuantos' => (int) ($f['cuantos'] ?? 0),
            'total' => Money::deDecimal((string) ($f['total'] ?? '0')),
        ];
    }

    /** La categoría de la plata que se presta y vuelve. */
    public const CATEGORIA_PRESTAMOS = 'Préstamos y ayuda';

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
     * solaparse: fijos + consumo + prestado + invertido + aPropio suma
     * exactamente lo que salió, y por eso los porcentajes cierran. Mover
     * plata a una cuenta propia va en su propio balde y no en consumo,
     * que era lo que hacía decir "gastaste $200.000" y "consumiste
     * $5.200.000" en el mismo mensaje.
     *
     * @return array{entroTerceros:Money, entroPropio:Money,
     *               salioTerceros:Money, salioPropio:Money,
     *               gastoReal:int, consumo:Money, fijos:Money,
     *               prestado:Money, invertido:Money, aPropio:Money}
     */
    public function flujoDeCaja(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
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
                COALESCE(SUM(CASE WHEN e.tipo <> :ingreso5 AND COALESCE(cp.es_propia,0) = 1
                                  THEN e.monto_ars END), 0) AS aPropio,
                COALESCE(SUM(CASE WHEN e.tipo = :gasto AND COALESCE(cp.es_propia,0) = 0
                                   AND c.nombre = :prestamos
                                  THEN e.monto_ars END), 0) AS prestado,
                COALESCE(SUM(CASE WHEN e.tipo = :gasto2 AND COALESCE(cp.es_propia,0) = 0
                                   AND COALESCE(c.nombre,'') <> :prestamos2
                                   AND e.naturaleza = :fijo THEN e.monto_ars END), 0) AS fijos,
                COALESCE(SUM(CASE WHEN e.tipo = :gasto3 AND COALESCE(cp.es_propia,0) = 0
                                   AND COALESCE(c.nombre,'') <> :prestamos3
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
            'gasto' => Draft::TIPO_GASTO,
            'gasto2' => Draft::TIPO_GASTO,
            'gasto3' => Draft::TIPO_GASTO,
            'prestamos' => self::CATEGORIA_PRESTAMOS,
            'prestamos2' => self::CATEGORIA_PRESTAMOS,
            'prestamos3' => self::CATEGORIA_PRESTAMOS,
            'fijo' => Draft::NATURALEZA_FIJO,
            'fijo2' => Draft::NATURALEZA_FIJO,
            'usuario' => $userId,
            'estado' => self::ESTADO_CONFIRMADO,
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
            'gastoReal' => $this->gastoRealEntre($userId, $desde, $hasta),
            'consumo' => $plata('consumo'),
            'fijos' => $plata('fijos'),
            'prestado' => $plata('prestado'),
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
    private function gastoRealEntre(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): int
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
            'estado' => self::ESTADO_CONFIRMADO,
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
            self::ESTADO_CONFIRMADO,
            Draft::TIPO_GASTO,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);

        return $conPersonas->centavos
            + Money::deDecimal((string) ($comercios->fetchColumn() ?: '0'))->centavos;
    }

    /**
     * El total de cada mes dentro de un rango arbitrario.
     *
     * El rango puede cruzar diciembre, que es el caso normal de "los
     * últimos doce meses" y de cualquier trimestre entre noviembre y
     * febrero.
     *
     * @return array<string,Money> "2026-08" => total, en orden
     */
    public function totalPorMesEntre(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $sentencia = $this->pdo->prepare(
            "SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, SUM(monto_ars) AS total
               FROM expenses
              WHERE user_id = ? AND estado = ? AND tipo = ?
                AND fecha BETWEEN ? AND ?
              GROUP BY DATE_FORMAT(fecha, '%Y-%m')
              ORDER BY mes"
        );
        $sentencia->execute([
            $userId,
            self::ESTADO_CONFIRMADO,
            Draft::TIPO_GASTO,
            $desde->format('Y-m-d'),
            $hasta->format('Y-m-d'),
        ]);

        $porMes = [];

        foreach ($sentencia->fetchAll() as $f) {
            $porMes[(string) $f['mes']] = Money::deDecimal((string) $f['total']);
        }

        return $porMes;
    }

    public function totalEntre(
        int $userId,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        string $tipo = Draft::TIPO_GASTO,
    ): Money {
        $sentencia = $this->pdo->prepare(
            'SELECT COALESCE(SUM(monto_ars), 0) AS total
             FROM expenses
             WHERE user_id = ? AND estado = ? AND tipo = ? AND fecha BETWEEN ? AND ?'
        );
        $sentencia->execute([
            $userId,
            self::ESTADO_CONFIRMADO,
            $tipo,
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
             WHERE e.user_id = ? AND e.estado = ? AND e.tipo = ? AND e.fecha BETWEEN ? AND ?
             GROUP BY categoria, emoji
             ORDER BY SUM(e.monto_ars) DESC'
        );
        $sentencia->execute([
            'Sin categoría',
            '📦',
            $userId,
            self::ESTADO_CONFIRMADO,
            Draft::TIPO_GASTO,
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
            'SELECT e.id, e.monto, e.moneda, e.fecha, e.comercio, e.tipo,
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
