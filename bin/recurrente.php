<?php

declare(strict_types=1);

/**
 * Registra un gasto que se repite todos los meses.
 *
 *   php bin/recurrente.php <chat_id> "<comercio>" <monto> <dia> [categoria] ["nota"]
 *
 * Ejemplo:
 *   php bin/recurrente.php 1595524230 "Alquiler" 1048190 10 Alquiler "Ajusta por IPC cada 3 meses"
 *
 * Los gastos más grandes son los que el bot no ve, porque se pagan por
 * fuera de todo lo que tiene conectado. El día que toca, pregunta.
 */

use Budget\App;
use Budget\Repository\CategoryRepository;
use Budget\Repository\RecurringRepository;
use Budget\Repository\UserRepository;
use Budget\Support\Money;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$chatId = (int) ($argv[1] ?? 0);
$comercio = (string) ($argv[2] ?? '');
$monto = (string) ($argv[3] ?? '');
$dia = (int) ($argv[4] ?? 0);
$categoria = (string) ($argv[5] ?? '');
$nota = (string) ($argv[6] ?? '');

if ($chatId === 0 || $comercio === '' || !is_numeric($monto) || $dia < 1 || $dia > 31) {
    fwrite(STDERR, "Uso: php bin/recurrente.php <chat_id> \"<comercio>\" <monto> <dia> [categoria] [nota]\n");
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
$categoryId = null;
$naturaleza = 'fijo';

if ($categoria !== '') {
    $categorias = new CategoryRepository($pdo);
    $categoryId = $categorias->idPorNombre($userId, $categoria);

    if ($categoryId === null) {
        fwrite(STDERR, "No existe la categoría \"{$categoria}\".\n");
        exit(1);
    }

    $naturaleza = (string) ($pdo->query(
        'SELECT naturaleza FROM categories WHERE id = ' . $categoryId
    )->fetchColumn() ?: 'fijo');
}

$recurrentes = new RecurringRepository($pdo);
$id = $recurrentes->crear($userId, $comercio, Money::deDecimal($monto), $dia, $categoryId, $naturaleza, $nota);

$proximo = RecurringRepository::proximaFecha($dia, new DateTimeImmutable());

printf(
    "Recurrente #%d: %s por %s, el día %d de cada mes.\nPrimer aviso: %s\n",
    $id,
    $comercio,
    Money::deDecimal($monto)->formatear(),
    $dia,
    $proximo->format('d/m/Y')
);
