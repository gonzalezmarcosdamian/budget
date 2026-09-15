<?php

declare(strict_types=1);

use Budget\Telegram\Menu;

/**
 * El menú se había registrado a mano una sola vez y quedó congelado
 * mientras el bot seguía aprendiendo cosas. Estos tests son el candado:
 * si un comando existe en el código, tiene que estar en el menú.
 */

prueba('los comandos cumplen lo que Telegram acepta', function (): void {
    foreach (Menu::COMANDOS as $comando => $descripcion) {
        afirmar(
            preg_match('/^[a-z0-9_]{1,32}$/', $comando) === 1,
            "Telegram sólo acepta [a-z0-9_] de hasta 32: '{$comando}'"
        );
        afirmar($descripcion !== '', "'{$comando}' sin descripción");
        afirmar(
            mb_strlen($descripcion) <= 256,
            "la descripción de '{$comando}' pasa los 256 caracteres"
        );
    }
});

prueba('el payload de setMyCommands tiene la forma que espera la API', function (): void {
    $lista = json_decode(Menu::comandos(), true);

    afirmar(is_array($lista), 'es un JSON válido');
    esIgual(count(Menu::COMANDOS), count($lista), 'van todos');

    foreach ($lista as $item) {
        afirmar(isset($item['command'], $item['description']), 'cada item lleva command y description');
    }
});

prueba('el menú y los comandos que el bot atiende son el mismo conjunto', function (): void {
    // Esta es la prueba que importa: el desajuste entre lo que el bot
    // sabe hacer y lo que muestra fue el bug original. Se compara el
    // conjunto entero y no comando por comando, porque iterar lo
    // encontrado pasa en silencio si no se encuentra nada.
    $codigo = file_get_contents(__DIR__ . '/../src/Handler/Dispatcher.php');
    afirmar($codigo !== false, 'se puede leer el Dispatcher');

    preg_match('/match \(\$update->comando\(\)\) \{(.+?)\n        \};/s', $codigo, $m);
    afirmar(isset($m[1]), 'se encuentra el match de comandos del Dispatcher');

    preg_match_all("/'\/([a-zA-ZñÑ0-9_]+)'/u", $m[1], $encontrados);

    // /start es la puerta de entrada, no un comando del menú; los alias
    // con eñe no los acepta Telegram y por eso no se publican.
    $exentos = ['start', 'año'];

    $detectados = array_values(array_diff(array_unique($encontrados[1]), $exentos));
    $esperados = array_keys(Menu::COMANDOS);
    sort($detectados);
    sort($esperados);

    esIgual(
        $esperados,
        $detectados,
        'lo que el bot atiende y lo que el menú publica tienen que coincidir'
    );
});
