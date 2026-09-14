<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Expense\Draft;
use Budget\Telegram\Keyboard;

/**
 * La tarjeta de confirmación: lo único que el usuario ve de todo el
 * pipeline de extracción.
 *
 * El bot nunca guarda a ciegas. Propone, y el usuario confirma con un
 * tap. Cuando la confianza es baja, el texto lo dice en vez de disimular.
 */
final class ExpenseCard
{
    public const ACCION_CONFIRMAR = 'ok';
    public const ACCION_DESCARTAR = 'no';
    public const ACCION_ELEGIR_CATEGORIA = 'cat';
    public const ACCION_FIJAR_CATEGORIA = 'set';

    private const CATEGORIAS_POR_FILA = 2;

    public static function texto(Draft $borrador, string $emoji = ''): string
    {
        $icono = $emoji !== '' ? $emoji . ' ' : '🧾 ';

        $lineas = [
            $icono . '<b>' . self::escapar($borrador->titulo()) . '</b>',
            '<b>' . self::escapar($borrador->monto->formatear()) . '</b>',
            '',
            '📅 ' . $borrador->fecha->format('d/m/Y'),
        ];

        $lineas[] = '📁 ' . self::escapar($borrador->categoria ?? 'Sin categoría');

        if ($borrador->medioPago !== '') {
            $lineas[] = '💳 ' . self::escapar($borrador->medioPago);
        }

        if ($borrador->necesitaConfirmacionExplicita()) {
            $lineas[] = '';
            $lineas[] = '<i>No estoy seguro de esto. Revisalo antes de guardar.</i>';
        }

        return implode("\n", $lineas);
    }

    public static function teclado(int $expenseId): Keyboard
    {
        return Keyboard::nueva()
            ->fila([
                '✓ Guardar' => self::ACCION_CONFIRMAR . ':' . $expenseId,
                '📁 Categoría' => self::ACCION_ELEGIR_CATEGORIA . ':' . $expenseId,
            ])
            ->fila([
                '✕ Descartar' => self::ACCION_DESCARTAR . ':' . $expenseId,
            ]);
    }

    /** @param list<array{id:int, nombre:string, emoji:string}> $categorias */
    public static function tecladoDeCategorias(int $expenseId, array $categorias): Keyboard
    {
        $teclado = Keyboard::nueva();
        $fila = [];

        foreach ($categorias as $categoria) {
            $etiqueta = trim($categoria['emoji'] . ' ' . $categoria['nombre']);
            $fila[$etiqueta] = self::ACCION_FIJAR_CATEGORIA . ':' . $expenseId . ':' . $categoria['id'];

            if (count($fila) === self::CATEGORIAS_POR_FILA) {
                $teclado = $teclado->fila($fila);
                $fila = [];
            }
        }

        if ($fila !== []) {
            $teclado = $teclado->fila($fila);
        }

        return $teclado;
    }

    /**
     * Decodifica el callback_data. Devuelve null ante cualquier cosa
     * inesperada: el payload viene de afuera y no se confía.
     *
     * @return array{accion:string, expenseId:int, valor:int}|null
     */
    public static function decodificar(string $datos): ?array
    {
        $partes = explode(':', $datos);

        if (count($partes) < 2) {
            return null;
        }

        $expenseId = filter_var($partes[1], FILTER_VALIDATE_INT);

        if ($expenseId === false || $expenseId <= 0) {
            return null;
        }

        $valor = 0;

        if (isset($partes[2])) {
            $crudo = filter_var($partes[2], FILTER_VALIDATE_INT);
            $valor = $crudo === false ? 0 : $crudo;
        }

        return [
            'accion' => $partes[0],
            'expenseId' => $expenseId,
            'valor' => $valor,
        ];
    }

    public static function escapar(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
