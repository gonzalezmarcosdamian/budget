<?php

declare(strict_types=1);

use Budget\Support\Money;

prueba('parsea un entero suelto', function (): void {
    $importe = Money::parsear('1200 super');

    noEsNulo($importe);
    esIgual(120_000, $importe?->centavos);
});

prueba('parsea el punto como separador de miles', function (): void {
    esIgual(1_845_000, Money::parsear('$18.450 coto')?->centavos);
    esIgual(120_000, Money::parsear('1.200')?->centavos);
});

prueba('parsea la coma como separador decimal', function (): void {
    esIgual(120_050, Money::parsear('1.200,50')?->centavos);
    esIgual(1_050, Money::parsear('10,50')?->centavos);
});

prueba('parsea el punto decimal cuando no hay grupo de miles', function (): void {
    esIgual(150, Money::parsear('1.50')?->centavos);
});

prueba('entiende la jerga de miles', function (): void {
    esIgual(2_500_000, Money::parsear('nafta 25k')?->centavos, '25k');
    esIgual(4_500_000, Money::parsear('45 lucas de prepaga')?->centavos, 'lucas');
    esIgual(100_000, Money::parsear('1 luca el cafe')?->centavos, 'singular');
});

prueba('entiende millones', function (): void {
    esIgual(200_000_000, Money::parsear('2 palos')?->centavos, 'palos');
    esIgual(150_000_000, Money::parsear('1,5 millones')?->centavos, 'millones con decimal');
});

prueba('no confunde una palabra con k adentro', function (): void {
    esIgual(120_000, Money::parsear('1200 kiosco')?->centavos);
});

prueba('devuelve null cuando no hay importe', function (): void {
    esNulo(Money::parsear('hola que tal'));
    esNulo(Money::parsear(''));
    esNulo(Money::parsear('0'), 'cero no es un gasto');
});

prueba('formatea a la argentina', function (): void {
    esIgual('$18.450', Money::deCentavos(1_845_000)->formatear());
    esIgual('$1.200,50', Money::deCentavos(120_050)->formatear());
    esIgual('US$100', Money::deCentavos(10_000, 'USD')->formatear());
});

prueba('serializa a decimal para la base', function (): void {
    esIgual('18450.00', Money::deCentavos(1_845_000)->aDecimal());
    esIgual('1200.50', Money::deCentavos(120_050)->aDecimal());
});

prueba('reconstruye desde el decimal de MySQL sin perder centavos', function (): void {
    esIgual(120_050, Money::deDecimal('1200.50')->centavos);
});

prueba('suma sin errores de coma flotante', function (): void {
    $total = Money::deCentavos(0);

    for ($i = 0; $i < 10; $i++) {
        $total = $total->mas(Money::deDecimal('0.10'));
    }

    esIgual('1.00', $total->aDecimal());
});

prueba('se niega a operar entre monedas distintas', function (): void {
    lanza(
        InvalidArgumentException::class,
        static fn () => Money::deCentavos(100, 'ARS')->mas(Money::deCentavos(100, 'USD'))
    );
});

prueba('calcula el porcentaje sobre un total', function (): void {
    esIgual(80, Money::deCentavos(8_000)->porcentajeDe(Money::deCentavos(10_000)));
    esIgual(0, Money::deCentavos(100)->porcentajeDe(Money::deCentavos(0)), 'total cero no divide');
});

prueba('entiende "mil" escrito como palabra', function (): void {
    // Producción, 14/09/2026: "gaste 30 mil" se guardó como $30.
    esIgual(3_000_000, Money::parsear('30 mil')?->centavos, '30 mil');
    esIgual(3_000_000, Money::parsear('gaste 30 mil en estacionamiento')?->centavos, 'en una frase');
    esIgual(150_000, Money::parsear('1,5 mil')?->centavos, 'con decimal');
});

prueba('"mil" no le roba el match a "millones"', function (): void {
    esIgual(200_000_000, Money::parsear('2 millones')?->centavos, 'millones');
    esIgual(200_000_000, Money::parsear('2 palos')?->centavos, 'palos');
});

prueba('enumera todos los importes de un texto', function (): void {
    // Es lo que permite al parser rápido darse cuenta de que el mensaje
    // trae varios gastos y no le corresponde a él.
    esIgual(3, count(Money::tokensNumericos('30 mil estacionamiento, 200 entradas y 100 bebidas')));
    esIgual(1, count(Money::tokensNumericos('1200 super')));
    esIgual(0, count(Money::tokensNumericos('hola que tal')));
});
