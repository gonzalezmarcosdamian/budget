<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Ai\Extraction;
use Budget\Ai\Router;
use Budget\Expense\Draft;
use Budget\Expense\FastParser;
use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
use Budget\Repository\UserRepository;
use Budget\Support\Clock;
use Budget\Support\Config;
use Budget\Support\Logger;
use Budget\Support\Money;
use Budget\Telegram\Client;
use Budget\Telegram\Update;

/**
 * Enruta cada update al handler que corresponde.
 *
 * Es el único lugar que conoce el orden de las decisiones: primero quién
 * escribe, después qué mandó. Todo lo que llega de Telegram es dato no
 * confiable hasta que pasa por acá.
 */
final class Dispatcher
{
    public function __construct(
        private readonly Client $telegram,
        private readonly UserRepository $usuarios,
        private readonly ExpenseRepository $gastos,
        private readonly CategoryRepository $categorias,
        private readonly FastParser $parser,
        private readonly Router $ia,
        private readonly Reports $reportes,
        private readonly Config $config,
        private readonly Clock $reloj,
        private readonly Logger $log,
    ) {
    }

    public function despachar(Update $update): void
    {
        if ($update->chatId === 0) {
            return;
        }

        $usuario = $this->usuarios->porChat($update->chatId);

        if ($usuario === null) {
            $this->manejarAlta($update);

            return;
        }

        if ((string) $usuario['estado'] !== 'activo') {
            return;
        }

        $userId = (int) $usuario['id'];

        match (true) {
            $update->tipo === Update::TIPO_CALLBACK => $this->manejarCallback($userId, $update),
            $update->esComando() => $this->manejarComando($userId, $update),
            $update->tipo === Update::TIPO_TEXTO => $this->manejarTexto($userId, $update),
            $update->tipo === Update::TIPO_FOTO => $this->manejarImagen($userId, $update),
            $update->tipo === Update::TIPO_VOZ => $this->manejarAudio($userId, $update),
            default => $this->telegram->enviarMensaje(
                $update->chatId,
                'Todavía no sé leer eso. Mandame texto, una foto del ticket o un audio.'
            ),
        };
    }

    /**
     * Acceso por invitación. Con INVITE_CODE vacío nadie puede darse de
     * alta: es el default seguro para un bot cuyo username es público.
     */
    private function manejarAlta(Update $update): void
    {
        $codigo = $this->config->codigoInvitacion;

        if ($codigo === '' || $update->comando() !== '/start' || $update->argumentos() !== $codigo) {
            $this->telegram->enviarMensaje(
                $update->chatId,
                'Este bot es privado. Si te invitaron, escribí <code>/start TU-CODIGO</code>.'
            );

            return;
        }

        $this->usuarios->crear(
            $update->chatId,
            $update->nombre,
            $this->config->zona->getName(),
            $this->config->monedaBase
        );

        $this->telegram->enviarMensaje(
            $update->chatId,
            "Listo, ya estás dentro.\n\n"
            . "Mandame un gasto como <code>1200 super</code>, una foto del ticket o un audio.\n"
            . 'Con /ayuda ves todo lo que sé hacer.'
        );
    }

    private function manejarComando(int $userId, Update $update): void
    {
        $hoy = $this->reloj->ahora();

        $respuesta = match ($update->comando()) {
            '/start', '/ayuda' => $this->ayuda(),
            '/hoy' => $this->reportes->delDia($userId, $hoy),
            '/mes' => $this->reportes->delMes($userId, $hoy),
            '/ultimos' => $this->reportes->ultimos($userId),
            default => 'No conozco ese comando. Probá /ayuda.',
        };

        $this->telegram->enviarMensaje($update->chatId, $respuesta);
    }

    private function manejarTexto(int $userId, Update $update): void
    {
        $rapido = $this->parser->parsear($update->texto);
        $borradores = $rapido !== null ? [$rapido] : $this->extraerConIa($userId, $update);

        if ($borradores === []) {
            $this->telegram->enviarMensaje(
                $update->chatId,
                'No encontré un importe ahí. Probá con algo como <code>1200 super</code>.'
            );

            return;
        }

        $this->proponerVarios($userId, $update->chatId, $borradores);
    }

