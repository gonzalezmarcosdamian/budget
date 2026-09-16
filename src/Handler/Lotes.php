<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Repository\ExpenseRepository;
use Budget\Support\Money;
use Budget\Telegram\Client;
use Budget\Telegram\Update;
use DateTimeImmutable;

/**
 * Los botones de una importación en lote.
 *
 * Un resumen de tarjeta entra como decenas de gastos de una vez, y
 * confirmarlos de a uno sería inusable: el lote los agrupa para poder
 * aceptarlos, verlos o descartarlos juntos.
 *
 * Vive aparte del Dispatcher porque es un bloque cerrado —tres acciones
 * sobre una misma entidad— y porque el Dispatcher estaba sobre las 800
 * líneas que el proyecto se puso como techo.
 */
final class Lotes
{
    public function __construct(
        private readonly Client $telegram,
        private readonly ExpenseRepository $gastos,
    ) {
    }

    public function confirmar(int $userId, Update $update, string $lote): void
    {
        $resumen = $this->gastos->resumenDeLote($userId, $lote);
        $cuantos = $this->gastos->confirmarLote($userId, $lote);

        $this->telegram->responderCallback(
            $update->callbackQueryId,
            $cuantos > 0 ? "Guardados {$cuantos}" : 'Ya estaba resuelto'
        );

        if ($cuantos === 0) {
            return;
        }

        $this->telegram->editarMensaje(
            $update->chatId,
            $update->messageId,
            sprintf(
                "✅ <b>Resumen importado</b>\n\n%d consumos — <b>%s</b>",
                $cuantos,
                ExpenseCard::escapar($resumen['total']->formatear())
            )
        );
    }

    public function descartar(int $userId, Update $update, string $lote): void
    {
        $cuantos = $this->gastos->descartarLote($userId, $lote);

        $this->telegram->responderCallback($update->callbackQueryId, 'Descartado');
        $this->telegram->editarMensaje(
            $update->chatId,
            $update->messageId,
            sprintf('🗑 <i>Importación descartada: %d consumos.</i>', $cuantos)
        );
    }

    /**
     * El detalle va como texto y no como tarjetas: cuarenta tarjetas son
     * cuarenta mensajes, y el chat queda inutilizable.
     */
    public function mostrar(int $userId, Update $update, string $lote): void
    {
        $this->telegram->responderCallback($update->callbackQueryId);
        $filas = $this->gastos->pendientesDeLote($userId, $lote);

        if ($filas === []) {
            return;
        }

        $lineas = ['🧾 <b>Detalle de la importación</b>', ''];

        foreach ($filas as $fila) {
            $monto = Money::deDecimal((string) $fila['monto'], (string) $fila['moneda']);
            $comercio = (string) $fila['comercio'];

            $lineas[] = sprintf(
                '%s  %s  %s — <b>%s</b>',
                (string) $fila['emoji'],
                self::soloDiaYMes((string) $fila['fecha']),
                ExpenseCard::escapar($comercio !== '' ? $comercio : 'Consumo'),
                ExpenseCard::escapar($monto->formatear())
            );
        }

        $lineas[] = '';
        $lineas[] = '<i>Si algo está mal, descartá la importación y mandame el resumen de nuevo.</i>';

        $this->telegram->enviarMensaje($update->chatId, implode("\n", $lineas));
    }

    private static function soloDiaYMes(string $fechaIso): string
    {
        $fecha = \DateTimeImmutable::createFromFormat('Y-m-d', $fechaIso);

        return $fecha === false ? $fechaIso : $fecha->format('d/m');
    }
}
