<?php

declare(strict_types=1);

namespace Budget\Reporte;

use Budget\Support\Money;
use DateTimeImmutable;

/**
 * Las cuentas que convierten un total en algo accionable.
 *
 * Un número suelto no dice nada: $2.400.000 este mes puede ser mucho o
 * poco. Lo que informa es contra qué se compara y hacia dónde va.
 *
 * Todo acá es cálculo puro, sin base ni formato, para poder probar cada
 * caso de borde sin levantar nada.
 */
final class Metricas
{
    /**
     * Comparación honesta entre meses.
     *
     * Comparar catorce días de septiembre contra treinta de agosto
     * siempre diría "gastaste menos", y sería mentira. Por eso se compara
     * el mismo tramo: del 1 al mismo día.
     *
     * @return int|null variación porcentual, o null si no hay con qué comparar
     */
    public static function variacion(Money $actual, Money $anterior): ?int
    {
        if ($anterior->centavos === 0) {
            return null;
        }

        return (int) round(($actual->centavos - $anterior->centavos) / $anterior->centavos * 100);
    }

    /**
     * A este ritmo, cuánto termina siendo el mes.
     *
     * Devuelve null el primer día y también cuando el mes ya terminó: en
     * el primero no hay ritmo que medir y en el segundo no hay nada que
     * proyectar, el total ya es el total.
     */
    public static function proyeccion(Money $acumulado, DateTimeImmutable $hoy): ?Money
    {
        $dia = (int) $hoy->format('j');
        $diasDelMes = (int) $hoy->format('t');

        if ($dia < 2 || $dia >= $diasDelMes) {
            return null;
        }

        return Money::deCentavos((int) round($acumulado->centavos / $dia * $diasDelMes));
    }

    public static function promedioDiario(Money $acumulado, DateTimeImmutable $hoy): Money
    {
        $dia = max(1, (int) $hoy->format('j'));

        return Money::deCentavos((int) round($acumulado->centavos / $dia));
    }

    /**
     * El mismo tramo del mes anterior: del 1 al día equivalente.
     *
     * Si hoy es 31 y el mes anterior tiene 30, se recorta al último día
     * que existe en vez de desbordar al mes siguiente.
     *
     * @return array{0:DateTimeImmutable, 1:DateTimeImmutable}
     */
    public static function tramoDelMesAnterior(DateTimeImmutable $hoy): array
    {
        $primeroAnterior = $hoy->modify('first day of last month')->setTime(0, 0);
        $ultimoDiaPosible = (int) $primeroAnterior->format('t');
        $dia = min((int) $hoy->format('j'), $ultimoDiaPosible);

        return [$primeroAnterior, $primeroAnterior->setDate(
            (int) $primeroAnterior->format('Y'),
            (int) $primeroAnterior->format('n'),
            $dia
        )];
    }

    /** Flecha y signo para mostrar una variación sin ambigüedad. */
    public static function flecha(int $variacion): string
    {
        return match (true) {
            $variacion > 0 => '📈 +' . $variacion . '%',
            $variacion < 0 => '📉 ' . $variacion . '%',
            default => '➡️ igual',
        };
    }
}
