<?php

declare(strict_types=1);

use Budget\Expense\Periodo;

/**
 * El trimestre y el año son meses calendario, no ventanas de días: el
 * mes es la unidad en la que la gente piensa la plata, y un corte a
 * noventa días parte un mes al medio y vuelve incomparable el número.
 */

prueba('el trimestre son tres meses calendario, con el actual adentro', function (): void {
    $p = Periodo::desde(Periodo::TRIMESTRE, new DateTimeImmutable('2026-09-15'));

    esIgual('2026-07-01', $p->desde->format('Y-m-d'));
    esIgual('2026-09-30', $p->hasta->format('Y-m-d'));
    esIgual(3, $p->meses());
});

prueba('el trimestre cruza diciembre sin romperse', function (): void {
    $p = Periodo::desde(Periodo::TRIMESTRE, new DateTimeImmutable('2026-01-20'));

    esIgual('2025-11-01', $p->desde->format('Y-m-d'));
    esIgual('2026-01-31', $p->hasta->format('Y-m-d'));
    esIgual(3, $p->meses());
});

prueba('el año son los ultimos doce meses, no el calendario', function (): void {
    // En febrero, "el último año" con corte en enero serían seis
    // semanas, y eso no es lo que nadie quiere ver.
    $p = Periodo::desde(Periodo::ANIO, new DateTimeImmutable('2026-02-10'));

    esIgual('2025-03-01', $p->desde->format('Y-m-d'));
    esIgual('2026-02-28', $p->hasta->format('Y-m-d'));
    esIgual(12, $p->meses());
});

prueba('el mes corriente es de punta a punta', function (): void {
    $p = Periodo::desde(Periodo::MES, new DateTimeImmutable('2026-09-15'));

    esIgual('2026-09-01', $p->desde->format('Y-m-d'));
    esIgual('2026-09-30', $p->hasta->format('Y-m-d'));
    esIgual(1, $p->meses());
});

prueba('febrero bisiesto no se corta antes de tiempo', function (): void {
    $p = Periodo::desde(Periodo::MES, new DateTimeImmutable('2028-02-10'));

    esIgual('2028-02-29', $p->hasta->format('Y-m-d'));
});
