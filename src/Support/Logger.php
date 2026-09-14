<?php

declare(strict_types=1);

namespace Budget\Support;

use Throwable;

/**
 * Log a archivo, append-only. Un cron lo rota; acá no hace falta más.
 *
 * Nunca registra el contenido de los mensajes: son datos financieros de
 * personas y el log no es el lugar donde tienen que estar.
 */
final class Logger
{
    public function __construct(private readonly string $ruta)
    {
    }

    /** @param array<string,scalar|null> $contexto */
    public function info(string $mensaje, array $contexto = []): void
    {
        $this->escribir('INFO', $mensaje, $contexto);
    }

    /** @param array<string,scalar|null> $contexto */
    public function advertencia(string $mensaje, array $contexto = []): void
    {
        $this->escribir('WARN', $mensaje, $contexto);
    }

    public function excepcion(Throwable $e, string $donde): void
    {
        $this->escribir('ERROR', $donde, [
            'clase' => $e::class,
            'mensaje' => $e->getMessage(),
            'archivo' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
    }

    /** @param array<string,scalar|null> $contexto */
    private function escribir(string $nivel, string $mensaje, array $contexto): void
    {
        $partes = [];

        foreach ($contexto as $clave => $valor) {
            $partes[] = $clave . '=' . var_export($valor, true);
        }

        $linea = sprintf(
            "[%s] %s %s %s\n",
            date('Y-m-d H:i:s'),
            $nivel,
            $mensaje,
            implode(' ', $partes)
        );

        @file_put_contents($this->ruta, $linea, FILE_APPEND | LOCK_EX);
    }
}
