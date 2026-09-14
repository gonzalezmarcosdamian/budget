<?php

declare(strict_types=1);

/**
 * Carga movimientos que ocurrieron pero que ningún sistema registró.
 *
 *   php bin/imputar.php [--real] <chat_id> <categoria> <dia> <comercio> <mes=importe>...
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

// --real marca los movimientos como dato verificado y no como estimación.
// Importa que la distinción viva en el registro, no en la memoria de
// quien lo cargó.
$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--real'));
$esReal = in_array('--real', $argv, true);

$chatId = (int) ($args[0] ?? 0);
$categoria = (string) ($args[1] ?? '');
$dia = (int) ($args[2] ?? 0);
$comercio = (string) ($args[3] ?? '');
$meses = array_slice($args, 4);

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
        descripcion: $comercio . ($esReal ? '' : ' (estimado)'),
        categoria: $categoria,
        medioPago: '',
        fuente: $esReal ? Draft::FUENTE_MANUAL : Draft::FUENTE_ESTIMADO,
        confianza: $esReal ? 1.0 : 0.50,
        modelo: $esReal ? 'comprobante' : 'interpolacion-geometrica',
        tipo: Draft::TIPO_GASTO,
        naturaleza: Draft::NATURALEZA_FIJO,
    );

    $id = $gastos->guardarBorrador(
        $userId,
        $borrador,
        $categoryId,
        $lote,
        sprintf('%s:%s:%s', $esReal ? 'manual' : 'estimado', mb_strtolower($categoria), $mes),
        ExpenseRepository::ESTADO_CONFIRMADO
    );

    if ($id === 0) {
        $salteados++;
        printf("  %s  ya estaba\n", $mes);

        continue;
    }

    $cargados++;
    printf("  %s  %s  %s\n", $mes, $borrador->monto->formatear(), $esReal ? 'real' : 'estimado');
}

printf("\n%d cargados, %d ya estaban. Lote %s\n", $cargados, $salteados, $lote);
