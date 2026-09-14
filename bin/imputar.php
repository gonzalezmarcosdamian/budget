<?php

declare(strict_types=1);

/**
 * Carga movimientos que ocurrieron pero que ningún sistema registró.
 *
 *   php bin/imputar.php <chat_id> <categoria> <dia> <comercio> <mes=importe>...
 *
 * Ejemplo:
 *   php bin/imputar.php 1595524230 Alquiler 10 "Alquiler" 2025-12=321973 2026-01=335501
 *
 * Existe para los meses en que el usuario pagó por otra vía. Son datos
 * inventados por el sistema a partir de una serie, así que quedan
 * marcados con fuente "estimado" de forma permanente: dentro de seis
 * meses tienen que seguir siendo distinguibles de un dato real, no sólo
 * en el momento de cargarlos.
 *
 * Es idempotente: cada mes lleva una referencia estable, así que
 * correrlo dos veces no duplica nada.
 */

use Budget\App;
use Budget\Expense\Draft;
use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
use Budget\Repository\UserRepository;
use Budget\Support\Money;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$chatId = (int) ($argv[1] ?? 0);
$categoria = (string) ($argv[2] ?? '');
$dia = (int) ($argv[3] ?? 0);
$comercio = (string) ($argv[4] ?? '');
$meses = array_slice($argv, 5);

if ($chatId === 0 || $categoria === '' || $dia < 1 || $dia > 28 || $comercio === '' || $meses === []) {
    fwrite(STDERR, "Uso: php bin/imputar.php <chat_id> <categoria> <dia> <comercio> <YYYY-MM=importe>...\n");
    exit(1);
}

$app = App::crear(dirname(__DIR__));
$pdo = $app->pdo();

$usuario = (new UserRepository($pdo))->porChat($chatId);

if ($usuario === null) {
    fwrite(STDERR, "No hay usuario con chat_id {$chatId}.\n");
    exit(1);
}

$userId = (int) $usuario['id'];
$categorias = new CategoryRepository($pdo);
$categoryId = $categorias->idPorNombre($userId, $categoria);

if ($categoryId === null) {
    fwrite(STDERR, "No existe la categoría \"{$categoria}\".\n");
    exit(1);
}

$gastos = new ExpenseRepository($pdo);
$lote = bin2hex(random_bytes(6));
$cargados = 0;
$salteados = 0;

foreach ($meses as $par) {
    [$mes, $importe] = array_pad(explode('=', $par, 2), 2, '');

    if (preg_match('/^\d{4}-\d{2}$/', $mes) !== 1 || !is_numeric($importe)) {
        fwrite(STDERR, "Ignoro \"{$par}\": se espera YYYY-MM=importe.\n");

        continue;
    }

    $fecha = new DateTimeImmutable(sprintf('%s-%02d', $mes, $dia));

    $borrador = new Draft(
        monto: Money::deDecimal((string) $importe),
        fecha: $fecha,
        comercio: $comercio,
        descripcion: $comercio . ' (estimado)',
        categoria: $categoria,
        medioPago: '',
        fuente: Draft::FUENTE_ESTIMADO,
        confianza: 0.50,
        modelo: 'interpolacion-geometrica',
        tipo: Draft::TIPO_GASTO,
        naturaleza: Draft::NATURALEZA_FIJO,
    );

    $id = $gastos->guardarBorrador(
        $userId,
        $borrador,
        $categoryId,
        $lote,
        sprintf('estimado:%s:%s', mb_strtolower($categoria), $mes),
        ExpenseRepository::ESTADO_CONFIRMADO
    );

    if ($id === 0) {
        $salteados++;
        printf("  %s  ya estaba\n", $mes);

        continue;
    }

    $cargados++;
    printf("  %s  %s  estimado\n", $mes, $borrador->monto->formatear());
}

printf("\n%d cargados, %d ya estaban. Lote %s\n", $cargados, $salteados, $lote);
