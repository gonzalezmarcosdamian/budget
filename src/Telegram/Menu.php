<?php

declare(strict_types=1);

namespace Budget\Telegram;

/**
 * El menú de comandos que Telegram muestra al tocar "/".
 *
 * Existe porque el menú se había registrado a mano una sola vez y quedó
 * congelado: el bot aprendió a contestar preguntas, a leer resúmenes y a
 * recordar gastos fijos, y nada de eso aparecía en ningún lado. Una
 * función que nadie descubre es una función que no existe.
 *
 * Esta es la fuente única: de acá sale lo que se publica en Telegram y
 * también el texto de /ayuda, así no pueden volver a divergir.
 */
final class Menu
{
    /**
     * Comando (sin barra) => descripción.
     *
     * Telegram sólo acepta [a-z0-9_] y corta la descripción en 256
     * caracteres, así que acá no van tildes ni eñes.
     *
     * @var array<string,string>
     */
    public const COMANDOS = [
        'hoy' => 'Lo del día, contra tu promedio',
        'mes' => 'El mes: fijo vs variable, proyección y transferencias',
        'anio' => 'Mes a mes del año',
        'ingresos' => 'Lo que entró contra lo que salió',
        'ultimos' => 'Los últimos 10 movimientos',
        'recurrentes' => 'Los gastos que se repiten todos los meses',
        'ayuda' => 'Todo lo que sé hacer',
    ];

    /** El payload de setMyCommands. */
    public static function comandos(): string
    {
        $lista = [];

        foreach (self::COMANDOS as $comando => $descripcion) {
            $lista[] = ['command' => $comando, 'description' => $descripcion];
        }

        // Sin JSON_THROW_ON_ERROR, un fallo devolvería '[]' y Telegram
        // contestaría ok: publicar un menú vacío es el peor resultado
        // posible para el único script que existe para poblarlo.
        return json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Las mismas líneas, para el cuerpo de /ayuda.
     *
     * @return list<string>
     */
    public static function lineasDeAyuda(): array
    {
        $lineas = [];

        foreach (self::COMANDOS as $comando => $descripcion) {
            $lineas[] = '/' . $comando . ' — ' . $descripcion;
        }

        return $lineas;
    }
}
