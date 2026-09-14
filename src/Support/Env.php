<?php

declare(strict_types=1);

namespace Budget\Support;

use RuntimeException;

/**
 * Lector de configuración sin dependencias.
 *
 * Combina el archivo .env con las variables reales del proceso, y las
 * reales ganan. Eso es lo que permite que el mismo código corra en el
 * hosting (con .env), en Docker y en CI (con variables inyectadas y sin
 * ningún archivo de secretos dando vueltas).
 *
 * El .env vive fuera del document root: si queda accesible por HTTP se
 * regalan el token del bot y las claves de IA.
 */
final class Env
{
    /** @param array<string,string> $valores */
    private function __construct(private readonly array $valores)
    {
    }

    /**
     * @param list<string> $clavesDelEntorno claves que se leen del proceso
     */
    public static function cargar(?string $ruta = null, array $clavesDelEntorno = []): self
    {
        $delArchivo = $ruta !== null && is_readable($ruta) ? self::leerArchivo($ruta) : [];
        $delProceso = self::leerProceso($clavesDelEntorno === [] ? array_keys($delArchivo) : $clavesDelEntorno);

        return new self(array_merge($delArchivo, $delProceso));
    }

    public static function desdeArchivo(string $ruta): self
    {
        if (!is_readable($ruta)) {
            throw new RuntimeException("No se puede leer el archivo de entorno: {$ruta}");
        }

        return self::cargar($ruta);
    }

    /** @param array<string,string> $valores */
    public static function desdeArray(array $valores): self
    {
        return new self($valores);
    }

    public function texto(string $clave, string $porDefecto = ''): string
    {
        $valor = $this->valores[$clave] ?? '';

        return $valor === '' ? $porDefecto : $valor;
    }

    public function entero(string $clave, int $porDefecto = 0): int
    {
        $crudo = $this->valores[$clave] ?? '';

        return $crudo === '' ? $porDefecto : (int) $crudo;
    }

    public function booleano(string $clave, bool $porDefecto = false): bool
    {
        return match (strtolower($this->valores[$clave] ?? '')) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $porDefecto,
        };
    }

    /** Falla al arrancar y no tres capas más abajo, en medio de un webhook. */
    public function requerido(string $clave): string
    {
        $valor = $this->valores[$clave] ?? '';

        if ($valor === '') {
            throw new RuntimeException("Falta la variable de entorno obligatoria: {$clave}");
        }

        return $valor;
    }

    /** @return array<string,string> */
    private static function leerArchivo(string $ruta): array
    {
        $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lineas === false) {
            throw new RuntimeException("Falló la lectura de: {$ruta}");
        }

        return self::parsear($lineas);
    }

    /**
     * @param list<string> $claves
     * @return array<string,string>
     */
    private static function leerProceso(array $claves): array
    {
        $valores = [];

        foreach (array_unique(array_merge($claves, self::CLAVES_CONOCIDAS)) as $clave) {
            $crudo = $_SERVER[$clave] ?? $_ENV[$clave] ?? getenv($clave);

            if (is_string($crudo) && $crudo !== '') {
                $valores[$clave] = $crudo;
            }
        }

        return $valores;
    }

    /**
     * Sin esta lista, un entorno sin .env (CI, Docker) no encontraría
     * nada, porque no habría claves de archivo de las cuales partir.
     */
    private const CLAVES_CONOCIDAS = [
        'TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET',
        'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS',
        'APP_ENV', 'APP_DEBUG', 'APP_TIMEZONE', 'APP_CURRENCY',
        'INVITE_CODE', 'OWNER_CHAT_ID',
        'GEMINI_API_KEY', 'GEMINI_MODELS', 'GROQ_API_KEY', 'OPENROUTER_API_KEY',
        'AI_MONTHLY_QUOTA',
    ];

    /**
     * @param list<string> $lineas
     * @return array<string,string>
     */
    private static function parsear(array $lineas): array
    {
        $valores = [];

        foreach ($lineas as $linea) {
            $linea = trim($linea);

            if ($linea === '' || str_starts_with($linea, '#')) {
                continue;
            }

            $partes = explode('=', $linea, 2);

            if (count($partes) !== 2) {
                continue;
            }

            $valores[trim($partes[0])] = self::sinComillas(trim($partes[1]));
        }

        return $valores;
    }

    private static function sinComillas(string $valor): string
    {
        if (strlen($valor) < 2) {
            return $valor;
        }

        $primero = $valor[0];
        $ultimo = $valor[strlen($valor) - 1];

        $entrecomillado = ($primero === '"' && $ultimo === '"')
            || ($primero === "'" && $ultimo === "'");

        return $entrecomillado ? substr($valor, 1, -1) : $valor;
    }
}
