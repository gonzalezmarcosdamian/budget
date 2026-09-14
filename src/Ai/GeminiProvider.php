<?php

declare(strict_types=1);

namespace Budget\Ai;

use Budget\Support\Clock;
use Budget\Support\Http;
use RuntimeException;

/**
 * Google Gemini: el único proveedor que cubre las tres tareas (texto,
 * imagen y audio) con una sola clave y capa gratuita.
 *
 * Acepta el OGG/Opus que manda Telegram sin transcodificar, que es
 * decisivo: en hosting compartido no hay ffmpeg.
 */
final class GeminiProvider implements LlmProvider
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models';
    // gemini-2.5-flash ya no se habilita a cuentas nuevas (404). Los
    // flash grandes devuelven 503 seguido en capa gratuita; el lite
    // responde de forma consistente, medido el 14/09/2026.
    private const MODELO_POR_DEFECTO = 'gemini-3.5-flash-lite';

    /** Un resumen mensual rara vez pasa los doscientos consumos. */
    private const MAXIMO_EN_UN_RESUMEN = 200;

    public function __construct(
        private readonly string $apiKey,
        private readonly Http $http,
        private readonly Clock $reloj,
        private readonly string $modelo = self::MODELO_POR_DEFECTO,
    ) {
    }

    public function nombre(): string
    {
        return 'gemini';
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    public function disponible(): bool
    {
        return $this->apiKey !== '';
    }

    public function soporta(string $tarea): bool
    {
        return in_array(
            $tarea,
            [self::TAREA_TEXTO, self::TAREA_IMAGEN, self::TAREA_AUDIO, self::TAREA_DOCUMENTO],
            true
        );
    }

    /** @return list<Extraction> */
    public function extraerDeTexto(string $texto): array
    {
        return $this->generar([
            ['text' => Prompt::paraTexto($texto, $this->hoy())],
        ]);
    }

    /** @return list<Extraction> */
    public function extraerDeImagen(string $binario, string $mimeType, string $epigrafe = ''): array
    {
        return $this->generar([
            ['text' => Prompt::paraImagen($epigrafe, $this->hoy())],
            ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($binario)]],
        ]);
    }

    /** @return list<Extraction> */
    public function extraerDeResumen(string $binario, string $mimeType): array
    {
        // Gemini lee PDFs de forma nativa: no hace falta ninguna librería
        // de parseo, que es lo que permite sostener la regla de cero
        // dependencias de runtime.
        return $this->generar([
            ['text' => Prompt::paraResumen($this->hoy())],
            ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($binario)]],
        ], self::MAXIMO_EN_UN_RESUMEN);
    }

    /** @return list<Extraction> */
    public function extraerDeAudio(string $binario, string $mimeType): array
    {
        return $this->generar([
            ['text' => Prompt::paraAudio($this->hoy())],
            ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($binario)]],
        ]);
    }

    /**
     * @param list<array<string,mixed>> $partes
     * @return list<Extraction>
     */
    private function generar(array $partes, ?int $maximo = null): array
    {
        if (!$this->disponible()) {
            throw new RuntimeException('Falta GEMINI_API_KEY');
        }

        $url = self::BASE . '/' . $this->modelo . ':generateContent?key=' . urlencode($this->apiKey);

        $respuesta = $this->http->postJson($url, [
            'contents' => [['parts' => $partes]],
            'generationConfig' => [
                // Temperatura cero: extraer un ticket no es una tarea
                // creativa, y queremos el mismo resultado entre corridas.
                'temperature' => 0,
                'response_mime_type' => 'application/json',
            ],
        ]);

        $json = self::primerTexto($respuesta);

        if ($json === null) {
            return [];
        }

        $datos = json_decode($json, true);

        if (!is_array($datos)) {
            throw new RuntimeException('Gemini devolvió algo que no es JSON');
        }

        return Extraction::variasDesdeJson($datos, $this->nombre(), $this->modelo, maximo: $maximo);
    }

    /** @param array<string,mixed> $respuesta */
    private static function primerTexto(array $respuesta): ?string
    {
        $candidatos = $respuesta['candidates'] ?? null;

        if (!is_array($candidatos) || $candidatos === []) {
            return null;
        }

        $partes = $candidatos[0]['content']['parts'] ?? null;

        if (!is_array($partes)) {
            return null;
        }

        foreach ($partes as $parte) {
            if (is_array($parte) && isset($parte['text']) && is_string($parte['text'])) {
                return $parte['text'];
            }
        }

        return null;
    }

    private function hoy(): string
    {
        return $this->reloj->ahora()->format('Y-m-d');
    }
}
