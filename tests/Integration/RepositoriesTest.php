<?php

declare(strict_types=1);

use Budget\Expense\Draft;
use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
use Budget\Repository\UpdateLog;
use Budget\Repository\UserRepository;
use Budget\Support\Money;
use Budget\Tests\Doubles\TestDatabase;

/**
 * Tests contra MariaDB de verdad. Verifican justamente lo que un doble
 * no podría: qué hace la base ante un INSERT IGNORE, un rowCount y una
 * transacción con FOR UPDATE.
 */

function nuevoUsuario(string $nombre = 'Gonza'): int
{
    static $siguienteChat = 1000;

    return (new UserRepository(TestDatabase::pdo()))
        ->crear(++$siguienteChat, $nombre, 'America/Argentina/Buenos_Aires', 'ARS');
}

function borradorDe(int $pesos, string $comercio): Draft
{
    return new Draft(
        monto: Money::deCentavos($pesos * 100),
        fecha: new DateTimeImmutable('2026-09-14'),
        comercio: $comercio,
        descripcion: $comercio,
    );
}

prueba('[db] el mismo update_id se reserva una sola vez', function (): void {
    TestDatabase::limpiar();
    $log = new UpdateLog(TestDatabase::pdo());

    afirmar($log->reservar(90_001), 'la primera vez se reserva');
    afirmar(!$log->reservar(90_001), 'el reintento no vuelve a pasar');
    afirmar($log->reservar(90_002), 'otro update sí pasa');
});

prueba('[db] un usuario no puede leer el gasto de otro', function (): void {
    TestDatabase::limpiar();
    $gastos = new ExpenseRepository(TestDatabase::pdo());

    $ana = nuevoUsuario('Ana');
    $beto = nuevoUsuario('Beto');

    $idDeAna = $gastos->guardarBorrador($ana, borradorDe(18_450, 'Coto'), null);

    noEsNulo($gastos->porId($ana, $idDeAna), 'la dueña lo ve');
    esNulo($gastos->porId($beto, $idDeAna), 'el otro usuario no');
});

prueba('[db] un usuario no puede confirmar ni borrar el gasto de otro', function (): void {
    TestDatabase::limpiar();
    $gastos = new ExpenseRepository(TestDatabase::pdo());

    $ana = nuevoUsuario('Ana');
    $beto = nuevoUsuario('Beto');
    $idDeAna = $gastos->guardarBorrador($ana, borradorDe(5_000, 'Kiosco'), null);

    afirmar(!$gastos->confirmar($beto, $idDeAna), 'confirmar ajeno falla');
    afirmar(!$gastos->descartar($beto, $idDeAna), 'descartar ajeno falla');
    afirmar($gastos->confirmar($ana, $idDeAna), 'la dueña sí puede');
});

prueba('[db] confirmar dos veces no duplica nada', function (): void {
    TestDatabase::limpiar();
    $gastos = new ExpenseRepository(TestDatabase::pdo());

    $ana = nuevoUsuario();
    $id = $gastos->guardarBorrador($ana, borradorDe(1_200, 'Super'), null);

    afirmar($gastos->confirmar($ana, $id), 'primer tap');
    afirmar(!$gastos->confirmar($ana, $id), 'segundo tap no hace nada');
});

prueba('[db] los totales sólo cuentan gastos confirmados del propio usuario', function (): void {
    TestDatabase::limpiar();
    $gastos = new ExpenseRepository(TestDatabase::pdo());

    $ana = nuevoUsuario('Ana');
    $beto = nuevoUsuario('Beto');
    $dia = new DateTimeImmutable('2026-09-14');

    $confirmado = $gastos->guardarBorrador($ana, borradorDe(10_000, 'Coto'), null);
    $gastos->confirmar($ana, $confirmado);

    $gastos->guardarBorrador($ana, borradorDe(99_999, 'Sin confirmar'), null);

    $deBeto = $gastos->guardarBorrador($beto, borradorDe(50_000, 'Ajeno'), null);
    $gastos->confirmar($beto, $deBeto);

    esIgual('10000.00', $gastos->totalEntre($ana, $dia, $dia)->aDecimal());
});

prueba('[db] una corrección se vuelve regla y gana sobre las palabras clave', function (): void {
    TestDatabase::limpiar();
    $pdo = TestDatabase::pdo();
    $categorias = new CategoryRepository($pdo);

    $ana = nuevoUsuario();
    $delivery = $categorias->idPorNombre($ana, 'Comida y delivery');
    noEsNulo($delivery);

    esNulo($categorias->categoriaPorRegla($ana, 'Coto'), 'todavía no hay regla');

    $categorias->recordarRegla($ana, 'Coto', (int) $delivery);

    esIgual($delivery, $categorias->categoriaPorRegla($ana, 'coto'), 'no distingue mayúsculas');
});

prueba('[db] las reglas no se filtran entre usuarios', function (): void {
    TestDatabase::limpiar();
    $categorias = new CategoryRepository(TestDatabase::pdo());

    $ana = nuevoUsuario('Ana');
    $beto = nuevoUsuario('Beto');
    $salud = (int) $categorias->idPorNombre($ana, 'Salud');

    $categorias->recordarRegla($ana, 'Farmacia', $salud);

    esNulo($categorias->categoriaPorRegla($beto, 'Farmacia'));
});

prueba('[db] el cupo de IA se agota y se renueva al cambiar de mes', function (): void {
    TestDatabase::limpiar();
    $usuarios = new UserRepository(TestDatabase::pdo());
    $ana = nuevoUsuario();

    afirmar($usuarios->consumirCupoIa($ana, 2, '2026-09'), 'primera');
    afirmar($usuarios->consumirCupoIa($ana, 2, '2026-09'), 'segunda');
    afirmar(!$usuarios->consumirCupoIa($ana, 2, '2026-09'), 'tercera, sin cupo');
    afirmar($usuarios->consumirCupoIa($ana, 2, '2026-10'), 'mes nuevo, cupo nuevo');
});

