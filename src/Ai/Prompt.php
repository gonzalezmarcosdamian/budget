<?php

declare(strict_types=1);

namespace Budget\Ai;

/**
 * El prompt de extracción, en un solo lugar.
 *
 * Vive acá y no adentro de cada proveedor para que comparar modelos
 * mida el modelo y no una variación accidental del texto.
 */
final class Prompt
{
    /** Categorías válidas: el modelo tiene que elegir de esta lista y no inventar. */
    public const CATEGORIAS = [
        'Supermercado', 'Comida y delivery', 'Transporte', 'Servicios',
        'Hogar', 'Salud', 'Entretenimiento', 'Compras', 'Educación', 'Otros',
    ];

    public static function instruccion(string $hoyIso): string
    {
        $categorias = implode(', ', self::CATEGORIAS);

        return <<<TEXTO
        Sos un extractor de gastos para un bot argentino de finanzas personales.
        Devolvés únicamente un objeto JSON, sin texto alrededor y sin markdown.

        Campos:
        - monto: número decimal, sin separador de miles y con punto decimal. Obligatorio.
        - moneda: "ARS" o "USD". Si no se aclara, "ARS".
        - comercio: nombre del negocio. Cadena vacía si no aparece.
        - fecha: "YYYY-MM-DD". Si el ticket no la trae, null.
        - categoria: exactamente una de: {$categorias}
        - medio_pago: "Efectivo", "Débito", "Crédito", "Visa", "Mastercard",
          "Transferencia", "Mercado Pago", "QR" o cadena vacía.
        - confianza: número entre 0 y 1 según lo seguro que estés.

        Reglas:
        - Hoy es {$hoyIso}. Resolvé fechas relativas contra esa fecha.
        - En un ticket, el monto es el TOTAL, no un ítem suelto ni el subtotal.
        - Los importes argentinos usan punto de miles y coma decimal:
          "18.450,75" son dieciocho mil cuatrocientos cincuenta con setenta y cinco.
        - Jerga: "luca" = mil, "palo" = millón, "25k" = 25000.
        - Si no encontrás un importe, devolvé {"monto": null}.
        - No inventes datos que no estén: es preferible una confianza baja.
        TEXTO;
    }

    public static function paraTexto(string $mensaje, string $hoyIso): string
    {
        return self::instruccion($hoyIso) . "\n\nMensaje del usuario:\n" . $mensaje;
    }

    public static function paraImagen(string $epigrafe, string $hoyIso): string
    {
        $extra = $epigrafe === '' ? '' : "\n\nEl usuario agregó: {$epigrafe}";

        return self::instruccion($hoyIso)
            . "\n\nExtraé el gasto del comprobante de la imagen."
            . $extra;
    }

    public static function paraAudio(string $hoyIso): string
    {
        return self::instruccion($hoyIso)
            . "\n\nEscuchá el audio y extraé el gasto que describe la persona.";
    }
}
