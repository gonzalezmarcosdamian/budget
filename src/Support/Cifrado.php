<?php

declare(strict_types=1);

namespace Budget\Support;

use RuntimeException;

/**
 * Cifrado simétrico para credenciales de terceros guardadas en la base.
 *
 * El token de Mercado Pago da acceso a los movimientos de dinero de una
 * persona. Guardarlo en claro convierte cualquier lectura de la base
 * —un backup, un volcado, una consulta de soporte— en acceso a sus pagos.
 *
 * Usa AES-256-GCM, que además de cifrar autentica: un texto manipulado
 * falla al descifrar en vez de devolver basura silenciosamente. Va sobre
 * la extensión openssl, que es parte del núcleo de PHP, así que no rompe
 * la regla de cero dependencias.
 */
final class Cifrado
{
    private const ALGORITMO = 'aes-256-gcm';
    private const LARGO_ETIQUETA = 16;

    /** La clave viaja como hex de 64 caracteres: 32 bytes. */
    private const LARGO_CLAVE_HEX = 64;

    private function __construct(private readonly string $clave)
    {
    }

    public static function conClaveHex(string $claveHex): self
    {
        if (strlen($claveHex) !== self::LARGO_CLAVE_HEX || preg_match('/^[0-9a-f]+$/i', $claveHex) !== 1) {
            throw new RuntimeException(
                'APP_KEY tiene que ser 64 caracteres hexadecimales. Generar con: '
                . 'php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        $clave = hex2bin($claveHex);

        if ($clave === false) {
            throw new RuntimeException('APP_KEY no es hexadecimal válido');
        }

        return new self($clave);
    }

    public static function generarClaveHex(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Devuelve base64 de iv + etiqueta + texto cifrado. */
    public function cifrar(string $texto): string
    {
        $largoIv = openssl_cipher_iv_length(self::ALGORITMO);

        if ($largoIv === false) {
            throw new RuntimeException('El algoritmo de cifrado no está disponible');
        }

        $iv = random_bytes($largoIv);
        $etiqueta = '';

        $cifrado = openssl_encrypt(
            $texto,
            self::ALGORITMO,
            $this->clave,
            OPENSSL_RAW_DATA,
            $iv,
            $etiqueta,
            '',
            self::LARGO_ETIQUETA
        );

        if ($cifrado === false) {
            throw new RuntimeException('Falló el cifrado');
        }

        return base64_encode($iv . $etiqueta . $cifrado);
    }

    public function descifrar(string $paquete): string
    {
        $crudo = base64_decode($paquete, true);
        $largoIv = openssl_cipher_iv_length(self::ALGORITMO);

        // Un texto vacío cifra a exactamente iv + etiqueta y cero bytes
        // de contenido, así que el mínimo válido es esa suma, no más.
        if ($crudo === false || $largoIv === false || strlen($crudo) < $largoIv + self::LARGO_ETIQUETA) {
            throw new RuntimeException('El texto cifrado está incompleto o corrupto');
        }

        $iv = substr($crudo, 0, $largoIv);
        $etiqueta = substr($crudo, $largoIv, self::LARGO_ETIQUETA);
        $cifrado = substr($crudo, $largoIv + self::LARGO_ETIQUETA);

        $texto = openssl_decrypt($cifrado, self::ALGORITMO, $this->clave, OPENSSL_RAW_DATA, $iv, $etiqueta);

        if ($texto === false) {
            // Con GCM esto significa clave equivocada o dato manipulado,
            // no un descifrado "parcial": por eso se puede fallar fuerte.
            throw new RuntimeException('No se pudo descifrar: clave incorrecta o dato alterado');
        }

        return $texto;
    }
}
