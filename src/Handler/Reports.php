<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Repository\ExpenseRepository;
use Budget\Support\Money;
use DateTimeImmutable;

/**
 * Los resúmenes que el bot devuelve ante /hoy, /mes y /ultimos.
 *
 * Un total suelto no dice nada: $2.400.000 este mes puede ser mucho o
 * poco. Lo que informa es contra qué se compara y hacia dónde va, así
 * que cada reporte lleva su contexto.
 */
final class Reports
{
    /** Cuántas contrapartes entran en el reporte antes de volverse ruido. */
    private const CONTRAPARTES_EN_EL_REPORTE = 5;

    public function __construct(private readonly ExpenseRepository $gastos)
    {
    }

    public function delDia(int $userId, DateTimeImmutable $dia): string
    {
        $total = $this->gastos->totalEntre($userId, $dia, $dia);

        if ($total->centavos === 0) {
            return '📅 Hoy no cargaste ningún gasto.';
        }

        $cuantos = $this->gastos->cantidadEntre($userId, $dia, $dia);
        $delMes = $this->gastos->totalEntre($userId, $dia->modify('first day of this month'), $dia);
        $promedio = Metricas::promedioDiario($delMes, $dia);

        $lineas = [
            '📅 <b>Hoy</b>',
            '',
            sprintf(
                '<b>%s</b> en %d %s',
                ExpenseCard::escapar($total->formatear()),
                $cuantos,
                $cuantos === 1 ? 'gasto' : 'gastos'
            ),
        ];

        // Sin la referencia, el número del día no se puede leer.
        if ($promedio->centavos > 0) {
            $variacion = Metricas::variacion($total, $promedio);
            $lineas[] = sprintf(
                '📊 Tu promedio del mes es %s por día%s',
                ExpenseCard::escapar($promedio->formatear()),
                $variacion === null ? '' : '  ·  ' . Metricas::flecha($variacion)
            );
        }

        return implode("\n", $lineas);
    }

    public function delMes(int $userId, DateTimeImmutable $enElMes): string
    {
        $desde = $enElMes->modify('first day of this month');
        $hasta = $enElMes->modify('last day of this month');

        $total = $this->gastos->totalEntre($userId, $desde, $hasta);

        if ($total->centavos === 0) {
            return '📊 Todavía no hay gastos confirmados este mes.';
        }

        $lineas = [
            sprintf('📊 <b>%s</b>', ExpenseCard::escapar(self::nombreDelMes($enElMes))),
            '',
            'Total: <b>' . ExpenseCard::escapar($total->formatear()) . '</b>',
        ];

        foreach ($this->contexto($userId, $enElMes, $desde, $total) as $linea) {
            $lineas[] = $linea;
        }

        $lineas[] = '';

        foreach ($this->gastos->totalPorCategoria($userId, $desde, $hasta) as $renglon) {
            $lineas[] = sprintf(
                '%s %s — <b>%s</b>  <i>%d%%</i>',
                $renglon['emoji'],
                ExpenseCard::escapar($renglon['categoria']),
                ExpenseCard::escapar($renglon['total']->formatear()),
                $renglon['total']->porcentajeDe($total)
            );
        }

        foreach ($this->transferencias($userId, $desde, $hasta) as $linea) {
            $lineas[] = $linea;
        }

        $cuantos = $this->gastos->cantidadEntre($userId, $desde, $hasta);
        $lineas[] = '';
        $lineas[] = sprintf('<i>%d movimientos</i>', $cuantos);

        return implode("\n", $lineas);
    }

