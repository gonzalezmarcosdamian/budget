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
 * Trae los pagos nuevos de Mercado Pago y los propone como gastos.
 *
 * Lo corre el cron. Los movimientos llegan como borradores agrupados en
 * un lote: el bot nunca guarda a ciegas, ni siquiera cuando el dato
 * viene de una API y no de una foto borrosa.
 */
final class SincronizadorMp
{
    /** Cuánto mirar hacia atrás la primera vez que se vincula una cuenta. */
    private const DIAS_PRIMERA_SYNC = 30;

    /** Margen sobre la última sincronización, por si un pago se aprobó tarde. */
    private const HORAS_DE_SOLAPE = 6;

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
        $pagos = $cliente->pagosDesde($this->desdeCuando($cuenta, $ahora));

        $lote = bin2hex(random_bytes(6));
        $nuevos = 0;

        // Del más viejo al más nuevo, para que el listado quede en orden.
        foreach (array_reverse($pagos) as $pago) {
            $borrador = MercadoPago::aBorrador($pago);

            if ($borrador === null) {
                continue;
            }

            $categoria = $this->categorizador->adivinar($borrador->comercio);
            $borrador = $borrador->conCategoria($categoria);

            $id = $this->gastos->guardarBorrador(
                $userId,
                $borrador,
                $this->categoriaId($userId, $borrador->comercio, $categoria),
                $lote,
                MercadoPago::referencia($pago)
            );

            // id 0 significa que ese movimiento ya estaba importado.
            if ($id !== 0) {
                $nuevos++;
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

        $resumen = $this->gastos->resumenDeLote($userId, $lote);

        $this->telegram->enviarMensaje(
            (int) $usuario['telegram_chat_id'],
            "💳 <b>Mercado Pago</b>\n\n"
            . ExpenseCard::textoDeLote($resumen['cantidad'], $resumen['total'], 0, '', ''),
            ExpenseCard::tecladoDeLote($lote, $resumen['cantidad'])
        );
    }
}
