<?php

declare(strict_types=1);

namespace Budget\Support;

/**
 * Resultado de una verificación del entorno.
 *
 * Un diagnóstico que dice "algo falló" sin decir qué hacer obliga a
 * abrir el código. Por eso el remedio es parte del resultado, no algo
 * que se busca después.
 */
final class Check
{
    public const OK = 'ok';
    public const AVISO = 'aviso';
    public const FALLA = 'falla';

    private function __construct(
        public readonly string $nombre,
        public readonly string $estado,
        public readonly string $detalle,
        public readonly string $remedio,
    ) {
    }

    public static function ok(string $nombre, string $detalle = ''): self
    {
        return new self($nombre, self::OK, $detalle, '');
    }

    /** Anda, pero hay algo que conviene saber. No corta el diagnóstico. */
    public static function aviso(string $nombre, string $detalle, string $remedio = ''): self
    {
        return new self($nombre, self::AVISO, $detalle, $remedio);
    }

    /** No anda. El remedio es obligatorio: sin él el diagnóstico no sirve. */
    public static function falla(string $nombre, string $detalle, string $remedio): self
    {
        return new self($nombre, self::FALLA, $detalle, $remedio);
    }

    public function fallo(): bool
    {
        return $this->estado === self::FALLA;
    }

    public function icono(): string
    {
        return match ($this->estado) {
            self::OK => 'OK  ',
            self::AVISO => 'AVISO',
            default => 'FALLA',
        };
    }
}
