<?php

declare(strict_types=1);

use Budget\Handler\MercadoPagoWizard;

/**
 * El corte por substring es lo único que impide que una credencial de
 * pleno acceso a la cuenta de dinero del usuario termine en un proveedor
 * de IA de terceros. Estos tests son ese candado.
 */

prueba('un mensaje con un token adentro nunca sigue de largo', function (): void {
    // El patrón exacto no alcanza: si el usuario pega con contexto, el
    // mensaje seguía hacia el parser y de ahí al proveedor de IA.
    foreach ([
        'APP_USR-1234567890abcdef-091512-abcdef0123456789-123456789',
        'Access Token: APP_USR-1234567890abcdef-091512-abc-123',
        'listo, ahi va: APP_USR-abc123',
        'APP_USR-abc123 y la public key es otra cosa',
        'TEST-1234567890-091512-abcdef-123456789',
    ] as $mensaje) {
        afirmar(MercadoPagoWizard::mencionaUnToken($mensaje), $mensaje);
    }
});

prueba('un mensaje normal no se confunde con un token', function (): void {
    // Si esto diera verdadero, el bot dejaría de cargar gastos.
    foreach ([
        '1200 super',
        'cuanto gaste este mes',
        'pasame detalle de agosto 2026',
        'app usr no es lo mismo',
        'TESTIGO de algo',
    ] as $mensaje) {
        afirmar(!MercadoPagoWizard::mencionaUnToken($mensaje), $mensaje);
    }
});

prueba('solo el token limpio se acepta como valido', function (): void {
    $metodo = new ReflectionMethod(MercadoPagoWizard::class, 'pareceTokenDeMercadoPago');
    $metodo->setAccessible(true);

    afirmar(
        $metodo->invoke(null, 'APP_USR-1234567890abcdef-091512-abcdef0123456789-123456789'),
        'el token solo, limpio'
    );
    afirmar(
        !$metodo->invoke(null, 'Access Token: APP_USR-1234567890abcdef-091512-abc-123'),
        'con texto alrededor no se vincula: se pide de nuevo'
    );
});

prueba('los pasos no prometen que el token sea de solo lectura', function (): void {
    // Decía "no puedo mover plata", y un access token de producción sí
    // puede. El consentimiento se apoya en esa frase.
    $texto = MercadoPagoWizard::pasos();

    afirmar(!str_contains($texto, 'no puedo mover plata'), 'nada de afirmar lo que no es cierto');
    contiene($texto, 'acceso completo', 'se dice lo que el token realmente permite');
    contiene($texto, 'revoc', 'y cómo cortarlo');
    contiene($texto, 'no como captura', 'una captura va al modelo de visión');
});
