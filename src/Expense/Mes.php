<?php

declare(strict_types=1);

namespace Budget\Expense;

use DateTimeImmutable;

/**
 * Un mes nombrado dentro de una frase: "agosto 2026", "en agosto", "08/2026".
 *
 * Los períodos relativos —este mes, el mes pasado— alcanzan hasta que
 * uno quiere mirar agosto en noviembre. Pedirlo por su nombre es la
 * forma natural de decirlo, y el mes es la unidad en la que la gente
 * piensa la plata.
 *
 * Es deterministico: reconocer un mes no necesita un modelo, y gastar
 * una llamada de IA en esto sería tirar cupo.
 */
final class Mes
{
    /** Nombre normalizado => número de mes. */
    private const NOMBRES = [
        'enero' => 1,
        'febrero' => 2,
        'marzo' => 3,
        'abril' => 4,
        'mayo' => 5,
        'junio' => 6,
        'julio' => 7,
        'agosto' => 8,
        'septiembre' => 9,
        'setiembre' => 9,
        'octubre' => 10,
        'noviembre' => 11,
        'diciembre' => 12,
    ];

    /** Cómo se escribe cada mes cuando el bot lo nombra de vuelta. */
    private const COMO_SE_ESCRIBE = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    private function __construct(
        public readonly int $anio,
        public readonly int $numero,
    ) {
    }

    /**
     * Devuelve null cuando la frase no nombra ningún mes.
     *
     * $hoy hace falta para resolver un mes sin año: sin referencia no se
     * puede saber de qué agosto se está hablando.
     */
    public static function desde(string $texto, DateTimeImmutable $hoy): ?self
    {
        $normalizado = CategoryGuesser::normalizar($texto);

        $porNombre = self::porNombre($normalizado, $hoy);

        return $porNombre ?? self::porNumero($normalizado);
    }

    private static function porNombre(string $normalizado, DateTimeImmutable $hoy): ?self
    {
        foreach (self::NOMBRES as $nombre => $numero) {
            if (preg_match('/\b' . $nombre . '\b/u', $normalizado) !== 1) {
                continue;
            }

            // El año, si lo dijo: cuatro dígitos entre 2000 y 2099.
            if (preg_match('/\b(20\d{2})\b/u', $normalizado, $m) === 1) {
                return new self((int) $m[1], $numero);
            }

            return new self(self::anioMasProbable($numero, $hoy), $numero);
        }

        return null;
    }

    /** Formatos numéricos: 08/2026, 2026-08, 8-2026. */
    private static function porNumero(string $normalizado): ?self
    {
        if (preg_match('#\b(20\d{2})[/-](0?[1-9]|1[0-2])\b#u', $normalizado, $m) === 1) {
            return new self((int) $m[1], (int) $m[2]);
        }

        if (preg_match('#\b(0?[1-9]|1[0-2])[/-](20\d{2})\b#u', $normalizado, $m) === 1) {
            return new self((int) $m[2], (int) $m[1]);
        }

        return null;
    }

    /**
     * Un mes sin año que caería en el futuro pertenece al año pasado.
     *
     * "Agosto" en septiembre es el agosto que pasó. "Diciembre" en enero
     * casi seguro es el diciembre recién terminado, no el que viene
     * dentro de once meses.
     */
    private static function anioMasProbable(int $numero, DateTimeImmutable $hoy): int
    {
        $anio = (int) $hoy->format('Y');

        return $numero > (int) $hoy->format('n') ? $anio - 1 : $anio;
    }

    /** @return array{0:DateTimeImmutable, 1:DateTimeImmutable, 2:string} */
    public function rango(): array
    {
        $primero = (new DateTimeImmutable())
            ->setDate($this->anio, $this->numero, 1)
            ->setTime(0, 0);

        return [$primero, $primero->modify('last day of this month'), $this->etiqueta()];
    }

    public function etiqueta(): string
    {
        return 'en ' . (self::COMO_SE_ESCRIBE[$this->numero] ?? '') . ' de ' . $this->anio;
    }
}
