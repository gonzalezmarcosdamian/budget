<?php

declare(strict_types=1);

namespace Budget\Support;

use RuntimeException;

/**
 * Cliente HTTP mínimo sobre cURL, compartido por los proveedores de IA.
 *
 * No se trae Guzzle porque el proyecto no tiene dependencias de runtime:
 * en hosting compartido, cada archivo que no hay que subir es una cosa
 * menos que puede fallar en el despliegue.
 */
final class Http
{
    public function __construct(private readonly int $timeoutSegundos = 30)
    {
    }

    /**
     * @param list<string> $cabeceras
     * @return array<string,mixed>
     */
    public function getJson(string $url, array $cabeceras = []): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('No se pudo inicializar cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSegundos,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $cabeceras),
        ]);

        $respuesta = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false) {
            throw new RuntimeException("Error de red: {$error}");
        }

        if ($codigo >= 400) {
            throw new RuntimeException("El proveedor respondió {$codigo}: " . substr((string) $respuesta, 0, 200));
        }

        $decodificado = json_decode((string) $respuesta, true);

        if (!is_array($decodificado)) {
            throw new RuntimeException('Respuesta que no es JSON');
        }

        return $decodificado;
    }

    /**
     * @param array<string,mixed> $cuerpo
     * @param list<string> $cabeceras
     * @return array<string,mixed>
     */
    public function postJson(string $url, array $cuerpo, array $cabeceras = []): array
    {
        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('No se pudo serializar el cuerpo de la petición');
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('No se pudo inicializar cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => $this->timeoutSegundos,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $cabeceras),
        ]);

        $respuesta = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false) {
            throw new RuntimeException("Error de red: {$error}");
        }

        if ($codigo >= 400) {
            throw new RuntimeException("El proveedor respondió {$codigo}: " . substr((string) $respuesta, 0, 200));
        }

        $decodificado = json_decode((string) $respuesta, true);

        if (!is_array($decodificado)) {
            throw new RuntimeException('Respuesta que no es JSON');
        }

        return $decodificado;
    }
}
