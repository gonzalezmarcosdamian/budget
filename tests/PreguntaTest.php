<?php

declare(strict_types=1);

use Budget\Expense\CategoryGuesser;
use Budget\Expense\Pregunta;

function pregunta(string $texto): ?Pregunta
{
    return Pregunta::desde($texto, new CategoryGuesser());
}

prueba('reconoce una pregunta con categoría y período', function (): void {
    $p = pregunta('cuanto gaste en super este mes');

    noEsNulo($p);
    esIgual('Supermercado', $p?->categoria);
    esIgual('mes', $p?->periodo);
});

prueba('distingue el mes pasado de este mes', function (): void {
    // El orden importa: si "mes" se evaluara primero, toda pregunta
    // sobre el mes pasado contestaría por el actual.
    esIgual('mes_pasado', pregunta('cuanto gaste el mes pasado')?->periodo);
    esIgual('mes', pregunta('cuanto gaste este mes')?->periodo);
});

prueba('reconoce los otros períodos', function (): void {
    esIgual('dia', pregunta('cuanto gaste hoy')?->periodo);
    esIgual('ayer', pregunta('cuanto gaste ayer')?->periodo);
    esIgual('semana', pregunta('cuanto gaste esta semana')?->periodo);
    esIgual('anio', pregunta('cuanto gaste este año')?->periodo);
});

prueba('un signo de pregunta alcanza', function (): void {
    noEsNulo(pregunta('gastos de nafta?'));
    noEsNulo(pregunta('¿en qué se me va la plata'));
});

prueba('sin período, asume este mes', function (): void {
    esIgual('mes', pregunta('cuanto gaste en delivery')?->periodo);
});

prueba('una pregunta sin categoría es sobre el total', function (): void {
    $p = pregunta('cuanto gaste este mes');

    noEsNulo($p);
    esNulo($p?->categoria);
});

prueba('un gasto normal no es una pregunta', function (): void {
    // Es lo que protege el camino principal: si "1200 super" se leyera
    // como pregunta, el bot dejaría de cargar gastos.
    esNulo(pregunta('1200 super'));
    esNulo(pregunta('nafta 25k'));
    esNulo(pregunta('gasté 45 lucas en la prepaga'), 'tiene importe, es un gasto');
});

prueba('calcula los rangos de fecha', function (): void {
    $hoy = new DateTimeImmutable('2026-09-14');

    [$d, $h, $etiqueta] = pregunta('cuanto gaste el mes pasado')->rango($hoy);
    esIgual('2026-08-01', $d->format('Y-m-d'));
    esIgual('2026-08-31', $h->format('Y-m-d'));
    esIgual('el mes pasado', $etiqueta);

    [$d, $h] = pregunta('cuanto gaste este año')->rango($hoy);
    esIgual('2026-01-01', $d->format('Y-m-d'));
    esIgual('2026-09-14', $h->format('Y-m-d'));
});

prueba('entiende un mes nombrado con año', function (): void {
    $hoy = new DateTimeImmutable('2026-09-15');
    $p = Pregunta::desde('dame detalle de agosto 2026', new CategoryGuesser(), $hoy);

    noEsNulo($p, '"dame detalle" tiene que leerse como pregunta');

    [$d, $h, $etiqueta] = $p->rango($hoy);
    esIgual('2026-08-01', $d->format('Y-m-d'));
    esIgual('2026-08-31', $h->format('Y-m-d'));
    esIgual('en agosto de 2026', $etiqueta);
});

prueba('un mes sin año se resuelve al más reciente que ya pasó', function (): void {
    $hoy = new DateTimeImmutable('2026-09-15');

    // Agosto ya pasó este año: es el de 2026.
    [$d] = Pregunta::desde('cuanto gaste en agosto', new CategoryGuesser(), $hoy)->rango($hoy);
    esIgual('2026-08-01', $d->format('Y-m-d'));

    // Diciembre todavía no llegó: es el del año pasado, no el que viene.
    [$d] = Pregunta::desde('cuanto gaste en diciembre', new CategoryGuesser(), $hoy)->rango($hoy);
    esIgual('2025-12-01', $d->format('Y-m-d'));

    // El mes en curso es el de este año.
    [$d] = Pregunta::desde('cuanto gaste en septiembre', new CategoryGuesser(), $hoy)->rango($hoy);
    esIgual('2026-09-01', $d->format('Y-m-d'));
});

prueba('acepta el mes en formato numérico', function (): void {
    $hoy = new DateTimeImmutable('2026-09-15');

    foreach (['detalle de 08/2026', 'detalle de 2026-08', 'dame el detalle de 8-2026'] as $frase) {
        $p = Pregunta::desde($frase, new CategoryGuesser(), $hoy);
        noEsNulo($p, $frase);
        [$d, $h] = $p->rango($hoy);
        esIgual('2026-08-01', $d->format('Y-m-d'), $frase);
        esIgual('2026-08-31', $h->format('Y-m-d'), $frase);
    }
});

prueba('el mes nombrado le gana al período relativo', function (): void {
    // "Este mes" está en la frase, pero lo que se pidió es febrero.
    $hoy = new DateTimeImmutable('2026-09-15');
    [$d] = Pregunta::desde('este mes no, dame febrero', new CategoryGuesser(), $hoy)->rango($hoy);

    esIgual('2026-02-01', $d->format('Y-m-d'));
});

prueba('un mes con categoría filtra por las dos cosas', function (): void {
    $hoy = new DateTimeImmutable('2026-09-15');
    $p = Pregunta::desde('cuanto gaste en super en agosto', new CategoryGuesser(), $hoy);

    noEsNulo($p);
    esIgual('Supermercado', $p?->categoria);
    [$d] = $p->rango($hoy);
    esIgual('2026-08-01', $d->format('Y-m-d'));
});

prueba('un gasto con un mes adentro sigue siendo un gasto', function (): void {
    // Esto es lo que protege el camino principal: si "1200 super de
    // agosto" se leyera como pregunta, el bot dejaría de cargarlo.
    esNulo(pregunta('1200 super'));
    esNulo(Pregunta::desde('nafta 25k', new CategoryGuesser(), new DateTimeImmutable()));
});
