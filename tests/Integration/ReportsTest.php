<?php

declare(strict_types=1);

use Budget\Expense\Draft;
use Budget\Handler\Reports;
use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
use Budget\Repository\PatrimonioRepository;
use Budget\Repository\RecurringRepository;
use Budget\Support\Money;
use Budget\Tests\Doubles\TestDatabase;

/**
 * Los reportes son lo único que el usuario ve. Se prueban contra la base
 * de verdad porque su trabajo es justamente armar consultas: un doble
 * sólo probaría el sprintf.
 */

function gastoConfirmado(
    int $userId,
    int $pesos,
    string $comercio,
    string $fecha = '2026-09-14',
    string $tipo = Draft::TIPO_GASTO,
): int {
    $gastos = new ExpenseRepository(TestDatabase::pdo());

    $id = $gastos->guardarBorrador($userId, new Draft(
        monto: Money::deCentavos($pesos * 100),
        fecha: new DateTimeImmutable($fecha),
        comercio: $comercio,
        descripcion: $comercio,
        tipo: $tipo,
    ), null);
    $gastos->confirmar($userId, $id);

    return $id;
}

function reportes(): Reports
{
    $pdo = TestDatabase::pdo();

    return new Reports(
        new ExpenseRepository($pdo),
        new CategoryRepository($pdo),
        new RecurringRepository($pdo),
        new PatrimonioRepository($pdo),
    );
}

/** Marca una contraparte como cuenta propia del usuario. */
function cuentaPropia(int $userId, string $externo, string $alias): void
{
    $pdo = TestDatabase::pdo();
    $pdo->prepare(
        'INSERT INTO contrapartes (user_id, externo, alias, es_propia) VALUES (?, ?, ?, 1)'
    )->execute([$userId, $externo, $alias]);
}

prueba('[db] /flujo separa lo propio de lo de terceros', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    cuentaPropia($ana, '999', 'Mi banco');

    gastoConfirmado($ana, 500_000, 'Cobro de Beto', '2026-09-05', Draft::TIPO_INGRESO);

    // Plata propia entrando desde mi banco: no es un ingreso de verdad.
    $propio = gastoConfirmado($ana, 800_000, 'Mi banco', '2026-09-06', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '999' WHERE id = {$propio}");

    gastoConfirmado($ana, 300_000, 'Super', '2026-09-07');

    // Plata volviendo a mi propio banco: tampoco es gasto.
    $vuelta = gastoConfirmado($ana, 200_000, 'Mi banco', '2026-09-08');
    $pdo->exec("UPDATE expenses SET contraparte = '999' WHERE id = {$vuelta}");

    $texto = $reportes->flujo($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'De terceros: <b>$500.000</b>');
    contiene($texto, 'Propio: <b>$800.000</b>');
    contiene($texto, 'A terceros: <b>$300.000</b>');
    contiene($texto, 'A cuenta propia: <b>$200.000</b>');
});

