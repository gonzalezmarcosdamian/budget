<?php

declare(strict_types=1);

namespace Budget\Expense;

/**
 * Categorización por palabras clave, sin IA.
 *
 * Resuelve la mayoría de los gastos cotidianos a costo cero. Lo que no
 * cae acá pasa al modelo, y lo que el usuario corrija se guarda como
 * regla propia en merchant_rules, que tiene prioridad sobre esta tabla.
 */
final class CategoryGuesser
{
    public const POR_DEFECTO = 'Otros';

    /** @var array<string, list<string>> categoría => palabras clave */
    private const PALABRAS = [
        'Supermercado' => [
            'super', 'supermercado', 'coto', 'carrefour', 'jumbo', 'disco', 'vea',
            'changomas', 'walmart', 'chino', 'almacen', 'verduleria',
            'carniceria', 'panaderia', 'fiambreria',
        ],
        'Comida y delivery' => [
            'delivery', 'rappi', 'pedidosya', 'mcdonalds', 'burger', 'pizza',
            'resto', 'restaurante', 'cafe', 'cafeteria', 'kiosco',
            'heladeria', 'empanadas', 'sushi', 'almuerzo', 'cena', 'desayuno',
        ],
        'Transporte' => [
            'nafta', 'combustible', 'ypf', 'shell', 'axion', 'puma', 'gnc',
            'sube', 'colectivo', 'subte', 'tren', 'uber', 'cabify', 'didi',
            'taxi', 'remis', 'peaje', 'estacionamiento', 'cochera', 'vtv',
            'patente',
        ],
        'Servicios' => [
            'luz', 'edenor', 'edesur', 'metrogas', 'aysa',
            'internet', 'fibertel', 'telecentro', 'movistar', 'personal',
            'claro', 'netflix', 'spotify', 'disney', 'chatgpt',
            'telefono', 'cable', 'suscripcion',
        ],
        'Hogar' => [
            'expensas', 'alquiler', 'ferreteria', 'pinturas', 'sodimac',
            'easy', 'mueble', 'limpieza', 'electrodomestico', 'abl',
            'inmobiliaria',
        ],
        'Salud' => [
            'farmacia', 'farmacity', 'remedio', 'medicamento', 'prepaga',
            'osde', 'swiss medical', 'galeno', 'medife', 'obra social',
            'dentista', 'odontologo', 'medico', 'analisis',
            'kinesiologia', 'psicologo', 'optica',
        ],
        'Salidas y fiestas' => [
            'fiesta', 'fiestas', 'salida', 'salidas', 'previa', 'after',
            'boliche', 'bar', 'pub', 'cumpleanos', 'cumple', 'casamiento',
            'recital', 'entrada', 'entradas', 'bebida', 'bebidas', 'tragos',
            'birra', 'birras', 'cerveza', 'fernet', 'vino', 'brindis',
        ],
        'Entretenimiento' => [
            'cine', 'teatro', 'steam', 'playstation', 'xbox', 'libro',
            'gimnasio', 'gym', 'padel', 'futbol',
        ],
        'Compras' => [
            'ropa', 'zapatillas', 'zara', 'mercadolibre', 'meli',
            'amazon', 'shein', 'regalo', 'perfumeria', 'electronica',
            'celular', 'notebook', 'auricular',
        ],
        'Educación' => [
            'curso', 'facultad', 'universidad', 'colegio',
            'ingles', 'apunte', 'matricula', 'capacitacion',
        ],
    ];

    /**
     * Devuelve el nombre de la categoría, o null si ninguna palabra clave
     * coincide. Null no es un error: es la señal de que conviene
     * preguntarle al usuario o al modelo.
     */
    public function adivinar(string $texto): ?string
    {
        $normalizado = self::normalizar($texto);

        if ($normalizado === '') {
            return null;
        }

        $palabras = preg_split('/[^a-z0-9]+/', $normalizado) ?: [];
        $palabras = array_values(array_filter($palabras, static fn (string $p): bool => $p !== ''));

        foreach (self::PALABRAS as $categoria => $claves) {
            foreach ($claves as $clave) {
                if (self::coincide($palabras, self::normalizar($clave))) {
                    return $categoria;
                }
            }
        }

        return null;
    }

    /** Minúsculas y sin acentos, para que "Educación" matchee "educacion". */
    public static function normalizar(string $texto): string
    {
        $bajo = mb_strtolower(trim($texto));

        return strtr($bajo, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
    }

    /**
     * Compara palabra completa y no subcadena: "super" no debe disparar
     * con "supervielle", ni "cafe" con "cafeteria" de otra categoría.
     *
     * @param list<string> $palabras
     */
    private static function coincide(array $palabras, string $clave): bool
    {
        $partes = explode(' ', $clave);

        if (count($partes) === 1) {
            return in_array($clave, $palabras, true);
        }

        // Claves de varias palabras ("obra social"): tienen que aparecer
        // consecutivas en el texto.
        $total = count($palabras);
        $largo = count($partes);

        for ($i = 0; $i + $largo <= $total; $i++) {
            if (array_slice($palabras, $i, $largo) === $partes) {
                return true;
            }
        }

        return false;
    }
}
