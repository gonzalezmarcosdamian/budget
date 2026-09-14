<?php

declare(strict_types=1);

use Budget\Repository\RecurringRepository;

/**
 * El cálculo de la próxima fecha es lo único con lógica acá, y es donde
 * están todos los bordes: meses cortos, fin de año y el día de hoy.
 */

prueba('el próximo aviso es este mes si todavía no pasó', function (): void {
    $f = RecurringRepository::proximaFecha(10, new DateTimeImmutable('2026-09-05'));

    esIgual('2026-09-10', $f->format('Y-m-d'));
});

prueba('si el día ya pasó, salta al mes que viene', function (): void {
    $f = RecurringRepository::proximaFecha(10, new DateTimeImmutable('2026-09-20'));

    esIgual('2026-10-10', $f->format('Y-m-d'));
});

prueba('el mismo día cuenta como hoy y no como el mes que viene', function (): void {
    // Si el aviso es hoy, hay que avisar hoy.
    $f = RecurringRepository::proximaFecha(10, new DateTimeImmutable('2026-09-10'));

    esIgual('2026-09-10', $f->format('Y-m-d'));
});

prueba('el día 31 cae al último día de un mes corto', function (): void {
    // Febrero no tiene 31: desbordar pondría el aviso en marzo.
    esIgual('2026-02-28', RecurringRepository::proximaFecha(31, new DateTimeImmutable('2026-02-01'))->format('Y-m-d'));
    esIgual('2026-04-30', RecurringRepository::proximaFecha(31, new DateTimeImmutable('2026-04-10'))->format('Y-m-d'));
});

prueba('cruza bien el cambio de año', function (): void {
    $f = RecurringRepository::proximaFecha(5, new DateTimeImmutable('2026-12-20'));

    esIgual('2027-01-05', $f->format('Y-m-d'));
});

prueba('un día inválido se acota en vez de romper', function (): void {
    esIgual('2026-09-01', RecurringRepository::proximaFecha(0, new DateTimeImmutable('2026-09-01'))->format('Y-m-d'));
    esIgual('2026-09-30', RecurringRepository::proximaFecha(99, new DateTimeImmutable('2026-09-01'))->format('Y-m-d'));
});
