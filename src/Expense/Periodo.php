<?php

declare(strict_types=1);

namespace Budget\Expense;

use DateTimeImmutable;

/**
 * Los tres períodos sobre los que el bot informa.
 *
 * Existen como una sola pieza porque los comandos de ranking —top
 * gastos, top entrantes, top salientes— tienen que poder correr sobre
 * cualquiera de los tres sin repetir el cálculo de fechas en cada uno.
 *
 * El trimestre son tres meses **calendario**, no noventa días: el mes es
 * la unidad en la que la gente piensa la plata, y un corte a noventa
 * días parte julio al medio y vuelve incomparable el número.
 */
final class Periodo
{
    public const MES = 'mes';
    public const TRIMESTRE = 'trimestre';
    public const ANIO = 'anio';

    private function __construct(
        public readonly DateTimeImmutable $desde,
        public readonly DateTimeImmutable $hasta,
        public readonly string $etiqueta,
    ) {
    }

    public static function desde(string $cual, DateTimeImmutable $hoy): self
    {
        return match ($cual) {
            self::TRIMESTRE => new self(
                $hoy->modify('first day of this month')->modify('-2 months')->setTime(0, 0),
                $hoy->modify('last day of this month')->setTime(0, 0),
                'los últimos 3 meses'
            ),
            self::ANIO => new self(
                // Los últimos doce meses y no el año calendario: en
                // febrero, "el último año" con corte en enero serían seis
                // semanas, y eso no es lo que nadie quiere ver.
                $hoy->modify('first day of this month')->modify('-11 months')->setTime(0, 0),
                $hoy->modify('last day of this month')->setTime(0, 0),
                'los últimos 12 meses'
            ),
            default => new self(
                $hoy->modify('first day of this month')->setTime(0, 0),
                $hoy->modify('last day of this month')->setTime(0, 0),
                'este mes'
            ),
        };
    }

    /** Cuántos meses abarca, para promediar sin contar de más. */
    public function meses(): int
    {
        $inicio = (int) $this->desde->format('Y') * 12 + (int) $this->desde->format('n');
        $fin = (int) $this->hasta->format('Y') * 12 + (int) $this->hasta->format('n');

        return $fin - $inicio + 1;
    }
}
