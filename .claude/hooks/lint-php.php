<?php

declare(strict_types=1);

/**
 * Hook PostToolUse: verifica la sintaxis de cada .php recién editado.
 *
 * Atrapa el error de tipeo en el momento en que se produce, que es
 * cuando cuesta diez segundos arreglarlo, y no veinte minutos después
 * cuando CI lo encuentra sin contexto.
 *
 * Sale con código 2 para que el fallo vuelva al agente y lo corrija.
 */

$entrada = json_decode((string) file_get_contents('php://stdin'), true);

if (!is_array($entrada)) {
    exit(0);
}

$ruta = $entrada['tool_input']['file_path'] ?? '';

if (!is_string($ruta) || !str_ends_with(strtolower($ruta), '.php') || !is_file($ruta)) {
    exit(0);
}

$salida = [];
$codigo = 0;
exec(sprintf('php -l %s 2>&1', escapeshellarg($ruta)), $salida, $codigo);

if ($codigo === 0) {
    exit(0);
}

fwrite(STDERR, "Error de sintaxis PHP en el archivo recién editado:\n");
fwrite(STDERR, implode("\n", $salida) . "\n");

exit(2);
