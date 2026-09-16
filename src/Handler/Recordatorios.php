<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Expense\Draft;
use Budget\Repository\ExpenseRepository;
use Budget\Repository\RecurringRepository;
use Budget\Support\Clock;
use Budget\Support\Logger;
use Budget\Support\Money;
use Budget\Telegram\Client;
use Budget\Telegram\Keyboard;
use Budget\Telegram\Update;
use Throwable;

/**
 * Le recuerda al usuario los gastos que se repiten.
 *
 * Los gastos más grandes son justamente los que el bot no ve: el
 * alquiler se paga por fuera de todo lo que tiene conectado. Esperar a
 * que aparezcan solos no iba a funcionar nunca.
 *
 * Anticipar en vez de esperar es lo que convierte un registro pasivo en
 * algo que sirve.
 */
final class Recordatorios
{
    public const ACCION_CARGAR = 'rok';
    public const ACCION_SALTEAR = 'rno';

    public function __construct(
        private readonly RecurringRepository $recurrentes,
        private readonly ExpenseRepository $gastos,
        private readonly Client $telegram,
        private readonly Clock $reloj,
        private readonly Logger $log,
    ) {
    }

    /** @return int cuántos recordatorios se enviaron */
    public function enviarPendientes(): int
    {
        $hoy = $this->reloj->ahora();
        $enviados = 0;

        foreach ($this->recurrentes->vencenHasta($hoy) as $r) {
            try {
                $this->telegram->enviarMensaje(
                    (int) $r['telegram_chat_id'],
                    self::texto($r),
                    self::teclado((int) $r['id'])
                );
                $enviados++;
            } catch (Throwable $e) {
                // Que falle un aviso no puede frenar a los demás.
                $this->log->excepcion($e, 'recordatorio de gasto recurrente');
            }
        }

        return $enviados;
    }

    /** @param array<string,mixed> $r */
    public static function texto(array $r): string
    {
        $monto = Money::deDecimal((string) $r['monto_esperado']);
        $nota = trim((string) ($r['nota'] ?? ''));

        $lineas = [
            '🔔 <b>' . ExpenseCard::escapar((string) $r['comercio']) . '</b>',
            '',
            'Toca hoy. La última vez fueron <b>' . ExpenseCard::escapar($monto->formatear()) . '</b>.',
        ];

        if ($nota !== '') {
            $lineas[] = '<i>' . ExpenseCard::escapar($nota) . '</i>';
        }

        $lineas[] = '';
        $lineas[] = '<i>Si el importe cambió, mandámelo como texto y lo cargo con el nuevo.</i>';

        return implode("\n", $lineas);
    }

    public static function teclado(int $id): Keyboard
    {
        return Keyboard::nueva()->fila([
            '✓ Cargar' => self::ACCION_CARGAR . ':' . $id,
            '⏭ Este mes no' => self::ACCION_SALTEAR . ':' . $id,
        ]);
    }

    /**
     * El usuario responde al recordatorio de un gasto que se repite.
     *
     * Cargarlo lo da por hecho con el monto conocido; saltearlo corre el
     * aviso al mes que viene. En los dos casos el recordatorio no vuelve
     * a aparecer este mes.
     */
    public function manejarRespuesta(int $userId, Update $update): void
    {
        $accion = ExpenseCard::decodificar($update->callbackData);
        $r = $accion === null ? null : $this->recurrentes->porId($userId, $accion['expenseId']);

        if ($accion === null || $r === null) {
            $this->telegram->responderCallback($update->callbackQueryId);

            return;
        }

        $ahora = $this->reloj->ahora();
        $this->recurrentes->posponerAlMesQueViene($userId, (int) $r['id'], $ahora);

        if ($accion['accion'] === Recordatorios::ACCION_SALTEAR) {
            $this->telegram->responderCallback($update->callbackQueryId, 'Salteado');
            $this->telegram->editarMensaje(
                $update->chatId,
                $update->messageId,
                sprintf('⏭ <i>%s: salteado este mes.</i>', ExpenseCard::escapar((string) $r['comercio']))
            );

            return;
        }

        $monto = Money::deDecimal((string) $r['monto_esperado']);

        $borrador = new Draft(
            monto: $monto,
            fecha: $ahora,
            comercio: (string) $r['comercio'],
            descripcion: (string) $r['comercio'],
            categoria: $r['categoria'] === null ? null : (string) $r['categoria'],
            medioPago: '',
            fuente: Draft::FUENTE_MANUAL,
            confianza: 1.0,
            modelo: 'recurrente',
            tipo: Draft::TIPO_GASTO,
            naturaleza: (string) ($r['naturaleza'] ?? Draft::NATURALEZA_FIJO),
        );

        $this->gastos->guardarBorrador(
            $userId,
            $borrador,
            $r['category_id'] === null ? null : (int) $r['category_id'],
            null,
            sprintf('recurrente:%d:%s', (int) $r['id'], $ahora->format('Y-m')),
            ExpenseRepository::ESTADO_CONFIRMADO
        );

        $this->telegram->responderCallback($update->callbackQueryId, 'Cargado');
        $this->telegram->editarMensaje(
            $update->chatId,
            $update->messageId,
            sprintf(
                "✅ <b>%s</b> — <b>%s</b>\n📁 %s",
                ExpenseCard::escapar((string) $r['comercio']),
                ExpenseCard::escapar($monto->formatear()),
                ExpenseCard::escapar((string) ($r['categoria'] ?? 'Sin categoría'))
            )
        );
    }

    public static function esAccion(string $datos): bool
    {
        $accion = explode(':', $datos, 2)[0];

        return in_array($accion, [self::ACCION_CARGAR, self::ACCION_SALTEAR], true);
    }
}
