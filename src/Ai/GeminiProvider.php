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
    private const MODELO_POR_DEFECTO = 'gemini-2.5-flash';

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

    public function disponible(): bool
    {
        return $this->apiKey !== '';
    }

    public function soporta(string $tarea): bool
    {
        return in_array($tarea, [self::TAREA_TEXTO, self::TAREA_IMAGEN, self::TAREA_AUDIO], true);
    }

    public function extraerDeTexto(string $texto): ?Extraction
    {
        return $this->generar([
            ['text' => Prompt::paraTexto($texto, $this->hoy())],
        ]);
    }

    public function extraerDeImagen(string $binario, string $mimeType, string $epigrafe = ''): ?Extraction
    {
        return $this->generar([
            ['text' => Prompt::paraImagen($epigrafe, $this->hoy())],
            ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($binario)]],
        ]);
    }

    public function extraerDeAudio(string $binario, string $mimeType): ?Extraction
    {
        return $this->generar([
            ['text' => Prompt::paraAudio($this->hoy())],
            ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode($binario)]],
        ]);
    }

    /** @param list<array<string,mixed>> $partes */
    private function generar(array $partes): ?Extraction
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
            return null;
        }

        $datos = json_decode($json, true);

        if (!is_array($datos)) {
            throw new RuntimeException('Gemini devolvió algo que no es JSON');
        }

        return Extraction::desdeJson($datos, $this->nombre(), $this->modelo);
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
