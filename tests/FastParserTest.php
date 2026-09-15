<?php

declare(strict_types=1);

use Budget\Expense\CategoryGuesser;
use Budget\Expense\Draft;
use Budget\Expense\FastParser;
use Budget\Tests\Doubles\FrozenClock;

function parserEn(string $fecha = '2026-09-14 20:41:00'): FastParser
{
    return new FastParser(FrozenClock::en($fecha), new CategoryGuesser());
}

prueba('extrae monto y comercio de un mensaje corto', function (): void {
    $borrador = parserEn()->parsear('1200 super');

    noEsNulo($borrador);
    esIgual(120_000, $borrador?->monto->centavos);
    esIgual('Super', $borrador?->comercio);
    esIgual('Supermercado', $borrador?->categoria);
});

prueba('funciona con el comercio antes del monto', function (): void {
    $borrador = parserEn()->parsear('nafta 25k');

    esIgual(2_500_000, $borrador?->monto->centavos);
    esIgual('Nafta', $borrador?->comercio);
    esIgual('Transporte', $borrador?->categoria);
});

prueba('limpia el relleno de una frase natural', function (): void {
    $borrador = parserEn()->parsear('gasté 45 lucas en la prepaga');

    esIgual(4_500_000, $borrador?->monto->centavos);
    esIgual('Prepaga', $borrador?->comercio);
    esIgual('Salud', $borrador?->categoria);
});

prueba('resuelve fechas relativas contra el reloj inyectado', function (): void {
    $parser = parserEn('2026-09-14 20:41:00');

    esIgual('2026-09-14', $parser->parsear('1200 super')?->fecha->format('Y-m-d'), 'por defecto hoy');
    esIgual('2026-09-13', $parser->parsear('ayer 1200 super')?->fecha->format('Y-m-d'), 'ayer');
    esIgual('2026-09-12', $parser->parsear('anteayer 1200 super')?->fecha->format('Y-m-d'), 'anteayer');
});

prueba('anteayer gana sobre ayer', function (): void {
    esIgual(
        '2026-09-12',
        parserEn('2026-09-14 10:00:00')->parsear('anteayer pagué 3000 el cine')?->fecha->format('Y-m-d')
    );
});

prueba('detecta dólares', function (): void {
    $borrador = parserEn()->parsear('u$s 100 hosting');

    esIgual('USD', $borrador?->monto->moneda);
    esIgual('US$100', $borrador?->monto->formatear());
});

prueba('detecta el medio de pago y lo saca del comercio', function (): void {
    $borrador = parserEn()->parsear('18450 coto visa');

    esIgual('Visa', $borrador?->medioPago);
    esIgual('Coto', $borrador?->comercio);
});

prueba('no rompe palabras al quitar relleno', function (): void {
    // Quitar la preposición "a" no puede mutilar "almacen".
    esIgual('Almacen', parserEn()->parsear('900 almacen')?->comercio);
});

prueba('no confunde supervielle con super', function (): void {
    $borrador = parserEn()->parsear('5000 supervielle');

    esIgual('Supervielle', $borrador?->comercio);
    esNulo($borrador?->categoria, 'ninguna palabra clave debería coincidir');
});

prueba('baja la confianza cuando no queda comercio', function (): void {
    $borrador = parserEn()->parsear('1200');

    esIgual('', $borrador?->comercio);
    esIgual(0.60, $borrador?->confianza);
});

prueba('marca la fuente y el modelo del camino rápido', function (): void {
    $borrador = parserEn()->parsear('1200 super');

    esIgual(Draft::FUENTE_TEXTO, $borrador?->fuente);
    esIgual('regex', $borrador?->modelo, 'sin gasto de tokens');
});

prueba('devuelve null cuando no hay importe', function (): void {
    esNulo(parserEn()->parsear('hola, como va?'));
    esNulo(parserEn()->parsear(''));
});

prueba('conserva el texto original como descripción', function (): void {
    esIgual('gasté 45 lucas en la prepaga', parserEn()->parsear('gasté 45 lucas en la prepaga')?->descripcion);
});

prueba('el borrador es inmutable al corregirlo', function (): void {
    $original = parserEn()->parsear('1200 super');
    noEsNulo($original);

    $corregido = $original?->conCategoria('Comida y delivery');

    esIgual('Supermercado', $original?->categoria, 'el original no cambia');
    esIgual('Comida y delivery', $corregido?->categoria);
});

prueba('se declara incompetente ante una frase con varios gastos', function (): void {
    // Caso real de producción: el parser respondió con confianza 0.95 y
    // se quedó con "$30" y un comercio de catorce palabras. Un camino
    // rápido que no reconoce la estructura tiene que pasar la pelota,
    // no adivinar.
    esNulo(parserEn()->parsear(
        'Me fui de fiesta y gaste 30 mil en estacionamiento y 200 en entradas y 100 en bebidas'
    ));
});

prueba('se declara incompetente cuando hay más de un importe', function (): void {
    esNulo(parserEn()->parsear('200 entradas y 100 bebidas'), 'dos importes');
    esNulo(parserEn()->parsear('1200 super y 800 nafta'), 'dos gastos cortos');
});

prueba('se declara incompetente ante una frase larga', function (): void {
    esNulo(parserEn()->parsear(
        'hoy estuve dando vueltas por el centro y al final termine gastando 5000'
    ));
});

prueba('sigue resolviendo los mensajes cortos de siempre', function (): void {
    esIgual(120_000, parserEn()->parsear('1200 super')?->monto->centavos);
    esIgual(2_500_000, parserEn()->parsear('nafta 25k')?->monto->centavos);
    esIgual(4_500_000, parserEn()->parsear('gasté 45 lucas en la prepaga')?->monto->centavos);
    esIgual(3_000_000, parserEn()->parsear('30 mil estacionamiento')?->monto->centavos, 'mil como palabra');
});

prueba('el parser rapido ve un importe donde hay un año, y por eso el orden importa', function (): void {
    // "pasame detalle de agosto 2026" tiene un número suelto, así que el
    // parser lo toma por un importe de $2.026. No está mal: es lo que
    // hace. Lo que estaba mal era correrlo antes de mirar si el mensaje
    // era una pregunta, y el bot ofrecía cargar ese gasto.
    $borrador = parserEn()->parsear('pasame detalle de agosto 2026');

    noEsNulo($borrador, 'el parser efectivamente lo agarra');
    esIgual(202_600, $borrador?->monto->centavos, 'lee el año como importe');
});

prueba('el Dispatcher mira la pregunta antes que el parser', function (): void {
    // El candado sobre el bug de verdad: los dos caminos existen y
    // funcionan, lo que falló fue el orden entre ellos. Si alguien lo
    // vuelve a invertir, toda pregunta con un número adentro se carga
    // como gasto otra vez.
    $codigo = file_get_contents(__DIR__ . '/../src/Handler/Dispatcher.php');
    afirmar($codigo !== false, 'se puede leer el Dispatcher');

    $pregunta = strpos($codigo, 'Pregunta::desde(');
    $parser = strpos($codigo, '$this->parser->parsear(');

    afirmar($pregunta !== false, 'el Dispatcher consulta Pregunta');
    afirmar($parser !== false, 'y usa el parser rápido');
    afirmar($pregunta < $parser, 'la pregunta se mira primero, o un año vuelve a ser un gasto');
});
