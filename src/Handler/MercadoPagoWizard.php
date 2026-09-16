<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Integracion\MercadoPago;
use Budget\Repository\MercadoPagoRepository;
use Budget\Support\Cifrado;
use Budget\Support\Http;
use Budget\Support\Logger;
use Budget\Telegram\Client;
use Budget\Telegram\Update;
use PDO;
use Throwable;

/**
 * Conectar una cuenta de Mercado Pago, en dos pasos.
 *
 * Vive aparte del Dispatcher por dos razones. La primera es tamaño: con
 * esto adentro el Dispatcher pasaba de mil líneas. La segunda importa
 * más: acá se manipula una credencial de pleno acceso a la cuenta de
 * dinero de una persona, y eso merece un archivo donde se pueda leer
 * entero de una sentada.
 *
 * No es una máquina de estados: el token empieza con APP_USR- y no se
 * parece a nada que alguien escriba de casualidad, así que se reconoce
 * solo cuando llega pegado. Para dos pasos, guardar en cuál está cada
 * usuario sería más código del que ahorra.
 */
final class MercadoPagoWizard
{
    public function __construct(
        private readonly Client $telegram,
        private readonly PDO $pdo,
        /** Como cierre y no como valor: leer APP_KEY revienta si falta,
         *  y /desvincular no la necesita para contestar. */
        private readonly \Closure $claveHex,
        private readonly Logger $log,
    ) {
    }

    /**
     * ¿El mensaje contiene algo que huela a credencial de Mercado Pago?
     *
     * A propósito más amplio que el patrón exacto: lo que decide acá no
     * es si el vínculo va a funcionar, es si el mensaje puede seguir
     * hacia la IA. Ante la duda, no sigue.
     */
    public static function mencionaUnToken(string $texto): bool
    {
        return str_contains($texto, 'APP_USR-') || str_contains($texto, 'TEST-');
    }

    /**
     * Corta el vínculo.
     *
     * Desactiva la cuenta de este lado, pero lo que de verdad protege al
     * usuario es revocar el token en el panel de Mercado Pago: mientras
     * exista, sigue siendo una credencial válida sobre su cuenta, esté o
     * no guardada acá. Por eso el mensaje insiste con eso.
     */
    public function desvincular(int $userId): string
    {
        $habia = (new MercadoPagoRepository($this->pdo))->desactivar($userId);

        if (!$habia) {
            return 'No tenés ninguna cuenta de Mercado Pago conectada.';
        }

        return "🔌 Listo, desconecté tu cuenta. Dejo de importar movimientos.\n\n"
            . '⚠️ <b>Revocá el token en Mercado Pago igual</b>: borrarlo de acá no lo '
            . 'invalida. Panel → tu aplicación → Credenciales de producción.';
    }

    /** El token de producción de Mercado Pago tiene una forma inconfundible. */
    private static function pareceTokenDeMercadoPago(string $texto): bool
    {
        return preg_match('/^APP_USR-[A-Za-z0-9._-]{20,}$/', trim($texto)) === 1;
    }

    /**
     * Guarda el token y deja la cuenta lista.
     *
     * Se verifica contra la API antes de guardarlo: un token que no
     * sirve, guardado, hace fallar el cron en silencio todas las horas.
     */
    public function vincular(int $userId, Update $update): void
    {
        $token = trim($update->texto);

        // Lo primero, pase lo que pase después: sacar la credencial del
        // historial del chat.
        $this->telegram->borrarMensaje($update->chatId, $update->messageId);

        if (!self::pareceTokenDeMercadoPago($token)) {
            $this->telegram->enviarMensaje(
                $update->chatId,
                "🔒 Borré ese mensaje: tenía algo que parece una credencial.\n\n"
                . '<i>Mandame el token <b>solo</b>, sin nada más en el mensaje: '
                . 'ni "Access Token:" adelante, ni la public key atrás.</i>'
            );

            return;
        }

        try {
            $cuenta = (new Http())->getJson(
                'https://api.mercadopago.com/users/me',
                ['Authorization: Bearer ' . $token]
            );
        } catch (Throwable $e) {
            $this->log->advertencia('token de Mercado Pago rechazado', ['user' => $userId]);
            $this->telegram->enviarMensaje(
                $update->chatId,
                "❌ Mercado Pago no aceptó ese token.\n\n"
                . '<i>Fijate que sea el de <b>producción</b> y no el de prueba. '
                . 'Con /mercadopago tenés los pasos otra vez.</i>'
            );

            return;
        }

        $mpUserId = (int) ($cuenta['id'] ?? 0);

        // Un solo lugar donde decidir el nombre: `?? ''` no cubre el
        // apodo presente pero vacío, y el mensaje quedaba con el nombre
        // en blanco.
        $apodo = trim((string) ($cuenta['nickname'] ?? ''));
        $nombre = $apodo === '' ? 'tu cuenta' : MercadoPago::nombreLegible($apodo);

        if ($mpUserId === 0) {
            $this->telegram->enviarMensaje($update->chatId, '❌ No pude leer la cuenta con ese token.');

            return;
        }

        // Cifrar y guardar pueden fallar por su cuenta, y si la
        // excepción sube el usuario no recibe nada: desde su lado eso es
        // "no funcionó", vuelve a pegar el token y ahora hay dos copias.
        try {
            (new MercadoPagoRepository($this->pdo))->vincular(
                $userId,
                $mpUserId,
                $apodo,
                Cifrado::conClaveHex(($this->claveHex)())->cifrar($token)
            );
        } catch (Throwable $e) {
            $this->log->excepcion($e, 'guardado del token de Mercado Pago');
            $this->telegram->enviarMensaje(
                $update->chatId,
                '⚠️ El token era válido pero no lo pude guardar. Probá de nuevo en un rato.'
            );

            return;
        }

        $this->telegram->enviarMensaje(
            $update->chatId,
            sprintf(
                "✅ Listo, <b>%s</b> quedó conectada.\n\n"
                    . "En la próxima hora traigo tu historial del último año.\n\n"
                    . '<i>Importo en silencio. Si querés que te avise cada vez, /avisos.</i>',
                ExpenseCard::escapar($nombre)
            )
        );
    }

    public static function pasos(): string
    {
        return implode("\n", [
            '💳 <b>Conectar Mercado Pago</b>',
            '',
            'Una vez conectada, cargo tus movimientos solo, cada hora.',
            '',
            '<b>1.</b> Entrá a <a href="https://www.mercadopago.com.ar/developers/panel">'
                . 'mercadopago.com.ar/developers/panel</a> con tu cuenta.',
            '<b>2.</b> Creá una aplicación (cualquier nombre sirve) y abrí '
                . '<b>Credenciales de producción</b>.',
            '<b>3.</b> Copiá el <b>Access Token</b> y pegámelo acá como mensaje.',
            '',
            '<i>Empieza con APP_USR- y lo reconozco solo. Lo guardo cifrado y borro '
                . 'tu mensaje apenas lo uso.</i>',
            '',
            '⚠️ <b>Mandalo solo, sin texto alrededor, y no como captura.</b>',
            '<i>Yo sólo leo tus movimientos, pero ese token da acceso completo a tu '
                . 'cuenta. Si algún día querés cortarlo, revocalo desde el panel de '
                . 'Mercado Pago —o usá /desvincular acá y revocalo allá igual.</i>',
            '',
            '<i>Si te arrepentís, con /avisos apagás lo automático.</i>',
        ]);
    }
}
