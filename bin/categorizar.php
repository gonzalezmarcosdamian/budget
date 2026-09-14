<?php

declare(strict_types=1);

/**
 * Categoriza con IA los movimientos que las palabras clave no pescaron.
 *
 *   php bin/categorizar.php <chat_id> [--limite=500] [--seco]
 *
 * Pregunta sobre descripciones únicas y no sobre movimientos: cuatrocientos
 * movimientos suelen ser cuarenta comercios distintos.
 *
 * Cada respuesta se guarda además como regla en merchant_rules, así el
 * mismo comercio no se vuelve a preguntar nunca más. Es la diferencia
 * entre un costo que se paga una vez y uno que se paga siempre.
 */

use Budget\Ai\Categorizador;
use Budget\App;
use Budget\Repository\CategoryRepository;
use Budget\Repository\UserRepository;
use Budget\Support\Http;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$chatId = (int) ($argv[1] ?? 0);
$seco = in_array('--seco', $argv, true);
$limite = 500;

foreach ($argv as $a) {
    if (str_starts_with($a, '--limite=')) {
        $limite = max(1, (int) substr($a, 9));
    }
}

if ($chatId === 0) {
    fwrite(STDERR, "Uso: php bin/categorizar.php <chat_id> [--limite=500] [--seco]\n");
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

$sentencia = $pdo->prepare(
    'SELECT comercio, COUNT(*) AS n, SUM(monto) AS total
     FROM expenses
     WHERE user_id = ? AND category_id IS NULL AND comercio <> \'\'
     GROUP BY comercio
     ORDER BY SUM(monto) DESC
     LIMIT ' . $limite
);
$sentencia->execute([$userId]);
$pendientes = $sentencia->fetchAll();

if ($pendientes === []) {
    echo "No queda nada sin categorizar.\n";
    exit(0);
}

printf("%d descripciones distintas sin categoría.\n\n", count($pendientes));

$categorizador = new Categorizador($app->config->claveIa('GEMINI_API_KEY'), new Http(90));

if (!$categorizador->disponible()) {
    fwrite(STDERR, "Falta GEMINI_API_KEY.\n");
    exit(1);
}

$categorias = new CategoryRepository($pdo);
$aplicar = $pdo->prepare(
    'UPDATE expenses SET category_id = ?, tipo = ?, naturaleza = ?
     WHERE user_id = ? AND comercio = ? AND category_id IS NULL'
);

$tandas = array_chunk($pendientes, Categorizador::POR_TANDA);
$clasificados = 0;
$movimientos = 0;

foreach ($tandas as $i => $tanda) {
    $comercios = array_map(static fn (array $f): string => (string) $f['comercio'], $tanda);

    try {
        $asignaciones = $categorizador->clasificar($comercios);
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("  tanda %d falló: %s\n", $i + 1, $e->getMessage()));

        continue;
    }

    foreach ($tanda as $fila) {
        $comercio = (string) $fila['comercio'];
        $asignado = $asignaciones[$comercio] ?? null;

        if ($asignado === null) {
            continue;
        }

        $categoryId = $categorias->idPorNombre($userId, $asignado['categoria']);

        if ($categoryId === null) {
            continue;
        }

        $naturaleza = (string) ($pdo->query(
            'SELECT naturaleza FROM categories WHERE id = ' . $categoryId
        )->fetchColumn() ?: 'variable');

        printf(
            "  %-40s -> %-20s %s (%d mov.)\n",
            mb_substr($comercio, 0, 40),
            $asignado['categoria'],
            $asignado['tipo'],
            (int) $fila['n']
        );

        if ($seco) {
            $clasificados++;

            continue;
        }

        $aplicar->execute([$categoryId, $asignado['tipo'], $naturaleza, $userId, $comercio]);
        $movimientos += $aplicar->rowCount();

        // La respuesta se vuelve regla: este comercio no se pregunta más.
        $categorias->recordarRegla($userId, $comercio, $categoryId);
        $clasificados++;
    }
}

printf(
    "\n%d descripciones clasificadas, %d movimientos actualizados%s\n",
    $clasificados,
    $movimientos,
    $seco ? ' (simulacro, no se escribió nada)' : ''
);
