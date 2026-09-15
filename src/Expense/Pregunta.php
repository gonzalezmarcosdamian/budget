<?php

declare(strict_types=1);

namespace Budget\Expense;

use DateTimeImmutable;

/**
 * Entiende preguntas sobre los gastos ya cargados.
 *
 * "cuánto gasté en súper este mes" es una pregunta que el bot puede
 * contestar con datos que ya tiene. Responder "no encontré un importe"
 * a eso es desperdiciar lo único que sabe hacer.
 *
 * Es deterministico a propósito: las cifras las calcula la base, no un
 * modelo. Un número inventado en un reporte de plata es peor que no
 * contestar.
 */
final class Pregunta
{
    /**
     * Señales fuertes: con una de estas, el mensaje es una pregunta.
     *
     * Ninguna aparece en un gasto anotado al pasar.
     */
    private const MARCAS_FUERTES = [
        'cuanto', 'cuánto', 'cuanta', 'cuánta', 'que gaste', 'qué gasté',
        'en que gaste', 'en qué gasté', 'gaste en', 'gasté en',
        'cuales', 'cuáles', 'total de', 'resumen de', 'como vengo', 'cómo vengo',
    ];

    /**
     * Señales débiles: piden algo, pero no dicen qué.
     *
     * "Dame 5000 de nafta" es un gasto y "dame el detalle de agosto" es
     * una pregunta, así que estas sólo cuentan si además hay un período:
     * un mes nombrado o una frase como "el mes pasado". Sin eso, el
     * mensaje sigue su camino normal.
     */
    private const MARCAS_DEBILES = [
        'dame', 'damelo', 'pasame', 'pasa', 'detalle', 'mostrame', 'mostra',
        'decime', 'ver', 'quiero ver',
    ];

    /** Frase => cuántos meses hacia atrás, o null para el período especial. */
    private const PERIODOS = [
        'mes pasado' => 'mes_pasado',
        'mes anterior' => 'mes_pasado',
        'este mes' => 'mes',
        'este año' => 'anio',
        'este anio' => 'anio',
        'en el año' => 'anio',
        'hoy' => 'dia',
        'ayer' => 'ayer',
        'esta semana' => 'semana',
    ];

    private function __construct(
        public readonly ?string $categoria,
        public readonly string $periodo,
        public readonly ?Mes $mes = null,
    ) {
    }

    /**
     * Devuelve null cuando el mensaje no parece una pregunta. Null es la
     * señal de seguir con el camino normal, no un error.
     */
    public static function desde(
        string $texto,
        CategoryGuesser $categorizador,
        ?DateTimeImmutable $hoy = null,
    ): ?self {
        $normalizado = CategoryGuesser::normalizar($texto);

        // Un mes nombrado gana sobre cualquier período relativo: si
        // alguien dice "agosto 2026" no está preguntando por este mes.
        $mes = Mes::desde($texto, $hoy ?? new DateTimeImmutable());

        if (!self::pareceUnaPregunta($normalizado, $texto, $mes)) {
            return null;
        }

        return new self(
            $categorizador->adivinar($texto),
            $mes !== null ? 'mes_nombrado' : self::periodo($normalizado),
            $mes
        );
    }

    private static function pareceUnaPregunta(string $normalizado, string $original, ?Mes $mes): bool
    {
        if (str_contains($original, '?') || str_contains($original, '¿')) {
            return true;
        }

        if (self::contieneAlguna($normalizado, self::MARCAS_FUERTES)) {
            return true;
        }

        // Una marca débil sólo alcanza si además se nombró un período:
        // es la diferencia entre "dame 5000 de nafta" y "dame agosto".
        return self::contieneAlguna($normalizado, self::MARCAS_DEBILES)
            && ($mes !== null || self::contieneAlguna($normalizado, array_keys(self::PERIODOS)));
    }

    /** @param list<string> $agujas */
    private static function contieneAlguna(string $normalizado, array $agujas): bool
    {
        foreach ($agujas as $aguja) {
            if (str_contains($normalizado, CategoryGuesser::normalizar($aguja))) {
                return true;
            }
        }

        return false;
    }

    private static function periodo(string $normalizado): string
    {
        // "mes pasado" antes que "mes": el orden decide, y al revés
        // cualquier pregunta sobre el mes pasado contestaría por este.
        foreach (self::PERIODOS as $frase => $periodo) {
            if (str_contains($normalizado, CategoryGuesser::normalizar($frase))) {
                return $periodo;
            }
        }

        return 'mes';
    }

    /**
     * El rango de fechas que hay que consultar.
     *
     * @return array{0:DateTimeImmutable, 1:DateTimeImmutable, 2:string}
     */
    public function rango(DateTimeImmutable $hoy): array
    {
        if ($this->mes !== null) {
            return $this->mes->rango();
        }

        return match ($this->periodo) {
            'dia' => [$hoy, $hoy, 'hoy'],
            'ayer' => [$hoy->modify('-1 day'), $hoy->modify('-1 day'), 'ayer'],
            'semana' => [$hoy->modify('monday this week'), $hoy, 'esta semana'],
            'mes_pasado' => [
                $hoy->modify('first day of last month'),
                $hoy->modify('last day of last month'),
                'el mes pasado',
            ],
            'anio' => [$hoy->modify('first day of January'), $hoy, 'este año'],
            default => [$hoy->modify('first day of this month'), $hoy, 'este mes'],
        };
    }
}
