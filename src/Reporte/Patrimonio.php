<?php

declare(strict_types=1);

namespace Budget\Reporte;

use Budget\Support\Money;

/**
 * Compara dos fotos del portafolio.
 *
 * La trampa de un "mes contra mes" de inversiones es que el total puede
 * subir por dos razones opuestas: porque los precios subieron, o porque
 * el usuario metió plata nueva. Un número que las mezcla no dice nada
 * —comprar $500.000 de CEDEARs no es haber ganado $500.000— y encima
 * halaga, que es la peor combinación en un reporte de plata.
 *
 * Por eso la diferencia se abre en dos, y la descomposición es exacta:
 *
 *     valor_ahora − valor_antes
 *         = cantidad_antes × (precio_ahora − precio_antes)   ← precio
 *         + (cantidad_ahora − cantidad_antes) × precio_ahora ← aporte
 *
 * El rendimiento es la primera parte sobre el valor inicial. La segunda
 * es plata que entró o salió, y no es mérito de nadie.
 *
 * Es una clase de cálculo puro: sin base, sin red, sin fechas. Todo lo
 * que decide el reporte se puede probar en milisegundos.
 */
final class Patrimonio
{
    /**
     * @param array<string,array{simbolo:string, descripcion:string, tipo:string,
     *                           cantidad:float, precio:float, valor:Money}> $ahora
     * @param array<string,array{simbolo:string, descripcion:string, tipo:string,
     *                           cantidad:float, precio:float, valor:Money}> $antes
     *
     * @return array{total:Money, anterior:Money, porPrecio:int, porAporte:int,
     *               rendimiento:?float, posiciones:list<array<string,mixed>>}
     */
    public static function comparar(array $ahora, array $antes): array
    {
        $porPrecio = 0.0;
        $porAporte = 0.0;
        $posiciones = [];

        foreach (self::simbolos($ahora, $antes) as $simbolo) {
            $a = $ahora[$simbolo] ?? null;
            $b = $antes[$simbolo] ?? null;

            $cantidadAntes = $b === null ? 0.0 : $b['cantidad'];
            $cantidadAhora = $a === null ? 0.0 : $a['cantidad'];
            $precioAntes = $b === null ? 0.0 : $b['precio'];
            $precioAhora = $a === null ? $precioAntes : $a['precio'];

            $precio = $cantidadAntes * ($precioAhora - $precioAntes);
            $aporte = ($cantidadAhora - $cantidadAntes) * $precioAhora;

            $porPrecio += $precio;
            $porAporte += $aporte;

            $posiciones[] = [
                'simbolo' => $simbolo,
                'descripcion' => $a['descripcion'] ?? $b['descripcion'] ?? '',
                'valor' => $a['valor'] ?? Money::deCentavos(0),
                'porPrecio' => self::aCentavos($precio),
                'porAporte' => self::aCentavos($aporte),
                // Cerrada: no se sabe a qué precio se vendió, y decir
                // 0,00% sería afirmar algo que el dato no sostiene.
                'variacion' => $a === null
                    ? null
                    : self::porcentaje($precio, $cantidadAntes * $precioAntes),
                'cerrada' => $a === null,
                'nueva' => $b === null,
            ];
        }

        $total = self::sumar($ahora);
        $anterior = self::sumar($antes);

        // El rendimiento se mide sobre lo que había, no sobre lo que hay:
        // dividir por el total de hoy licúa el resultado justo en el mes
        // en que se aportó plata.
        return [
            'total' => $total,
            'anterior' => $anterior,
            'porPrecio' => self::aCentavos($porPrecio),
            'porAporte' => self::aCentavos($porAporte),
            'rendimiento' => self::porcentaje($porPrecio, $anterior->centavos / 100),
            'posiciones' => self::ordenadas($posiciones),
        ];
    }

    /**
     * @param array<string,mixed> $ahora
     * @param array<string,mixed> $antes
     * @return list<string>
     */
    private static function simbolos(array $ahora, array $antes): array
    {
        return array_values(array_unique(array_merge(array_keys($ahora), array_keys($antes))));
    }

    /** @param array<string,array{valor:Money}> $posiciones */
    private static function sumar(array $posiciones): Money
    {
        $total = 0;

        foreach ($posiciones as $p) {
            $total += $p['valor']->centavos;
        }

        return Money::deCentavos($total);
    }

    private static function aCentavos(float $pesos): int
    {
        return (int) round($pesos * 100);
    }

    /** Null cuando no hay base contra la cual medir: 0% sería mentir. */
    private static function porcentaje(float $delta, float $base): ?float
    {
        if ($base <= 0.0) {
            return null;
        }

        return round($delta / $base * 100, 2);
    }

    /**
     * Lo que más movió la aguja primero, en plata y no en porcentaje.
     *
     * Un 40% sobre una posición de $20.000 es ruido al lado de un 2%
     * sobre una de $3.000.000.
     *
     * @param list<array<string,mixed>> $posiciones
     * @return list<array<string,mixed>>
     */
    private static function ordenadas(array $posiciones): array
    {
        usort(
            $posiciones,
            static fn (array $a, array $b): int => abs((int) $b['porPrecio']) <=> abs((int) $a['porPrecio'])
        );

        return $posiciones;
    }
}
