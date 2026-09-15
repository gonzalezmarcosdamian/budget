<?php

declare(strict_types=1);

namespace Budget\Telegram;

use RuntimeException;

/**
 * Cliente de la Bot API sobre cURL, sin dependencias.
 *
 * Los errores de red no se tragan: si Telegram no acepta un mensaje hay
 * que enterarse por el log, no descubrirlo cuando falta un gasto.
 */
final class Client
{
    private const BASE = 'https://api.telegram.org';
    private const TIMEOUT_SEGUNDOS = 20;
    private const TIMEOUT_DESCARGA_SEGUNDOS = 60;

    public function __construct(private readonly string $token)
    {
    }

    public function enviarMensaje(int $chatId, string $texto, ?Keyboard $teclado = null): int
    {
        $parametros = [
            'chat_id' => $chatId,
            'text' => $texto,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($teclado !== null && !$teclado->vacio()) {
            $parametros['reply_markup'] = json_encode($teclado->aArray(), JSON_UNESCAPED_UNICODE);
        }

        $respuesta = $this->llamar('sendMessage', $parametros);

        return (int) ($respuesta['message_id'] ?? 0);
    }

    public function editarMensaje(int $chatId, int $messageId, string $texto, ?Keyboard $teclado = null): void
    {
        $parametros = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $texto,
            'parse_mode' => 'HTML',
        ];

        if ($teclado !== null && !$teclado->vacio()) {
            $parametros['reply_markup'] = json_encode($teclado->aArray(), JSON_UNESCAPED_UNICODE);
        }

        $this->llamar('editMessageText', $parametros);
    }

    /**
     * Apaga el relojito del botón. Telegram lo deja girando hasta que se
     * responde, y un botón que parece colgado se toca dos veces.
     */
    public function responderCallback(string $callbackQueryId, string $aviso = ''): void
    {
        $this->llamar('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $aviso,
        ]);
    }

    public function enviarAccion(int $chatId, string $accion = 'typing'): void
    {
        $this->llamar('sendChatAction', ['chat_id' => $chatId, 'action' => $accion]);
    }

    /** Devuelve el contenido binario de un archivo subido por el usuario. */
    public function descargarArchivo(string $fileId): string
    {
        $info = $this->llamar('getFile', ['file_id' => $fileId]);
        $ruta = (string) ($info['file_path'] ?? '');

        if ($ruta === '') {
            throw new RuntimeException("Telegram no devolvió ruta para el archivo {$fileId}");
        }

        $url = self::BASE . '/file/bot' . $this->token . '/' . $ruta;
        $contenido = $this->pedirCrudo($url, self::TIMEOUT_DESCARGA_SEGUNDOS);

        if ($contenido === '') {
            throw new RuntimeException("Descarga vacía para el archivo {$fileId}");
        }

        return $contenido;
    }

    /**
     * Trae los updates pendientes en vez de esperar a que Telegram los
     * entregue. Sólo para desarrollo local: en producción el bot usa
     * webhook, porque polling necesita un proceso vivo 24/7 y eso no
     * entra en hosting compartido.
     *
     * @return list<array<string,mixed>>
     */
    public function obtenerUpdates(int $desde, int $esperaSegundos = 25): array
    {
        $url = self::BASE . '/bot' . $this->token . '/getUpdates';

        $crudo = $this->pedirCrudo($url, $esperaSegundos + 10, [
            'offset' => $desde,
            'timeout' => $esperaSegundos,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ]);

        $decodificado = json_decode($crudo, true);

        if (!is_array($decodificado) || ($decodificado['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram rechazó getUpdates: ' . substr($crudo, 0, 200));
        }

        $resultado = $decodificado['result'] ?? [];

        return is_array($resultado) ? array_values($resultado) : [];
    }

    /** @return array<string,mixed> */
    public function fijarWebhook(string $url, string $secreto): array
    {
        return $this->llamar('setWebhook', [
            'url' => $url,
            'secret_token' => $secreto,
            'allowed_updates' => json_encode(['message', 'callback_query']),
            'drop_pending_updates' => true,
        ]);
    }

    /**
     * Publica el menú de comandos que Telegram muestra al tocar "/".
     *
     * @return array<string,mixed>
     */
    public function fijarComandos(string $comandosJson): array
    {
        return $this->llamar('setMyCommands', ['commands' => $comandosJson]);
    }

    /** @return array<string,mixed> */
    public function infoWebhook(): array
    {
        return $this->llamar('getWebhookInfo', []);
    }

    /**
     * @param array<string,scalar|null> $parametros
     * @return array<string,mixed>
     */
    private function llamar(string $metodo, array $parametros): array
    {
        $url = self::BASE . '/bot' . $this->token . '/' . $metodo;
        $crudo = $this->pedirCrudo($url, self::TIMEOUT_SEGUNDOS, $parametros);
        $decodificado = json_decode($crudo, true);

        if (!is_array($decodificado)) {
            throw new RuntimeException("Respuesta ilegible de Telegram en {$metodo}");
        }

        if (($decodificado['ok'] ?? false) !== true) {
            $detalle = (string) ($decodificado['description'] ?? 'sin detalle');

            throw new RuntimeException("Telegram rechazó {$metodo}: {$detalle}");
        }

        $resultado = $decodificado['result'] ?? [];

        return is_array($resultado) ? $resultado : ['result' => $resultado];
    }

    /** @param array<string,scalar|null>|null $parametros */
    private function pedirCrudo(string $url, int $timeout, ?array $parametros = null): string
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('No se pudo inicializar cURL');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($parametros !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
        }

        $respuesta = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false) {
            throw new RuntimeException("Error de red hablando con Telegram: {$error}");
        }

        return (string) $respuesta;
    }
}
