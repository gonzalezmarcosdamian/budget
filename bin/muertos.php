<?php

declare(strict_types=1);

/**
 * Métodos de src/ que nadie llama.
 *
 *   php bin/muertos.php
 *
 * Cuenta las menciones del nombre en todo el proyecto —src, bin, tests,
 * public— y descuenta la declaracion. Si queda en cero, no lo llama
 * nadie. Es aproximado a proposito: prefiere sobre-reportar y que lo
 * decida una persona, antes que borrar algo que se invoca dinamicamente.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = getcwd();

/** @return list<string> */
function archivosPhp(string $raiz): array
{
    $salida = [];

    foreach (['src', 'bin', 'tests', 'public'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $dir));

        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $salida[] = strtr($f->getPathname(), DIRECTORY_SEPARATOR, '/');
            }
        }
    }

    return $salida;
}

$archivos = archivosPhp($raiz);
$todo = '';

foreach ($archivos as $f) {
    $todo .= file_get_contents($f) . "\n";
}

// Los nombres que PHP o el harness invocan sin que aparezca una llamada.
$exentos = [
    '__construct', '__toString', '__invoke',
    // El harness de tests registra closures, no metodos.
    'prueba', 'afirmar', 'esIgual', 'esNulo', 'noEsNulo', 'contiene', 'lanza',
];

$muertos = [];

foreach ($archivos as $archivo) {
    if (!str_contains($archivo, '/src/')) {
        continue;
    }

    $codigo = (string) file_get_contents($archivo);

    if (preg_match_all('/function\s+(\w+)\s*\(/', $codigo, $m) === 0) {
        continue;
    }

    foreach ($m[1] as $metodo) {
        if (in_array($metodo, $exentos, true)) {
            continue;
        }

        // Cuantas veces aparece el nombre seguido de parentesis, menos
        // las declaraciones.
        $usos = preg_match_all('/\b' . preg_quote($metodo, '/') . '\s*\(/', $todo);
        $declaraciones = preg_match_all('/function\s+' . preg_quote($metodo, '/') . '\s*\(/', $todo);

        if ($usos - $declaraciones <= 0) {
            $muertos[] = sprintf('%-28s %s', $metodo, basename($archivo));
        }
    }
}

if ($muertos === []) {
    echo "Sin metodos muertos.\n";
    exit(0);
}

printf("%d metodos sin llamadores:\n\n", count($muertos));

foreach ($muertos as $m) {
    echo '  ' . $m . "\n";
}
