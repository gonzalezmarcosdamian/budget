<?php

declare(strict_types=1);

use Budget\Expense\Draft;
use Budget\Expense\Periodo;
use Budget\Reporte\Rankings;
use Budget\Reporte\Reports;
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

prueba('[db] el flujo clasifica la salida sin solapes y sin huecos', function (): void {
    // Los cuatro destinos tienen que sumar exactamente lo que salió: si
    // se solapan, los porcentajes pasan del 100%; si dejan un hueco, hay
    // plata que salió y no aparece en ningún lado.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 200_000, 'Super', '2026-09-06');

    $alquiler = gastoConfirmado($ana, 400_000, 'Locador', '2026-09-10');
    $pdo->exec("UPDATE expenses SET naturaleza = 'fijo', contraparte = '900000031' WHERE id = {$alquiler}");

    gastoConfirmado($ana, 100_000, 'CEDEARs', '2026-09-12', Draft::TIPO_INVERSION);

    $texto = $reportes->flujo($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Fijos — <b>$400.000</b>', 'el alquiler es fijo, aunque vaya a una persona');
    contiene($texto, 'Consumo — <b>$200.000</b>');
    contiene($texto, 'Invertido — <b>$100.000</b>', 'la inversión también baja la caja');
    contiene($texto, 'A cuenta propia: <b>$100.000</b>', 'comprar CEDEARs es mover plata a lo propio');
    afirmar(!str_contains($texto, 'Prestado'), 'entre personas hay transferencias, no préstamos');
});

prueba('[db] una transferencia a alguien no necesita una categoria de prestamo', function (): void {
    // El neteo por contraparte ya dice lo que hay que saber: lo que le
    // mandaste menos lo que te devolvió. Llamarlo préstamo agregaba una
    // afirmación sobre la intención que el bot no puede saber.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    $mande = gastoConfirmado($ana, 300_000, 'Alguien', '2026-09-11');
    $volvio = gastoConfirmado($ana, 120_000, 'Alguien', '2026-09-12', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '900000032' WHERE id IN ({$mande}, {$volvio})");

    $texto = $reportes->flujo($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Gasto real: $180.000</b>', 'el neto, que es lo que costó de verdad');
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

prueba('[db] el año son los ultimos doce meses, mes a mes', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 111_111, 'Enero', '2026-01-15');
    gastoConfirmado($ana, 222_222, 'Marzo', '2026-03-15');
    gastoConfirmado($ana, 999_999, 'Hace mas de un año', '2025-06-15');

    $texto = $reportes->delPeriodo(
        $ana,
        Periodo::desde(Periodo::ANIO, new DateTimeImmutable('2026-09-14'))
    );

    contiene($texto, 'Enero 2026', 'aparece el mes con gastos');
    contiene($texto, '111.111', 'con su total');
    contiene($texto, 'Marzo 2026');
    contiene($texto, '333.333', 'el total es la suma de la ventana');
    afirmar(!str_contains($texto, '999.999'), 'lo anterior a la ventana queda afuera');
});

prueba('[db] los meses sin gastos se muestran en cero, no desaparecen', function (): void {
    // Si julio y septiembre tienen $300.000 cada uno y agosto no
    // aparece, el promedio de $200.000 se lee como un error del bot.
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 300_000, 'Julio', '2026-07-15');
    gastoConfirmado($ana, 300_000, 'Septiembre', '2026-09-15');

    $texto = $reportes->delPeriodo(
        $ana,
        Periodo::desde(Periodo::TRIMESTRE, new DateTimeImmutable('2026-09-20'))
    );

    contiene($texto, 'Agosto 2026 — <b>$0</b>', 'el mes vacío se ve, y explica el promedio');
    contiene($texto, 'Promedio: <b>$200.000</b>', 'sobre los tres meses del período');
});

prueba('[db] el mes en curso se cuenta entero, aunque falten dias', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 50_000, 'Fin de mes', '2026-09-30');

    contiene(
        $reportes->delPeriodo($ana, Periodo::desde(Periodo::TRIMESTRE, new DateTimeImmutable('2026-09-14'))),
        '50.000',
        'el corte es por mes, no por día'
    );
});

