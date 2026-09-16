<?php

declare(strict_types=1);

namespace Budget\Reporte;

use Budget\Expense\Draft;
use Budget\Expense\Periodo;
use Budget\Expense\Pregunta;
use Budget\Handler\ExpenseCard;
use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
use Budget\Repository\PatrimonioRepository;
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
        private readonly PatrimonioRepository $patrimonio,
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
                ExpenseCard::escapar((string) $r['emoji']),
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

        foreach ($this->contexto($userId, $enElMes, $desde) as $linea) {
            $lineas[] = $linea;
        }

        $lineas[] = '';

        foreach ($this->gastos->totalPorCategoria($userId, $desde, $hasta) as $renglon) {
            $lineas[] = sprintf(
                '%s %s — <b>%s</b>  <i>%d%%</i>',
                ExpenseCard::escapar((string) $renglon['emoji']),
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
    ): array {
        // Lo acumulado hasta hoy, no el mes entero: el promedio diario y
        // la proyección dividen por los días transcurridos, así que un
        // gasto con fecha futura —una cuota mal leída en un resumen—
        // duplicaba las dos cifras. Y /hoy sí acotaba bien, con lo cual
        // los dos comandos daban promedios distintos del mismo mes.
        $total = $this->gastos->totalEntre($userId, $desde, $hoy);
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
    /**
     * Las transferencias entre personas, neteadas y en dos grupos.
     *
     * Un solo listado mezclado obliga a leer la flecha de cada renglón
     * para saber de qué lado quedaste. Separadas, la pregunta "¿con
     * quién quedé en deuda y quién quedó conmigo?" se contesta de un
     * vistazo, que es lo único que se le pide a este bloque.
     *
     * @return list<string>
     */
    private function transferencias(int $userId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $netos = $this->gastos->netoPorContraparte($userId, $desde, $hasta);

        $salieron = array_values(array_filter($netos, static fn (array $n): bool => $n['neto'] > 0));
        $entraron = array_values(array_filter($netos, static fn (array $n): bool => $n['neto'] < 0));

        // El más grande primero de cada lado: los de entrada vienen
        // ordenados al revés porque su neto es negativo.
        usort($entraron, static fn (array $a, array $b): int => $a['neto'] <=> $b['neto']);

        $bloques = [
            ['➖', '−', 'Mandaste de más', $salieron],
            ['➕', '+', 'Te mandaron de más', $entraron],
        ];

        $lineas = [];

        foreach ($bloques as [$flecha, $signo, $titulo, $grupo]) {
            foreach (self::grupoDeTransferencias($flecha, $signo, $titulo, $grupo) as $linea) {
                $lineas[] = $linea;
            }
        }

        // Un neto en cero es alguien con quien se compensó todo: no hubo
        // gasto, y listarlo sólo ocupa lugar.
        return $lineas === [] ? [] : array_merge(['', '🔁 <b>Transferencias (neto)</b>'], $lineas);
    }

    /**
     * @param list<array<string,mixed>> $grupo
     * @return list<string>
     */
    private static function grupoDeTransferencias(
        string $flecha,
        string $signo,
        string $titulo,
        array $grupo,
    ): array
    {
        if ($grupo === []) {
            return [];
        }

        $lineas = ['', $flecha . ' <b>' . $titulo . '</b>'];

        foreach (array_slice($grupo, 0, self::CONTRAPARTES_EN_EL_REPORTE) as $n) {
            $nombre = $n['nombre'] !== '' ? $n['nombre'] : $n['contraparte'];

            $lineas[] = sprintf(
                '   %s — <b>%s%s</b>  <i>(mandaste %s, te devolvieron %s)</i>',
                ExpenseCard::escapar(mb_substr($nombre, 0, 26)),
                $signo,
                ExpenseCard::escapar(Money::deCentavos(abs($n['neto']))->formatear()),
                ExpenseCard::escapar($n['enviado']->formatear()),
                ExpenseCard::escapar($n['recibido']->formatear())
            );
        }

        return $lineas;
    }

    /**
     * El próximo movimiento que falta clasificar, y cuántos quedan.
     *
     * Los movimientos de Mercado Pago entran ya confirmados y sin
     * tarjeta, así que lo que el categorizador no supo ubicar no tenía
     * arreglo desde el bot. Esto es la cola para arreglarlo de a uno.
     */
    public function aCategorizar(int $userId, ?array $siguiente = null): string
    {
        $siguiente ??= $this->gastos->sinCategorizar($userId, 1)[0] ?? null;

        if ($siguiente === null) {
            return '✅ No queda nada sin clasificar.';
        }

        $falta = $this->gastos->cuantoFaltaCategorizar($userId);

        $monto = Money::deDecimal((string) $siguiente['monto_ars']);

        return implode("\n", [
            sprintf(
                '📁 Quedan <b>%d</b> sin clasificar por <b>%s</b>',
                $falta['cuantos'],
                ExpenseCard::escapar($falta['total']->formatear())
            ),
            '',
            '<b>' . ExpenseCard::escapar($monto->formatear()) . '</b>  '
                . ExpenseCard::escapar(
                    mb_substr((string) $siguiente['comercio'], 0, 60)
                ),
            '<i>' . (new DateTimeImmutable((string) $siguiente['fecha']))->format('d/m/Y') . '</i>',
            '',
            '<i>Elegí la categoría y la recuerdo para ese comercio.</i>',
        ]);
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
                ExpenseCard::escapar((string) ($r['emoji'] ?? '🔔')),
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

        return implode("\n", $lineas);
    }

    /**
     * El flujo de caja del mes, separando lo propio de lo de terceros.
     *
     * Tres errores de concepto encadenados terminaron acá. El primero
     * fue armar un estado de resultados sobre datos de caja. El segundo,
     * querer arreglarlo sacando del cálculo lo que "no es gasto", cuando
     * esa plata se fue de la cuenta igual. El tercero era más sutil:
     * tratar igual una transferencia a un amigo y una a tu propio banco.
     *
     * Mover plata entre bolsillos propios no es gastar. Y lo que le
     * mandás a alguien que después te devuelve, tampoco: el gasto real
     * es el **neto** contra terceros. Si pagás $100.000 de una cena y te
     * devuelven $70.000, gastaste $30.000.
     */
    public function flujo(int $userId, DateTimeImmutable $enElMes): string
    {
        $desde = $enElMes->modify('first day of this month');
        $hasta = $enElMes->modify('last day of this month');
        $f = $this->gastos->flujoDeCaja($userId, $desde, $hasta);

        $entro = $f['entroTerceros']->centavos + $f['entroPropio']->centavos;
        $salio = $f['salioTerceros']->centavos + $f['salioPropio']->centavos;

        if ($entro === 0 && $salio === 0) {
            return '💵 Todavía no hay movimientos este mes.';
        }

        $lineas = [
            '💵 <b>Flujo de caja — ' . ExpenseCard::escapar(self::nombreDelMes($enElMes, false)) . '</b>',
            '',
            '<b>Entró</b>',
            '  👥 De terceros: <b>' . ExpenseCard::escapar($f['entroTerceros']->formatear()) . '</b>',
            '  🔁 Propio: <b>' . ExpenseCard::escapar($f['entroPropio']->formatear()) . '</b>',
            '',
            '<b>Salió</b>',
            '  👥 A terceros: <b>' . ExpenseCard::escapar($f['salioTerceros']->formatear()) . '</b>',
            '  🔁 A cuenta propia: <b>' . ExpenseCard::escapar($f['salioPropio']->formatear()) . '</b>',
            '',
            sprintf(
                '💸 <b>Gasto real: %s%s</b>',
                $f['gastoReal'] < 0 ? '−' : '',
                ExpenseCard::escapar(Money::deCentavos(abs($f['gastoReal']))->formatear())
            ),
            '<i>Lo que pagaste, neteado con lo que cada uno te devolvió.</i>',
        ];

        $variacion = $entro - $salio;
        $lineas[] = '';
        $lineas[] = sprintf(
            '%s Variación de caja: <b>%s%s</b>',
            $variacion >= 0 ? '📈' : '📉',
            $variacion >= 0 ? '+' : '−',
            ExpenseCard::escapar(Money::deCentavos(abs($variacion))->formatear())
        );

        foreach (self::destinoDeLaPlata($f) as $linea) {
            $lineas[] = $linea;
        }

        $lineas[] = '';
        $lineas[] = '<i>Sólo las cuentas que veo. Lo que movés por fuera no entra acá.</i>';

        return implode("\n", $lineas);
    }

    /**
     * En qué se fue la plata que salió.
     *
     * Prestar e invertir van separados del consumo a propósito: los tres
     * bajan la caja, pero sólo uno es plata que no vuelve.
     *
     * @param array<string,mixed> $f
     * @return list<string>
     */
    private static function destinoDeLaPlata(array $f): array
    {
        $salio = Money::deCentavos(
            $f['salioTerceros']->centavos + $f['salioPropio']->centavos
        );

        $partes = [
            ['🔒', 'Fijos', $f['fijos']],
            ['🛒', 'Consumo', $f['consumo']],
            ['📈', 'Invertido', $f['invertido']],
            ['🔁', 'A cuenta tuya', $f['aPropio']],
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
                $monto->porcentajeDe($salio)
            );
        }

        return $lineas === [] ? [] : array_merge(['', '<b>Adónde fue</b>'], $lineas);
    }

    /** Ver Handler\CarteraCard, que es donde vive el reporte entero. */
    public function inversiones(int $userId, DateTimeImmutable $hoy): string
    {
        return (new CarteraCard($this->patrimonio))->inversiones($userId, $hoy);
    }

    /**
     * Un período largo: el trimestre o los últimos doce meses.
     *
     * Mes a mes y con el promedio, que es lo que vuelve comparable un
     * total de tres meses contra uno de doce. Sin el promedio, el número
     * grande sólo dice que el período era largo.
     */
    public function delPeriodo(int $userId, Periodo $p): string
    {
        $total = $this->gastos->totalEntre($userId, $p->desde, $p->hasta);

        if ($total->centavos === 0) {
            return '📆 No hay gastos cargados en ' . ExpenseCard::escapar($p->etiqueta) . '.';
        }

        $lineas = [
            '📆 <b>' . ExpenseCard::escapar(ucfirst($p->etiqueta)) . '</b>',
            '<i>' . $p->desde->format('j/n/Y') . ' al ' . $p->hasta->format('j/n/Y') . '</i>',
            '',
            'Total: <b>' . ExpenseCard::escapar($total->formatear()) . '</b>',
            'Promedio: <b>' . ExpenseCard::escapar(
                Money::deCentavos(intdiv($total->centavos, max(1, $p->meses())))->formatear()
            ) . '</b> por mes',
            '',
        ];

        // Con los meses vacíos adentro: si julio y septiembre tienen
        // $300.000 cada uno y agosto no aparece, el promedio de $200.000
        // se lee como un error. Un cero explícito lo explica.
        $porMes = $this->gastos->totalPorMesEntre($userId, $p->desde, $p->hasta);
        $cursor = $p->desde;

        while ($cursor <= $p->hasta) {
            $clave = $cursor->format('Y-m');
            $lineas[] = sprintf(
                '%s — <b>%s</b>',
                ExpenseCard::escapar(self::nombreDelMes($cursor)),
                ExpenseCard::escapar(($porMes[$clave] ?? Money::deCentavos(0))->formatear())
            );
            $cursor = $cursor->modify('first day of next month');
        }

        $lineas[] = '';
        $lineas[] = '<b>Por categoría</b>';

        foreach (array_slice($this->gastos->totalPorCategoria($userId, $p->desde, $p->hasta), 0, 8) as $r) {
            $lineas[] = sprintf(
                '%s %s — <b>%s</b>  <i>%d%%</i>',
                ExpenseCard::escapar((string) $r['emoji']),
                ExpenseCard::escapar($r['categoria']),
                ExpenseCard::escapar($r['total']->formatear()),
                $r['total']->porcentajeDe($total)
            );
        }

        return implode("\n", $lineas);
    }

    public function ultimos(int $userId): string
    {
        $filas = $this->gastos->ultimos($userId);

        if ($filas === []) {
            return 'Todavía no hay movimientos confirmados.';
        }

        // Dice "movimientos" y no "gastos" porque la consulta trae las
        // tres clases. Antes decía gastos y listaba los ingresos entre
        // ellos: una devolución de $600.000 figuraba como si se hubiera
        // gastado. El signo es lo que vuelve legible la lista.
        $lineas = ['🧾 <b>Últimos movimientos</b>', ''];

        foreach ($filas as $fila) {
            $monto = Money::deDecimal((string) $fila['monto'], (string) $fila['moneda']);
            $comercio = (string) $fila['comercio'];
            $entra = (string) $fila['tipo'] === Draft::TIPO_INGRESO;

            $lineas[] = sprintf(
                '%s  %s  %s — <b>%s%s</b>',
                ExpenseCard::escapar((string) $fila['emoji']),
                self::soloDiaYMes((string) $fila['fecha']),
                ExpenseCard::escapar($comercio !== '' ? $comercio : 'Movimiento'),
                $entra ? '+' : '−',
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
