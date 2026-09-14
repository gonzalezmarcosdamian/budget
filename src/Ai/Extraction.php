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
    /**
     * Tope de gastos por mensaje. Existe para que un modelo que se
     * desboca no produzca cincuenta tarjetas en el chat.
     */
    private const MAXIMO_POR_MENSAJE = 10;

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

    /**
     * Los gastos de un mensaje. Un mensaje puede describir varios:
     * "30 mil de estacionamiento y 200 de entradas" son dos.
     *
     * Tolera que el modelo devuelva un objeto suelto en vez del
     * envoltorio: pasa cuando hay un único gasto.
     *
     * @param array<string,mixed> $datos
     * @return list<self>
     */
    public static function variasDesdeJson(
        array $datos,
        string $proveedor,
        string $modelo,
        string $monedaPorDefecto = Money::MONEDA_POR_DEFECTO
    ): array {
        $crudos = $datos['gastos'] ?? null;

        if (!is_array($crudos)) {
            $crudos = [$datos];
        }

        $extracciones = [];

        foreach ($crudos as $crudo) {
            if (!is_array($crudo)) {
                continue;
            }

            $una = self::desdeJson($crudo, $proveedor, $modelo, $monedaPorDefecto);

            if ($una !== null) {
                $extracciones[] = $una;
            }

            if (count($extracciones) >= self::MAXIMO_POR_MENSAJE) {
                break;
            }
        }

        return $extracciones;
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
