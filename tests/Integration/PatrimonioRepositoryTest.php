<?php

declare(strict_types=1);

use Budget\Repository\PatrimonioRepository;
use Budget\Tests\Doubles\TestDatabase;

/**
 * El cálculo del mes contra mes se prueba puro en PatrimonioTest. Acá se
 * prueba lo único que un doble no podría: que lo que se guarda vuelve
 * igual. Existe porque no volvía: la columna se llama `precio_ars` y se
 * leía como `precio`, así que todas las posiciones volvían con precio
 * cero y el rendimiento habría dado cualquier cosa.
 */

/** @return list<array<string,mixed>> */
function posicionesDeMuestra(): array
{
    return [
        [
            'simbolo' => 'SPY',
            'descripcion' => 'Cedear Spdr S&P 500',
            'tipo' => 'CEDEARS',
            'origen' => PatrimonioRepository::ORIGEN_IOL,
            'clase' => PatrimonioRepository::CLASE_INVERSION,
            'cantidad' => 115.0,
            'precio' => 20280.0,
            'valor' => 115.0 * 20280.0,
        ],
        [
            'simbolo' => 'USD-MP',
            'descripcion' => 'Dolares en Mercado Pago',
            'tipo' => 'MONEDA',
            'origen' => PatrimonioRepository::ORIGEN_MERCADOPAGO,
            'clase' => PatrimonioRepository::CLASE_RESERVA,
            'cantidad' => 1000.0,
            'precio' => 1450.0,
            'valor' => 1000.0 * 1450.0,
        ],
    ];
}

prueba('[db] una foto vuelve con precio y cantidad intactos', function (): void {
    TestDatabase::limpiar();
    $repo = new PatrimonioRepository(TestDatabase::pdo());
    $ana = nuevoUsuario();

    $repo->guardar($ana, new DateTimeImmutable('2026-09-15'), posicionesDeMuestra());
    $foto = $repo->ultimo($ana);

    noEsNulo($foto);
    esIgual('2026-09-15', $foto['fecha']->format('Y-m-d'));
    esIgual(378_220_000, $foto['total']->centavos, '2.332.200 + 1.450.000');

    $spy = $foto['posiciones']['SPY'];
    esIgual(115.0, $spy['cantidad']);
    esIgual(20280.0, $spy['precio'], 'el precio tiene que volver, no venir en cero');
    esIgual(PatrimonioRepository::CLASE_INVERSION, $spy['clase']);

    $usd = $foto['posiciones']['USD-MP'];
    esIgual(PatrimonioRepository::CLASE_RESERVA, $usd['clase']);
    esIgual(PatrimonioRepository::ORIGEN_MERCADOPAGO, $usd['origen']);
});

prueba('[db] volver a sacar la foto el mismo dia la reemplaza', function (): void {
    // Corregir una foto mal tomada tiene que ser posible sin borrar a
    // mano, y sin que queden dos fotos del mismo día compitiendo.
    TestDatabase::limpiar();
    $repo = new PatrimonioRepository(TestDatabase::pdo());
    $ana = nuevoUsuario();
    $dia = new DateTimeImmutable('2026-09-15');

    $repo->guardar($ana, $dia, posicionesDeMuestra());
    $repo->guardar($ana, $dia, [[
        'simbolo' => 'GLD',
        'descripcion' => 'Oro',
        'tipo' => 'CEDEARS',
        'cantidad' => 10.0,
        'precio' => 12560.0,
        'valor' => 125600.0,
    ]]);

    $foto = $repo->ultimo($ana);

    esIgual(1, count($foto['posiciones']), 'queda sólo la última');
    esIgual(12_560_000, $foto['total']->centavos);

    $cuantas = TestDatabase::pdo()
        ->query('SELECT COUNT(*) FROM patrimonio_snapshot')
        ->fetchColumn();
    esIgual('1', (string) $cuantas, 'y una sola foto de ese día');
});

prueba('[db] la foto de comparacion es la ultima del mes anterior', function (): void {
    // Con el corte en "hace treinta días" esto fallaba: la foto del 20
    // de agosto queda fuera de la ventana del 15 de septiembre, y el
    // bot habría dicho "es la primera foto" teniendo una de agosto.
    TestDatabase::limpiar();
    $repo = new PatrimonioRepository(TestDatabase::pdo());
    $ana = nuevoUsuario();

    $repo->guardar($ana, new DateTimeImmutable('2026-08-20'), posicionesDeMuestra());
    $repo->guardar($ana, new DateTimeImmutable('2026-09-10'), posicionesDeMuestra());
    $repo->guardar($ana, new DateTimeImmutable('2026-09-15'), posicionesDeMuestra());

    $previa = $repo->delMesAnterior($ana, new DateTimeImmutable('2026-09-15'));

    noEsNulo($previa);
    esIgual(
        '2026-08-20',
        $previa['fecha']->format('Y-m-d'),
        'la de agosto, no la del 10 de septiembre: ésa es del mismo mes'
    );
});

prueba('[db] con una sola foto no hay mes anterior que buscar', function (): void {
    TestDatabase::limpiar();
    $repo = new PatrimonioRepository(TestDatabase::pdo());
    $ana = nuevoUsuario();

    $repo->guardar($ana, new DateTimeImmutable('2026-09-15'), posicionesDeMuestra());

    esNulo($repo->delMesAnterior($ana, new DateTimeImmutable('2026-09-15')));
});

prueba('[db] las fotos no cruzan usuarios', function (): void {
    TestDatabase::limpiar();
    $repo = new PatrimonioRepository(TestDatabase::pdo());
    $ana = nuevoUsuario('Ana');
    $beto = nuevoUsuario('Beto');

    $repo->guardar($ana, new DateTimeImmutable('2026-09-15'), posicionesDeMuestra());

    esNulo($repo->ultimo($beto), 'Beto no ve la cartera de Ana');
});
