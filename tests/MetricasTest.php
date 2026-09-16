<?php

declare(strict_types=1);

use Budget\Reporte\Metricas;
use Budget\Support\Money;

function pesos(int $monto): Money
{
    return Money::deCentavos($monto * 100);
}

prueba('la variación compara contra el mes anterior', function (): void {
    esIgual(20, Metricas::variacion(pesos(120_000), pesos(100_000)), 'gastó 20% más');
    esIgual(-25, Metricas::variacion(pesos(75_000), pesos(100_000)), 'gastó 25% menos');
    esIgual(0, Metricas::variacion(pesos(100_000), pesos(100_000)), 'igual');
});

prueba('sin mes anterior no hay variación que mostrar', function (): void {
    // Dividir por cero daría infinito, y "+∞%" no informa nada.
    esNulo(Metricas::variacion(pesos(50_000), pesos(0)));
});

prueba('el tramo del mes anterior es el mismo tramo, no el mes entero', function (): void {
    // Es el punto entero de la métrica: comparar 14 días contra 30
    // siempre diría "gastaste menos" y sería mentira.
    [$desde, $hasta] = Metricas::tramoDelMesAnterior(new DateTimeImmutable('2026-09-14'));

    esIgual('2026-08-01', $desde->format('Y-m-d'));
    esIgual('2026-08-14', $hasta->format('Y-m-d'));
});

prueba('el tramo se recorta cuando el mes anterior es más corto', function (): void {
    // 31 de marzo contra febrero: febrero no tiene 31.
    [$desde, $hasta] = Metricas::tramoDelMesAnterior(new DateTimeImmutable('2026-03-31'));

    esIgual('2026-02-01', $desde->format('Y-m-d'));
    esIgual('2026-02-28', $hasta->format('Y-m-d'), 'se recorta al último día que existe');
});

prueba('el tramo cruza bien el cambio de año', function (): void {
    [$desde, $hasta] = Metricas::tramoDelMesAnterior(new DateTimeImmutable('2026-01-10'));

    esIgual('2025-12-01', $desde->format('Y-m-d'));
    esIgual('2025-12-10', $hasta->format('Y-m-d'));
});

prueba('la proyección estima el cierre del mes', function (): void {
    // $150.000 en 15 días de un mes de 30 proyecta $300.000.
    $p = Metricas::proyeccion(pesos(150_000), new DateTimeImmutable('2026-09-15'));

    esIgual('300000.00', $p?->aDecimal());
});

prueba('no proyecta el primer día ni el último', function (): void {
    esNulo(Metricas::proyeccion(pesos(10_000), new DateTimeImmutable('2026-09-01')), 'sin ritmo aún');
    esNulo(Metricas::proyeccion(pesos(300_000), new DateTimeImmutable('2026-09-30')), 'el mes ya cerró');
});

prueba('el promedio diario divide por los días transcurridos', function (): void {
    esIgual('10000.00', Metricas::promedioDiario(pesos(100_000), new DateTimeImmutable('2026-09-10'))->aDecimal());
});

prueba('el promedio del primer día no divide por cero', function (): void {
    esIgual('5000.00', Metricas::promedioDiario(pesos(5_000), new DateTimeImmutable('2026-09-01'))->aDecimal());
});

prueba('la flecha no deja dudas sobre el signo', function (): void {
    esIgual('📈 +12%', Metricas::flecha(12));
    esIgual('📉 -8%', Metricas::flecha(-8));
    esIgual('➡️ igual', Metricas::flecha(0));
});
