<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
use Budget\Support\Clock;
use Budget\Support\Money;
use Budget\Telegram\Client;
use Budget\Telegram\Update;

/**
 * Los botones de la tarjeta de un gasto.
 *
 * Guardar, descartar y corregir la categoría, más la cola de /revisar,
 * que es la misma corrección aplicada a lo que ya estaba guardado.
 *
 * Vive aparte del Dispatcher, que es un router y estaba haciendo también
 * de manejador. Acá adentro está todo lo que ocurre después de que el
 * usuario toca un botón sobre un movimiento.
 */
final class Tarjetas
{
    public function __construct(
        private readonly Client $telegram,
        private readonly ExpenseRepository $gastos,
        private readonly CategoryRepository $categorias,
        private readonly Reports $reportes,
        private readonly Clock $reloj,
    ) {
    }

    public function confirmar(int $userId, Update $update, int $expenseId): void
    {
        $guardado = $this->gastos->confirmar($userId, $expenseId);

        $this->telegram->responderCallback($update->callbackQueryId, $guardado ? 'Guardado' : 'Ya estaba resuelto');

        if ($guardado) {
            $this->reemplazarTarjeta($userId, $update, $expenseId, '✅');
        }
    }

    public function descartar(int $userId, Update $update, int $expenseId): void
    {
        $this->gastos->descartar($userId, $expenseId);
        $this->telegram->responderCallback($update->callbackQueryId, 'Descartado');
        $this->telegram->editarMensaje($update->chatId, $update->messageId, '🗑 <i>Gasto descartado.</i>');
    }

    public function ofrecerCategorias(int $userId, Update $update, int $expenseId): void
    {
        $this->telegram->responderCallback($update->callbackQueryId);
        $this->telegram->editarMensaje(
            $update->chatId,
            $update->messageId,
            '📁 ¿En qué categoría va?',
            ExpenseCard::tecladoDeCategorias($expenseId, $this->categorias->disponibles($userId))
        );
    }

    /** @param array{accion:string, expenseId:int, valor:int} $accion */
    public function fijarCategoria(int $userId, Update $update, array $accion): void
    {
        $gasto = $this->gastos->porId($userId, $accion['expenseId']);

        if ($gasto === null || $accion['valor'] === 0) {
            $this->telegram->responderCallback($update->callbackQueryId);

            return;
        }

        $yaEstabaConfirmado = ($gasto['estado'] ?? '') === ExpenseRepository::ESTADO_CONFIRMADO;

        $this->gastos->recategorizar($userId, $accion['expenseId'], $accion['valor']);

        // La corrección se vuelve regla: la próxima vez no hay que
        // preguntar ni gastar una llamada de IA.
        $this->categorias->recordarRegla($userId, (string) $gasto['comercio'], $accion['valor']);
        $this->gastos->confirmar($userId, $accion['expenseId']);

        $this->telegram->responderCallback($update->callbackQueryId, 'Guardado');
        $this->reemplazarTarjeta($userId, $update, $accion['expenseId'], '✅');

        // Si lo que se corrigió ya estaba confirmado, esto es limpieza
        // de la cola y no la carga de un gasto nuevo: sigue el próximo.
        if (!$yaEstabaConfirmado) {
            return;
        }

        // `revisar` devuelve texto cuando no hay teclado que mandar, que
        // es justamente el caso de "no queda nada". Descartarlo dejaba
        // la cola sin cierre: el usuario clasificaba el último y no
        // pasaba nada.
        $cierre = $this->revisar($userId, $update->chatId);

        if ($cierre !== '') {
            $this->telegram->enviarMensaje($update->chatId, $cierre);
        }
    }

    private function reemplazarTarjeta(int $userId, Update $update, int $expenseId, string $icono): void
    {
        $gasto = $this->gastos->porId($userId, $expenseId);

        if ($gasto === null) {
            return;
        }

        $monto = Money::deDecimal((string) $gasto['monto'], (string) $gasto['moneda']);
        $comercio = (string) $gasto['comercio'];
        $categoria = (string) ($gasto['categoria'] ?? 'Sin categoría');

        $lineas = [
            sprintf(
                '%s <b>%s</b> — <b>%s</b>',
                $icono,
                ExpenseCard::escapar($comercio !== '' ? $comercio : 'Gasto'),
                ExpenseCard::escapar($monto->formatear())
            ),
            '📁 ' . ExpenseCard::escapar($categoria),
        ];

        // Guardar un gasto sin decir cómo viene el mes deja al usuario
        // con un dato y sin ninguna consecuencia.
        $acumulado = $this->acumuladoDeCategoria($userId, $gasto);

        if ($acumulado !== '') {
            $lineas[] = '';
            $lineas[] = $acumulado;
        }

        $this->telegram->editarMensaje($update->chatId, $update->messageId, implode("\n", $lineas));
    }

    /**
     * Cuánto va del mes en esa categoría, para que confirmar un gasto
     * también informe algo.
     *
     * @param array<string,mixed> $gasto
     */
    private function acumuladoDeCategoria(int $userId, array $gasto): string
    {
        $categoryId = $gasto['category_id'] ?? null;

        if ($categoryId === null) {
            return '';
        }

        $hoy = $this->reloj->ahora();
        $acumulado = $this->gastos->totalDeCategoria(
            $userId,
            (int) $categoryId,
            $hoy->modify('first day of this month'),
            $hoy->modify('last day of this month')
        );

        if ($acumulado->centavos === 0) {
            return '';
        }

        return sprintf(
            '<i>Llevás %s en %s este mes.</i>',
            ExpenseCard::escapar($acumulado->formatear()),
            ExpenseCard::escapar((string) ($gasto['categoria'] ?? 'esa categoría'))
        );
    }

    /**
     * Lo que se dice cuando no se entendió.
     *
     * "No encontré un importe" es un callejón sin salida: no dice qué
     * más se puede hacer. Si el bot no entendió, al menos que muestre
     * las salidas.
     */
    public function revisar(int $userId, int $chatId): string
    {
        // Una sola consulta y no dos: con dos, el texto podía describir
        // un movimiento y los botones llevar el id de otro —hay empates
        // de importe— y la regla que se aprende quedaba mal para
        // siempre, mirando un comercio y guardando otro.
        $siguiente = $this->gastos->sinCategorizar($userId, 1)[0] ?? null;
        $texto = $this->reportes->aCategorizar($userId, $siguiente);

        if ($siguiente === null) {
            return $texto;
        }

        $this->telegram->enviarMensaje(
            $chatId,
            $texto,
            ExpenseCard::tecladoDeCategorias(
                (int) $siguiente['id'],
                // Sin sacar "Otros" el comando entra en bucle: es la
                // categoría que define la cola, así que elegirla deja
                // al movimiento donde estaba y vuelve a salir sorteado.
                array_values(array_filter(
                    $this->categorias->disponibles($userId),
                    static fn (array $c): bool
                        => $c['nombre'] !== ExpenseRepository::CATEGORIA_OTROS
                ))
            )
        );

        return '';
    }

    /**
     * Cómo conectar Mercado Pago, paso a paso.
     *
     * No es un asistente con estado: el token de Mercado Pago empieza
     * con APP_USR- y no se parece a nada más que un usuario escriba, así
     * que el Dispatcher lo reconoce solo cuando llega pegado. Un wizard
     * de verdad necesitaría guardar en qué paso está cada usuario, y
     * para dos pasos no vale la máquina de estados.
     */
}
