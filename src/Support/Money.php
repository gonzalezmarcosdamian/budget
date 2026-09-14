<?php

declare(strict_types=1);

namespace Budget\Support;

use InvalidArgumentException;

/**
 * Importe inmutable, guardado en centavos para no arrastrar errores de
 * coma flotante en las sumas de los reportes.
 *
 * Entiende cómo se escribe la plata en Argentina: punto como separador
 * de miles, coma decimal, y la jerga habitual ("25k", "45 lucas",
 * "2 palos").
 */
final class Money
{
    public const MONEDA_POR_DEFECTO = 'ARS';

    private const CENTAVOS_POR_UNIDAD = 100;

    /** Multiplicadores de jerga, evaluados en orden. */
    private const MULTIPLICADORES = [
        '/\b(?:palos?|millones?|mill[oó]n)\b/u' => 1_000_000,
        '/\b(?:lucas?|mangos? de mil)\b/u' => 1_000,
        '/(?<=\d)\s*k\b/u' => 1_000,
    ];

    private function __construct(
        public readonly int $centavos,
        public readonly string $moneda,
    ) {
    }

    public static function deCentavos(int $centavos, string $moneda = self::MONEDA_POR_DEFECTO): self
    {
        return new self($centavos, strtoupper($moneda));
    }

    /**
     * Acepta lo que devuelve MySQL en un DECIMAL: "18450.00".
     */
    public static function deDecimal(string $decimal, string $moneda = self::MONEDA_POR_DEFECTO): self
    {
        if (!is_numeric($decimal)) {
            throw new InvalidArgumentException("Importe decimal inválido: {$decimal}");
        }

        return new self((int) round(((float) $decimal) * self::CENTAVOS_POR_UNIDAD), strtoupper($moneda));
    }

    /**
     * Extrae un importe de texto libre. Devuelve null si no hay ninguno,
     * que es la señal para que el mensaje pase al motor de IA.
     */
    public static function parsear(string $texto, string $moneda = self::MONEDA_POR_DEFECTO): ?self
    {
        $normalizado = mb_strtolower(trim($texto));

        if ($normalizado === '') {
            return null;
        }

        $numero = self::tokenNumerico($normalizado);

        if ($numero === null) {
            return null;
        }

        $unidades = self::aFloat($numero);
        $multiplicador = self::multiplicador($normalizado);

        $centavos = (int) round($unidades * $multiplicador * self::CENTAVOS_POR_UNIDAD);

        if ($centavos <= 0) {
            return null;
        }

        return new self($centavos, strtoupper($moneda));
    }

    public function mas(self $otro): self
    {
        $this->exigirMismaMoneda($otro);

        return new self($this->centavos + $otro->centavos, $this->moneda);
    }

    public function esMayorQue(self $otro): bool
    {
        $this->exigirMismaMoneda($otro);

        return $this->centavos > $otro->centavos;
    }

    /** Porcentaje que representa este importe sobre otro, 0 si el otro es cero. */
    public function porcentajeDe(self $total): int
    {
        $this->exigirMismaMoneda($total);

        if ($total->centavos === 0) {
            return 0;
        }

        return (int) round($this->centavos * 100 / $total->centavos);
    }

    /** Formato para guardar en un DECIMAL(14,2). */
    public function aDecimal(): string
    {
        return number_format($this->centavos / self::CENTAVOS_POR_UNIDAD, 2, '.', '');
    }

    /** Formato para mostrarle a una persona: $18.450 o $18.450,75 */
    public function formatear(): string
    {
        $unidades = $this->centavos / self::CENTAVOS_POR_UNIDAD;
        $decimales = $this->centavos % self::CENTAVOS_POR_UNIDAD === 0 ? 0 : 2;
        $simbolo = $this->moneda === 'USD' ? 'US$' : '$';

        return $simbolo . number_format($unidades, $decimales, ',', '.');
    }

    private function exigirMismaMoneda(self $otro): void
    {
        if ($otro->moneda !== $this->moneda) {
            throw new InvalidArgumentException(
                "No se pueden operar importes en {$this->moneda} y {$otro->moneda}"
            );
        }
    }

    /**
     * El fragmento exacto de texto que representa el importe. El parser
     * de gastos lo necesita para quitarlo y quedarse con el comercio.
     */
    public static function tokenNumerico(string $texto): ?string
    {
        // Primero los formatos con separador de miles, para que "18.450"
        // no matchee como "18" seguido de basura.
        $patron = '/\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?/u';

        if (preg_match($patron, $texto, $coincidencias) !== 1) {
            return null;
        }

        return $coincidencias[0];
    }

    private static function multiplicador(string $texto): int
    {
        foreach (self::MULTIPLICADORES as $patron => $factor) {
            if (preg_match($patron, $texto) === 1) {
                return $factor;
            }
        }

        return 1;
    }

    /**
     * Resuelve la ambigüedad del punto: seguido de exactamente tres
     * dígitos es separador de miles ("18.450"); en cualquier otro caso
     * es decimal ("1.50").
     */
    private static function aFloat(string $numero): float
    {
        // La coma siempre es decimal: los puntos que la acompañan sólo
        // pueden ser separadores de miles.
        if (str_contains($numero, ',')) {
            return (float) str_replace(',', '.', str_replace('.', '', $numero));
        }

        if (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $numero) === 1) {
            return (float) str_replace('.', '', $numero);
        }

        return (float) $numero;
    }
}
