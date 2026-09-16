<?php

declare(strict_types=1);

/**
 * Todo método que el código llama sobre sí mismo o sobre una dependencia
 * tiene que existir.
 *
 * Hermano de ClasesResuelvenTest, y por el mismo motivo con otra cara.
 * Mover un bloque de código entre clases deja llamadas apuntando a
 * métodos que ya no están ahí, y eso no lo ve nadie: `php -l` acepta
 * `$this->loQueSea()` porque es sintaxis válida, y la suite no lo toca
 * si nadie construye esa clase.
 *
 * Pasó dos veces en la misma tarde. Una dejó al bot mudo cuando no
 * entendía un mensaje; la otra rompía la tarjeta al confirmar un gasto
 * con categoría, con el gasto ya guardado y el botón intacto. Las dos
 * en silencio, porque el webhook ya había contestado 200 y Telegram no
 * reintenta.
 *
 * Chequea tres formas, que son las que se rompen al mover código:
 *
 *     $this->metodo()            el método existe en la clase
 *     self::metodo()             idem, estático
 *     $this->dep->metodo()       la propiedad existe, y el método en su tipo
 *     $this->fabrica()->metodo() el método existe en el tipo que devuelve
 */

/** @return int|null la posición del `)` que cierra el `(` de $desde */
function cierraParentesis(array $tokens, int $desde): ?int
{
    $nivel = 0;

    for ($i = $desde, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i] === '(') {
            $nivel++;
        } elseif ($tokens[$i] === ')') {
            $nivel--;

            if ($nivel === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * Las llamadas que hace un archivo, leídas del código y no adivinadas.
 *
 * @return array{propias:list<string>, sobreDependencia:list<array{0:string,1:string}>}
 */
function llamadasDe(string $codigo): array
{
    $tokens = token_get_all($codigo);
    $propias = [];
    $sobreDependencia = [];
    $cadenas = [];

    foreach ($tokens as $i => $token) {
        $esThis = is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$this';
        $esSelf = is_array($token) && $token[0] === T_STRING && $token[1] === 'self';

        if (!$esThis && !$esSelf) {
            continue;
        }

        $flecha = siguienteUtil($tokens, $i);

        if ($flecha === null) {
            continue;
        }

        [$posFlecha, $tokenFlecha] = $flecha;
        $esAcceso = is_array($tokenFlecha)
            && in_array($tokenFlecha[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true);

        if (!$esAcceso) {
            continue;
        }

        $nombre = siguienteUtil($tokens, $posFlecha);

        if ($nombre === null || !is_array($nombre[1]) || $nombre[1][0] !== T_STRING) {
            continue;
        }

        $identificador = $nombre[1][1];
        $despues = siguienteUtil($tokens, $nombre[0]);

        if ($despues === null) {
            continue;
        }

        // `(` la vuelve una llamada; `->` la vuelve una propiedad sobre
        // la que se llama algo más abajo.
        if ($despues[1] === '(') {
            $propias[] = $identificador;

            // Y si después del paréntesis de cierre viene `->`, es una
            // cadena: `$this->tarjetas()->revisar()`. Ese es el patrón
            // con el que el Dispatcher delega en todos sus handlers, así
            // que sin esto el guard no mira justamente lo que más se
            // mueve. El tipo lo da el `: Tipo` de la declaración.
            $cierre = cierraParentesis($tokens, $despues[0]);

            if ($cierre !== null) {
                $flechaCadena = siguienteUtil($tokens, $cierre);

                if ($flechaCadena !== null
                    && is_array($flechaCadena[1])
                    && $flechaCadena[1][0] === T_OBJECT_OPERATOR
                ) {
                    $encadenado = siguienteUtil($tokens, $flechaCadena[0]);

                    // Con el paréntesis: sin él es una propiedad
                    // —`$this->sumaDe()->centavos`— y no hay método
                    // que verificar.
                    if ($encadenado !== null
                        && is_array($encadenado[1])
                        && $encadenado[1][0] === T_STRING
                        && (siguienteUtil($tokens, $encadenado[0])[1] ?? null) === '('
                    ) {
                        $cadenas[] = [$identificador, $encadenado[1][1]];
                    }
                }
            }

            continue;
        }

        if (is_array($despues[1]) && $despues[1][0] === T_OBJECT_OPERATOR) {
            $metodo = siguienteUtil($tokens, $despues[0]);

            if ($metodo !== null && is_array($metodo[1]) && $metodo[1][0] === T_STRING) {
                $cierra = siguienteUtil($tokens, $metodo[0]);

                if ($cierra !== null && $cierra[1] === '(') {
                    $sobreDependencia[] = [$identificador, $metodo[1][1]];
                }
            }
        }
    }

    return [
        'propias' => array_values(array_unique($propias)),
        'sobreDependencia' => array_values(array_unique($sobreDependencia, SORT_REGULAR)),
        'cadenas' => array_values(array_unique($cadenas, SORT_REGULAR)),
    ];
}

/** @return array{0:int, 1:mixed}|null el próximo token que no sea espacio ni comentario */
function siguienteUtil(array $tokens, int $desde): ?array
{
    for ($i = $desde + 1, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return [$i, $token];
    }

    return null;
}

/** El nombre completo de la clase que define un archivo de src/. */
function claseDeArchivo(string $codigo): ?string
{
    if (preg_match('/^namespace\s+([^;]+);/m', $codigo, $ns) !== 1) {
        return null;
    }

    if (preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $codigo, $c) !== 1) {
        return null;
    }

    $nombre = trim($ns[1]) . '\\' . $c[1];

    return class_exists($nombre) ? $nombre : null;
}

prueba('todo metodo que una clase se llama a si misma existe', function (): void {
    $revisadas = 0;
    $archivos = array_merge(
        glob(__DIR__ . '/../src/**/*.php') ?: [],
        glob(__DIR__ . '/../src/*.php') ?: []
    );

    foreach ($archivos as $archivo) {
        $codigo = (string) file_get_contents($archivo);
        $clase = claseDeArchivo($codigo);

        if ($clase === null) {
            continue;
        }

        $reflexion = new ReflectionClass($clase);
        $llamadas = llamadasDe($codigo);

        foreach ($llamadas['propias'] as $metodo) {
            $revisadas++;
            afirmar(
                $reflexion->hasMethod($metodo),
                sprintf('%s llama a $this->%s(), que no existe en esa clase', $reflexion->getShortName(), $metodo)
            );
        }
    }

    afirmar($revisadas > 50, "sólo se revisaron {$revisadas} llamadas: el analizador no está leyendo nada");
});

prueba('todo metodo que se llama sobre una dependencia existe en su tipo', function (): void {
    $revisadas = 0;
    $archivos = array_merge(
        glob(__DIR__ . '/../src/**/*.php') ?: [],
        glob(__DIR__ . '/../src/*.php') ?: []
    );

    foreach ($archivos as $archivo) {
        $codigo = (string) file_get_contents($archivo);
        $clase = claseDeArchivo($codigo);

        if ($clase === null) {
            continue;
        }

        $reflexion = new ReflectionClass($clase);

        foreach (llamadasDe($codigo)['sobreDependencia'] as [$propiedad, $metodo]) {
            afirmar(
                $reflexion->hasProperty($propiedad),
                sprintf('%s usa $this->%s, que no es una propiedad suya', $reflexion->getShortName(), $propiedad)
            );

            $tipo = $reflexion->getProperty($propiedad)->getType();

            // Sólo lo tipado con algo nuestro: un array o un tipo
            // nativo no tiene métodos que chequear. Las interfaces del
            // proyecto sí cuentan —`Clock`, `LlmProvider`— y quedaban
            // afuera porque `class_exists` devuelve false para ellas.
            if (!$tipo instanceof ReflectionNamedType || $tipo->isBuiltin()) {
                continue;
            }

            $nombreTipo = $tipo->getName();

            $existe = class_exists($nombreTipo) || interface_exists($nombreTipo);

            if (!str_starts_with($nombreTipo, 'Budget\\') || !$existe) {
                continue;
            }

            $revisadas++;
            afirmar(
                (new ReflectionClass($nombreTipo))->hasMethod($metodo),
                sprintf(
                    '%s llama a $this->%s->%s(), y %s no tiene ese método',
                    $reflexion->getShortName(),
                    $propiedad,
                    $metodo,
                    (new ReflectionClass($nombreTipo))->getShortName()
                )
            );
        }
    }

    afirmar($revisadas > 30, "sólo se revisaron {$revisadas} llamadas: el analizador no está leyendo nada");
});

prueba('todo metodo encadenado sobre una fabrica propia existe', function (): void {
    // `$this->tarjetas()->revisar()` es como el Dispatcher delega en los
    // cuatro handlers que se le extrajeron. Sin este chequeo, renombrar
    // `Tarjetas::revisar` deja lint, guards y suite en verde, y el
    // comando revienta en producción con el 200 ya contestado.
    $revisadas = 0;
    $archivos = array_merge(
        glob(__DIR__ . '/../src/**/*.php') ?: [],
        glob(__DIR__ . '/../src/*.php') ?: []
    );

    foreach ($archivos as $archivo) {
        $codigo = (string) file_get_contents($archivo);
        $clase = claseDeArchivo($codigo);

        if ($clase === null) {
            continue;
        }

        $reflexion = new ReflectionClass($clase);

        foreach (llamadasDe($codigo)['cadenas'] as [$fabrica, $metodo]) {
            if (!$reflexion->hasMethod($fabrica)) {
                continue;
            }

            $tipo = $reflexion->getMethod($fabrica)->getReturnType();

            if (!$tipo instanceof ReflectionNamedType || $tipo->isBuiltin()) {
                continue;
            }

            $devuelto = $tipo->getName();

            if (!str_starts_with($devuelto, 'Budget\\')
                || !(class_exists($devuelto) || interface_exists($devuelto))
            ) {
                continue;
            }

            $revisadas++;
            afirmar(
                (new ReflectionClass($devuelto))->hasMethod($metodo),
                sprintf(
                    '%s llama a $this->%s()->%s(), y %s no tiene ese método',
                    $reflexion->getShortName(),
                    $fabrica,
                    $metodo,
                    (new ReflectionClass($devuelto))->getShortName()
                )
            );
        }
    }

    afirmar($revisadas > 5, "sólo se revisaron {$revisadas} cadenas: el analizador no está leyendo nada");
});
