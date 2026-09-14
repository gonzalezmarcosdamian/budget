<?php

declare(strict_types=1);

namespace Budget\Ai;

use Budget\Expense\Draft;
use Budget\Support\Money;
use DateTimeImmutable;

/**
 * Lo que un modelo dice haber entendido de un ticket, un audio o una
 * frase. Todavía no es un gasto: es una propuesta con un número de
 * confianza al lado.
 */
final class Extraction
{
    private function __construct(
        public readonly Money $monto,
        public readonly string $comercio,
        public readonly ?string $fechaIso,
        public readonly ?string $categoria,
        public readonly string $medioPago,
        public readonly float $confianza,
        public readonly string $proveedor,
        public readonly string $modelo,
    ) {
    }

    /**
     * Construye desde el JSON del modelo. Devuelve null si falta lo único
     * que no se puede inventar: el importe.
     *
     * @param array<string,mixed> $datos
     */
    public static function desdeJson(
        array $datos,
        string $proveedor,
        string $modelo,
        string $monedaPorDefecto = Money::MONEDA_POR_DEFECTO
    ): ?self {
        $montoCrudo = $datos['monto'] ?? null;

        if (!is_numeric($montoCrudo) || (float) $montoCrudo <= 0) {
            return null;
        }

        $moneda = is_string($datos['moneda'] ?? null) && $datos['moneda'] !== ''
            ? strtoupper((string) $datos['moneda'])
            : $monedaPorDefecto;

        return new self(
            monto: Money::deDecimal((string) $montoCrudo, $moneda),
            comercio: self::texto($datos['comercio'] ?? ''),
            fechaIso: self::fechaValida($datos['fecha'] ?? null),
            categoria: self::texto($datos['categoria'] ?? '') ?: null,
            medioPago: self::texto($datos['medio_pago'] ?? ''),
            confianza: self::confianza($datos['confianza'] ?? null),
            proveedor: $proveedor,
            modelo: $modelo,
        );
    }

    public function aBorrador(string $fuente, DateTimeImmutable $siNoHayFecha): Draft
    {
        $fecha = $siNoHayFecha;

        if ($this->fechaIso !== null) {
            $parseada = DateTimeImmutable::createFromFormat('Y-m-d', $this->fechaIso);

            if ($parseada !== false) {
                $fecha = $parseada->setTime(0, 0);
            }
        }

        return new Draft(
            monto: $this->monto,
            fecha: $fecha,
            comercio: $this->comercio,
            descripcion: $this->comercio,
            categoria: $this->categoria,
            medioPago: $this->medioPago,
            fuente: $fuente,
            confianza: $this->confianza,
            modelo: $this->proveedor . '/' . $this->modelo,
        );
    }

    private static function texto(mixed $valor): string
    {
        return is_string($valor) ? trim($valor) : '';
    }

    private static function fechaValida(mixed $valor): ?string
    {
        if (!is_string($valor) || $valor === '') {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('Y-m-d', $valor);

        return $fecha !== false && $fecha->format('Y-m-d') === $valor ? $valor : null;
    }

    private static function confianza(mixed $valor): float
    {
        if (!is_numeric($valor)) {
            return 0.5;
        }

        return max(0.0, min(1.0, (float) $valor));
    }
}