    private function manejarImagen(int $userId, Update $update): void
    {
        $this->desdeArchivo(
            $userId,
            $update,
            'image/jpeg',
            Draft::FUENTE_FOTO,
            fn (string $binario, string $mime): mixed => $this->ia->imagen($userId, $binario, $mime, $update->texto)
        );
    }

    private function manejarAudio(int $userId, Update $update): void
    {
        // Telegram entrega OGG/Opus. Los proveedores lo aceptan tal cual,
        // así que no hace falta ffmpeg, que en hosting compartido no hay.
        $this->desdeArchivo(
            $userId,
            $update,
            'audio/ogg',
            Draft::FUENTE_VOZ,
            fn (string $binario, string $mime): mixed => $this->ia->audio($userId, $binario, $mime)
        );
    }

    /** @param callable(string,string): list<Extraction> $extraer */
    private function desdeArchivo(
        int $userId,
        Update $update,
        string $mimeType,
        string $fuente,
        callable $extraer,
    ): void {
        if (!$this->puedeUsarIa($userId, $update->chatId)) {
            return;
        }

        $this->telegram->enviarAccion($update->chatId);

        try {
            $binario = $this->telegram->descargarArchivo($update->fileId);
            $extracciones = $extraer($binario, $mimeType);
        } catch (\Throwable $e) {
            $this->log->excepcion($e, 'extracción desde archivo');
            $this->telegram->enviarMensaje(
                $update->chatId,
                'No pude procesar eso. Probá de nuevo o cargalo como texto.'
            );

            return;
        }

        if ($extracciones === []) {
            $this->telegram->enviarMensaje(
                $update->chatId,
                'No encontré un gasto ahí. Si el ticket salió borroso, probá otra foto.'
            );

            return;
        }

        $ahora = $this->reloj->ahora();
        $borradores = array_map(
            static fn (Extraction $e): Draft => $e->aBorrador($fuente, $ahora),
            $extracciones
        );

        $this->proponerVarios($userId, $update->chatId, $borradores);
    }

    /** @return list<Draft> */
    private function extraerConIa(int $userId, Update $update): array
    {
        if (!$this->ia->hayProveedores() || !$this->puedeUsarIa($userId, $update->chatId)) {
            return [];
        }

        $this->telegram->enviarAccion($update->chatId);
        $ahora = $this->reloj->ahora();

        return array_map(
            static fn (Extraction $e): Draft => $e->aBorrador(Draft::FUENTE_TEXTO, $ahora),
            $this->ia->texto($userId, $update->texto)
        );
    }

    /**
     * Un mensaje puede describir varios gastos. Se avisa cuántos antes de
     * mandar las tarjetas: si no, aparecen tres mensajes seguidos sin
     * explicación y parece que el bot se trabó.
     *
     * @param list<Draft> $borradores
     */
    private function proponerVarios(int $userId, int $chatId, array $borradores): void
    {
        if (count($borradores) > 1) {
            $this->telegram->enviarMensaje(
                $chatId,
                sprintf('Encontré <b>%d gastos</b> en ese mensaje. Confirmá los que estén bien:', count($borradores))
            );
        }

        foreach ($borradores as $borrador) {
            $this->proponer($userId, $chatId, $borrador);
        }
    }

    private function puedeUsarIa(int $userId, int $chatId): bool
    {
        $periodo = $this->reloj->ahora()->format('Y-m');

        if ($this->usuarios->consumirCupoIa($userId, $this->config->cuotaMensualIa, $periodo)) {
            return true;
        }

        $this->telegram->enviarMensaje(
            $chatId,
            "Se te acabó el cupo de IA de este mes.\n"
            . 'Podés seguir cargando gastos por texto, como <code>1200 super</code>.'
        );

        return false;
    }

    private function proponer(int $userId, int $chatId, Draft $borrador): void
    {
        $categoryId = $this->resolverCategoria($userId, $borrador);
        $expenseId = $this->gastos->guardarBorrador($userId, $borrador, $categoryId);

        $emoji = $categoryId === null ? '' : $this->categorias->emoji($categoryId);

        $messageId = $this->telegram->enviarMensaje(
            $chatId,
            ExpenseCard::texto($borrador, $emoji),
            ExpenseCard::teclado($expenseId)
        );

        $this->gastos->vincularMensaje($userId, $expenseId, $messageId);
    }

