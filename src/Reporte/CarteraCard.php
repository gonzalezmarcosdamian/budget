<?php

declare(strict_types=1);

namespace Budget\Reporte;

use Budget\Handler\ExpenseCard;
use Budget\Repository\PatrimonioRepository;
use Budget\Support\Money;
use DateTimeImmutable;

/**
 * Lo que el bot contesta ante /inversiones.
 *
 * Vive aparte de `Reports` porque no comparte nada con los gastos: los
 * gastos se miden por flujo y la cartera por stock y su variación. Y
 * porque `Reports` ya estaba en 828 líneas, sobre el límite del
 * proyecto, y este bloque era el corte natural — está al lado de
 * `Patrimonio`, que tiene la matemática.
 */
final class CarteraCard
{
    public function __construct(private readonly PatrimonioRepository $fotos)
    {
    }

    /**
     * El portafolio hoy contra el de hace un mes.
     *
     * Separa la cartera de inversión de las reservas —dólares, saldo
     * quieto— porque se miden distinto: una por rendimiento, la otra por
     * cuánta hay. Promediarlas daría un rendimiento licuado por la plata
     * que está parada a propósito.
     *
     * Sobre la cartera, la diferencia se abre en lo que se movió por
     * precio y lo que se movió por aporte. Ver Patrimonio::comparar.
     */
    public function inversiones(int $userId, DateTimeImmutable $hoy): string
    {
        $actual = $this->fotos->ultimo($userId);

        if ($actual === null) {
            return "📈 Todavía no tengo ninguna foto del portafolio.

"
                . '<i>Se toma con <code>php bin/snapshot.php</code>. '
                . 'Hace falta una por mes para poder comparar.</i>';
        }

        $cartera = self::deClase($actual['posiciones'], PatrimonioRepository::CLASE_INVERSION);
        $reservas = self::deClase($actual['posiciones'], PatrimonioRepository::CLASE_RESERVA);

        $lineas = [
            '📈 <b>Inversiones</b>  <i>(' . $actual['fecha']->format('d/m/Y') . ')</i>',
            '',
            'Cartera: <b>' . ExpenseCard::escapar(self::sumaDe($cartera)->formatear()) . '</b>',
        ];

        if ($reservas !== []) {
            $lineas[] = 'Reservas: <b>' . ExpenseCard::escapar(self::sumaDe($reservas)->formatear()) . '</b>';
            $lineas[] = 'Total: <b>' . ExpenseCard::escapar($actual['total']->formatear()) . '</b>';
        }

        $previo = $this->fotos->delMesAnterior($userId, $actual['fecha']);

        if ($previo !== null) {
            foreach (self::mesContraMes($cartera, $reservas, $previo) as $linea) {
                $lineas[] = $linea;
            }

            return implode("\n", $lineas);
        }

        // Sin foto anterior no hay rendimiento que mostrar, pero las
        // posiciones sí: son las inversiones. La primera versión sólo
        // las listaba dentro de la comparación, así que el comando
        // quedaba casi vacío hasta el mes siguiente.
        foreach (self::composicion($cartera, $reservas) as $linea) {
            $lineas[] = $linea;
        }

        $lineas[] = '';
        $lineas[] = '<i>Es la primera foto: el mes que viene te digo cuánto rindió.</i>';

        return implode("\n", $lineas);
    }

    /**
     * Qué hay en la cartera, de mayor a menor.
     *
     * @param array<string,array<string,mixed>> $cartera
     * @param array<string,array<string,mixed>> $reservas
     * @return list<string>
     */
    private static function composicion(array $cartera, array $reservas): array
    {
        $total = self::sumaDe($cartera);
        $ordenadas = $cartera;
        uasort(
            $ordenadas,
            static fn (array $a, array $b): int => $b['valor']->centavos <=> $a['valor']->centavos
        );

        $lineas = ['', '<b>Composición</b>'];

        foreach ($ordenadas as $p) {
            $lineas[] = sprintf(
                '%s — <b>%s</b>  <i>%d%%</i>',
                ExpenseCard::escapar((string) $p['simbolo']),
                ExpenseCard::escapar($p['valor']->formatear()),
                $p['valor']->porcentajeDe($total)
            );
        }

        if ($reservas === []) {
            return $lineas;
        }

        $lineas[] = '';
        $lineas[] = '<b>Reservas</b>';

        foreach ($reservas as $p) {
            $lineas[] = sprintf(
                '%s — <b>%s</b>',
                ExpenseCard::escapar((string) $p['simbolo']),
                ExpenseCard::escapar($p['valor']->formatear())
            );
        }

        return $lineas;
    }

