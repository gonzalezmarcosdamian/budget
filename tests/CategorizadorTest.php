<?php

declare(strict_types=1);

use Budget\Ai\Categorizador;

/**
 * Lo que devuelve el modelo es texto de afuera. Escribir una categoría
 * inventada en cuatrocientos movimientos es peor que no clasificar
 * ninguno, así que todo lo que no encaje se descarta.
 */

function respuestaDe(array $asignaciones): array
{
    return ['candidates' => [['content' => ['parts' => [
        ['text' => json_encode(['asignaciones' => $asignaciones])],
    ]]]]];
}

prueba('traduce una respuesta bien formada', function (): void {
    $r = Categorizador::interpretar(
        respuestaDe([['n' => 1, 'categoria' => 'Supermercado', 'tipo' => 'gasto']]),
        ['Coto']
    );

    esIgual('Supermercado', $r['Coto']['categoria'] ?? null);
    esIgual('gasto', $r['Coto']['tipo'] ?? null);
});

prueba('descarta una categoría que no existe', function (): void {
    // El modelo puede inventar "Mascotas" y esa categoría no está en la
    // base: aplicarla dejaría los movimientos sin categoría igual, pero
    // con una regla aprendida que apunta a la nada.
    $r = Categorizador::interpretar(
        respuestaDe([['n' => 1, 'categoria' => 'Mascotas', 'tipo' => 'gasto']]),
        ['Veterinaria']
    );

    esIgual([], $r);
});

prueba('descarta un índice fuera de rango', function (): void {
    $r = Categorizador::interpretar(
        respuestaDe([
            ['n' => 5, 'categoria' => 'Otros', 'tipo' => 'gasto'],
            ['n' => 0, 'categoria' => 'Otros', 'tipo' => 'gasto'],
        ]),
        ['Uno', 'Dos']
    );

    esIgual([], $r, 'ni el 5 ni el 0 existen en una lista de dos');
});

prueba('un tipo desconocido cae a gasto', function (): void {
    $r = Categorizador::interpretar(
        respuestaDe([['n' => 1, 'categoria' => 'Otros', 'tipo' => 'chirimbolo']]),
        ['Algo']
    );

    esIgual('gasto', $r['Algo']['tipo'] ?? null);
});

prueba('acepta ingreso e inversion', function (): void {
    $r = Categorizador::interpretar(
        respuestaDe([
            ['n' => 1, 'categoria' => 'Otros ingresos', 'tipo' => 'ingreso'],
            ['n' => 2, 'categoria' => 'Inversiones', 'tipo' => 'inversion'],
        ]),
        ['Cobro', 'Compra CEDEAR']
    );

    esIgual('ingreso', $r['Cobro']['tipo'] ?? null);
    esIgual('inversion', $r['Compra CEDEAR']['tipo'] ?? null);
});

prueba('una respuesta sin texto lanza en vez de devolver vacío', function (): void {
    // Silenciar esto haría que una tanda entera se saltee sin que nadie
    // se entere de que el proveedor está fallando.
    lanza(
        RuntimeException::class,
        static fn (): array => Categorizador::interpretar(['candidates' => []], ['Algo'])
    );
});

prueba('una respuesta que no es JSON lanza', function (): void {
    lanza(
        RuntimeException::class,
        static fn (): array => Categorizador::interpretar(
            ['candidates' => [['content' => ['parts' => [['text' => 'perdón, no puedo']]]]]],
            ['Algo']
        )
    );
});

prueba('ignora entradas que no son objetos', function (): void {
    $r = Categorizador::interpretar(
        respuestaDe(['basura', ['n' => 1, 'categoria' => 'Otros', 'tipo' => 'gasto']]),
        ['Algo']
    );

    esIgual(1, count($r));
});

prueba('las categorías del prompt existen todas en las migraciones', function (): void {
    // Una categoría que el modelo puede elegir pero que no está en la
    // base no clasifica nada: se descarta en silencio.
    // Lee todas las migraciones y no una lista fija: agregar una
    // migración no puede romper este test.
    $sql = '';

    foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $archivo) {
        $sql .= file_get_contents($archivo);
    }

    foreach (Budget\Ai\Prompt::CATEGORIAS as $categoria) {
        afirmar(
            str_contains($sql, "'" . $categoria . "'"),
            "la categoría \"{$categoria}\" no se crea en ninguna migración"
        );
    }
});
