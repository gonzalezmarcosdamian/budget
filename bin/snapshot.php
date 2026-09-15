<?php

declare(strict_types=1);

/**
 * Guarda una foto del portafolio.
 *
 *   php bin/snapshot.php --user=1 --archivo=portafolio.json
 *   php bin/snapshot.php --user=1 --fecha=2026-08-15 --archivo=agosto.json
 *
 * El bot no tiene credenciales de IOL —eso queda pendiente— así que la
 * foto entra desde afuera. El JSON acepta las dos partes de la cartera:
 *
 *   {
 *     "positions": [                      <- tal cual lo devuelve IOL
 *       {"quantity": 115, "unit_price": 20280,
 *        "asset": {"symbol": "SPY", "description": "...", "type": "CEDEARS"}}
 *     ],
 *     "manual": [                         <- lo que ninguna API entrega
 *       {"simbolo": "USD-MP", "descripcion": "Dolares en Mercado Pago",
 *        "origen": "mercadopago", "clase": "reserva",
 *        "cantidad": 1000, "precio": 1450}
 *     ]
 *   }
 *
 * El saldo de Mercado Pago va en `manual` porque su API no lo entrega:
 * con el token de la aplicación devuelve 403 en balance y 404 en los
 * endpoints de inversiones. Está medido, no supuesto.
 *
 * Volver a correrlo con la misma fecha reemplaza la foto, no la duplica.
 */

use Budget\App;
use Budget\Repository\PatrimonioRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

/** @return string el valor de --clave=valor, o el default */
function opcion(array $argv, string $clave, string $defecto = ''): string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$clave}=")) {
            return substr($arg, strlen($clave) + 3);
        }
    }

    return $defecto;
}

$archivo = opcion($argv, 'archivo');
$userId = (int) opcion($argv, 'user', '1');
$fecha = new DateTimeImmutable(opcion($argv, 'fecha', 'today'));

if ($archivo === '' || !is_readable($archivo)) {
    fwrite(STDERR, "Falta --archivo=<json del portafolio>\n");
    exit(1);
}

$crudo = json_decode((string) file_get_contents($archivo), true);

if (!is_array($crudo) || !is_array($crudo['positions'] ?? null)) {
    fwrite(STDERR, "El JSON no tiene 'positions'.\n");
    exit(1);
}

$posiciones = [];

foreach ($crudo['positions'] as $p) {
    if (!is_array($p)) {
        continue;
    }

    $activo = is_array($p['asset'] ?? null) ? $p['asset'] : [];
    $simbolo = trim((string) ($activo['symbol'] ?? ''));
    $cantidad = (float) ($p['quantity'] ?? 0);
    $precio = (float) ($p['unit_price'] ?? 0);

    // Una posición sin símbolo o sin precio no se puede comparar después:
    // guardarla a medias ensucia el mes que viene.
    if ($simbolo === '' || $cantidad <= 0.0 || $precio <= 0.0) {
        fwrite(STDERR, "Se saltea una posición incompleta: " . json_encode($p) . "\n");
        continue;
    }

    $posiciones[] = [
        'simbolo' => $simbolo,
        'descripcion' => (string) ($activo['description'] ?? ''),
        'tipo' => (string) ($activo['type'] ?? ''),
        'origen' => PatrimonioRepository::ORIGEN_IOL,
        'clase' => PatrimonioRepository::CLASE_INVERSION,
        'cantidad' => $cantidad,
        'precio' => $precio,
        'valor' => $cantidad * $precio,
    ];
}

// Lo que ninguna API entrega: dólares y fondo de Mercado Pago.
foreach (($crudo['manual'] ?? []) as $p) {
    if (!is_array($p)) {
        continue;
    }

    $simbolo = trim((string) ($p['simbolo'] ?? ''));
    $cantidad = (float) ($p['cantidad'] ?? 0);
    $precio = (float) ($p['precio'] ?? 0);

    if ($simbolo === '' || $cantidad <= 0.0 || $precio <= 0.0) {
        fwrite(STDERR, "Se saltea una posición manual incompleta: " . json_encode($p) . "\n");
        continue;
    }

    $posiciones[] = [
        'simbolo' => $simbolo,
        'descripcion' => (string) ($p['descripcion'] ?? ''),
        'tipo' => (string) ($p['tipo'] ?? ''),
        'origen' => (string) ($p['origen'] ?? PatrimonioRepository::ORIGEN_MANUAL),
        'clase' => (string) ($p['clase'] ?? PatrimonioRepository::CLASE_RESERVA),
        'cantidad' => $cantidad,
        'precio' => $precio,
        'valor' => $cantidad * $precio,
    ];
}

if ($posiciones === []) {
    fwrite(STDERR, "No quedó ninguna posición válida.\n");
    exit(1);
}

$app = App::crear(dirname(__DIR__));
(new PatrimonioRepository($app->pdo()))->guardar($userId, $fecha, $posiciones);

$total = 0.0;

foreach ($posiciones as $p) {
    $total += $p['valor'];
}

printf("Foto del %s guardada: %d posiciones, $%s\n",
    $fecha->format('d/m/Y'),
    count($posiciones),
    number_format($total, 2, ',', '.')
);

foreach ($posiciones as $p) {
    printf("  %-8s %10s x %12s = %14s\n",
        $p['simbolo'],
        number_format($p['cantidad'], 0, ',', '.'),
        number_format($p['precio'], 2, ',', '.'),
        '$' . number_format($p['valor'], 2, ',', '.')
    );
}
