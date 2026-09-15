<?php

declare(strict_types=1);

/**
 * Nombra y clasifica una contraparte.
 *
 *   php bin/contraparte.php                      lista las que faltan clasificar
 *   php bin/contraparte.php 252300561            muestra su historial
 *   php bin/contraparte.php 252300561 --alias="Amiga" --reintegra
 *   php bin/contraparte.php 2825076  --propia
 *
 * Las columnas `es_propia` y `reintegra` deciden dos cosas que Mercado
 * Pago no puede saber —si una cuenta es tuya, y si lo que te manda es
 * devolución de algo que pagaste vos— y sin este comando quedaban
 * inertes: estaban en la base y no había forma de ponerlas en 1 salvo
 * escribiendo SQL contra producción.
 *
 * Marcar `reintegra` a alguien que además te paga esconde gasto, así
 * que antes de escribir muestra el historial con las dos puntas: la
 * decisión se toma mirando los números, no de memoria.
 */

use Budget\App;
use Budget\Support\Money;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

function bandera(array $argv, string $nombre): bool
{
    return in_array('--' . $nombre, $argv, true);
}

function valor(array $argv, string $nombre): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$nombre}=")) {
            return trim(substr($arg, strlen($nombre) + 3), '"\'');
        }
    }

    return null;
}

function plata(mixed $v): string
{
    return Money::deDecimal((string) $v)->formatear();
}

$app = App::crear(dirname(__DIR__));
$pdo = $app->pdo();

$userId = (int) (valor($argv, 'user') ?? 0);

if ($userId === 0) {
    $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
}

$externo = null;

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        $externo = $arg;
        break;
    }
}

// Sin contraparte: el listado de las que más plata mueven y todavía no
// están clasificadas, que es por dónde conviene empezar.
if ($externo === null) {
    $filas = $pdo->prepare(
        "SELECT e.contraparte cp, COALESCE(p.alias, MAX(e.comercio)) nombre,
                COALESCE(p.es_propia, 0) propia, COALESCE(p.reintegra, 0) reintegra,
                SUM(CASE WHEN e.tipo = 'gasto' THEN e.monto_ars ELSE 0 END) env,
                SUM(CASE WHEN e.tipo = 'ingreso' THEN e.monto_ars ELSE 0 END) rec,
                COUNT(*) n
           FROM expenses e
           LEFT JOIN contrapartes p ON p.externo = e.contraparte AND p.user_id = e.user_id
          WHERE e.user_id = ? AND e.estado = 'confirmado' AND e.contraparte IS NOT NULL
          GROUP BY e.contraparte, p.alias, p.es_propia, p.reintegra
          ORDER BY SUM(e.monto_ars) DESC
          LIMIT 20"
    );
    $filas->execute([$userId]);

    printf("%-12s %-28s %14s %14s %4s  marcas\n", 'ID', 'NOMBRE', 'MANDASTE', 'TE MANDARON', 'MOV');

    foreach ($filas->fetchAll() as $f) {
        $marcas = [];

        if ((int) $f['propia'] === 1) {
            $marcas[] = 'propia';
        }

        if ((int) $f['reintegra'] === 1) {
            $marcas[] = 'reintegra';
        }

        printf(
            "%-12s %-28s %14s %14s %4d  %s\n",
            $f['cp'],
            mb_substr((string) $f['nombre'], 0, 27),
            plata($f['env']),
            plata($f['rec']),
            (int) $f['n'],
            implode(', ', $marcas)
        );
    }

    echo "\nPara clasificar una: php bin/contraparte.php <ID> --alias=\"Nombre\" [--propia] [--reintegra]\n";
    exit(0);
}

// Con contraparte: primero el historial, siempre.
$detalle = $pdo->prepare(
    "SELECT e.fecha, e.tipo, e.monto_ars m, LEFT(e.comercio, 40) com
       FROM expenses e
      WHERE e.user_id = ? AND e.contraparte = ? AND e.estado = 'confirmado'
      ORDER BY e.fecha DESC LIMIT 15"
);
$detalle->execute([$userId, $externo]);
$movimientos = $detalle->fetchAll();

if ($movimientos === []) {
    fwrite(STDERR, "No hay movimientos con la contraparte {$externo}.\n");
    exit(1);
}

printf("Contraparte %s — últimos %d movimientos\n\n", $externo, count($movimientos));

$enviado = 0;
$recibido = 0;

foreach ($movimientos as $m) {
    $entra = (string) $m['tipo'] === 'ingreso';
    printf("  %s  %s%-14s  %s\n", $m['fecha'], $entra ? '+' : '−', plata($m['m']), $m['com']);
}

$totales = $pdo->prepare(
    "SELECT SUM(CASE WHEN tipo = 'gasto' THEN monto_ars ELSE 0 END) env,
            SUM(CASE WHEN tipo = 'ingreso' THEN monto_ars ELSE 0 END) rec
       FROM expenses WHERE user_id = ? AND contraparte = ? AND estado = 'confirmado'"
);
$totales->execute([$userId, $externo]);
$t = $totales->fetch() ?: ['env' => '0', 'rec' => '0'];

printf("\n  total mandado:   %s\n  total recibido:  %s\n", plata($t['env']), plata($t['rec']));

$alias = valor($argv, 'alias');
$propia = bandera($argv, 'propia');
$reintegra = bandera($argv, 'reintegra');

if ($alias === null && !$propia && !$reintegra) {
    echo "\n(sin cambios: pasá --alias, --propia o --reintegra para clasificarla)\n";
    exit(0);
}

// La advertencia que pidió la auditoría: marcar reintegra a alguien que
// además te paga esconde gasto, y es el error que más caro sale.
if ($reintegra && (float) $t['env'] > 0.0 && (float) $t['rec'] > (float) $t['env']) {
    echo "\n⚠ Te mandó más de lo que le mandaste. Si parte de eso es un cobro\n"
        . "  y no una devolución, marcar --reintegra va a esconder gasto.\n";
}

$pdo->prepare(
    'INSERT INTO contrapartes (user_id, externo, alias, es_propia, reintegra)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        alias = COALESCE(NULLIF(VALUES(alias), \'\'), alias),
        es_propia = VALUES(es_propia),
        reintegra = VALUES(reintegra)'
)->execute([$userId, $externo, $alias ?? '', $propia ? 1 : 0, $reintegra ? 1 : 0]);

if ($alias !== null && $alias !== '') {
    $pdo->prepare('UPDATE expenses SET comercio = ? WHERE user_id = ? AND contraparte = ?')
        ->execute([$alias, $userId, $externo]);
}

printf(
    "\n✓ %s — propia=%s reintegra=%s\n",
    $alias ?? '(alias sin cambiar)',
    $propia ? 'sí' : 'no',
    $reintegra ? 'sí' : 'no'
);