prueba('[db] el gasto real es el neto contra terceros', function (): void {
    // Si pagás $100.000 de una cena y te devuelven $70.000, gastaste
    // $30.000. Sin netear el total dice $100.000 y no se parece a nada.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    $cena = gastoConfirmado($ana, 100_000, 'Restaurante', '2026-09-05');
    $pdo->exec("UPDATE expenses SET contraparte = '111' WHERE id = {$cena}");

    $devuelto = gastoConfirmado($ana, 70_000, 'Beto', '2026-09-06', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '111' WHERE id = {$devuelto}");

    contiene(
        $reportes->flujo($ana, new DateTimeImmutable('2026-09-14')),
        'Gasto real: $30.000</b>',
        'lo que saliste menos lo que te devolvieron'
    );
});

prueba('[db] mover plata a la cuenta propia no cuenta como gasto real', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    cuentaPropia($ana, '999', 'Mi banco');

    gastoConfirmado($ana, 200_000, 'Super', '2026-09-07');

    $mudanza = gastoConfirmado($ana, 5_000_000, 'Mi banco', '2026-09-08');
    $pdo->exec("UPDATE expenses SET contraparte = '999' WHERE id = {$mudanza}");

    $texto = $reportes->flujo($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Gasto real: $200.000</b>', 'los 5 palos cambiaron de bolsillo, no se gastaron');
    contiene($texto, 'A cuenta propia: <b>$5.000.000</b>', 'pero se muestran');
});

prueba('[db] el flujo no saca los prestamos ni las inversiones: los clasifica', function (): void {
    // El error anterior fue excluirlos porque "no son gastos". Pero la
    // plata se fue de la cuenta igual: un flujo de caja al que le
    // sacás movimientos deja de explicar dónde está la plata.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $categorias = new CategoryRepository($pdo);
    $ana = nuevoUsuario();

    $prestamos = $categorias->idPorNombre($ana, ExpenseRepository::CATEGORIA_PRESTAMOS);
    noEsNulo($prestamos, 'la categoría de préstamos existe en las migraciones');

    gastoConfirmado($ana, 200_000, 'Super', '2026-09-06');

    $alquiler = gastoConfirmado($ana, 400_000, 'Juan Manuel', '2026-09-10');
    $pdo->exec("UPDATE expenses SET naturaleza = 'fijo', contraparte = '555' WHERE id = {$alquiler}");

    $prestado = gastoConfirmado($ana, 300_000, 'Beto', '2026-09-11');
    $pdo->exec("UPDATE expenses SET category_id = {$prestamos}, contraparte = '777' WHERE id = {$prestado}");

    gastoConfirmado($ana, 100_000, 'CEDEARs', '2026-09-12', Draft::TIPO_INVERSION);

    $texto = $reportes->flujo($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Fijos — <b>$400.000</b>', 'el alquiler es fijo, aunque vaya a una persona');
    contiene($texto, 'Consumo — <b>$200.000</b>');
    contiene($texto, 'Prestado — <b>$300.000</b>', 'el préstamo se muestra aparte, no se borra');
    contiene($texto, 'Invertido — <b>$100.000</b>', 'la inversión también baja la caja');
    contiene($texto, 'A cuenta propia: <b>$100.000</b>', 'comprar CEDEARs es mover plata a lo propio');
});

prueba('[db] sin ningún movimiento el flujo no inventa nada', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    contiene(
        $reportes->flujo($ana, new DateTimeImmutable('2026-09-14')),
        'Todavía no hay movimientos',
        'sin datos no hay flujo que mostrar'
    );
});

prueba('[db] /anio muestra mes a mes y no se cuela el año anterior', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 111_111, 'Enero', '2026-01-15');
    gastoConfirmado($ana, 222_222, 'Marzo', '2026-03-15');
    gastoConfirmado($ana, 999_999, 'Diciembre pasado', '2025-12-15');

    $texto = $reportes->delAnio($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Enero', 'aparece el mes con gastos');
    contiene($texto, '111.111', 'con su total');
    contiene($texto, 'Marzo');
    afirmar(!str_contains($texto, 'Febrero'), 'un mes sin gastos no ocupa una línea');
    afirmar(!str_contains($texto, '999.999'), 'el año anterior no entra');
    contiene($texto, '333.333', 'el total es la suma del año en curso');
});

prueba('[db] /anio no corta los meses que todavía no llegaron', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    // Un gasto futuro dentro del mes en curso: el corte es por mes, no
    // por día, así que tiene que entrar.
    gastoConfirmado($ana, 50_000, 'Fin de mes', '2026-09-30');

    contiene(
        $reportes->delAnio($ana, new DateTimeImmutable('2026-09-14')),
        '50.000',
        'el mes en curso se cuenta entero'
    );
});

prueba('[db] los reportes no cruzan usuarios', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario('Ana');
    $beto = nuevoUsuario('Beto');

    gastoConfirmado($ana, 777_777, 'Sueldo de Ana', '2026-09-05', Draft::TIPO_INGRESO);
    gastoConfirmado($ana, 444_444, 'Gasto de Ana', '2026-09-05');

    $hoy = new DateTimeImmutable('2026-09-14');

    afirmar(!str_contains($reportes->flujo($beto, $hoy), '777.777'), 'Beto no ve los ingresos de Ana');
    afirmar(!str_contains($reportes->delAnio($beto, $hoy), '444.444'), 'ni sus gastos');
});

prueba('[db] /recurrentes lista lo que se repite, con o sin categoría', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    // Sin category_id: el LEFT JOIN devuelve emoji nulo y tiene que
    // caer al de por defecto en vez de romper.
    (new RecurringRepository(TestDatabase::pdo()))->crear(
        $ana,
        'Alquiler',
        Money::deCentavos(981_000_00),
        10,
        null,
        'fijo',
        'Juan Manuel'
    );

    $texto = $reportes->recurrentes($ana);

    contiene($texto, 'Alquiler', 'aparece el gasto');
    contiene($texto, '$981.000', 'con el último monto conocido');
    contiene($texto, 'día 10', 'y el día en que toca');
    contiene($texto, 'Juan Manuel', 'la nota también');
    contiene($texto, '🔔', 'sin categoría cae al emoji por defecto');
});

