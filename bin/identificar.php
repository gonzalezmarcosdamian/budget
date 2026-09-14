<?php

declare(strict_types=1);

/**
 * Le pone nombre a los destinatarios de transferencias.
 *
 *   php bin/identificar.php <chat_id> [--limite=100] [--seco]
 *
 * Mercado Pago no manda el nombre en el movimiento, pero el perfil del
 * destinatario sí es consultable, y MP autogenera el apodo con el
 * apellido de la persona. Con eso, 180 transferencias que decían
 * "Varios" pasan a decir quién cobró.
 *
 * Trabaja sobre destinatarios distintos, no sobre movimientos: 180
 * transferencias son 85 personas.
 */

use Budget\App;
use Budget\Integracion\MercadoPago;
use Budget\Repository\MercadoPagoRepository;
use Budget\Repository\UserRepository;
use Budget\Support\Cifrado;
use Budget\Support\Http;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$chatId = (int) ($argv[1] ?? 0);
$seco = in_array('--seco', $argv, true);
$limite = 100;

foreach ($argv as $a) {
    if (str_starts_with($a, '--limite=')) {
        $limite = max(1, (int) substr($a, 9));
    }
}

if ($chatId === 0) {
    fwrite(STDERR, "Uso: php bin/identificar.php <chat_id> [--limite=100] [--seco]\n");
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
$cuenta = (new MercadoPagoRepository($pdo))->porUsuario($userId);

if ($cuenta === null) {
    fwrite(STDERR, "El usuario no tiene cuenta de Mercado Pago vinculada.\n");
    exit(1);
}

$mp = new MercadoPago(
    Cifrado::conClaveHex($app->config->claveDeCifrado())->descifrar((string) $cuenta['token_cifrado']),
    (int) $cuenta['mp_user_id'],
    new Http(40)
);

// Fase 1: completar el destinatario en los movimientos que no lo tienen.
// No hace falta volver a buscarlos: origen_externo ya guarda el id del
// pago, así que se pide el detalle directo.
$sinContraparte = $pdo->prepare(
    "SELECT id, origen_externo FROM expenses
     WHERE user_id = ? AND modelo = 'mercadopago' AND contraparte IS NULL
       AND comercio = 'Transferencia' AND origen_externo LIKE 'mp:%'
     LIMIT " . ($limite * 4)
);
$sinContraparte->execute([$userId]);
$porCompletar = $sinContraparte->fetchAll();

if ($porCompletar !== [] && !$seco) {
    $marcar = $pdo->prepare('UPDATE expenses SET contraparte = ? WHERE id = ?');
    $completados = 0;

    foreach ($porCompletar as $fila) {
        $pagoId = substr((string) $fila['origen_externo'], 3);

        try {
            $contraparte = $mp->contraparteDe(['id' => $pagoId, 'operation_type' => 'money_transfer']);
        } catch (Throwable $e) {
            continue;
        }

        if ($contraparte !== null) {
            $marcar->execute([$contraparte, (int) $fila['id']]);
            $completados++;
        }
    }

    printf("Destinatario completado en %d movimientos.\n\n", $completados);
}

// Fase 2: destinatarios que todavía no tienen nombre, los que más plata
// mueven primero: ahí está el valor.
$sentencia = $pdo->prepare(
    'SELECT e.contraparte, COUNT(*) AS n, SUM(e.monto) AS total
     FROM expenses e
     LEFT JOIN contrapartes c ON c.user_id = e.user_id AND c.externo = e.contraparte
     WHERE e.user_id = ? AND e.contraparte IS NOT NULL AND c.id IS NULL
     GROUP BY e.contraparte
     ORDER BY SUM(e.monto) DESC
     LIMIT ' . $limite
);
$sentencia->execute([$userId]);
$pendientes = $sentencia->fetchAll();

if ($pendientes === []) {
    echo "No hay destinatarios sin identificar.\n";
    exit(0);
}

printf("%d destinatarios sin nombre.\n\n", count($pendientes));

$guardar = $pdo->prepare(
    'INSERT INTO contrapartes (user_id, externo, alias) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE alias = VALUES(alias)'
);
$aplicar = $pdo->prepare('UPDATE expenses SET comercio = ? WHERE user_id = ? AND contraparte = ?');

$identificados = 0;
$movimientos = 0;

foreach ($pendientes as $fila) {
    $externo = (string) $fila['contraparte'];

    try {
        $apodo = $mp->apodoDe($externo);
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("  %s: %s\n", $externo, substr($e->getMessage(), 0, 70)));

        continue;
    }

    if ($apodo === '') {
        continue;
    }

    $alias = MercadoPago::nombreLegible($apodo);

    printf(
        "  %-12s -> %-24s %2d mov.  $%s\n",
        $externo,
        $alias,
        (int) $fila['n'],
        number_format((float) $fila['total'], 0, ',', '.')
    );

    if ($seco) {
        $identificados++;

        continue;
    }

    $guardar->execute([$userId, $externo, $alias]);
    $aplicar->execute([$alias, $userId, $externo]);
    $movimientos += $aplicar->rowCount();
    $identificados++;
}

printf(
    "\n%d identificados, %d movimientos renombrados%s\n",
    $identificados,
    $movimientos,
    $seco ? ' (simulacro)' : ''
);
