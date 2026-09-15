<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Expense\Draft;
use Budget\Expense\Pregunta;
use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
use Budget\Repository\RecurringRepository;
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

    public function __construct(
        private readonly ExpenseRepository $gastos,
        private readonly CategoryRepository $categorias,
        private readonly RecurringRepository $recurrentes,
    ) {
    }

    /**
     * Contesta una pregunta sobre los gastos ya cargados.
     *
     * Las cifras salen de la base, nunca de un modelo: un número
     * inventado en un reporte de plata es peor que no contestar.
     */
    public function responder(int $userId, Pregunta $p, DateTimeImmutable $hoy): string
    {
        [$desde, $hasta, $etiqueta] = $p->rango($hoy);

        if ($p->categoria !== null) {
            $categoryId = $this->categorias->idPorNombre($userId, $p->categoria);

            if ($categoryId !== null) {
                $total = $this->gastos->totalDeCategoria($userId, $categoryId, $desde, $hasta);

                if ($total->centavos === 0) {
                    return sprintf(
                        'No tenés nada cargado en <b>%s</b> %s.',
                        ExpenseCard::escapar($p->categoria),
                        ExpenseCard::escapar($etiqueta)
                    );
                }

                $general = $this->gastos->totalEntre($userId, $desde, $hasta);

                return sprintf(
                    "En <b>%s</b> %s llevás <b>%s</b>.

<i>Es el %d%% de los %s que gastaste en total.</i>",
                    ExpenseCard::escapar($p->categoria),
                    ExpenseCard::escapar($etiqueta),
                    ExpenseCard::escapar($total->formatear()),
                    $total->porcentajeDe($general),
                    ExpenseCard::escapar($general->formatear())
                );
            }
        }

        $total = $this->gastos->totalEntre($userId, $desde, $hasta);

        if ($total->centavos === 0) {
            return sprintf('No tenés gastos cargados %s.', ExpenseCard::escapar($etiqueta));
        }

        $lineas = [
            sprintf(
                '%s gastaste <b>%s</b> en %d movimientos.',
                ExpenseCard::escapar(ucfirst($etiqueta)),
                ExpenseCard::escapar($total->formatear()),
                $this->gastos->cantidadEntre($userId, $desde, $hasta)
            ),
            '',
        ];

        foreach (array_slice($this->gastos->totalPorCategoria($userId, $desde, $hasta), 0, 5) as $r) {
            $lineas[] = sprintf(
                '%s %s — <b>%s</b>  <i>%d%%</i>',
                $r['emoji'],
                ExpenseCard::escapar($r['categoria']),
                ExpenseCard::escapar($r['total']->formatear()),
                $r['total']->porcentajeDe($total)
            );
        }

        return implode("\n", $lineas);
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

        // Los meses de alquiler que se pagaron por otra app se
        // reconstruyeron con el IPC. Son la mejor estimación que hay,
        // pero presentarlos sin aclararlo los convierte en un dato
        // medido, y no lo son.
        $estimado = $this->gastos->totalEstimadoEntre(
            $userId,
            $desde,
            $hoy->modify('last day of this month')
        );

        if ($estimado->centavos > 0) {
            $lineas[] = sprintf(
                '❓ <i>%s son estimados, no medidos</i>',
                ExpenseCard::escapar($estimado->formatear())
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

    /** Los gastos que se repiten, con el día en que toca cada uno. */
    public function recurrentes(int $userId): string
    {
        $activos = $this->recurrentes->activos($userId);

        if ($activos === []) {
            return 'No tenés gastos recurrentes cargados todavía.';
        }

        $lineas = ['🔁 <b>Gastos que se repiten</b>', ''];

        foreach ($activos as $r) {
            $lineas[] = sprintf(
                '%s %s — <b>%s</b>  <i>día %d</i>',
                (string) ($r['emoji'] ?? '🔔'),
                ExpenseCard::escapar((string) $r['comercio']),
                ExpenseCard::escapar(Money::deDecimal((string) $r['monto_esperado'])->formatear()),
                (int) $r['dia_del_mes']
            );

            if (trim((string) ($r['nota'] ?? '')) !== '') {
                $lineas[] = '   <i>' . ExpenseCard::escapar((string) $r['nota']) . '</i>';
            }
        }

        $lineas[] = '';
        $lineas[] = '<i>Te aviso el día que toca y lo cargás con un toque.</i>';

        return implode("
", $lineas);
    }

    /**
     * El flujo de caja del mes: qué entró, qué salió y en qué.
     *
     * Acá hubo dos errores de concepto encadenados. El primero fue armar
     * un estado de resultados —ingresos menos gastos, "saldo en rojo"—
     * sobre datos que son movimientos de caja. El bot no sabe lo que el
     * usuario gana: sabe lo que entra y sale de las cuentas que ve.
     *
     * El segundo fue querer arreglarlo sacando del cálculo lo que "no es
     * gasto": préstamos, inversiones. Pero esa plata se fue de la cuenta
     * igual. Un flujo de caja al que le sacás movimientos deja de
     * explicar dónde está la plata, que es para lo único que sirve.
     *
     * Así que no se excluye nada: se clasifica, y se aclara hasta dónde
     * llega lo que el bot ve.
     */
    public function flujo(int $userId, DateTimeImmutable $enElMes): string
    {
        $desde = $enElMes->modify('first day of this month');
        $hasta = $enElMes->modify('last day of this month');
        $f = $this->gastos->flujoDeCaja($userId, $desde, $hasta);

        if ($f['entro']->centavos === 0 && $f['salio']->centavos === 0) {
            return '💵 Todavía no hay movimientos este mes.';
        }

        $variacion = $f['entro']->centavos - $f['salio']->centavos;

        $lineas = [
            '💵 <b>Flujo de caja — ' . ExpenseCard::escapar(self::nombreDelMes($enElMes, false)) . '</b>',
            '',
            'Entró: <b>' . ExpenseCard::escapar($f['entro']->formatear()) . '</b>',
            'Salió: <b>' . ExpenseCard::escapar($f['salio']->formatear()) . '</b>',
            '',
            sprintf(
                '%s Variación de caja: <b>%s%s</b>',
                $variacion >= 0 ? '📈' : '📉',
                $variacion >= 0 ? '+' : '−',
                ExpenseCard::escapar(Money::deCentavos(abs($variacion))->formatear())
            ),
        ];

        foreach (self::destinoDeLaPlata($f) as $linea) {
            $lineas[] = $linea;
        }

        $lineas[] = '';
        $lineas[] = '<i>Sólo las cuentas que veo. Lo que movés por fuera no entra acá.</i>';

        return implode("
", $lineas);
    }

    /**
     * En qué se fue la plata que salió.
     *
     * Prestar e invertir van separados del consumo a propósito: los tres
     * bajan la caja, pero sólo uno es plata que no vuelve.
     *
     * @param array{entro:Money, salio:Money, consumo:Money, fijos:Money,
     *              prestado:Money, invertido:Money} $f
     * @return list<string>
     */
    private static function destinoDeLaPlata(array $f): array
    {
        $partes = [
            ['🔒', 'Fijos', $f['fijos']],
            ['🛒', 'Consumo', $f['consumo']],
            ['🤝', 'Prestado', $f['prestado']],
            ['📈', 'Invertido', $f['invertido']],
        ];

        $lineas = [];

        foreach ($partes as [$emoji, $nombre, $monto]) {
            if ($monto->centavos === 0) {
                continue;
            }

            $lineas[] = sprintf(
                '%s %s — <b>%s</b>  <i>%d%%</i>',
                $emoji,
                $nombre,
                ExpenseCard::escapar($monto->formatear()),
                $monto->porcentajeDe($f['salio'])
            );
        }

        return $lineas === [] ? [] : array_merge(['', '<b>Adónde fue</b>'], $lineas);
    }

    /** Resumen del año, mes a mes. */
    public function delAnio(int $userId, DateTimeImmutable $hoy): string
    {
        $porMes = $this->gastos->totalPorMes($userId, (int) $hoy->format('Y'));

        if ($porMes === []) {
            return '📆 Todavía no hay gastos cargados este año.';
        }

        $lineas = ['📆 <b>' . $hoy->format('Y') . '</b>', ''];
        $total = Money::deCentavos(0);

        foreach ($porMes as $mes => $delMes) {
            $total = $total->mas($delMes);
            $lineas[] = sprintf(
                '%s — <b>%s</b>',
                ExpenseCard::escapar(self::nombreDelMes($hoy->setDate((int) $hoy->format('Y'), $mes, 1), false)),
                ExpenseCard::escapar($delMes->formatear())
            );
        }

        $lineas[] = '';
        $lineas[] = 'Total: <b>' . ExpenseCard::escapar($total->formatear()) . '</b>';

        return implode("
", $lineas);
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
