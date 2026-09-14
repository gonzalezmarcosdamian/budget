<?php

declare(strict_types=1);

namespace Budget\Integracion;

use Budget\Expense\CategoryGuesser;
use Budget\Handler\ExpenseCard;
use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
use Budget\Repository\MercadoPagoRepository;
use Budget\Repository\UserRepository;
use Budget\Support\Cifrado;
use Budget\Support\Clock;
use Budget\Support\Http;
use Budget\Support\Logger;
use Budget\Telegram\Client;
use DateTimeImmutable;
use Throwable;

/**
 * Trae los pagos nuevos de Mercado Pago y los carga como gastos.
 *
 * Lo corre el cron. A diferencia del resto del bot, acá los movimientos
 * se guardan ya confirmados: el dato no lo interpretó un modelo, viene
 * de una API con importe exacto e identificador estable. El lote permite
 * deshacer la importación entera si algo entró mal.
 */
final class SincronizadorMp
{
    /**
     * Cuánto mirar hacia atrás la primera vez que se vincula una cuenta.
     *
     * Un año, para que los reportes de meses pasados tengan datos desde
     * el arranque en vez de empezar vacíos. Sólo ocurre una vez: después
     * la sincronización va desde la última corrida.
     */
    private const DIAS_PRIMERA_SYNC = 365;

    /** Margen sobre la última sincronización, por si un pago se aprobó tarde. */
    private const HORAS_DE_SOLAPE = 6;

    /** Movimientos por página al pedirle el historial a Mercado Pago. */
    private const POR_PAGINA = 100;

    /** Tope de páginas, para que un historial largo no cuelgue el cron. */
    private const PAGINAS_MAXIMAS = 30;

    public function __construct(
        private readonly MercadoPagoRepository $cuentas,
        private readonly ExpenseRepository $gastos,
        private readonly CategoryRepository $categorias,
        private readonly UserRepository $usuarios,
        private readonly CategoryGuesser $categorizador,
        private readonly Cifrado $cifrado,
        private readonly Client $telegram,
        private readonly Http $http,
        private readonly Clock $reloj,
        private readonly Logger $log,
    ) {
    }

    /** @return array{cuentas:int, gastos:int, fallas:int} */
    public function sincronizarTodas(): array
    {
        $resumen = ['cuentas' => 0, 'gastos' => 0, 'fallas' => 0];

        foreach ($this->cuentas->activas() as $cuenta) {
            $resumen['cuentas']++;

            try {
                $resumen['gastos'] += $this->sincronizarUna($cuenta);
            } catch (Throwable $e) {
                $resumen['fallas']++;
                // Que una cuenta falle no puede frenar a las demás: un
                // token vencido de un usuario dejaría sin sincronizar a
                // todo el resto.
                $this->log->excepcion($e, 'sincronización de Mercado Pago');
            }
        }

        return $resumen;
    }

    /** @param array<string,mixed> $cuenta */
    private function sincronizarUna(array $cuenta): int
    {
        $userId = (int) $cuenta['user_id'];
        $cliente = new MercadoPago(
            $this->cifrado->descifrar((string) $cuenta['token_cifrado']),
            (int) $cuenta['mp_user_id'],
            $this->http
        );

        $ahora = $this->reloj->ahora();
        $desde = $this->desdeCuando($cuenta, $ahora);
        $lote = bin2hex(random_bytes(6));
        $nuevos = 0;

        for ($pagina = 0; $pagina < self::PAGINAS_MAXIMAS; $pagina++) {
            $pagos = $cliente->pagosDesde($desde, self::POR_PAGINA, $pagina * self::POR_PAGINA);

            if ($pagos === []) {
                break;
            }

            // Del más viejo al más nuevo, para que el listado quede en orden.
            foreach (array_reverse($pagos) as $pago) {
                $nuevos += $this->importar($userId, $pago, $lote, $cliente);
            }

            if (count($pagos) < self::POR_PAGINA) {
                break;
            }
        }

        // El lado de entrada: sin esto los numeros mienten, porque una
        // devolucion queda contada como si nunca hubiera vuelto.
        for ($pagina = 0; $pagina < self::PAGINAS_MAXIMAS; $pagina++) {
            $cobros = $cliente->cobrosDesde($desde, self::POR_PAGINA, $pagina * self::POR_PAGINA);

            if ($cobros === []) {
                break;
            }

            foreach (array_reverse($cobros) as $cobro) {
                $nuevos += $this->importarCobro($userId, $cobro, $lote, $cliente);
            }

            if (count($cobros) < self::POR_PAGINA) {
                break;
            }
        }

        $this->cuentas->marcarSync((int) $cuenta['id'], $ahora);

        if ($nuevos > 0) {
            try {
                $this->avisar($userId, $lote, $nuevos);
            } catch (Throwable $e) {
                // Los gastos ya están guardados. Que falle el aviso no
                // puede contarse como sincronización fallida: reportar
                // "0 importados" habiendo importado doce es peor que no
                // reportar nada.
                $this->log->excepcion($e, 'aviso de sincronización de Mercado Pago');
            }
        }

        return $nuevos;
    }

