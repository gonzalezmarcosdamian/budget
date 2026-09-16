<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Expense\Draft;
use Budget\Repository\ExpenseRepository;
use Budget\Support\Money;
use DateTimeImmutable;

/**
 * Los tres "top" del bot: gastos, entrantes y salientes.
 *
 * Un reporte por categoría contesta "en qué se me va la plata". Éstos
 * contestan otra cosa: "cuál fue el movimiento que más me costó" y
 * "quién movió más plata conmigo". Agrupar arruinaría la primera —
 * escondería justamente el gasto que duele— y netear arruinaría las
 * otras dos, que son direccionales por definición.
 *
 * Van sobre el período que se les pase, así que los mismos tres
 * comandos sirven para el mes, el trimestre o el año.
 */
final class Rankings
{
    /** Más que esto es una lista que nadie lee entera. */
    private const CUANTOS = 10;

    public function __construct(private readonly ExpenseRepository $gastos)
    {
    }

    public function topGastos(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): string
    {
        $filas = $this->gastos->mayoresGastos($userId, $desde, $hasta, self::CUANTOS);

        if ($filas === []) {
            return '🔝 No hay gastos en ese período.';
        }

        $lineas = [
            '🔝 <b>Los gastos más grandes</b>',
            '<i>' . self::periodo($desde, $hasta) . '</i>',
            '',
        ];

        foreach ($filas as $i => $f) {
            $monto = Money::deDecimal((string) $f['monto_ars']);

            $lineas[] = sprintf(
                '%d. %s %s — <b>%s</b>',
                $i + 1,
                (string) $f['emoji'],
                ExpenseCard::escapar(mb_substr((string) $f['comercio'], 0, 34)),
                ExpenseCard::escapar($monto->formatear())
            );
            $lineas[] = sprintf(
                '    <i>%s · %s</i>',
                self::soloDia((string) $f['fecha']),
                ExpenseCard::escapar((string) $f['categoria'])
            );
        }

        return implode("\n", $lineas);
    }

    public function topEntrantes(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): string
    {
        return $this->transferencias(
            $userId,
            $desde,
            $hasta,
            Draft::TIPO_INGRESO,
            '➕ <b>Quién te mandó más plata</b>',
            'Nadie te transfirió nada en ese período.'
        );
    }

    public function topSalientes(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): string
    {
        return $this->transferencias(
            $userId,
            $desde,
            $hasta,
            Draft::TIPO_GASTO,
            '➖ <b>A quién le mandaste más plata</b>',
            'No transferiste nada a nadie en ese período.'
        );
    }

    private function transferencias(
        int $userId,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        string $tipo,
        string $titulo,
        string $vacio,
    ): string {
        $filas = $this->gastos->mayoresTransferencias($userId, $desde, $hasta, $tipo, self::CUANTOS);

        if ($filas === []) {
            return $vacio;
        }

        $signo = $tipo === Draft::TIPO_INGRESO ? '➕' : '➖';
        $total = Money::deCentavos(0);

        foreach ($filas as $f) {
            $total = $total->mas($f['total']);
        }

        $lineas = [$titulo, '<i>' . self::periodo($desde, $hasta) . '</i>', ''];

        foreach ($filas as $f) {
            $lineas[] = sprintf(
                '%s %s — <b>%s</b>  <i>(%d)</i>',
                $signo,
                ExpenseCard::escapar(mb_substr($f['nombre'], 0, 30)),
                ExpenseCard::escapar($f['total']->formatear()),
                $f['movimientos']
            );
        }

        $lineas[] = '';
        $lineas[] = 'Total: <b>' . ExpenseCard::escapar($total->formatear()) . '</b>';

        return implode("\n", $lineas);
    }

    /** "1/7 al 30/9", que es más corto de leer que dos fechas completas. */
    private static function periodo(DateTimeImmutable $desde, DateTimeImmutable $hasta): string
    {
        return $desde->format('j/n') . ' al ' . $hasta->format('j/n/Y');
    }

    private static function soloDia(string $fecha): string
    {
        return (new DateTimeImmutable($fecha))->format('d/m/Y');
    }
}
