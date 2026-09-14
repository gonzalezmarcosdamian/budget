<?php

declare(strict_types=1);

namespace Budget\Telegram;

/**
 * Un update de Telegram, reducido a lo que el bot necesita.
 *
 * Todo lo que llega es dato externo no confiable: se lee con acceso
 * defensivo y se normaliza acá, una sola vez, en vez de repartir
 * isset() por todos los handlers.
 */
final class Update
{
    public const TIPO_TEXTO = 'texto';
    public const TIPO_FOTO = 'foto';
    public const TIPO_VOZ = 'voz';
    public const TIPO_DOCUMENTO = 'documento';
    public const TIPO_CALLBACK = 'callback';
    public const TIPO_IGNORADO = 'ignorado';

    private function __construct(
        public readonly int $updateId,
        public readonly string $tipo,
        public readonly int $chatId,
        public readonly int $messageId,
        public readonly string $nombre,
        public readonly string $texto,
        public readonly string $fileId,
        public readonly string $mimeType,
        public readonly string $callbackData,
        public readonly string $callbackQueryId,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public static function desdeArray(array $payload): ?self
    {
        $updateId = isset($payload['update_id']) ? (int) $payload['update_id'] : 0;

        if ($updateId === 0) {
            return null;
        }

        if (isset($payload['callback_query']) && is_array($payload['callback_query'])) {
            return self::desdeCallback($updateId, $payload['callback_query']);
        }

        $mensaje = $payload['message'] ?? $payload['edited_message'] ?? null;

        if (!is_array($mensaje)) {
            return null;
        }

        return self::desdeMensaje($updateId, $mensaje);
    }

    public function esComando(): bool
    {
        return $this->tipo === self::TIPO_TEXTO && str_starts_with($this->texto, '/');
    }

    public function comando(): string
    {
        if (!$this->esComando()) {
            return '';
        }

        $primera = explode(' ', $this->texto, 2)[0];

        // /gastos@MiBot -> /gastos
        return strtolower(explode('@', $primera, 2)[0]);
    }

    public function argumentos(): string
    {
        $partes = explode(' ', $this->texto, 2);

        return trim($partes[1] ?? '');
    }

    /** @param array<string,mixed> $callback */
    private static function desdeCallback(int $updateId, array $callback): self
    {
        $mensaje = is_array($callback['message'] ?? null) ? $callback['message'] : [];
        $chat = is_array($mensaje['chat'] ?? null) ? $mensaje['chat'] : [];
        $desde = is_array($callback['from'] ?? null) ? $callback['from'] : [];

        return new self(
            updateId: $updateId,
            tipo: self::TIPO_CALLBACK,
            chatId: (int) ($chat['id'] ?? 0),
            messageId: (int) ($mensaje['message_id'] ?? 0),
            nombre: self::nombreDe($desde),
            texto: '',
            fileId: '',
            mimeType: '',
            callbackData: (string) ($callback['data'] ?? ''),
            callbackQueryId: (string) ($callback['id'] ?? ''),
        );
    }

    /** @param array<string,mixed> $mensaje */
    private static function desdeMensaje(int $updateId, array $mensaje): self
    {
        $chat = is_array($mensaje['chat'] ?? null) ? $mensaje['chat'] : [];
        $desde = is_array($mensaje['from'] ?? null) ? $mensaje['from'] : [];

        [$tipo, $fileId, $texto, $mimeType] = self::clasificar($mensaje);

        return new self(
            updateId: $updateId,
            tipo: $tipo,
            chatId: (int) ($chat['id'] ?? 0),
            messageId: (int) ($mensaje['message_id'] ?? 0),
            nombre: self::nombreDe($desde),
            texto: $texto,
            fileId: $fileId,
            mimeType: $mimeType,
            callbackData: '',
            callbackQueryId: '',
        );
    }

    /**
     * @param array<string,mixed> $mensaje
     * @return array{0:string, 1:string, 2:string, 3:string}
     */
    private static function clasificar(array $mensaje): array
    {
        if (isset($mensaje['text']) && is_string($mensaje['text'])) {
            return [self::TIPO_TEXTO, '', trim($mensaje['text']), ''];
        }

        // Telegram manda varias resoluciones; la última es la más grande.
        if (isset($mensaje['photo']) && is_array($mensaje['photo']) && $mensaje['photo'] !== []) {
            $mayor = end($mensaje['photo']);
            $fileId = is_array($mayor) ? (string) ($mayor['file_id'] ?? '') : '';

            return [self::TIPO_FOTO, $fileId, self::epigrafe($mensaje), 'image/jpeg'];
        }

        foreach (['voice', 'audio', 'video_note'] as $clave) {
            if (isset($mensaje[$clave]) && is_array($mensaje[$clave])) {
                $medio = $mensaje[$clave];

                return [
                    self::TIPO_VOZ,
                    (string) ($medio['file_id'] ?? ''),
                    self::epigrafe($mensaje),
                    (string) ($medio['mime_type'] ?? 'audio/ogg'),
                ];
            }
        }

        // Un PDF adjunto suele ser el resumen de la tarjeta: ahí está el
        // mes entero de consumos, no un gasto suelto.
        if (isset($mensaje['document']) && is_array($mensaje['document'])) {
            $doc = $mensaje['document'];

            return [
                self::TIPO_DOCUMENTO,
                (string) ($doc['file_id'] ?? ''),
                self::epigrafe($mensaje),
                (string) ($doc['mime_type'] ?? ''),
            ];
        }

        return [self::TIPO_IGNORADO, '', '', ''];
    }

    /** @param array<string,mixed> $mensaje */
    private static function epigrafe(array $mensaje): string
    {
        return isset($mensaje['caption']) && is_string($mensaje['caption'])
            ? trim($mensaje['caption'])
            : '';
    }

    /** @param array<string,mixed> $desde */
    private static function nombreDe(array $desde): string
    {
        $nombre = trim((string) ($desde['first_name'] ?? ''));

        return $nombre !== '' ? $nombre : trim((string) ($desde['username'] ?? ''));
    }
}