    /**
     * Las tres cuentas que vuelven accionable el total.
     *
     * @return list<string>
     */
    private function contexto(
        int $userId,
        DateTimeImmutable $hoy,
        DateTimeImmutable $desde,
        Money $total,
    ): array {
        $lineas = [];

        // 1. Fijo contra variable: sobre el variable se puede decidir.
        $porNaturaleza = $this->gastos->gastoPorNaturaleza($userId, $desde, $hoy->modify('last day of this month'));
        $fijo = $porNaturaleza['fijo'] ?? Money::deCentavos(0);
        $variable = $porNaturaleza['variable'] ?? Money::deCentavos(0);

        if ($fijo->centavos > 0 && $variable->centavos > 0) {
            $lineas[] = sprintf(
                '🔒 Fijo %s <i>(%d%%)</i>   ·   🔀 Variable %s',
                ExpenseCard::escapar($fijo->formatear()),
                $fijo->porcentajeDe($total),
                ExpenseCard::escapar($variable->formatear())
            );
        }

        // 2. Contra el mismo tramo del mes pasado, no contra el mes entero.
        [$desdeAnterior, $hastaAnterior] = Metricas::tramoDelMesAnterior($hoy);
        $anterior = $this->gastos->totalEntre($userId, $desdeAnterior, $hastaAnterior);
        $variacion = Metricas::variacion($total, $anterior);

        if ($variacion !== null) {
            $lineas[] = sprintf(
                '%s vs los primeros %d días de %s (%s)',
                Metricas::flecha($variacion),
                (int) $hastaAnterior->format('j'),
                ExpenseCard::escapar(self::nombreDelMes($desdeAnterior, false)),
                ExpenseCard::escapar($anterior->formatear())
            );
        }

        // 3. A este ritmo, dónde termina el mes.
        $proyeccion = Metricas::proyeccion($total, $hoy);

        if ($proyeccion !== null) {
            $lineas[] = sprintf(
                '📅 %s por día  ·  cierra cerca de <b>%s</b>',
                ExpenseCard::escapar(Metricas::promedioDiario($total, $hoy)->formatear()),
                ExpenseCard::escapar($proyeccion->formatear())
            );
        }

        $mayor = $this->gastos->mayorGasto($userId, $desde, $hoy->modify('last day of this month'));

        if ($mayor !== null && $mayor['comercio'] !== '') {
            $lineas[] = sprintf(
                '🔝 El más grande: %s — %s',
                ExpenseCard::escapar(mb_substr($mayor['comercio'], 0, 34)),
                ExpenseCard::escapar($mayor['monto']->formatear())
            );
        }

        return $lineas;
    }

    /**
     * Transferencias entre personas, siempre en neto.
     *
     * Mandarle plata a alguien que te devuelve casi todo no es un gasto
     * por el total enviado. Mostrar las dos puntas por separado invita a
     * leer mal; el neto es el número que importa.
     *
     * @return list<string>
     */
    private function transferencias(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $netos = $this->gastos->netoPorContraparte($userId, $desde, $hasta);

        if ($netos === []) {
            return [];
        }

        $lineas = ['', '🔁 <b>Transferencias (neto)</b>'];
        $mostradas = 0;

        foreach ($netos as $n) {
            if ($mostradas >= self::CONTRAPARTES_EN_EL_REPORTE) {
                break;
            }

            // Un neto en cero es alguien con quien se compensó todo: no
            // hubo gasto, y listarlo sólo ocupa lugar.
            if ($n['neto'] === 0) {
                continue;
            }

            $neto = Money::deCentavos(abs($n['neto']));
            $nombre = $n['nombre'] !== '' ? $n['nombre'] : $n['contraparte'];

            $lineas[] = sprintf(
                '%s %s — <b>%s</b>  <i>(mandaste %s, te devolvieron %s)</i>',
                $n['neto'] > 0 ? '↗' : '↘',
                ExpenseCard::escapar(mb_substr($nombre, 0, 26)),
                ExpenseCard::escapar($neto->formatear()),
                ExpenseCard::escapar($n['enviado']->formatear()),
                ExpenseCard::escapar($n['recibido']->formatear())
            );

            $mostradas++;
        }

        return $mostradas === 0 ? [] : $lineas;
    }

    public function ultimos(int $userId): string
    {
        $filas = $this->gastos->ultimos($userId);

        if ($filas === []) {
            return 'Todavía no hay gastos confirmados.';
        }

        $lineas = ['🧾 <b>Últimos gastos</b>', ''];

        foreach ($filas as $fila) {
            $monto = Money::deDecimal((string) $fila['monto'], (string) $fila['moneda']);
            $comercio = (string) $fila['comercio'];

            $lineas[] = sprintf(
                '%s  %s  %s — <b>%s</b>',
                (string) $fila['emoji'],
                self::soloDiaYMes((string) $fila['fecha']),
                ExpenseCard::escapar($comercio !== '' ? $comercio : 'Gasto'),
                ExpenseCard::escapar($monto->formatear())
            );
        }

        return implode("\n", $lineas);
    }

    private static function soloDiaYMes(string $fechaIso): string
    {
        $fecha = DateTimeImmutable::createFromFormat('Y-m-d', $fechaIso);

        return $fecha === false ? $fechaIso : $fecha->format('d/m');
    }

    private static function nombreDelMes(DateTimeImmutable $fecha, bool $conAnio = true): string
    {
        $meses = [
            1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
        ];

        $nombre = $meses[(int) $fecha->format('n')] ?? '';

        return $conAnio ? $nombre . ' ' . $fecha->format('Y') : $nombre;
    }
}