prueba('[db] /recurrentes de otro usuario no se ve', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario('Ana');
    $beto = nuevoUsuario('Beto');

    (new RecurringRepository(TestDatabase::pdo()))
        ->crear($ana, 'Alquiler de Ana', Money::deCentavos(981_000_00), 10, null);

    contiene($reportes->recurrentes($beto), 'No tenés gastos recurrentes', 'Beto no ve lo de Ana');
});

prueba('[db] /mes aclara cuanto del total es estimado', function (): void {
    // Diez de los dieciocho meses de alquiler se reconstruyeron con el
    // IPC porque se pagaron por otra app. Mostrarlos sin aclararlo los
    // presenta como un dato medido, y no lo son.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 200_000, 'Super', '2026-09-03');
    $supuesto = gastoConfirmado($ana, 981_000, 'Alquiler', '2026-09-10');
    $pdo->exec("UPDATE expenses SET fuente = 'estimado' WHERE id = {$supuesto}");

    $texto = $reportes->delMes($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, '$1.181.000', 'el total los incluye');
    contiene($texto, '$981.000 son estimados', 'pero dice cuánto no se midió');
});

prueba('[db] sin estimados el reporte no agrega ruido', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 200_000, 'Super', '2026-09-03');

    afirmar(
        !str_contains($reportes->delMes($ana, new DateTimeImmutable('2026-09-14')), 'estimados'),
        'la aclaración sólo aparece cuando hace falta'
    );
});

