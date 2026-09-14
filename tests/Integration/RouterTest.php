<?php

declare(strict_types=1);

use Budget\Ai\Router;
use Budget\Support\Logger;
use Budget\Tests\Doubles\FakeProvider;
use Budget\Tests\Doubles\TestDatabase;

/**
 * La cadena de respaldo del Router.
 *
 * Es de integración porque el Router registra cada intento en ai_calls,
 * y esa tabla es de donde sale el dato de qué modelo acierta más. Un
 * doble de PDO verificaría el doble, no el comportamiento.
 */

function routerCon(array $proveedores): Router
{
    return new Router(
        $proveedores,
        TestDatabase::pdo(),
        new Logger(sys_get_temp_dir() . '/budget-test.log')
    );
}

function filasDeIa(): array
{
    return TestDatabase::pdo()
        ->query('SELECT proveedor, modelo, tarea, exito FROM ai_calls ORDER BY id')
        ->fetchAll();
}

prueba('[db] un 503 baja al siguiente modelo de la cadena', function (): void {
    TestDatabase::limpiar();

    $caido = FakeProvider::queFalla('modelo-saturado');
    $sano = FakeProvider::queFunciona('modelo-lite', 1234.0);

    $extracciones = routerCon([$caido, $sano])->texto(1, 'gasté 1234');

    esIgual(1, count($extracciones), 'el usuario recibe su gasto igual');
    esIgual(123_400, $extracciones[0]->monto->centavos);
    esIgual(1, $caido->llamadas, 'el caído se intentó una vez');
    esIgual(1, $sano->llamadas, 'y el siguiente resolvió');
});

prueba('[db] cada intento queda registrado con su modelo', function (): void {
    TestDatabase::limpiar();

    routerCon([
        FakeProvider::queFalla('modelo-saturado'),
        FakeProvider::queFunciona('modelo-lite'),
    ])->texto(7, 'gasté 1000');

    $filas = filasDeIa();

    esIgual(2, count($filas), 'un renglón por intento');
    esIgual('modelo-saturado', (string) $filas[0]['modelo']);
    esIgual(0, (int) $filas[0]['exito'], 'el fallido se registra como fallido');
    esIgual('modelo-lite', (string) $filas[1]['modelo']);
    esIgual(1, (int) $filas[1]['exito']);
});

prueba('[db] que el modelo no encuentre un gasto no dispara el respaldo', function (): void {
    TestDatabase::limpiar();

    $primero = FakeProvider::queNoEncuentraNada('modelo-lite');
    $segundo = FakeProvider::queFunciona('modelo-grande');

    // "Acá no hay ningún gasto" es una respuesta válida, no una falla.
    // Reintentar con otro modelo sólo gastaría cupo para llegar a lo mismo.
    esIgual([], routerCon([$primero, $segundo])->texto(1, 'hola que tal'));
    esIgual(0, $segundo->llamadas, 'el segundo ni se intenta');
});

prueba('[db] un proveedor sin clave no entra en la cadena', function (): void {
    TestDatabase::limpiar();

    $sinClave = FakeProvider::sinClave('modelo-sin-configurar');
    $router = routerCon([$sinClave, FakeProvider::queFunciona('modelo-lite')]);

    esIgual(1, count($router->texto(1, 'gasté 500')));
    esIgual(0, $sinClave->llamadas, 'no se intenta siquiera');
    esIgual(1, count(filasDeIa()), 'y no ensucia las métricas');
});

prueba('[db] sin ningún proveedor disponible el router lo dice', function (): void {
    TestDatabase::limpiar();

    $router = routerCon([FakeProvider::sinClave('a'), FakeProvider::sinClave('b')]);

    afirmar(!$router->hayProveedores(), 'el Dispatcher usa esto para no prometer IA');
    esIgual([], $router->texto(1, 'gasté 500'));
});

prueba('[db] si toda la cadena falla devuelve vacío en vez de explotar', function (): void {
    TestDatabase::limpiar();

    // El usuario mandó una foto y espera un gasto: que se caigan todos
    // los proveedores tiene que producir un mensaje, no un 500.
    $router = routerCon([
        FakeProvider::queFalla('uno'),
        FakeProvider::queFalla('dos'),
    ]);

    esIgual([], $router->imagen(1, 'binario', 'image/jpeg'));
    esIgual(2, count(filasDeIa()), 'los dos fallos quedan registrados');
});

prueba('[db] un mensaje puede traer varios gastos', function (): void {
    TestDatabase::limpiar();

    // El caso que rompió en producción: "30 mil en estacionamiento y 200
    // en entradas y 100 en bebidas" son tres gastos, no uno.
    $extracciones = routerCon([
        FakeProvider::queDevuelveVarios('modelo-lite', [30000.0, 200000.0, 100000.0]),
    ])->texto(1, 'me fui de fiesta');

    esIgual(3, count($extracciones));
    esIgual(3_000_000, $extracciones[0]->monto->centavos);
    esIgual(20_000_000, $extracciones[1]->monto->centavos);
    esIgual(10_000_000, $extracciones[2]->monto->centavos);
    esIgual(1, count(filasDeIa()), 'los tres salen de una sola llamada');
});