    /**
     * Los movimientos de Mercado Pago se guardan ya confirmados.
     *
     * Es una excepción deliberada a la regla de que el bot nunca guarda
     * a ciegas, y se sostiene porque acá el dato no lo leyó un modelo de
     * una foto borrosa: viene de una API, con importe exacto, fecha e
     * identificador estable. Confirmar de a uno cuarenta movimientos
     * ciertos es fricción sin información.
     *
     * El lote sigue existiendo, para poder deshacer la importación entera.
     *
     * @param array<string,mixed> $pago
     * @return int 1 si se importó, 0 si ya estaba
     */
    private function importar(int $userId, array $pago, string $lote, ?MercadoPago $cliente = null): int
    {
        $borrador = MercadoPago::aBorrador($pago);

        if ($borrador === null) {
            return 0;
        }

        // Para las transferencias se pide el detalle: es la única forma
        // de saber quién cobró, y sin eso todas quedan como "Varios".
        $contraparte = null;

        if ($cliente !== null) {
            try {
                $contraparte = $cliente->contraparteDe($pago);
            } catch (Throwable $e) {
                $this->log->advertencia('no se pudo leer el destinatario', ['pago' => (string) ($pago['id'] ?? '')]);
            }
        }

        if ($contraparte !== null) {
            $alias = $this->aliasDeContraparte($userId, $contraparte);

            if ($alias !== null) {
                $borrador = $borrador->conComercio($alias);
            }
        }

        $categoria = $this->categorizador->adivinar($borrador->comercio);
        $borrador = $borrador->conCategoria($categoria);

        $id = $this->gastos->guardarBorrador(
            $userId,
            $borrador,
            $this->categoriaId($userId, $borrador->comercio, $categoria),
            $lote,
            MercadoPago::referencia($pago),
            ExpenseRepository::ESTADO_CONFIRMADO
        );

        // id 0 significa que ese movimiento ya estaba importado.
        return $id === 0 ? 0 : 1;
    }

    /**
     * @param array<string,mixed> $pago
     * @return int 1 si se importó, 0 si ya estaba o no corresponde
     */
    private function importarCobro(int $userId, array $pago, string $lote, MercadoPago $cliente): int
    {
        $borrador = MercadoPago::aIngreso($pago);

        if ($borrador === null) {
            return 0;
        }

        $contraparte = null;

        try {
            $contraparte = $cliente->contraparteDe($pago);
        } catch (Throwable) {
            $contraparte = null;
        }

        $id = $this->gastos->guardarBorrador(
            $userId,
            $borrador,
            null,
            $lote,
            MercadoPago::referencia($pago),
            ExpenseRepository::ESTADO_CONFIRMADO,
        );

        if ($id !== 0 && $contraparte !== null) {
            $marcar = $this->gastos->pdo()->prepare('UPDATE expenses SET contraparte = ? WHERE id = ?');
            $marcar->execute([$contraparte, $id]);
        }

        return $id === 0 ? 0 : 1;
    }

    /** @param array<string,mixed> $cuenta */
    private function desdeCuando(array $cuenta, DateTimeImmutable $ahora): DateTimeImmutable
    {
        $ultima = (string) ($cuenta['ultima_sync'] ?? '');

        if ($ultima === '') {
            return $ahora->modify('-' . self::DIAS_PRIMERA_SYNC . ' days');
        }

        try {
            // El solape es a propósito: un pago puede aprobarse después
            // de creado, y sin margen se perdería. Los repetidos los
            // filtra el índice único de origen_externo.
            return (new DateTimeImmutable($ultima))->modify('-' . self::HORAS_DE_SOLAPE . ' hours');
        } catch (Throwable) {
            return $ahora->modify('-' . self::DIAS_PRIMERA_SYNC . ' days');
        }
    }

    /** El nombre que el usuario ya le puso a ese destinatario, si lo tiene. */
    private function aliasDeContraparte(int $userId, string $externo): ?string
    {
        $sentencia = $this->gastos->pdo()->prepare(
            'SELECT alias FROM contrapartes WHERE user_id = ? AND externo = ?'
        );
        $sentencia->execute([$userId, $externo]);
        $alias = $sentencia->fetchColumn();

        return $alias === false || $alias === '' ? null : (string) $alias;
    }

    private function categoriaId(int $userId, string $comercio, ?string $categoria): ?int
    {
        $porRegla = $this->categorias->categoriaPorRegla($userId, $comercio);

        if ($porRegla !== null) {
            return $porRegla;
        }

        return $categoria === null ? null : $this->categorias->idPorNombre($userId, $categoria);
    }

    private function avisar(int $userId, string $lote, int $nuevos): void
    {
        $usuario = $this->usuarios->porId($userId);

        if ($usuario === null) {
            return;
        }

        $resumen = $this->gastos->resumenDeLote($userId, $lote, ExpenseRepository::ESTADO_CONFIRMADO);

        $this->telegram->enviarMensaje(
            (int) $usuario['telegram_chat_id'],
            sprintf(
                "💳 <b>Mercado Pago</b>\n\n%d movimientos nuevos — <b>%s</b>\n\n<i>Ya quedaron guardados.</i>",
                $resumen['cantidad'],
                ExpenseCard::escapar($resumen['total']->formatear())
            ),
            ExpenseCard::tecladoDeDeshacer($lote)
        );
    }
}
