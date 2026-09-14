<?php

declare(strict_types=1);

namespace Budget\Ai;

use Budget\Support\Logger;
use PDO;
use Throwable;

/**
 * Elige proveedor por tarea y baja al siguiente cuando uno falla.
 *
 * Que un proveedor agote su cuota o se caiga no puede ser un error
 * visible: el usuario mandó una foto de un ticket y espera un gasto.
 * Cada intento queda registrado en ai_calls, que es de donde sale el
 * dato de qué modelo acierta más y cuánto consume cada usuario.
 */
final class Router
{
    /** @var list<LlmProvider> */
    private readonly array $cadena;

    /** @param list<LlmProvider> $proveedores en orden de preferencia */
    public function __construct(
        array $proveedores,
        private readonly PDO $pdo,
        private readonly Logger $log,
    ) {
        $this->cadena = array_values(array_filter(
            $proveedores,
            static fn (LlmProvider $p): bool => $p->disponible()
        ));
    }

    public function hayProveedores(): bool
    {
        return $this->cadena !== [];
    }

    public function texto(int $userId, string $mensaje): ?Extraction
    {
        return $this->intentar(
            $userId,
            LlmProvider::TAREA_TEXTO,
            static fn (LlmProvider $p): ?Extraction => $p->extraerDeTexto($mensaje)
        );
    }

    public function imagen(int $userId, string $binario, string $mimeType, string $epigrafe = ''): ?Extraction
    {
        return $this->intentar(
            $userId,
            LlmProvider::TAREA_IMAGEN,
            static fn (LlmProvider $p): ?Extraction => $p->extraerDeImagen($binario, $mimeType, $epigrafe)
        );
    }

    public function audio(int $userId, string $binario, string $mimeType): ?Extraction
    {
        return $this->intentar(
            $userId,
            LlmProvider::TAREA_AUDIO,
            static fn (LlmProvider $p): ?Extraction => $p->extraerDeAudio($binario, $mimeType)
        );
    }

    /** @param callable(LlmProvider): ?Extraction $operacion */
    private function intentar(int $userId, string $tarea, callable $operacion): ?Extraction
    {
        foreach ($this->cadena as $proveedor) {
            if (!$proveedor->soporta($tarea)) {
                continue;
            }

            $inicio = microtime(true);

            try {
                $resultado = $operacion($proveedor);
                $this->registrar($userId, $proveedor, $tarea, $inicio, true, '');

                // Que el modelo no encuentre un gasto es una respuesta
                // válida, no una falla: no se reintenta con el siguiente.
                return $resultado;
            } catch (Throwable $e) {
                $this->registrar($userId, $proveedor, $tarea, $inicio, false, $e->getMessage());
                $this->log->advertencia('proveedor de IA falló, bajando al siguiente', [
                    'proveedor' => $proveedor->nombre(),
                    'tarea' => $tarea,
                ]);
            }
        }

        return null;
    }

    private function registrar(
        int $userId,
        LlmProvider $proveedor,
        string $tarea,
        float $inicio,
        bool $exito,
        string $error,
    ): void {
        try {
            $sentencia = $this->pdo->prepare(
                'INSERT INTO ai_calls (user_id, proveedor, modelo, tarea, ms, exito, error)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $sentencia->execute([
                $userId,
                $proveedor->nombre(),
                '',
                $tarea,
                (int) round((microtime(true) - $inicio) * 1000),
                $exito ? 1 : 0,
                mb_substr($error, 0, 255),
            ]);
        } catch (Throwable $e) {
            // La métrica no puede tumbar el gasto del usuario.
            $this->log->excepcion($e, 'registro de ai_calls');
        }
    }
}
