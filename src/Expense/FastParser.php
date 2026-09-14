<?php

declare(strict_types=1);

namespace Budget\Expense;

use Budget\Support\Clock;
use Budget\Support\Money;

/**
 * Camino rápido: convierte "1200 super" o "ayer 45 lucas de prepaga" en
 * un borrador sin llamar a ningún modelo.
 *
 * Es el que resuelve la mayoría de los mensajes del día a día. Cada
 * gasto que atrapa acá es una llamada de IA que no se gasta, medio
 * segundo menos de espera y un resultado idéntico entre corridas.
 */
final class FastParser
{
    private const LARGO_MAXIMO_COMERCIO = 160;

    /**
     * A partir de acá el mensaje ya no es una anotación rápida sino una
     * frase, y la frase es trabajo del modelo.
     *
     * El valor sale de los mensajes que sí tiene que resolver: "gasté 45
     * lucas en la prepaga" son seis palabras.
     */
    private const MAXIMO_PALABRAS = 8;

    /** Palabras que no aportan al nombre del comercio. */
    private const RELLENO = [
        'gaste', 'gasté', 'pague', 'pagué', 'compre', 'compré', 'puse', 'saque', 'saqué',
        'de', 'del', 'en', 'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas',
        'por', 'con', 'para', 'mi', 'al', 'a', 'y',
        'k', 'luca', 'lucas', 'palo', 'palos', 'millon', 'millón', 'millones',
        'mil', 'miles', 'peso', 'pesos', 'mango', 'mangos', 'ars',
    ];

    /** Frase relativa => días hacia atrás. Se evalúan en orden. */
    private const FECHAS_RELATIVAS = [
        'anteayer' => 2,
        'antes de ayer' => 2,
        'ayer' => 1,
        'hoy' => 0,
    ];

    /** Palabra en el mensaje => medio de pago normalizado. */
    private const MEDIOS_DE_PAGO = [
        'efectivo' => 'Efectivo',
        'debito' => 'Débito',
        'débito' => 'Débito',
        'credito' => 'Crédito',
        'crédito' => 'Crédito',
        'visa' => 'Visa',
        'mastercard' => 'Mastercard',
        'master' => 'Mastercard',
        'amex' => 'Amex',
        'naranja' => 'Naranja',
        'transferencia' => 'Transferencia',
        'mercadopago' => 'Mercado Pago',
        'mp' => 'Mercado Pago',
        'uala' => 'Ualá',
        'ualá' => 'Ualá',
        'qr' => 'QR',
    ];

    private const MARCAS_DOLAR = ['u$s', 'usd', 'dolares', 'dólares', 'dolar', 'dólar'];

    public function __construct(
        private readonly Clock $reloj,
        private readonly CategoryGuesser $categorizador,
    ) {
    }

    /**
     * Devuelve null cuando no encuentra un importe. Null es la señal de
     * que el mensaje tiene que subir al motor de IA, no un error.
     */
    public function parsear(string $texto): ?Draft
    {
        $limpio = trim($texto);

        if ($limpio === '' || !self::esDeMiIncumbencia($limpio)) {
            return null;
        }

        $moneda = self::detectarMoneda($limpio);
        $monto = Money::parsear($limpio, $moneda);

        if ($monto === null) {
            return null;
        }

        $comercio = $this->extraerComercio($limpio);

        return new Draft(
            monto: $monto,
            fecha: $this->resolverFecha($limpio),
            comercio: $comercio,
            descripcion: $limpio,
            categoria: $this->categorizador->adivinar($limpio),
            medioPago: self::detectarMedioDePago($limpio),
            fuente: Draft::FUENTE_TEXTO,
            confianza: $comercio === '' ? 0.60 : 0.95,
            modelo: 'regex',
        );
    }

