<?php

declare(strict_types=1);

use Budget\Expense\Draft;
use Budget\Handler\Reports;
use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
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
    );
}

prueba('[db] /ingresos contrasta lo que entró con lo que salió', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 2_000_000, 'Sueldo', '2026-09-05', Draft::TIPO_INGRESO);
    gastoConfirmado($ana, 500_000, 'Alquiler', '2026-09-10');

    $texto = $reportes->ingresos($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Entró: <b>$2.000.000</b>', 'muestra lo que entró');
    contiene($texto, 'Salió: <b>$500.000</b>', 'muestra lo que salió');
    contiene($texto, 'Saldo: <b>$1.500.000</b>', 'el saldo es la diferencia');
    afirmar(!str_contains($texto, 'en rojo'), 'con saldo positivo no avisa');
});

prueba('[db] /ingresos avisa cuando el mes cierra en rojo', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 100_000, 'Sueldo', '2026-09-05', Draft::TIPO_INGRESO);
    gastoConfirmado($ana, 250_000, 'Alquiler', '2026-09-10');

    $texto = $reportes->ingresos($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'en rojo', 'gastar más de lo que entra se avisa');
    contiene($texto, 'Saldo: <b>$150.000</b>', 'el saldo va en positivo, el rojo lo dice el texto');
});

prueba('[db] sin ingresos cargados el reporte no miente con un cero', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 250_000, 'Alquiler', '2026-09-10');

    contiene(
        $reportes->ingresos($ana, new DateTimeImmutable('2026-09-14')),
        'No hay ingresos cargados',
        'mejor decir que no hay datos que mostrar un saldo negativo falso'
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

    afirmar(!str_contains($reportes->ingresos($beto, $hoy), '777.777'), 'Beto no ve los ingresos de Ana');
    afirmar(!str_contains($reportes->delAnio($beto, $hoy), '444.444'), 'ni sus gastos');
});

prueba('[db] el balance no cuenta las transferencias entre personas', function (): void {
    // Prestarle plata a alguien y que te la devuelva no es gastar ni
    // cobrar. Si las dos puntas entran al balance, el mes en que
    // prestás cierra bajo y el mes en que te devuelven cierra alto, y
    // las dos cifras son falsas.
    TestDatabase::limpiar();
    $reportes = reportes();
    $pdo = TestDatabase::pdo();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 2_000_000, 'Sueldo', '2026-09-05', Draft::TIPO_INGRESO);
    gastoConfirmado($ana, 1_600_000, 'Gastos del mes', '2026-09-10');

    $prestado = gastoConfirmado($ana, 300_000, 'Beto', '2026-09-11');
    $devuelto = gastoConfirmado($ana, 120_000, 'Beto', '2026-09-12', Draft::TIPO_INGRESO);
    $pdo->exec("UPDATE expenses SET contraparte = '777' WHERE id IN ({$prestado}, {$devuelto})");

    $texto = $reportes->ingresos($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Entró: <b>$2.000.000</b>', 'lo que devolvió Beto no es cobrar');
    contiene($texto, 'Salió: <b>$1.600.000</b>', 'prestarle a Beto no es gastar');
    contiene($texto, 'Saldo: <b>$400.000</b>', 'el saldo sale del consumo propio');
});

prueba('[db] la inversión se muestra aparte y no resta del saldo', function (): void {
    TestDatabase::limpiar();
    $reportes = reportes();
    $ana = nuevoUsuario();

    gastoConfirmado($ana, 2_000_000, 'Sueldo', '2026-09-05', Draft::TIPO_INGRESO);
    gastoConfirmado($ana, 800_000, 'Gastos', '2026-09-10');
    gastoConfirmado($ana, 500_000, 'CEDEARs', '2026-09-11', Draft::TIPO_INVERSION);

    $texto = $reportes->ingresos($ana, new DateTimeImmutable('2026-09-14'));

    contiene($texto, 'Salió: <b>$800.000</b>', 'comprar CEDEARs no es un gasto');
    contiene($texto, 'Saldo: <b>$1.200.000</b>', 'la plata invertida no se perdió');
    contiene($texto, 'invertiste <b>$500.000</b>', 'pero se dice cuánto del excedente ya está colocado');
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
