<?php

declare(strict_types=1);

namespace Budget\Integracion;

use Budget\Expense\Draft;
use Budget\Support\Http;
use Budget\Support\Money;
use DateTimeImmutable;
use RuntimeException;

/**
 * Lectura de los pagos propios en Mercado Pago.
 *
 * La API de MP está pensada para cobrar, pero `payments/search` filtrado
 * por `payer.id` devuelve lo que uno pagó. Está verificado contra la
 * cuenta real: 550 pagos, el más reciente del mismo día.
 *
 * Es la única fuente de gastos en tiempo real que no depende de scrapear
 * nada ni de guardar credenciales bancarias.
 */
final class MercadoPago
{
    private const BASE = 'https://api.mercadopago.com';

    /**
     * Qué movimiento es un gasto y cuál es plata propia cambiando de
     * lugar. La clasificación sale de mirar 50 movimientos reales, no de
     * la documentación.
     */
    private const GASTOS = [
        // Compras de verdad: traen el comercio en la descripción.
        'regular_payment' => 0.95,
        // Transferencias salientes. Son plata que se va, pero la
        // descripción es siempre "Varios": sin comercio que mostrar, así
        // que van con confianza baja para que el usuario las mire.
        'money_transfer' => 0.55,
    ];

    /**
     * Movimientos que NO son gastos: es plata del usuario moviéndose
     * entre sus propios bolsillos. Contarlos infla el total y hace que
     * el reporte mensual mienta.
     */
    private const NO_SON_GASTOS = [
        'account_fund',        // cargar saldo
        'partition_transfer',  // alcancías
        'investment',          // invertir el saldo
    ];

    public function __construct(
        private readonly string $accessToken,
        private readonly int $mpUserId,
        private readonly Http $http,
    ) {
    }

    /**
     * Los pagos aprobados desde una fecha, más nuevos primero.
     *
     * @return list<array<string,mixed>>
     */
    public function pagosDesde(DateTimeImmutable $desde, int $limite = 50, int $offset = 0): array
    {
        $url = self::BASE . '/v1/payments/search?' . http_build_query([
            'payer.id' => $this->mpUserId,
            'status' => 'approved',
            'sort' => 'date_created',
            'criteria' => 'desc',
            'limit' => max(1, min($limite, 100)),
            'offset' => max(0, $offset),
            'range' => 'date_created',
            'begin_date' => $desde->format('Y-m-d\TH:i:s.000P'),
            'end_date' => 'NOW',
        ]);

        $respuesta = $this->http->getJson($url, ['Authorization: Bearer ' . $this->accessToken]);
        $resultados = $respuesta['results'] ?? null;

        if (!is_array($resultados)) {
            throw new RuntimeException('Mercado Pago no devolvió resultados');
        }

        return array_values(array_filter($resultados, 'is_array'));
    }

    /** Identificador estable del pago, para no importarlo dos veces. */
    public static function referencia(array $pago): string
    {
        return 'mp:' . (string) ($pago['id'] ?? '');
    }

    /**
     * Convierte un pago en un borrador de gasto.
     *
     * Devuelve null cuando el movimiento no es un gasto, que es la mitad
     * de los casos.
     *
     * @param array<string,mixed> $pago
     */
    public static function aBorrador(array $pago): ?Draft
    {
        $tipo = (string) ($pago['operation_type'] ?? '');

        if (in_array($tipo, self::NO_SON_GASTOS, true) || !isset(self::GASTOS[$tipo])) {
            return null;
        }

        $monto = $pago['transaction_amount'] ?? null;

        if (!is_numeric($monto) || (float) $monto <= 0) {
            return null;
        }

        $fecha = self::fecha($pago);

        if ($fecha === null) {
            return null;
        }

        $comercio = self::comercio($pago, $tipo);

        return new Draft(
            monto: Money::deDecimal((string) $monto, (string) ($pago['currency_id'] ?? 'ARS')),
            fecha: $fecha,
            comercio: $comercio,
            descripcion: $comercio,
            categoria: null,
            medioPago: self::medioDePago($pago),
            fuente: Draft::FUENTE_API,
            confianza: self::GASTOS[$tipo],
            modelo: 'mercadopago',
        );
    }

    /** @param array<string,mixed> $pago */
    private static function comercio(array $pago, string $tipo): string
    {
        foreach (['description', 'statement_descriptor'] as $campo) {
            $valor = trim((string) ($pago[$campo] ?? ''));

            // "Varios" es lo que MP pone en toda transferencia: no dice
            // nada y ensucia el nombre del gasto.
            if ($valor !== '' && mb_strtolower($valor) !== 'varios') {
                return mb_substr($valor, 0, 160);
            }
        }

        return $tipo === 'money_transfer' ? 'Transferencia' : 'Pago con Mercado Pago';
    }

    /** @param array<string,mixed> $pago */
    private static function medioDePago(array $pago): string
    {
        return match ((string) ($pago['payment_type_id'] ?? '')) {
            'account_money' => 'Mercado Pago',
            'credit_card' => 'Crédito',
            'debit_card' => 'Débito',
            'bank_transfer' => 'Transferencia',
            'ticket' => 'Efectivo',
            default => 'Mercado Pago',
        };
    }

    /** @param array<string,mixed> $pago */
    private static function fecha(array $pago): ?DateTimeImmutable
    {
        foreach (['date_approved', 'date_created'] as $campo) {
            $crudo = (string) ($pago[$campo] ?? '');

            if ($crudo === '') {
                continue;
            }

            try {
                return (new DateTimeImmutable($crudo))->setTime(0, 0);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
