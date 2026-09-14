<?php

declare(strict_types=1);

use Budget\Expense\CategoryGuesser;

prueba('reconoce una salida como salida y no como entretenimiento', function (): void {
    $c = new CategoryGuesser();

    esIgual('Salidas y fiestas', $c->adivinar('me fui de fiesta'), 'fiesta');
    esIgual('Salidas y fiestas', $c->adivinar('entradas al boliche'), 'entradas');
    esIgual('Salidas y fiestas', $c->adivinar('bebidas'), 'bebidas');
    esIgual('Salidas y fiestas', $c->adivinar('cumple de un amigo'), 'cumple');
    esIgual('Salidas y fiestas', $c->adivinar('regalo de cumpleaños'), 'con eñe');
});

prueba('el cine y el gimnasio siguen siendo entretenimiento', function (): void {
    $c = new CategoryGuesser();

    esIgual('Entretenimiento', $c->adivinar('cine'));
    esIgual('Entretenimiento', $c->adivinar('cuota del gym'));
});

prueba('no confunde palabras que contienen a otra', function (): void {
    $c = new CategoryGuesser();

    esNulo($c->adivinar('supervielle'), 'super no matchea supervielle');
    esNulo($c->adivinar('barrio norte'), 'bar no matchea barrio');
});

prueba('normaliza acentos para poder comparar', function (): void {
    esIgual('educacion', CategoryGuesser::normalizar('Educación'));
    esIgual('cumpleanos', CategoryGuesser::normalizar('cumpleaños'));
});

prueba('reconoce a las distribuidoras de gas y luz del interior', function (): void {
    // Salieron de los pagos reales de Mercado Pago: "Ecogas Centro"
    // quedaba sin categoría.
    $c = new CategoryGuesser();

    esIgual('Servicios', $c->adivinar('Ecogas Centro'), 'ecogas');
    esIgual('Servicios', $c->adivinar('pago epec'), 'epec');
    esIgual('Servicios', $c->adivinar('camuzzi gas'), 'camuzzi');
});