prueba('[db] un ingreso de alguien a quien no le mandas nada no baja el gasto', function (): void {
    // El caso que destapó el error: un canon mensual de un cliente.
    // Con el neto sin piso, esos $120.000 descontaban gasto todos los
    // meses sin que nadie hubiera gastado menos. Una devolución y un
    // cobro son lo mismo para la base —plata que entra de alguien— y lo
    // único que los distingue es si a esa persona también le mandaste.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 500_000, 'Super', '2026-09-05');

    $canon = gastoConfirmado($ana, 120_000, 'Laboratorio', '2026-09-10', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '2825076' WHERE id = {$canon}");

    $texto = $reportes->flujo($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Gasto real: $500.000</b>', 'el canon no descuenta gasto');
    contiene($texto, 'De terceros: <b>$120.000</b>', 'pero sí figura como ingreso');
});

prueba('[db] una devolucion si baja el gasto, hasta lo que le mandaste', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    // Le mandé 100.000, me devolvió 70.000: me costó 30.000.
    $cena = gastoConfirmado($ana, 100_000, 'Beto', '2026-09-05');
    $vuelto = gastoConfirmado($ana, 70_000, 'Beto', '2026-09-06', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '111' WHERE id IN ({$cena}, {$vuelto})");

    contiene(
        $reportes->flujo($ana, new DateTimeImmutable('2026-09-14')),
        'Gasto real: $30.000</b>',
        'lo que le mandaste menos lo que te devolvió'
    );
});

prueba('[db] si te devuelven de mas, el exceso no descuenta otros gastos', function (): void {
    // Beto devolvió más de lo que le mandé. Ese excedente es plata que
    // entró, no una rebaja sobre el supermercado.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 500_000, 'Super', '2026-09-05');

    $mande = gastoConfirmado($ana, 50_000, 'Beto', '2026-09-06');
    $volvio = gastoConfirmado($ana, 200_000, 'Beto', '2026-09-07', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '111' WHERE id IN ({$mande}, {$volvio})");

    contiene(
        $reportes->flujo($ana, new DateTimeImmutable('2026-09-14')),
        'Gasto real: $500.000</b>',
        'el neto con Beto queda en cero, no en menos 150.000'
    );
});

prueba('[db] con una sola foto igual se ven las inversiones', function (): void {
    // Este era el agujero: las posiciones sólo se listaban dentro de la
    // comparación, así que el primer mes el comando mostraba un total
    // suelto y nada más. El usuario lo dijo en tres palabras: "no veo
    // inversiones".
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    (new PatrimonioRepository(TestDatabase::pdo()))->guardar(
        $ana,
        new DateTimeImmutable('2026-09-15'),
        [
            [
                'simbolo' => 'SPY', 'descripcion' => 'S&P 500', 'tipo' => 'CEDEARS',
                'origen' => 'iol', 'clase' => 'inversion',
                'cantidad' => 115.0, 'precio' => 20280.0, 'valor' => 2_332_200.0,
            ],
            [
                'simbolo' => 'GLD', 'descripcion' => 'Oro', 'tipo' => 'CEDEARS',
                'origen' => 'iol', 'clase' => 'inversion',
                'cantidad' => 67.0, 'precio' => 12560.0, 'valor' => 841_520.0,
            ],
            [
                'simbolo' => 'USD-MP', 'descripcion' => 'Dolares', 'tipo' => 'MONEDA',
                'origen' => 'mercadopago', 'clase' => 'reserva',
                'cantidad' => 1000.0, 'precio' => 1450.0, 'valor' => 1_450_000.0,
            ],
        ]
    );

    $texto = $reportes->inversiones($ana, new DateTimeImmutable('2026-09-15'));

    contiene($texto, 'SPY — <b>$2.332.200</b>', 'se ve cada posición, no sólo el total');
    contiene($texto, 'GLD — <b>$841.520</b>');
    contiene($texto, 'USD-MP — <b>$1.450.000</b>', 'las reservas también');
    contiene($texto, 'Cartera: <b>$3.173.720</b>', 'la cartera no incluye las reservas');
    contiene($texto, 'Reservas: <b>$1.450.000</b>');
    contiene($texto, 'el mes que viene te digo cuánto rindió', 'y se explica qué falta');
});

prueba('[db] las posiciones se listan de mayor a menor', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    (new PatrimonioRepository(TestDatabase::pdo()))->guardar(
        $ana,
        new DateTimeImmutable('2026-09-15'),
        [
            [
                'simbolo' => 'CHICA', 'descripcion' => '', 'tipo' => '',
                'cantidad' => 1.0, 'precio' => 100.0, 'valor' => 100.0,
            ],
            [
                'simbolo' => 'GRANDE', 'descripcion' => '', 'tipo' => '',
                'cantidad' => 1.0, 'precio' => 900.0, 'valor' => 900.0,
            ],
        ]
    );

    $texto = $reportes->inversiones($ana, new DateTimeImmutable('2026-09-15'));

    afirmar(
        strpos($texto, 'GRANDE') < strpos($texto, 'CHICA'),
        'primero la posición más grande'
    );
});

prueba('[db] la cola de clasificacion junta lo que quedo sin categoria y en Otros', function (): void {
    // Los movimientos de Mercado Pago entran ya confirmados y sin
    // tarjeta: lo que el categorizador no supo ubicar quedaba sin
    // arreglo posible desde el bot.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $categorias = new CategoryRepository($pdo);
    $ana = nuevoUsuario();

    $otros = $categorias->idPorNombre($ana, ExpenseRepository::CATEGORIA_OTROS);
    $super = $categorias->idPorNombre($ana, 'Supermercado');
    noEsNulo($otros);
    noEsNulo($super);

    // Sin categoría.
    gastoConfirmado($ana, 86_250, 'Forja Centro de Eventos', '2026-09-15');

    // En "Otros", que es lo mismo que sin clasificar.
    $enOtros = gastoConfirmado($ana, 850_000, 'Ropa Rosario', '2026-09-14');
    $pdo->exec("UPDATE expenses SET category_id = {$otros} WHERE id = {$enOtros}");

    // Ya clasificado: no entra en la cola.
    $listo = gastoConfirmado($ana, 40_000, 'Coto', '2026-09-13');
    $pdo->exec("UPDATE expenses SET category_id = {$super} WHERE id = {$listo}");

    $texto = $reportes->aCategorizar($ana);

    contiene($texto, 'Quedan <b>2</b> sin clasificar', 'los dos, no los tres');
    contiene($texto, '$936.250', 'con el total pendiente');
    contiene($texto, 'Ropa Rosario', 'primero el más grande, que es el que mueve la aguja');
    afirmar(!str_contains($texto, 'Coto'), 'lo ya clasificado no vuelve a aparecer');
});

prueba('[db] cuando no queda nada sin clasificar lo dice', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    $super = (new CategoryRepository($pdo))->idPorNombre($ana, 'Supermercado');
    $id = gastoConfirmado($ana, 40_000, 'Coto', '2026-09-13');
    $pdo->exec("UPDATE expenses SET category_id = {$super} WHERE id = {$id}");

    contiene($reportes->aCategorizar($ana), 'No queda nada sin clasificar');
});

prueba('[db] la cola no cruza usuarios ni toma ingresos', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario('Ana');
    $beto = nuevoUsuario('Beto');

    gastoConfirmado($ana, 500_000, 'Sin categoria de Ana', '2026-09-15');

    // Un ingreso sin categoría no es algo que haya que clasificar como
    // gasto: la cola es de gastos.
    gastoConfirmado($beto, 700_000, 'Cobro sin categoria', '2026-09-15', Draft::TIPO_INGRESO);

    contiene($reportes->aCategorizar($beto), 'No queda nada sin clasificar');
    contiene($reportes->aCategorizar($ana), 'Quedan <b>1</b> sin clasificar');
});

