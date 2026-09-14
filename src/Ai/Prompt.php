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
    /**
     * Categorías válidas: el modelo tiene que elegir de esta lista y no
     * inventar.
     *
     * Tiene que coincidir con las semillas de las migraciones. Cuando se
     * agregaron Alquiler, Impuestos y las de ingreso e inversión, esta
     * lista quedó atrás y el modelo no podía asignarlas por más que
     * existieran en la base.
     */
    public const CATEGORIAS = [
        'Alquiler', 'Supermercado', 'Comida y delivery', 'Transporte',
        'Servicios', 'Impuestos', 'Hogar', 'Salud', 'Salidas y fiestas',
        'Entretenimiento', 'Compras', 'Educación', 'Comisiones', 'Otros',
        'Sueldo', 'Otros ingresos', 'Inversiones',
    ];

    public static function instruccion(string $hoyIso): string
    {
        $categorias = implode(', ', self::CATEGORIAS);

        return <<<TEXTO
        Sos un extractor de gastos para un bot argentino de finanzas personales.
        Devolvés únicamente un objeto JSON, sin texto alrededor y sin markdown,
        con esta forma:

        {"gastos": [ {gasto}, {gasto}, ... ]}

        Un mensaje puede describir VARIOS gastos: "gasté 30 mil en
        estacionamiento y 200 en entradas" son dos gastos distintos, no uno.
        Devolvé uno por cada importe que la persona menciona.

        Cada gasto tiene:
        - monto: número decimal, sin separador de miles y con punto decimal. Obligatorio.
        - moneda: "ARS" o "USD". Si no se aclara, "ARS".
        - comercio: qué se pagó o dónde. Cadena vacía si no aparece.
        - fecha: "YYYY-MM-DD". Si no se puede saber, null.
        - categoria: exactamente una de: {$categorias}
        - medio_pago: "Efectivo", "Débito", "Crédito", "Visa", "Mastercard",
          "Transferencia", "Mercado Pago", "QR" o cadena vacía.
        - confianza: número entre 0 y 1 según lo seguro que estés.

        Reglas:
        - Hoy es {$hoyIso}. Resolvé fechas relativas contra esa fecha.
        - En un ticket, el monto es el TOTAL, no un ítem suelto ni el subtotal.
        - Los importes argentinos usan punto de miles y coma decimal:
          "18.450,75" son dieciocho mil cuatrocientos cincuenta con setenta y cinco.
        - Jerga: "luca" y "mil" = 1000, "palo" = millón, "25k" = 25000.
        - Una aclaración como "son miles de pesos" o "todo en miles" aplica a
          TODOS los importes del mensaje: "200" pasa a ser 200000.
        - Si no encontrás ningún importe, devolvé {"gastos": []}.
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

    /**
     * Un resumen de tarjeta no es un ticket: trae el mes entero, con
     * cuotas, impuestos y el saldo anterior mezclados entre los consumos.
     * Distinguir qué es un gasto real es la mitad del trabajo.
     */
    public static function paraResumen(string $hoyIso): string
    {
        return self::instruccion($hoyIso) . <<<'TEXTO'


        El archivo es un RESUMEN DE TARJETA DE CRÉDITO argentino.
        Extraé todos los consumos del detalle, uno por línea.

        Qué SÍ es un gasto:
        - Cada compra o consumo con su fecha, descripción e importe.
        - Una cuota ("3/12") es un gasto del mes: usá el importe de la
          cuota, no el total de la compra, y dejá la referencia en el
          comercio: "Zara 3/12".

        Qué NO hay que cargar, porque no son consumos nuevos:
        - Saldo anterior, pago recibido, saldo actual, total a pagar.
        - Impuestos y percepciones (IVA, ingresos brutos, ley 25.413),
          intereses, punitorios, seguros de la tarjeta y cargos
          administrativos.
        - Los subtotales y el total del resumen.
        - Los consumos en dólares de la sección en dólares, salvo que
          lleven moneda "USD" explícita.

        Usá la fecha de cada consumo, no la del resumen.
        Si el archivo está protegido o no se puede leer, devolvé
        {"gastos": []}.
        TEXTO;
    }

    public static function paraAudio(string $hoyIso): string
    {
        return self::instruccion($hoyIso)
            . "\n\nEscuchá el audio y extraé los gastos que describe la persona.";
    }
}
