<?php

declare(strict_types=1);

namespace Budget\Ai;

use Budget\Support\Http;
use RuntimeException;

/**
 * Categoriza descripciones de comercio que las palabras clave no pescan.
 *
 * "ARCA" es AFIP, "CUPON DE PAGO 431234" no dice nada y "MERPAGO*
 * ELECTRONICAFL" es una compra de electrónica. Un diccionario nunca va a
 * cubrir eso; un modelo lo entiende leyendo.
 *
 * Trabaja de a tandas y sobre descripciones **únicas**: cuatrocientos
 * movimientos suelen ser cuarenta comercios distintos, así que agrupar
 * antes de preguntar baja el costo un orden de magnitud.
 */
final class Categorizador
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    /** Cuántas descripciones entran en una misma pregunta. */
    public const POR_TANDA = 60;

    public function __construct(
        private readonly string $apiKey,
        private readonly Http $http,
        private readonly string $modelo = 'gemini-3.5-flash-lite',
    ) {
    }

    public function disponible(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param list<string> $comercios descripciones únicas
     * @return array<string,array{categoria:string, tipo:string}>
     */
    public function clasificar(array $comercios): array
    {
        if ($comercios === [] || !$this->disponible()) {
            return [];
        }

        $categorias = implode(', ', Prompt::CATEGORIAS);
        $lista = '';

        foreach (array_values($comercios) as $i => $c) {
            $lista .= sprintf("%d. %s\n", $i + 1, $c);
        }

        $instruccion = <<<TEXTO
        Sos un clasificador de gastos para un bot argentino de finanzas
        personales. Te paso descripciones de movimientos bancarios y de
        Mercado Pago, tal como las escribe cada comercio.

        Devolvés sólo un objeto JSON, sin texto alrededor:

        {"asignaciones": [{"n": 1, "categoria": "...", "tipo": "..."}, ...]}

        - categoria: exactamente una de: {$categorias}
        - tipo: "gasto", "ingreso" o "inversion".

        Contexto argentino que conviene tener presente:
        - "ARCA" y "AFIP" son el organismo de impuestos: categoría Impuestos.
        - "Rentas", "ATM", "ABL", "Municipalidad" también son Impuestos.
        - "SIRO", "Roela" y las inmobiliarias cobran alquiler o expensas.
        - "MERPAGO*" es un prefijo de Mercado Pago: mirá lo que sigue.
        - "VENTA PRESENCIAL", "CUPON DE PAGO" y similares no dicen nada:
          usá Otros antes que inventar.
        - Una transferencia a una persona, sin más datos, es Otros.

        Si una descripción no alcanza para decidir, poné "Otros". Es
        preferible a una categoría inventada: un gasto mal clasificado en
        silencio ensucia el reporte más que uno sin clasificar.

        Descripciones:
        {$lista}
        TEXTO;

        $url = self::BASE . '/' . $this->modelo . ':generateContent?key=' . urlencode($this->apiKey);

        $respuesta = $this->http->postJson($url, [
            'contents' => [['parts' => [['text' => $instruccion]]]],
            'generationConfig' => ['temperature' => 0, 'response_mime_type' => 'application/json'],
        ]);

        return self::interpretar($respuesta, array_values($comercios));
    }

    /**
     * Traduce la respuesta del modelo a asignaciones utilizables.
     *
     * Es pública y estática porque acá está el riesgo real: lo que
     * devuelve el modelo es texto de afuera, y escribir una categoría
     * inventada en cuatrocientos movimientos es peor que no clasificar
     * ninguno. Se prueba sola, sin pegarle a la API.
     *
     * @param array<string,mixed> $respuesta
     * @param list<string> $comercios
     * @return array<string,array{categoria:string, tipo:string}>
     */
    public static function interpretar(array $respuesta, array $comercios): array
    {
        $texto = $respuesta['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!is_string($texto)) {
            throw new RuntimeException('El modelo no devolvió texto');
        }

        $datos = json_decode($texto, true);

        if (!is_array($datos)) {
            throw new RuntimeException('El modelo no devolvió JSON');
        }

        $validas = Prompt::CATEGORIAS;
        $resultado = [];

        foreach (($datos['asignaciones'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }

            $n = (int) ($a['n'] ?? 0);
            $categoria = trim((string) ($a['categoria'] ?? ''));

            // El índice y la categoría vienen del modelo: si no encajan
            // con lo que se pidió, se descartan en vez de escribirse.
            if ($n < 1 || $n > count($comercios) || !in_array($categoria, $validas, true)) {
                continue;
            }

            $tipo = (string) ($a['tipo'] ?? 'gasto');

            $resultado[$comercios[$n - 1]] = [
                'categoria' => $categoria,
                'tipo' => in_array($tipo, ['gasto', 'ingreso', 'inversion'], true) ? $tipo : 'gasto',
            ];
        }

        return $resultado;
    }
}