    /**
     * @param array<string,array<string,mixed>> $posiciones
     * @return array<string,array<string,mixed>>
     */
    private static function deClase(array $posiciones, string $clase): array
    {
        return array_filter(
            $posiciones,
            static fn (array $p): bool => ($p['clase'] ?? PatrimonioRepository::CLASE_INVERSION) === $clase
        );
    }

    /** @param array<string,array{valor:Money}> $posiciones */
    private static function sumaDe(array $posiciones): Money
    {
        $total = 0;

        foreach ($posiciones as $p) {
            $total += $p['valor']->centavos;
        }

        return Money::deCentavos($total);
    }

    /**
     * @param array<string,array<string,mixed>> $cartera
     * @param array<string,array<string,mixed>> $reservas
     * @param array<string,mixed> $previo
     * @return list<string>
     */
    private static function mesContraMes(array $cartera, array $reservas, array $previo): array
    {
        $c = Patrimonio::comparar(
            $cartera,
            self::deClase($previo['posiciones'], PatrimonioRepository::CLASE_INVERSION)
        );

        $lineas = [
            '',
            'Hace un mes la cartera era <b>' . ExpenseCard::escapar($c['anterior']->formatear())
                . '</b>  <i>(' . $previo['fecha']->format('d/m/Y') . ')</i>',
            '',
            sprintf(
                '%s Rendimiento: <b>%s%s</b>%s',
                $c['porPrecio'] >= 0 ? '🟢' : '🔴',
                $c['porPrecio'] >= 0 ? '+' : '−',
                ExpenseCard::escapar(Money::deCentavos(abs($c['porPrecio']))->formatear()),
                $c['rendimiento'] === null ? '' : sprintf('  <i>(%+.2f%%)</i>', $c['rendimiento'])
            ),
        ];

        // Sin esta línea el rendimiento se lee mal en cualquier mes en
        // que se haya aportado: el total sube y parece ganancia.
        if ($c['porAporte'] !== 0) {
            $lineas[] = sprintf(
                '%s %s <b>%s</b>  <i>no es rendimiento</i>',
                $c['porAporte'] > 0 ? '➕' : '➖',
                $c['porAporte'] > 0 ? 'Aportaste' : 'Retiraste',
                ExpenseCard::escapar(Money::deCentavos(abs($c['porAporte']))->formatear())
            );
        }

        foreach (self::variacionDeReservas($reservas, $previo) as $linea) {
            $lineas[] = $linea;
        }

        $lineas[] = '';
        $lineas[] = '<b>Por posición</b>';

        foreach ($c['posiciones'] as $p) {
            $lineas[] = self::renglonDePosicion($p);
        }

        return $lineas;
    }

    /**
     * Las reservas no rinden: lo único que informa es si subieron o bajaron.
     *
     * @param array<string,array<string,mixed>> $reservas
     * @param array<string,mixed> $previo
     * @return list<string>
     */
    private static function variacionDeReservas(array $reservas, array $previo): array
    {
        if ($reservas === []) {
            return [];
        }

        $antes = self::sumaDe(self::deClase($previo['posiciones'], PatrimonioRepository::CLASE_RESERVA));
        $delta = self::sumaDe($reservas)->centavos - $antes->centavos;

        if ($delta === 0) {
            return [];
        }

        return [sprintf(
            '🏦 Reservas: %s%s respecto del mes pasado',
            $delta > 0 ? '+' : '−',
            ExpenseCard::escapar(Money::deCentavos(abs($delta))->formatear())
        )];
    }

    /** @param array<string,mixed> $p */
    private static function renglonDePosicion(array $p): string
    {
        $delta = (int) $p['porPrecio'];
        $variacion = $p['variacion'];

        return sprintf(
            '%s %s — %s%s%s',
            $delta >= 0 ? '▲' : '▼',
            ExpenseCard::escapar((string) $p['simbolo']),
            '<b>' . ExpenseCard::escapar($p['valor']->formatear()) . '</b>',
            $variacion === null ? '' : sprintf('  <i>%+.2f%%</i>', $variacion),
            $p['nueva'] === true ? '  <i>nueva</i>' : ($p['cerrada'] === true ? '  <i>cerrada</i>' : '')
        );
    }
}