prueba('[db] una amiga que devuelve su parte si descuenta, aunque no le hayas transferido', function (): void {
    // Caso real: pagaste la cena en el restaurante y ella te devolvió su
    // parte. Nunca recibió una transferencia tuya, así que el piso en
    // cero dejaba su devolución sin efecto y la cena quedaba contada
    // entera. No hay nada en el movimiento que diga si eso es una
    // devolución o un cobro: se declara en la contraparte.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 100_000, 'Restaurante', '2026-09-05');

    $devuelto = gastoConfirmado($ana, 60_000, 'Cami', '2026-09-06', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '252300561' WHERE id = {$devuelto}");

    contiene(
        $reportes->flujo($ana, new DateTimeImmutable('2026-09-14')),
        'Gasto real: $100.000</b>',
        'sin marcar, la devolución no descuenta'
    );

    $pdo->prepare(
        'INSERT INTO contrapartes (user_id, externo, alias, reintegra) VALUES (?, ?, ?, 1)'
    )->execute([$ana, '252300561', 'Cami']);

    contiene(
        $reportes->flujo($ana, new DateTimeImmutable('2026-09-14')),
        'Gasto real: $40.000</b>',
        'marcada como reintegro, la cena costó lo que quedó'
    );
});

prueba('[db] marcar reintegro no convierte un cobro en descuento de otro', function (): void {
    // El canon del laboratorio no está marcado, así que sigue sin
    // descontar: las dos reglas conviven.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 500_000, 'Super', '2026-09-05');

    $canon = gastoConfirmado($ana, 120_000, 'Laboratorio', '2026-09-10', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '2825076' WHERE id = {$canon}");

    $devuelto = gastoConfirmado($ana, 60_000, 'Cami', '2026-09-11', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '252300561' WHERE id = {$devuelto}");

    $pdo->prepare(
        'INSERT INTO contrapartes (user_id, externo, alias, reintegra) VALUES (?, ?, ?, 1)'
    )->execute([$ana, '252300561', 'Cami']);

    contiene(
        $reportes->flujo($ana, new DateTimeImmutable('2026-09-14')),
        'Gasto real: $440.000</b>',
        'resta los 60.000 de Cami, no los 120.000 del canon'
    );
});

prueba('[db] las transferencias se parten en las que salieron y las que entraron', function (): void {
    // Un solo listado mezclado obliga a leer la flecha de cada renglón
    // para saber de qué lado quedaste.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    // Con Goma quedó debiendo ella: mandó más de lo que le devolvieron.
    $mande = gastoConfirmado($ana, 900_000, 'Goma', '2026-09-05');
    $volvio = gastoConfirmado($ana, 300_000, 'Goma', '2026-09-06', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '111' WHERE id IN ({$mande}, {$volvio})");

    // Con Brian al revés: le devolvieron más de lo que mandó.
    $poco = gastoConfirmado($ana, 100_000, 'Brian', '2026-09-07');
    $mucho = gastoConfirmado($ana, 450_000, 'Brian', '2026-09-08', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '222' WHERE id IN ({$poco}, {$mucho})");

    // Compensado del todo: no aporta información, no ocupa lugar.
    $ida = gastoConfirmado($ana, 50_000, 'Nadie', '2026-09-09');
    $vuelta = gastoConfirmado($ana, 50_000, 'Nadie', '2026-09-10', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '333' WHERE id IN ({$ida}, {$vuelta})");

    $texto = $reportes->delMes($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Mandaste de más', 'el grupo de las que salieron');
    contiene($texto, 'Te mandaron de más', 'y el de las que entraron');
    contiene($texto, 'Goma — <b>$600.000</b>', 'el neto, no el bruto');
    contiene($texto, 'Brian — <b>$350.000</b>', 'del otro lado, también en positivo');
    afirmar(!str_contains($texto, 'Nadie'), 'el que compensó todo no ocupa lugar');

    afirmar(
        strpos($texto, 'Mandaste de más') < strpos($texto, 'Te mandaron de más'),
        'primero lo que saliste'
    );
});

prueba('[db] sin transferencias en un sentido, ese grupo no aparece', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    $id = gastoConfirmado($ana, 400_000, 'Juan Manuel', '2026-09-05');
    $pdo->exec("UPDATE expenses SET contraparte = '555' WHERE id = {$id}");

    $texto = $reportes->delMes($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Mandaste de más');
    afirmar(!str_contains($texto, 'Te mandaron de más'), 'un título vacío es ruido');
});
