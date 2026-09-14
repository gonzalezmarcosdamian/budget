<?php

declare(strict_types=1);

/**
 * Hook Stop: corre los tests unitarios al terminar cada turno.
 *
 * Tarda milisegundos y evita el peor final posible de una sesión: dar
 * algo por terminado con la suite en rojo.
 *
 * Es advertencia, no bloqueo. Sale siempre con 0 a propósito: un hook de
 * Stop que bloquea con tests rojos deja al agente dando vueltas en vez
 * de dejar que la persona decida qué hacer.
 */

$raiz = dirname(__DIR__, 2);
$runner = $raiz . '/tests/run.php';

if (!is_file($runner)) {
    exit(0);
}

$salida = [];
$codigo = 0;
exec(sprintf('php %s --unit 2>&1', escapeshellarg($runner)), $salida, $codigo);

if ($codigo === 0) {
    exit(0);
}

fwrite(STDERR, "⚠ Los tests unitarios están en rojo:\n");
fwrite(STDERR, implode("\n", array_slice($salida, -20)) . "\n");

exit(0);
