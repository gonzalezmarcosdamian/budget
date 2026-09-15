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
    esIgual('Contraparte', MercadoPago::nombreLegible('CONTRAPARTE20220203174229'));
    esIgual('Limpieza', MercadoPago::nombreLegible('LIMPIEZA20230118185650'));
    esIgual('Hermano', MercadoPago::nombreLegible('HERMANO1285147'));
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

prueba('una transferencia recibida es ingreso', function (): void {
    $b = MercadoPago::aIngreso(pagoMp([
        'operation_type' => 'money_transfer',
        'transaction_amount' => 50000,
        'description' => 'Varios',
    ]));

    noEsNulo($b);
    esIgual(Draft::TIPO_INGRESO, $b?->tipo);
    esIgual(5_000_000, $b?->monto->centavos);
});

prueba('cargar saldo o rescatar una inversión no son ingresos', function (): void {
    // Es plata propia volviendo. Contarla como ingreso duplicaría el
    // patrimonio: ya estaba, sólo cambió de lugar.
    foreach (['account_fund', 'investment', 'partition_transfer', 'regular_payment'] as $tipo) {
        esNulo(MercadoPago::aIngreso(pagoMp(['operation_type' => $tipo])), $tipo);
    }
});

prueba('la contraparte de un cobro es quien pago, no quien cobro', function (): void {
    // Este fue un error de concepto con consecuencias: para los cobros
    // se usaba el `collector`, que en un cobro soy yo. Resultado: los
    // 171 ingresos quedaron sin contraparte y el neteo por persona
    // —que el usuario pidió explícitamente— no neteaba nada.
    $cobro = pagoMp([
        'operation_type' => 'money_transfer',
        'transaction_amount' => 120000,
        'payer' => ['id' => 987654321],
        'collector' => ['id' => 111111111],
    ]);

    esIgual('987654321', MercadoPago::quienPago($cobro), 'la contraparte es el pagador');
});

prueba('sin pagador identificable no se inventa una contraparte', function (): void {
    esNulo(MercadoPago::quienPago(pagoMp([
        'operation_type' => 'money_transfer',
        'payer' => ['id' => ''],
    ])), 'id vacío');

    esNulo(MercadoPago::quienPago(pagoMp([
        'operation_type' => 'money_transfer',
    ])), 'sin payer');

    // Cargar saldo no viene de nadie: agrupar eso como contraparte
    // mezclaría plata propia con transferencias de terceros.
    esNulo(MercadoPago::quienPago(pagoMp([
        'operation_type' => 'account_fund',
        'payer' => ['id' => 987654321],
    ])), 'no es una transferencia');
});
