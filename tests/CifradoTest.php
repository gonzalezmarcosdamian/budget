<?php

declare(strict_types=1);

use Budget\Support\Cifrado;

function cifradoDePrueba(): Cifrado
{
    return Cifrado::conClaveHex(str_repeat('a1b2c3d4', 8));
}

prueba('lo cifrado se recupera igual', function (): void {
    $c = cifradoDePrueba();
    $token = 'APP_USR-1234567890-ABCDEF-token-de-mercado-pago';

    esIgual($token, $c->descifrar($c->cifrar($token)));
});

prueba('dos cifrados del mismo texto son distintos', function (): void {
    // Cada uno lleva su propio IV: si dieran igual, cualquiera podría
    // saber qué usuarios comparten credencial mirando la base.
    $c = cifradoDePrueba();

    afirmar($c->cifrar('mismo') !== $c->cifrar('mismo'), 'el IV cambia en cada cifrado');
});

prueba('una clave equivocada no descifra a medias', function (): void {
    $paquete = cifradoDePrueba()->cifrar('secreto');
    $otra = Cifrado::conClaveHex(str_repeat('ffffffff', 8));

    lanza(RuntimeException::class, static fn (): string => $otra->descifrar($paquete), 'clave distinta');
});

prueba('un texto manipulado falla en vez de devolver basura', function (): void {
    // Es la diferencia entre GCM y un cifrado sin autenticar.
    $paquete = cifradoDePrueba()->cifrar('importe: 1000');
    $alterado = substr($paquete, 0, -6) . 'AAAAAA';

    lanza(RuntimeException::class, static fn (): string => cifradoDePrueba()->descifrar($alterado), 'alterado');
});

prueba('rechaza una clave con formato inválido', function (): void {
    lanza(RuntimeException::class, static fn (): Cifrado => Cifrado::conClaveHex('corta'), 'muy corta');
    lanza(RuntimeException::class, static fn (): Cifrado => Cifrado::conClaveHex(str_repeat('z', 64)), 'no es hex');
});

prueba('la clave generada sirve', function (): void {
    $c = Cifrado::conClaveHex(Cifrado::generarClaveHex());

    esIgual('hola', $c->descifrar($c->cifrar('hola')));
});

prueba('cifra texto vacío sin romperse', function (): void {
    $c = cifradoDePrueba();

    esIgual('', $c->descifrar($c->cifrar('')));
});
