<?php

declare(strict_types=1);

use Budget\Reporte\Patrimonio;
use Budget\Support\Money;

/**
 * El mes contra mes de un portafolio tiene una trampa: el total puede
 * subir porque los precios subieron o porque entró plata nueva, y son
 * cosas opuestas. Estos tests son el candado sobre esa distinción.
 */

/** @return array<string,array<string,mixed>> */
function posiciones(array $filas): array
{
    $salida = [];

    foreach ($filas as $simbolo => [$cantidad, $precio]) {
        $salida[$simbolo] = [
            'simbolo' => $simbolo,
            'descripcion' => $simbolo,
            'tipo' => 'CEDEARS',
            'cantidad' => (float) $cantidad,
            'precio' => (float) $precio,
            'valor' => Money::deCentavos((int) round($cantidad * $precio * 100)),
        ];
    }

    return $salida;
}

prueba('sin movimientos de cartera, todo el cambio es precio', function (): void {
    $c = Patrimonio::comparar(
        posiciones(['SPY' => [100, 22000]]),
        posiciones(['SPY' => [100, 20000]])
    );

    esIgual(200_000_00, $c['porPrecio'], 'subió 2.000 por acción sobre 100 acciones');
    esIgual(0, $c['porAporte'], 'no entró ni salió plata');
    esIgual(10.0, $c['rendimiento'], '2.000.000 sobre 2.000.000 iniciales');
});

prueba('comprar mas no es haber ganado', function (): void {
    // Este es el error que la clase existe para no cometer: el total
    // sube 50%, pero el rendimiento es cero.
    $c = Patrimonio::comparar(
        posiciones(['SPY' => [150, 20000]]),
        posiciones(['SPY' => [100, 20000]])
    );

    esIgual(100_000_000, $c['total']->centavos - $c['anterior']->centavos, 'el total sube 1.000.000');
    esIgual(0, $c['porPrecio'], 'pero no ganó un peso');
    esIgual(100_000_000, $c['porAporte'], 'fue todo aporte');
    esIgual(0.0, $c['rendimiento']);
});

prueba('la descomposicion es exacta aunque cambien precio y cantidad juntos', function (): void {
    // La propiedad que sostiene todo el reporte: precio + aporte tiene
    // que dar exactamente la diferencia de valor. Si no cierra, alguna
    // de las dos cifras está mintiendo.
    $c = Patrimonio::comparar(
        posiciones(['SPY' => [150, 22000], 'GLD' => [67, 12560]]),
        posiciones(['SPY' => [100, 20000], 'GLD' => [80, 13000]])
    );

    esIgual(
        $c['total']->centavos - $c['anterior']->centavos,
        $c['porPrecio'] + $c['porAporte'],
        'precio + aporte = diferencia de valor'
    );
});

prueba('una posicion nueva es aporte, no rendimiento', function (): void {
    $c = Patrimonio::comparar(
        posiciones(['SPY' => [100, 20000], 'QQQ' => [10, 50000]]),
        posiciones(['SPY' => [100, 20000]])
    );

    esIgual(0, $c['porPrecio']);
    esIgual(500_000_00, $c['porAporte'], 'los QQQ nuevos son plata que entró');

    $qqq = null;

    foreach ($c['posiciones'] as $p) {
        if ($p['simbolo'] === 'QQQ') {
            $qqq = $p;
        }
    }

    noEsNulo($qqq);
    afirmar($qqq['nueva'] === true, 'se marca como nueva');
    esNulo($qqq['variacion'], 'sin precio anterior no hay variación que calcular');
});

prueba('una posicion vendida es retiro, no perdida', function (): void {
    $c = Patrimonio::comparar(
        posiciones(['SPY' => [100, 20000]]),
        posiciones(['SPY' => [100, 20000], 'XLE' => [16, 50000]])
    );

    esIgual(0, $c['porPrecio'], 'vender no es perder');
    esIgual(-800_000_00, $c['porAporte'], 'salieron 800.000 de la cartera');
});

prueba('el rendimiento se mide sobre lo que habia, no sobre lo que hay', function (): void {
    // Dividir por el total de hoy licuaría el resultado justo en el mes
    // en que se aportó plata, que es cuando más importa leerlo bien.
    $c = Patrimonio::comparar(
        posiciones(['SPY' => [200, 22000]]),
        posiciones(['SPY' => [100, 20000]])
    );

    esIgual(200_000_00, $c['porPrecio'], 'las 100 que ya tenía subieron 2.000 cada una');
    esIgual(10.0, $c['rendimiento'], '200.000 sobre 2.000.000, no sobre 4.400.000');
});

prueba('sin foto anterior no se inventa un rendimiento', function (): void {
    $c = Patrimonio::comparar(posiciones(['SPY' => [100, 20000]]), []);

    esNulo($c['rendimiento'], 'dividir por cero daría 0%, que sería mentir');
    esIgual(200_000_000, $c['porAporte'], 'la cartera entera cuenta como aporte');
});

prueba('las posiciones se ordenan por cuanto movieron la aguja en plata', function (): void {
    // Un 40% sobre $20.000 es ruido al lado de un 2% sobre $3.000.000.
    $c = Patrimonio::comparar(
        posiciones(['CHICA' => [10, 1400], 'GRANDE' => [100, 30600]]),
        posiciones(['CHICA' => [10, 1000], 'GRANDE' => [100, 30000]])
    );

    esIgual('GRANDE', $c['posiciones'][0]['simbolo'], 'primero la que más plata movió');
    esIgual('CHICA', $c['posiciones'][1]['simbolo']);
});

prueba('vender una posicion no reporta rendimiento cero', function (): void {
    // Con dos fotos no hay forma de saber a qué precio se vendió. Decir
    // 0,00% es afirmar algo que el dato no sostiene.
    $c = Patrimonio::comparar(
        posiciones(['GLD' => [10, 12560]]),
        posiciones(['GLD' => [10, 12560], 'SPY' => [100, 20000]])
    );

    $spy = null;

    foreach ($c['posiciones'] as $p) {
        if ($p['simbolo'] === 'SPY') {
            $spy = $p;
        }
    }

    noEsNulo($spy);
    afirmar($spy['cerrada'] === true, 'se marca como cerrada');
    esNulo($spy['variacion'], 'sin precio de venta no hay porcentaje que afirmar');
});