    /**
     * El camino rápido tiene que ser angosto.
     *
     * En producción (14/09/2026) esta clase respondió con confianza 0.95
     * a "Me fui de fiesta y gaste 30 mil en estacionamiento y 200 en
     * entradas y 100 en bebidas": se quedó con el primer número, armó un
     * comercio de catorce palabras y, por declararse confiada, impidió
     * que el mensaje llegara al modelo.
     *
     * Reconocer la propia incompetencia vale más que adivinar: devolver
     * null acá es lo que deja pasar el mensaje a la IA.
     */
    private static function esDeMiIncumbencia(string $texto): bool
    {
        // Varios importes suelen ser varios gastos en un mismo mensaje,
        // y el camino rápido sólo sabe proponer uno.
        if (count(Money::tokensNumericos($texto)) > 1) {
            return false;
        }

        $palabras = preg_split('/\s+/u', $texto) ?: [];

        return count($palabras) <= self::MAXIMO_PALABRAS;
    }

    private function resolverFecha(string $texto): \DateTimeImmutable
    {
        $normalizado = CategoryGuesser::normalizar($texto);
        $hoy = $this->reloj->ahora()->setTime(0, 0);

        foreach (self::FECHAS_RELATIVAS as $frase => $dias) {
            if (str_contains($normalizado, CategoryGuesser::normalizar($frase))) {
                return $dias === 0 ? $hoy : $hoy->modify("-{$dias} days");
            }
        }

        return $hoy;
    }

    private function extraerComercio(string $texto): string
    {
        $resto = $texto;
        $token = Money::tokenNumerico(mb_strtolower($texto));

        if ($token !== null) {
            $posicion = mb_strpos($resto, $token);

            if ($posicion !== false) {
                $resto = mb_substr($resto, 0, $posicion) . ' ' . mb_substr($resto, $posicion + mb_strlen($token));
            }
        }

        $resto = str_ireplace(self::MARCAS_DOLAR, ' ', $resto);
        $resto = str_replace('$', ' ', $resto);
        $resto = self::quitarPalabras($resto, self::RELLENO);
        $resto = self::quitarPalabras($resto, array_keys(self::FECHAS_RELATIVAS));
        $resto = self::quitarPalabras($resto, array_keys(self::MEDIOS_DE_PAGO));

        $resto = trim((string) preg_replace('/\s+/u', ' ', $resto));

        if ($resto === '') {
            return '';
        }

        return mb_substr(self::capitalizar($resto), 0, self::LARGO_MAXIMO_COMERCIO);
    }

    private static function detectarMoneda(string $texto): string
    {
        $normalizado = CategoryGuesser::normalizar($texto);

        foreach (self::MARCAS_DOLAR as $marca) {
            if (str_contains($normalizado, CategoryGuesser::normalizar($marca))) {
                return 'USD';
            }
        }

        return Money::MONEDA_POR_DEFECTO;
    }

    private static function detectarMedioDePago(string $texto): string
    {
        $normalizado = CategoryGuesser::normalizar($texto);
        $palabras = preg_split('/[^a-z0-9]+/', $normalizado) ?: [];

        foreach (self::MEDIOS_DE_PAGO as $clave => $etiqueta) {
            if (in_array(CategoryGuesser::normalizar($clave), $palabras, true)) {
                return $etiqueta;
            }
        }

        return '';
    }

    /**
     * Borra palabras completas, sin tocar subcadenas: quitar "a" no puede
     * romper "almacen".
     *
     * @param list<string> $palabras
     */
    private static function quitarPalabras(string $texto, array $palabras): string
    {
        $fragmentos = array_map(
            static fn (string $p): string => preg_quote($p, '/'),
            $palabras
        );

        $patron = '/(?<![\p{L}\p{N}])(?:' . implode('|', $fragmentos) . ')(?![\p{L}\p{N}])/iu';

        return (string) preg_replace($patron, ' ', $texto);
    }

    private static function capitalizar(string $texto): string
    {
        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }
}
