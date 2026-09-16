<?php

declare(strict_types=1);

/**
 * Prueba de humo: renderiza todo lo que el bot contesta, con datos reales.
 *
 *   php bin/humo.php
 *   php bin/humo.php --user=1
 *
 * Existe por una razón concreta: la suite puede estar en verde, el
 * despliegue en verde y el menú publicado, y el usuario igual no ver lo
 * que se acaba de construir. Pasó. Los tests prueban las piezas contra
 * datos de laboratorio; esto ejecuta el camino entero contra los datos
 * que hay de verdad, que es lo único que responde "¿lo ve o no lo ve?".
 *
 * No manda nada a Telegram y no escribe en la base: sólo imprime lo que
 * el bot diría. Correrlo después de desplegar, **antes** de decir que
 * algo está andando.
 */

use Budget\App;
use Budget\Expense\CategoryGuesser;
use Budget\Expense\Periodo;
use Budget\Expense\Pregunta;
use Budget\Handler\Tarjetas;
use Budget\Reporte\Rankings;
use Budget\Reporte\Reports;
use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
use Budget\Repository\PatrimonioRepository;
use Budget\Repository\RecurringRepository;
use Budget\Telegram\Menu;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

/** Lo que el bot manda, legible en una consola. */
function comoSeVe(string $html): string
{
    $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return '    ' . str_replace("\n", "\n    ", trim($texto));
}

$app = App::crear(dirname(__DIR__));
$pdo = $app->pdo();

$userId = 0;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--user=')) {
        $userId = (int) substr($arg, 7);
    }
}

if ($userId === 0) {
    $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
}

if ($userId === 0) {
    fwrite(STDERR, "No hay ningún usuario en la base.\n");
    exit(1);
}

$reportes = new Reports(
    new ExpenseRepository($pdo),
    new CategoryRepository($pdo),
    new RecurringRepository($pdo),
    new PatrimonioRepository($pdo)
);

$hoy = $app->reloj->ahora();
$rankings = new Rankings(new ExpenseRepository($pdo));
$mes = Periodo::desde(Periodo::MES, $hoy);

printf("Usuario %d · %s\n", $userId, $hoy->format('d/m/Y H:i'));

/** @var array<string,callable():string> $comandos */
$comandos = [
    '/hoy' => static fn (): string => $reportes->delDia($userId, $hoy),
    '/mes' => static fn (): string => $reportes->delMes($userId, $hoy),
    '/anio' => static fn (): string
        => $reportes->delPeriodo($userId, Periodo::desde(Periodo::ANIO, $hoy)),
    '/trimestre' => static fn (): string
        => $reportes->delPeriodo($userId, Periodo::desde(Periodo::TRIMESTRE, $hoy)),
    '/topgastos' => static fn (): string => $rankings->topGastos($userId, $mes),
    '/topentrantes' => static fn (): string => $rankings->topEntrantes($userId, $mes),
    '/topsalientes' => static fn (): string => $rankings->topSalientes($userId, $mes),
    '/flujo' => static fn (): string => $reportes->flujo($userId, $hoy),
    '/ultimos' => static fn (): string => $reportes->ultimos($userId),
    '/inversiones' => static fn (): string => $reportes->inversiones($userId, $hoy),
    '/recurrentes' => static fn (): string => $reportes->recurrentes($userId),
    // Por el handler real y no por Reports directo: /revisar es el único
    // comando de reporte cuyo handler tiene lógica propia —arma el
    // teclado, filtra Otros, manda el mensaje él mismo— y saltearlo dejó
    // justamente esa parte sin cubrir por nada.
    '/revisar' => static fn (): string => (new Tarjetas(
        $app->telegram(),
        new ExpenseRepository($pdo),
        new CategoryRepository($pdo),
        $reportes,
        $app->reloj
    ))->siguientePendiente($userId)['texto'],
];

$preguntas = [
    'cuanto gaste este mes',
    'dame detalle de agosto 2026',
    'cuanto gaste en super el mes pasado',
];

$fallas = 0;

foreach ($comandos as $nombre => $render) {
    echo "\n" . str_repeat('=', 66) . "\n" . $nombre . "\n" . str_repeat('=', 66) . "\n";

    try {
        echo comoSeVe($render()) . "\n";
    } catch (Throwable $e) {
        $fallas++;
        printf("    ROTO: %s — %s\n", $e::class, $e->getMessage());
    }
}

foreach ($preguntas as $texto) {
    echo "\n" . str_repeat('=', 66) . "\n\"" . $texto . "\"\n" . str_repeat('=', 66) . "\n";

    try {
        $pregunta = Pregunta::desde($texto, new CategoryGuesser(), $hoy);

        if ($pregunta === null) {
            $fallas++;
            echo "    ROTO: no se reconoció como pregunta\n";

            continue;
        }

        echo comoSeVe($reportes->responder($userId, $pregunta, $hoy)) . "\n";
    } catch (Throwable $e) {
        $fallas++;
        printf("    ROTO: %s — %s\n", $e::class, $e->getMessage());
    }
}

// El menú que Telegram publica tiene que coincidir con lo que se probó.
$publicados = array_map(static fn (string $c): string => '/' . $c, array_keys(Menu::COMANDOS));
$probados = array_keys($comandos);
// Estos cuatro no rinden texto de reporte: dos son instrucciones fijas
// y dos escriben estado, así que una prueba de solo lectura no los
// puede ejecutar sin efectos.
$sinProbar = array_values(array_diff(
    $publicados,
    $probados,
    ['/ayuda', '/mercadopago', '/avisos', '/desvincular']
));

echo "\n" . str_repeat('=', 66) . "\n";

if ($sinProbar !== []) {
    $fallas++;
    printf("Comandos del menú que esta prueba no ejecuta: %s\n", implode(', ', $sinProbar));
}

printf("%d comandos, %d preguntas, %d rotos\n", count($comandos), count($preguntas), $fallas);

exit($fallas === 0 ? 0 : 1);