    /** La regla propia del usuario gana sobre cualquier palabra clave general. */
    private function resolverCategoria(int $userId, Draft $borrador): ?int
    {
        $porRegla = $this->categorias->categoriaPorRegla($userId, $borrador->comercio);

        if ($porRegla !== null) {
            return $porRegla;
        }

        return $borrador->categoria === null
            ? null
            : $this->categorias->idPorNombre($userId, $borrador->categoria);
    }

    private function manejarCallback(int $userId, Update $update): void
    {
        $accion = ExpenseCard::decodificar($update->callbackData);

        if ($accion === null) {
            $this->telegram->responderCallback($update->callbackQueryId);

            return;
        }

        match ($accion['accion']) {
            ExpenseCard::ACCION_CONFIRMAR => $this->confirmar($userId, $update, $accion['expenseId']),
            ExpenseCard::ACCION_DESCARTAR => $this->descartar($userId, $update, $accion['expenseId']),
            ExpenseCard::ACCION_ELEGIR_CATEGORIA => $this->ofrecerCategorias($userId, $update, $accion['expenseId']),
            ExpenseCard::ACCION_FIJAR_CATEGORIA => $this->fijarCategoria($userId, $update, $accion),
            default => $this->telegram->responderCallback($update->callbackQueryId),
        };
    }

    private function confirmar(int $userId, Update $update, int $expenseId): void
    {
        $guardado = $this->gastos->confirmar($userId, $expenseId);

        $this->telegram->responderCallback($update->callbackQueryId, $guardado ? 'Guardado' : 'Ya estaba resuelto');

        if ($guardado) {
            $this->reemplazarTarjeta($userId, $update, $expenseId, '✅');
        }
    }

    private function descartar(int $userId, Update $update, int $expenseId): void
    {
        $this->gastos->descartar($userId, $expenseId);
        $this->telegram->responderCallback($update->callbackQueryId, 'Descartado');
        $this->telegram->editarMensaje($update->chatId, $update->messageId, '🗑 <i>Gasto descartado.</i>');
    }

    private function ofrecerCategorias(int $userId, Update $update, int $expenseId): void
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
    private function fijarCategoria(int $userId, Update $update, array $accion): void
    {
        $gasto = $this->gastos->porId($userId, $accion['expenseId']);

        if ($gasto === null || $accion['valor'] === 0) {
            $this->telegram->responderCallback($update->callbackQueryId);

            return;
        }

        $this->gastos->recategorizar($userId, $accion['expenseId'], $accion['valor']);

        // La corrección se vuelve regla: la próxima vez no hay que
        // preguntar ni gastar una llamada de IA.
        $this->categorias->recordarRegla($userId, (string) $gasto['comercio'], $accion['valor']);
        $this->gastos->confirmar($userId, $accion['expenseId']);

        $this->telegram->responderCallback($update->callbackQueryId, 'Guardado');
        $this->reemplazarTarjeta($userId, $update, $accion['expenseId'], '✅');
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

        $this->telegram->editarMensaje(
            $update->chatId,
            $update->messageId,
            sprintf(
                '%s <b>%s</b> — <b>%s</b>%s%s',
                $icono,
                ExpenseCard::escapar($comercio !== '' ? $comercio : 'Gasto'),
                ExpenseCard::escapar($monto->formatear()),
                "\n",
                '📁 ' . ExpenseCard::escapar($categoria)
            )
        );
    }

    private function ayuda(): string
    {
        return implode("\n", [
            '<b>Cómo cargar un gasto</b>',
            '',
            '• Texto: <code>1200 super</code>, <code>nafta 25k</code>, <code>ayer 45 lucas de prepaga</code>',
            '• Foto del ticket o captura de la app',
            '• Nota de voz',
            '',
            '<b>Comandos</b>',
            '',
            '/hoy — total del día',
            '/mes — total del mes por categoría',
            '/ultimos — los últimos 10 gastos',
            '/ayuda — esto',
        ]);
    }
}
