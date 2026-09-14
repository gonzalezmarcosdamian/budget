<?php

declare(strict_types=1);

/**
 * Le pone nombre y categoría a un destinatario de transferencias.
 *
 *   php bin/nombrar.php <chat_id> <id_externo> "<alias>" [categoria]
 *
 * Mercado Pago no dice a quién le transferiste, sólo un identificador.
 * Una vez que ese identificador tiene nombre, todos sus movimientos
 * —pasados y futuros— quedan categorizados. Nombrar cinco destinatarios
 * cubre el 59% de la plata transferida.
 */

use Budget\App;
use Budget\Repository\CategoryRepository;
use Budget\Repository\UserRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$chatId = (int) ($argv[1] ?? 0);
$externo = (string) ($argv[2] ?? '');
$alias = (string) ($argv[3] ?? '');
$categoria = (string) ($argv[4] ?? '');

if ($chatId === 0 || $externo === '' || $alias === '') {
    fwrite(STDERR, "Uso: php bin/nombrar.php <chat_id> <id_externo> \"<alias>\" [categoria]\n");
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
$categoryId = null;

if ($categoria !== '') {
    $categoryId = $categorias->idPorNombre($userId, $categoria);

    if ($categoryId === null) {
        fwrite(STDERR, "No existe la categoría \"{$categoria}\".\n");
        exit(1);
    }
}

$guardar = $pdo->prepare(
    'INSERT INTO contrapartes (user_id, externo, alias, category_id)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE alias = VALUES(alias), category_id = VALUES(category_id)'
);
$guardar->execute([$userId, $externo, $alias, $categoryId]);

// El alias se aplica hacia atrás: el sentido de nombrar es que los
// movimientos viejos dejen de decir "Transferencia".
$aplicar = $pdo->prepare(
    $categoryId === null
        ? 'UPDATE expenses SET comercio = ? WHERE user_id = ? AND contraparte = ?'
        : 'UPDATE expenses SET comercio = ?, category_id = ' . $categoryId
          . ' WHERE user_id = ? AND contraparte = ?'
);
$aplicar->execute([$alias, $userId, $externo]);

printf(
    "%s -> %s%s\n%d movimientos actualizados.\n",
    $externo,
    $alias,
    $categoria === '' ? '' : ' (' . $categoria . ')',
    $aplicar->rowCount()
);
