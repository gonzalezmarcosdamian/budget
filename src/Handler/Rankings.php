<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Expense\Draft;
use Budget\Expense\Periodo;
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

    public function topGastos(int $userId, Periodo $p): string
    {
        $filas = $this->gastos->mayoresGastos($userId, $p->desde, $p->hasta, self::CUANTOS);

        if ($filas === []) {
            return '🔝 No hay gastos en ' . ExpenseCard::escapar($p->etiqueta) . '.';
        }

        $lineas = [
            '🔝 <b>Los gastos más grandes</b>',
            '<i>' . ExpenseCard::escapar($p->etiqueta) . '</i>',
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

    public function topEntrantes(int $userId, Periodo $p): string
    {
        return $this->transferencias(
            $userId,
            $p,
            Draft::TIPO_INGRESO,
            '➕ <b>Quién te mandó más plata</b>',
            'Nadie te transfirió nada en ese período.'
        );
    }

    public function topSalientes(int $userId, Periodo $p): string
    {
        return $this->transferencias(
            $userId,
            $p,
            Draft::TIPO_GASTO,
            '➖ <b>A quién le mandaste más plata</b>',
            'No transferiste nada a nadie en ese período.'
        );
    }

    private function transferencias(
        int $userId,
        Periodo $p,
        string $tipo,
        string $titulo,
        string $vacio,
    ): string {
        $filas = $this->gastos->mayoresTransferencias(
            $userId,
            $p->desde,
            $p->hasta,
            $tipo,
            self::CUANTOS
        );

        if ($filas === []) {
            return $vacio;
        }

        $signo = $tipo === Draft::TIPO_INGRESO ? '➕' : '➖';
        $total = Money::deCentavos(0);

        foreach ($filas as $f) {
            $total = $total->mas($f['total']);
        }

        $lineas = [$titulo, '<i>' . ExpenseCard::escapar($p->etiqueta) . '</i>', ''];

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
        // "Total" a secas no cerraría con el de /mes: esto suma sólo
        // las que se muestran, no todas las del período.
        $lineas[] = sprintf(
            'Total de est%s %d: <b>%s</b>',
            count($filas) === 1 ? 'a' : 'as',
            count($filas),
            ExpenseCard::escapar($total->formatear())
        );

        return implode("\n", $lineas);
    }

    /**
     * Una fecha de la base, en formato local.
     *
     * Con `createFromFormat` y no `new DateTimeImmutable`: éste último,
     * ante una cadena vacía, devuelve la fecha de hoy en silencio.
     */
    private static function soloDia(string $fecha): string
    {
        $dia = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);

        return $dia === false ? $fecha : $dia->format('d/m/Y');
    }
}
