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
        'Gasto real: <b>$30.000</b>',
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

    contiene($texto, 'Gasto real: <b>$200.000</b>', 'los 5 palos cambiaron de bolsillo, no se gastaron');
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
