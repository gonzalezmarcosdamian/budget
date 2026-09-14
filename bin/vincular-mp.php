<?php

declare(strict_types=1);

/**
 * Vincula una cuenta de Mercado Pago a un usuario del bot.
 *
 *   php bin/vincular-mp.php <chat_id> <access_token>
 *
 * El token se verifica contra la API antes de guardarlo, y se guarda
 * cifrado: es una credencial sobre la cuenta de dinero de una persona.
 */

use Budget\App;
use Budget\Repository\MercadoPagoRepository;
use Budget\Repository\UserRepository;
use Budget\Support\Cifrado;
use Budget\Support\Http;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

$chatId = (int) ($argv[1] ?? 0);
$token = (string) ($argv[2] ?? '');

if ($chatId === 0 || $token === '') {
    fwrite(STDERR, "Uso: php bin/vincular-mp.php <chat_id> <access_token>\n");
    exit(1);
}

$app = App::crear(dirname(__DIR__));
$usuario = (new UserRepository($app->pdo()))->porChat($chatId);

if ($usuario === null) {
    fwrite(STDERR, "No hay ningún usuario con chat_id {$chatId}.\n");
    exit(1);
}

// Verificar antes de guardar: un token que no sirve guardado en la base
// se descubre recién cuando el cron falla de madrugada.
try {
    $perfil = (new Http(30))->getJson(
        'https://api.mercadopago.com/users/me',
        ['Authorization: Bearer ' . $token]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'El token no sirve: ' . $e->getMessage() . "\n");
    exit(1);
}

$mpUserId = (int) ($perfil['id'] ?? 0);

if ($mpUserId === 0) {
    fwrite(STDERR, "Mercado Pago no devolvió un id de usuario.\n");
    exit(1);
}

(new MercadoPagoRepository($app->pdo()))->vincular(
    (int) $usuario['id'],
    $mpUserId,
    (string) ($perfil['nickname'] ?? ''),
    Cifrado::conClaveHex($app->config->claveDeCifrado())->cifrar($token)
);

printf(
    "Vinculada la cuenta %s (%d) al usuario %s.\n",
    (string) ($perfil['nickname'] ?? '?'),
    $mpUserId,
    (string) $usuario['nombre']
);
echo "El cron va a traer los pagos nuevos cada hora.\n";
