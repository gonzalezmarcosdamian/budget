<?php

declare(strict_types=1);

namespace Budget\Handler;

/**
 * Decide si el webhook está sano a partir de lo que reporta Telegram.
 *
 * Existe por un riesgo concreto del hosting: el firewall de WNPower
 * bloquea IPs ante ráfagas de pedidos. Si llegara a bloquear a Telegram,
 * el bot deja de recibir mensajes **en silencio**: no hay error, no hay
 * log, simplemente nadie escribe. La única superficie donde eso se ve es
 * getWebhookInfo, y hay que ir a mirarla.
 *
 * Lógica pura y sin efectos, para poder probar cada escenario sin
 * depender de que Telegram se caiga justo durante el test.
 */
final class HealthCheck
{
    /**
     * Updates encolados a partir de los cuales algo anda mal.
     *
     * Telegram acumula lo que no pudo entregar. Un puñado es normal entre
     * corridas del cron; decenas significan que el webhook no responde.
     */
    public const PENDIENTES_SOSPECHOSOS = 20;

    /**
     * Devuelve el aviso a mandarle al dueño, o null si está todo bien.
     *
     * @param array<string,mixed> $info respuesta de getWebhookInfo
     */
    public static function alerta(array $info, int $pendientesMaximos = self::PENDIENTES_SOSPECHOSOS): ?string
    {
        $url = trim((string) ($info['url'] ?? ''));

        if ($url === '') {
            return '🔴 El bot no tiene webhook configurado. Nadie le está llegando.'
                . "\nCorrer: <code>php bin/webhook.php set https://TU-URL/webhook.php</code>";
        }

        $error = trim((string) ($info['last_error_message'] ?? ''));
        $pendientes = (int) ($info['pending_update_count'] ?? 0);

        if ($error !== '') {
            return "🔴 Telegram no puede entregar los mensajes.\n\n"
                . 'Último error: <code>' . ExpenseCard::escapar($error) . "</code>\n"
                . "Pendientes: {$pendientes}\n\n"
                . self::pistaSegunError($error);
        }

        if ($pendientes >= $pendientesMaximos) {
            return "🟠 Hay {$pendientes} mensajes encolados en Telegram sin entregar.\n"
                . 'El webhook responde, pero algo lo está frenando.';
        }

        return null;
    }

    /**
     * Un mensaje de error genérico no sirve a las tres de la mañana. Acá
     * se traduce a la causa más probable en este hosting.
     */
    private static function pistaSegunError(string $error): string
    {
        $normalizado = mb_strtolower($error);

        return match (true) {
            str_contains($normalizado, 'connection refused'),
            str_contains($normalizado, 'timeout'),
            str_contains($normalizado, 'timed out') => 'Probable: el firewall de WNPower bloqueó la IP de Telegram. '
                . 'Lo destraba una persona desde el panel del hosting.',

            str_contains($normalizado, 'ssl'),
            str_contains($normalizado, 'certificate') => 'Probable: venció el certificado. Renovar el Let\'s Encrypt del subdominio.',

            str_contains($normalizado, '403') => 'Probable: bloqueo del firewall o permisos del archivo.',

            str_contains($normalizado, '404') => 'Probable: el despliegue movió o borró webhook.php.',

            str_contains($normalizado, '500'),
            str_contains($normalizado, '503') => 'Probable: la aplicación está fallando. Revisar storage/app.log en el servidor.',

            default => 'Revisar storage/app.log en el servidor.',
        };
    }
}
