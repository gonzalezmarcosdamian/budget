<?php

declare(strict_types=1);

/**
 * Toda clase que el código nombra tiene que existir con ese nombre.
 *
 * Existe por un bug real: un `use` que se borró de más dejó un
 * `new RecurringRepository(...)` resolviendo a `Budget\Handler\
 * RecurringRepository`, que no existe. `php -l` no lo ve —es sintaxis
 * válida— y el error sólo aparece al ejecutar esa línea: en producción,
 * cuando el usuario toca el botón de un recordatorio. Como el webhook ya
 * contestó 200, Telegram tampoco reintenta.
 *
 * El chequeo imita la resolución de PHP: un nombre sin barra se busca
 * primero en los `use` del archivo y si no, en el namespace propio.
 */

/** @return list<string> los nombres de clase que el archivo menciona */
function clasesMencionadas(string $codigo): array
{
    $tokens = token_get_all($codigo);
    $namespace = '';
    $alias = [];
    $mencionadas = [];

    foreach ($tokens as $i => $token) {
        if (!is_array($token)) {
            continue;
        }

        [$tipo, $texto] = $token;

        if ($tipo === T_NAMESPACE) {
            $namespace = nombreSiguiente($tokens, $i);
            continue;
        }

        if ($tipo === T_USE && $namespace !== '') {
            // Sólo los `use` de nivel superior: los de las clausuras no
            // traen nombres de clase.
            $importado = nombreSiguiente($tokens, $i);

            if ($importado !== '' && !str_contains($importado, '(')) {
                $partes = explode('\\', $importado);
                $alias[renombre($tokens, $i) ?? end($partes)] = $importado;
            }

            continue;
        }

        if ($tipo !== T_NEW) {
            continue;
        }

        $nombre = nombreSiguiente($tokens, $i);

        if ($nombre === '' || in_array(strtolower($nombre), ['self', 'static', 'parent', 'class'], true)) {
            continue;
        }

        if (str_starts_with($nombre, '\\')) {
            $mencionadas[] = ltrim($nombre, '\\');
            continue;
        }

        $raiz = explode('\\', $nombre)[0];

        if (isset($alias[$raiz])) {
            $mencionadas[] = $alias[$raiz] . substr($nombre, strlen($raiz));
            continue;
        }

        $mencionadas[] = $namespace . '\\' . $nombre;
    }

    return array_values(array_unique($mencionadas));
}

/** El alias de un `use ... as X`, o null si no lo renombra. */
function renombre(array $tokens, int $desdeUse): ?string
{
    for ($i = $desdeUse + 1, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i] === ';' || $tokens[$i] === '{') {
            return null;
        }

        if (is_array($tokens[$i]) && $tokens[$i][0] === T_AS) {
            $alias = nombreSiguiente($tokens, $i);

            return $alias === '' ? null : $alias;
        }
    }

    return null;
}

/** El nombre calificado que sigue a un token, salteando espacios. */
function nombreSiguiente(array $tokens, int $desde): string
{
    $nombre = '';

    for ($i = $desde + 1, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            if ($nombre !== '') {
                break;
            }

            continue;
        }

        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $nombre .= $token[1];
            continue;
        }

        break;
    }

    return $nombre;
}

prueba('toda clase instanciada en src/ resuelve a una clase que existe', function (): void {
    $archivos = glob(__DIR__ . '/../src/**/*.php') ?: [];
    $archivos = array_merge($archivos, glob(__DIR__ . '/../src/*.php') ?: []);

    afirmar(count($archivos) > 20, 'se encontraron los archivos de src/');

    $revisadas = 0;

    foreach ($archivos as $archivo) {
        $codigo = file_get_contents($archivo);

        if ($codigo === false) {
            continue;
        }

        foreach (clasesMencionadas($codigo) as $clase) {
            $revisadas++;
            afirmar(
                class_exists($clase) || interface_exists($clase) || enum_exists($clase),
                sprintf('%s instancia %s, que no existe con ese nombre', basename($archivo), $clase)
            );
        }
    }

    afirmar($revisadas > 20, "sólo se revisaron {$revisadas} clases: el analizador no está leyendo nada");
});
