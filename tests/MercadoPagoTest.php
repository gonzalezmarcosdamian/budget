<?php

declare(strict_types=1);

use Budget\Expense\Draft;
use Budget\Integracion\MercadoPago;

/**
 * El mapeo sale de mirar 50 movimientos reales de la cuenta, no de la
 * documentación de Mercado Pago. Estos casos son esos movimientos.
 */

function pagoMp(array $extra = []): array
{
    return array_merge([
        'id' => 123456789,
        'operation_type' => 'regular_payment',
        'status' => 'approved',
        'transaction_amount' => 18450.5,
        'currency_id' => 'ARS',
        'payment_type_id' => 'account_money',
        'description' => 'Compra en Carrefour - Suc. 767',
        'date_approved' => '2026-09-14T10:30:00.000-03:00',
    ], $extra);
}

prueba('una compra se convierte en gasto', function (): void {
    $b = MercadoPago::aBorrador(pagoMp());

    noEsNulo($b);
    esIgual(1_845_050, $b?->monto->centavos);
    esIgual('Compra en Carrefour - Suc. 767', $b?->comercio);
    esIgual('2026-09-14', $b?->fecha->format('Y-m-d'));
    esIgual('Mercado Pago', $b?->medioPago);
    esIgual(Draft::FUENTE_API, $b?->fuente);
});

prueba('cargar saldo no es un gasto', function (): void {
    // Es plata del usuario cambiando de bolsillo. Contarla infla el
    // total y hace que el reporte mensual mienta.
    esNulo(MercadoPago::aBorrador(pagoMp(['operation_type' => 'account_fund'])));
});

prueba('las alcancías y las inversiones tampoco', function (): void {
    esNulo(MercadoPago::aBorrador(pagoMp(['operation_type' => 'partition_transfer'])), 'alcancía');
    esNulo(MercadoPago::aBorrador(pagoMp(['operation_type' => 'investment'])), 'inversión');
});

prueba('un tipo de movimiento desconocido se descarta', function (): void {
    // Ante algo que MP agregue mañana, no inventar: mejor perder un
    // gasto que cargar basura en el total.
    esNulo(MercadoPago::aBorrador(pagoMp(['operation_type' => 'algo_nuevo'])));
});

prueba('una transferencia es gasto pero con confianza baja', function (): void {
    $b = MercadoPago::aBorrador(pagoMp([
        'operation_type' => 'money_transfer',
        'description' => 'Varios',
    ]));

    noEsNulo($b);
    esIgual('Transferencia', $b?->comercio, '"Varios" no dice nada, no sirve de comercio');
    afirmar(($b?->confianza ?? 1.0) < 0.7, 'baja, para que el usuario la revise');
});

prueba('cae al descriptor cuando no hay descripción', function (): void {
    $b = MercadoPago::aBorrador(pagoMp([
        'description' => '',
        'statement_descriptor' => 'ECOGAS',
    ]));

    esIgual('ECOGAS', $b?->comercio);
});

prueba('sin ningún nombre usable queda una etiqueta genérica', function (): void {
    $b = MercadoPago::aBorrador(pagoMp(['description' => '', 'statement_descriptor' => '']));

    esIgual('Pago con Mercado Pago', $b?->comercio);
});

prueba('traduce el medio de pago', function (): void {
    esIgual('Crédito', MercadoPago::aBorrador(pagoMp(['payment_type_id' => 'credit_card']))?->medioPago);
    esIgual('Transferencia', MercadoPago::aBorrador(pagoMp(['payment_type_id' => 'bank_transfer']))?->medioPago);
    esIgual('Efectivo', MercadoPago::aBorrador(pagoMp(['payment_type_id' => 'ticket']))?->medioPago);
});

prueba('un importe inválido no produce gasto', function (): void {
    esNulo(MercadoPago::aBorrador(pagoMp(['transaction_amount' => 0])), 'cero');
    esNulo(MercadoPago::aBorrador(pagoMp(['transaction_amount' => null])), 'nulo');
});

prueba('sin fecha usable se descarta', function (): void {
    esNulo(MercadoPago::aBorrador(pagoMp(['date_approved' => '', 'date_created' => ''])));
});

prueba('la referencia del pago es estable', function (): void {
    // Es lo que evita importar dos veces el mismo movimiento.
    esIgual('mp:123456789', MercadoPago::referencia(pagoMp()));
});

prueba('limpia el apodo autogenerado de Mercado Pago', function (): void {
    // MP arma el apodo con apellido + fecha de registro pegados.
    esIgual('Delpascual', MercadoPago::nombreLegible('DELPASCUAL20220203174229'));
    esIgual('Nietomaria', MercadoPago::nombreLegible('NIETOMARIA20230118185650'));
    esIgual('Goma', MercadoPago::nombreLegible('GOMA1285147'));
});

prueba('respeta un apodo elegido por la persona', function (): void {
    // El guión bajo cuenta como separador de palabras, así que cada
    // parte queda capitalizada. Es legible y no hace falta más.
    esIgual('Chipi_Mdg', MercadoPago::nombreLegible('CHIPI_MDG'));
});

prueba('un apodo que es sólo números queda como está', function (): void {
    // Sacarle los dígitos dejaría la cadena vacía: mejor el original
    // que un fragmento sin sentido.
    esIgual('123456789', MercadoPago::nombreLegible('123456789'));
    esIgual('AB99999', MercadoPago::nombreLegible('AB99999'), 'dos letras no alcanzan');
});