prueba('[db] los reportes no cruzan usuarios', function (): void {
    // Regla 3 del proyecto. Cubre los cuatro caminos nuevos, que hasta
    // ahora no tenían ninguna prueba de aislamiento.
    TestDatabase::limpiar();
    $pdo = TestDatabase::pdo();
    $reportes = reportes();
    $rankings = new Rankings(new ExpenseRepository($pdo));
    $ana = nuevoUsuario('Ana');
    $beto = nuevoUsuario('Beto');

    gastoConfirmado($ana, 777_777, 'Sueldo de Ana', '2026-09-05', Draft::TIPO_INGRESO);
    $gasto = gastoConfirmado($ana, 444_444, 'Gasto de Ana', '2026-09-05');
    $pdo->exec("UPDATE expenses SET contraparte = '900000099' WHERE id = {$gasto}");

    $hoy = new DateTimeImmutable('2026-09-14');
    $mes = Periodo::desde(Periodo::MES, $hoy);

    afirmar(!str_contains($reportes->flujo($beto, $hoy), '777.777'), 'el flujo no cruza');
    afirmar(
        !str_contains($reportes->delPeriodo($beto, Periodo::desde(Periodo::ANIO, $hoy)), '444.444'),
        'el período no cruza'
    );
    afirmar(!str_contains($rankings->topGastos($beto, $mes), '444.444'), 'el top de gastos no cruza');
    afirmar(
        !str_contains($rankings->topSalientes($beto, $mes), '444.444'),
        'el top de transferencias no cruza'
    );
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
        'Locador'
    );

    $texto = $reportes->recurrentes($ana);

    contiene($texto, 'Alquiler', 'aparece el gasto');
    contiene($texto, '$981.000', 'con el último monto conocido');
    contiene($texto, 'día 10', 'y el día en que toca');
    contiene($texto, 'Locador', 'la nota también');
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

    $canon = gastoConfirmado($ana, 120_000, 'Cliente', '2026-09-10', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '900000002' WHERE id = {$canon}");

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

    $devuelto = gastoConfirmado($ana, 60_000, 'Amiga', '2026-09-06', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '900000001' WHERE id = {$devuelto}");

    contiene(
        $reportes->flujo($ana, new DateTimeImmutable('2026-09-14')),
        'Gasto real: $100.000</b>',
        'sin marcar, la devolución no descuenta'
    );

    $pdo->prepare(
        'INSERT INTO contrapartes (user_id, externo, alias, reintegra) VALUES (?, ?, ?, 1)'
    )->execute([$ana, '900000001', 'Amiga']);

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

    $canon = gastoConfirmado($ana, 120_000, 'Cliente', '2026-09-10', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '900000002' WHERE id = {$canon}");

    $devuelto = gastoConfirmado($ana, 60_000, 'Amiga', '2026-09-11', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '900000001' WHERE id = {$devuelto}");

    $pdo->prepare(
        'INSERT INTO contrapartes (user_id, externo, alias, reintegra) VALUES (?, ?, ?, 1)'
    )->execute([$ana, '900000001', 'Amiga']);

    contiene(
        $reportes->flujo($ana, new DateTimeImmutable('2026-09-14')),
        'Gasto real: $440.000</b>',
        'resta los 60.000 de la amiga, no los 120.000 del canon'
    );
});

prueba('[db] las transferencias se parten en las que salieron y las que entraron', function (): void {
    // Un solo listado mezclado obliga a leer la flecha de cada renglón
    // para saber de qué lado quedaste.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    // Con la primera quedó debiendo ella: mandó más de lo que le devolvieron.
    $mande = gastoConfirmado($ana, 900_000, 'Hermano', '2026-09-05');
    $volvio = gastoConfirmado($ana, 300_000, 'Hermano', '2026-09-06', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '111' WHERE id IN ({$mande}, {$volvio})");

    // Con Brian al revés: le devolvieron más de lo que mandó.
    $poco = gastoConfirmado($ana, 100_000, 'Conocido', '2026-09-07');
    $mucho = gastoConfirmado($ana, 450_000, 'Conocido', '2026-09-08', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '222' WHERE id IN ({$poco}, {$mucho})");

    // Compensado del todo: no aporta información, no ocupa lugar.
    $ida = gastoConfirmado($ana, 50_000, 'Nadie', '2026-09-09');
    $vuelta = gastoConfirmado($ana, 50_000, 'Nadie', '2026-09-10', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '333' WHERE id IN ({$ida}, {$vuelta})");

    $texto = $reportes->delMes($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Mandaste de más', 'el grupo de las que salieron');
    contiene($texto, 'Te mandaron de más', 'y el de las que entraron');
    contiene($texto, 'Hermano — <b>−$600.000</b>', 'el neto con signo, no el bruto');
    contiene($texto, 'Conocido — <b>+$350.000</b>', 'del otro lado, en positivo');
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

    $id = gastoConfirmado($ana, 400_000, 'Locador', '2026-09-05');
    $pdo->exec("UPDATE expenses SET contraparte = '555' WHERE id = {$id}");

    $texto = $reportes->delMes($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Mandaste de más');
    afirmar(!str_contains($texto, 'Te mandaron de más'), 'un título vacío es ruido');
});

prueba('[db] /ultimos marca con signo lo que entra y lo que sale', function (): void {
    // Antes decía "Últimos gastos" y listaba los ingresos entre ellos:
    // una devolución de $600.000 figuraba como si se hubiera gastado.
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 34_000, 'Picada', '2026-09-15');
    gastoConfirmado($ana, 600_000, 'Hermano', '2026-09-15', Draft::TIPO_INGRESO);

    $texto = $reportes->ultimos($ana);

    contiene($texto, 'Últimos movimientos', 'el título dice lo que la lista contiene');
    contiene($texto, 'Picada — <b>−$34.000</b>', 'lo que salió, con menos');
    contiene($texto, 'Hermano — <b>+$600.000</b>', 'lo que entró, con más');
    afirmar(!str_contains($texto, 'Últimos gastos'), 'ya no promete sólo gastos');
});

prueba('[db] los netos de transferencias llevan signo', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    $mande = gastoConfirmado($ana, 900_000, 'Uno', '2026-09-05');
    $volvio = gastoConfirmado($ana, 300_000, 'Uno', '2026-09-06', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '900000011' WHERE id IN ({$mande}, {$volvio})");

    $poco = gastoConfirmado($ana, 100_000, 'Otro', '2026-09-07');
    $mucho = gastoConfirmado($ana, 450_000, 'Otro', '2026-09-08', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '900000012' WHERE id IN ({$poco}, {$mucho})");

    $texto = $reportes->delMes($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Uno — <b>−$600.000</b>', 'lo que saliste va en negativo');
    contiene($texto, 'Otro — <b>+$350.000</b>', 'lo que entró va en positivo');
});

prueba('[db] el top de gastos lista los movimientos, no las categorias', function (): void {
    // La pregunta es "qué fue lo más caro que pagué", y para eso el
    // movimiento suelto es la unidad: agrupar escondería el que duele.
    TestDatabase::limpiar();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();
    $rankings = new Rankings(new ExpenseRepository($pdo));

    gastoConfirmado($ana, 850_000, 'Ropa Rosario', '2026-09-14');
    gastoConfirmado($ana, 600_000, 'Viaje cumple', '2026-09-14');
    gastoConfirmado($ana, 40_000, 'Coto', '2026-09-13');
    gastoConfirmado($ana, 30_000, 'Coto', '2026-09-12');

    $texto = $rankings->topGastos($ana, Periodo::desde(Periodo::MES, new DateTimeImmutable('2026-09-15')));

    contiene($texto, '1. ', 'va numerado');
    contiene($texto, 'Ropa Rosario', 'el más grande primero');
    afirmar(
        strpos($texto, 'Ropa Rosario') < strpos($texto, 'Viaje cumple'),
        'ordenado por monto'
    );
    afirmar(
        substr_count($texto, 'Coto') === 2,
        'los dos Coto aparecen por separado, no sumados'
    );
});

prueba('[db] los tops de transferencias van en bruto y por direccion', function (): void {
    // En bruto y no en neto a propósito: el neto contesta "con quién
    // quedé en deuda" y ya lo muestra /mes. Esto contesta "quién movió
    // más plata conmigo", que se arruina al netear.
    TestDatabase::limpiar();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();
    $rankings = new Rankings(new ExpenseRepository($pdo));

    $mande = gastoConfirmado($ana, 900_000, 'Uno', '2026-09-05');
    $volvio = gastoConfirmado($ana, 800_000, 'Uno', '2026-09-06', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '900000021' WHERE id IN ({$mande}, {$volvio})");

    $chico = gastoConfirmado($ana, 100_000, 'Otro', '2026-09-07');
    $pdo->exec("UPDATE expenses SET contraparte = '900000022' WHERE id = {$chico}");

    $mes = Periodo::desde(Periodo::MES, new DateTimeImmutable('2026-09-15'));

    $salientes = $rankings->topSalientes($ana, $mes);
    contiene($salientes, 'Uno — <b>$900.000</b>', 'el bruto, no el neto de $100.000');
    contiene($salientes, 'Otro — <b>$100.000</b>');
    contiene($salientes, '➖', 'el signo de lo que sale');

    $entrantes = $rankings->topEntrantes($ana, $mes);
    contiene($entrantes, 'Uno — <b>$800.000</b>', 'del otro lado, lo que entró');
    contiene($entrantes, '➕', 'el signo de lo que entra');
    afirmar(!str_contains($entrantes, 'Otro'), 'quien no mandó nada no aparece entre los entrantes');
});

prueba('[db] el trimestre suma tres meses y promedia sobre tres', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 300_000, 'Julio', '2026-07-15');
    gastoConfirmado($ana, 300_000, 'Agosto', '2026-08-15');
    gastoConfirmado($ana, 300_000, 'Septiembre', '2026-09-15');
    gastoConfirmado($ana, 999_999, 'Junio, afuera', '2026-06-15');

    $texto = $reportes->delPeriodo(
        $ana,
        Periodo::desde(Periodo::TRIMESTRE, new DateTimeImmutable('2026-09-20'))
    );

    contiene($texto, 'Total: <b>$900.000</b>', 'los tres meses');
    contiene($texto, 'Promedio: <b>$300.000</b>', 'sobre tres, no sobre uno');
    contiene($texto, 'Julio 2026');
    afirmar(!str_contains($texto, '999.999'), 'junio queda afuera');
});

prueba('[db] un periodo sin gastos no inventa un cero', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    contiene(
        $reportes->delPeriodo($ana, Periodo::desde(Periodo::TRIMESTRE, new DateTimeImmutable('2026-09-20'))),
        'No hay gastos cargados'
    );
});