prueba('[db] cupo cero significa sin límite', function (): void {
    TestDatabase::limpiar();
    $usuarios = new UserRepository(TestDatabase::pdo());
    $ana = nuevoUsuario();

    afirmar($usuarios->consumirCupoIa($ana, 0, '2026-09'), 'sin tope configurado');
});

prueba('[db] el desglose por categoría suma al total', function (): void {
    TestDatabase::limpiar();
    $pdo = TestDatabase::pdo();
    $gastos = new ExpenseRepository($pdo);
    $categorias = new CategoryRepository($pdo);

    $ana = nuevoUsuario();
    $superId = (int) $categorias->idPorNombre($ana, 'Supermercado');
    $dia = new DateTimeImmutable('2026-09-14');

    foreach ([10_000, 5_000] as $pesos) {
        $id = $gastos->guardarBorrador($ana, borradorDe($pesos, 'Coto'), $superId);
        $gastos->confirmar($ana, $id);
    }

    $desglose = $gastos->totalPorCategoria($ana, $dia, $dia);

    esIgual(1, count($desglose), 'una sola categoría');
    esIgual('Supermercado', $desglose[0]['categoria']);
    esIgual('15000.00', $desglose[0]['total']->aDecimal());
});

prueba('[db] detecta un gasto que ya existe con misma fecha e importe', function (): void {
    TestDatabase::limpiar();
    $gastos = new ExpenseRepository(TestDatabase::pdo());
    $ana = nuevoUsuario();
    $dia = new DateTimeImmutable('2026-09-14');

    $id = $gastos->guardarBorrador($ana, borradorDe(18_450, 'Coto'), null);
    $gastos->confirmar($ana, $id);

    // Importar el resumen de la tarjeta no puede volver a cargar lo que
    // el usuario ya anotó a mano durante el mes.
    afirmar($gastos->yaExiste($ana, $dia, '18450.00'), 'mismo dia y monto');
    afirmar(!$gastos->yaExiste($ana, $dia, '18451.00'), 'otro monto');
    afirmar(!$gastos->yaExiste($ana, new DateTimeImmutable('2026-09-13'), '18450.00'), 'otro dia');
});

prueba('[db] un borrador sin confirmar no cuenta como duplicado', function (): void {
    TestDatabase::limpiar();
    $gastos = new ExpenseRepository(TestDatabase::pdo());
    $ana = nuevoUsuario();

    $gastos->guardarBorrador($ana, borradorDe(5_000, 'Kiosco'), null);

    afirmar(!$gastos->yaExiste($ana, new DateTimeImmutable('2026-09-14'), '5000.00'), 'sin confirmar no duplica');
});

prueba('[db] el duplicado no se detecta entre usuarios distintos', function (): void {
    TestDatabase::limpiar();
    $gastos = new ExpenseRepository(TestDatabase::pdo());
    $ana = nuevoUsuario('Ana');
    $beto = nuevoUsuario('Beto');

    $id = $gastos->guardarBorrador($ana, borradorDe(9_000, 'Nafta'), null);
    $gastos->confirmar($ana, $id);

    afirmar(!$gastos->yaExiste($beto, new DateTimeImmutable('2026-09-14'), '9000.00'), 'el gasto de Ana no es duplicado de Beto');
});

prueba('[db] un lote se confirma entero de una', function (): void {
    TestDatabase::limpiar();
    $gastos = new ExpenseRepository(TestDatabase::pdo());
    $ana = nuevoUsuario();
    $lote = 'ABC123456789';

    foreach ([1_000, 2_000, 3_000] as $pesos) {
        $gastos->guardarBorrador($ana, borradorDe($pesos, 'Consumo'), null, $lote);
    }

    $resumen = $gastos->resumenDeLote($ana, $lote);
    esIgual(3, $resumen['cantidad']);
    esIgual('6000.00', $resumen['total']->aDecimal());

    esIgual(3, $gastos->confirmarLote($ana, $lote), 'confirma los tres');
    esIgual(0, $gastos->confirmarLote($ana, $lote), 'el segundo toque no hace nada');
});

prueba('[db] un lote se descarta entero cuando el PDF se leyó mal', function (): void {
    TestDatabase::limpiar();
    $gastos = new ExpenseRepository(TestDatabase::pdo());
    $ana = nuevoUsuario();
    $lote = 'XYZ987654321';

    foreach ([500, 700] as $pesos) {
        $gastos->guardarBorrador($ana, borradorDe($pesos, 'Consumo'), null, $lote);
    }

    esIgual(2, $gastos->descartarLote($ana, $lote));
    esIgual(0, $gastos->resumenDeLote($ana, $lote)['cantidad'], 'ya no queda nada pendiente');
});

prueba('[db] un usuario no puede confirmar el lote de otro', function (): void {
    TestDatabase::limpiar();
    $gastos = new ExpenseRepository(TestDatabase::pdo());
    $ana = nuevoUsuario('Ana');
    $beto = nuevoUsuario('Beto');
    $lote = 'LOTEDEANA123';

    $gastos->guardarBorrador($ana, borradorDe(4_000, 'Consumo'), null, $lote);

    esIgual(0, $gastos->confirmarLote($beto, $lote), 'ajeno no confirma');
    esIgual(0, $gastos->resumenDeLote($beto, $lote)['cantidad'], 'ni lo ve');
    esIgual(1, $gastos->confirmarLote($ana, $lote), 'la dueña sí');
});
