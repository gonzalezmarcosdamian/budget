<?php

declare(strict_types=1);

namespace Budget\Telegram;

/**
 * Constructor de teclados inline.
 *
 * El callback_data de Telegram admite 64 bytes como máximo, así que se
 * codifica corto: "ok:123" y no JSON.
 */
final class Keyboard
{
    private const LIMITE_CALLBACK_BYTES = 64;

    /** @var list<list<array{text:string, callback_data:string}>> */
    private array $filas = [];

    public static function nueva(): self
    {
        return new self();
    }

    /** @param array<string,string> $botones etiqueta => callback_data */
    public function fila(array $botones): self
    {
        $copia = clone $this;
        $fila = [];

        foreach ($botones as $etiqueta => $datos) {
            $fila[] = [
                'text' => $etiqueta,
                'callback_data' => self::recortar($datos),
            ];
        }

        if ($fila !== []) {
            $copia->filas[] = $fila;
        }

        return $copia;
    }

    public function vacio(): bool
    {
        return $this->filas === [];
    }

    /** @return array{inline_keyboard: list<list<array{text:string, callback_data:string}>>} */
    public function aArray(): array
    {
        return ['inline_keyboard' => $this->filas];
    }

    private static function recortar(string $datos): string
    {
        return strlen($datos) <= self::LIMITE_CALLBACK_BYTES
            ? $datos
            : substr($datos, 0, self::LIMITE_CALLBACK_BYTES);
    }
}
