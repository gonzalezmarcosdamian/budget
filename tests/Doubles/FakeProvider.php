<?php

declare(strict_types=1);

namespace Budget\Tests\Doubles;

use Budget\Ai\Extraction;
use Budget\Ai\LlmProvider;
use RuntimeException;

/**
 * Proveedor de mentira para probar la cadena de respaldo del Router.
 *
 * Simular el 503 "high demand" que devuelve la capa gratuita es la única
 * forma de verificar que el bot degrada en vez de romperse, sin depender
 * de que un proveedor real se caiga justo durante el test.
 */
final class FakeProvider implements LlmProvider
{
    public int $llamadas = 0;

    private function __construct(
        private readonly string $nombre,
        private readonly string $modelo,
        private readonly bool $disponible,
        private readonly ?string $errorQueLanza,
        private readonly ?float $montoQueDevuelve,
    ) {
    }

    public static function queFunciona(string $modelo, float $monto = 1000.0): self
    {
        return new self('falso', $modelo, true, null, $monto);
    }

    public static function queFalla(string $modelo, string $error = 'El proveedor respondió 503'): self
    {
        return new self('falso', $modelo, true, $error, null);
    }

    public static function sinClave(string $modelo): self
    {
        return new self('falso', $modelo, false, null, null);
    }

    /** Responde bien pero no encuentra ningún gasto: no es una falla. */
    public static function queNoEncuentraNada(string $modelo): self
    {
        return new self('falso', $modelo, true, null, null);
    }

    public function nombre(): string
    {
        return $this->nombre;
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    public function disponible(): bool
    {
        return $this->disponible;
    }

    public function soporta(string $tarea): bool
    {
        return true;
    }

    public function extraerDeTexto(string $texto): ?Extraction
    {
        return $this->responder();
    }

    public function extraerDeImagen(string $binario, string $mimeType, string $epigrafe = ''): ?Extraction
    {
        return $this->responder();
    }

    public function extraerDeAudio(string $binario, string $mimeType): ?Extraction
    {
        return $this->responder();
    }

    private function responder(): ?Extraction
    {
        $this->llamadas++;

        if ($this->errorQueLanza !== null) {
            throw new RuntimeException($this->errorQueLanza);
        }

        if ($this->montoQueDevuelve === null) {
            return null;
        }

        return Extraction::desdeJson(
            [
                'monto' => $this->montoQueDevuelve,
                'comercio' => 'Comercio de prueba',
                'categoria' => 'Otros',
                'confianza' => 0.9,
            ],
            $this->nombre,
            $this->modelo
        );
    }
}
