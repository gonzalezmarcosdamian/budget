<?php

declare(strict_types=1);

/**
 * Runner de tests de ~80 líneas, sin dependencias.
 *
 * Existe porque el proyecto no usa Composer: los tests corren igual en
 * esta máquina, en CI y en el hosting, con `php tests/run.php`.
 */

final class Registro
{
    /** @var list<array{nombre:string, fn:callable}> */
    public static array $pruebas = [];

    /** @var list<string> */
    public static array $fallas = [];

    public static int $afirmaciones = 0;
}

function prueba(string $nombre, callable $fn): void
{
    Registro::$pruebas[] = ['nombre' => $nombre, 'fn' => $fn];
}

final class FallaDeAfirmacion extends RuntimeException
{
}

function afirmar(bool $condicion, string $mensaje): void
{
    Registro::$afirmaciones++;

    if (!$condicion) {
        throw new FallaDeAfirmacion($mensaje);
    }
}

function esIgual(mixed $esperado, mixed $real, string $contexto = ''): void
{
    $sufijo = $contexto === '' ? '' : " ({$contexto})";

    afirmar(
        $esperado === $real,
        sprintf('esperaba %s, llegó %s%s', representar($esperado), representar($real), $sufijo)
    );
}

function contiene(string $texto, string $fragmento, string $contexto = ''): void
{
    $sufijo = $contexto === '' ? '' : " ({$contexto})";

    afirmar(
        str_contains($texto, $fragmento),
        sprintf('esperaba encontrar %s en:%s%s%s', representar($fragmento), PHP_EOL, $texto, $sufijo)
    );
}

function esNulo(mixed $real, string $contexto = ''): void
{
    $sufijo = $contexto === '' ? '' : " ({$contexto})";

    afirmar($real === null, sprintf('esperaba null, llegó %s%s', representar($real), $sufijo));
}

function noEsNulo(mixed $real, string $contexto = ''): void
{
    $sufijo = $contexto === '' ? '' : " ({$contexto})";

    afirmar($real !== null, "esperaba un valor, llegó null{$sufijo}");
}

function lanza(string $claseEsperada, callable $fn, string $contexto = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        afirmar(
            $e instanceof $claseEsperada,
            sprintf('esperaba %s, llegó %s (%s)', $claseEsperada, $e::class, $contexto)
        );

        return;
    }

    afirmar(false, "esperaba que lanzara {$claseEsperada}, no lanzó nada ({$contexto})");
}

function representar(mixed $valor): string
{
    return match (true) {
        is_string($valor) => '"' . $valor . '"',
        is_bool($valor) => $valor ? 'true' : 'false',
        $valor === null => 'null',
        is_scalar($valor) => (string) $valor,
        default => get_debug_type($valor),
    };
}

function correrPruebas(): int
{
    $inicio = microtime(true);
    $pasadas = 0;

    foreach (Registro::$pruebas as $prueba) {
        try {
            ($prueba['fn'])();
            $pasadas++;
            echo '.';
        } catch (Throwable $e) {
            Registro::$fallas[] = sprintf("%s\n    %s", $prueba['nombre'], $e->getMessage());
            echo 'F';
        }
    }

    $ms = (int) round((microtime(true) - $inicio) * 1000);
    echo "\n\n";

    foreach (Registro::$fallas as $i => $falla) {
        printf("  %d) %s\n\n", $i + 1, $falla);
    }

    printf(
        "%d pruebas, %d afirmaciones, %d fallas — %d ms\n",
        count(Registro::$pruebas),
        Registro::$afirmaciones,
        count(Registro::$fallas),
        $ms
    );

    return Registro::$fallas === [] ? 0 : 1;
}
