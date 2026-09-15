<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Ai\Extraction;
use Budget\Ai\Router;
use Budget\Expense\Draft;
use Budget\Expense\CategoryGuesser;
use Budget\Expense\FastParser;
use Budget\Expense\Pregunta;
use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
use Budget\Repository\RecurringRepository;
use Budget\Repository\UserRepository;
use Budget\Support\Clock;
use Budget\Support\Config;
use Budget\Support\Logger;
use Budget\Support\Money;
use Budget\Telegram\Client;
use Budget\Telegram\Menu;
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
        private readonly RecurringRepository $recurrentes,
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
            $update->tipo === Update::TIPO_DOCUMENTO => $this->manejarResumen($userId, $update),
            default => $this->telegram->enviarMensaje(
                $update->chatId,
                'Todavía no sé leer eso. Mandame texto, una foto del ticket, un audio '
                . 'o el PDF del resumen de la tarjeta.'
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
            '/ingresos' => $this->reportes->ingresos($userId, $hoy),
            '/anio', '/año' => $this->reportes->delAnio($userId, $hoy),
            '/recurrentes' => $this->reportes->recurrentes($userId),
            default => 'No conozco ese comando. Probá /ayuda.',
        };

        $this->telegram->enviarMensaje($update->chatId, $respuesta);
    }

    private function manejarTexto(int $userId, Update $update): void
    {
        $rapido = $this->parser->parsear($update->texto);

        // Antes de intentar extraer un gasto: puede ser una pregunta
        // sobre lo que ya está cargado, y contestarla es gratis.
        if ($rapido === null) {
            $pregunta = Pregunta::desde($update->texto, new CategoryGuesser());

            if ($pregunta !== null) {
                $this->telegram->enviarMensaje(
                    $update->chatId,
                    $this->reportes->responder($userId, $pregunta, $this->reloj->ahora())
                );

                return;
            }
        }

        $borradores = $rapido !== null ? [$rapido] : $this->extraerConIa($userId, $update);

        if ($borradores === []) {
            $this->telegram->enviarMensaje($update->chatId, self::noEntendi());

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

    /**
     * Un PDF adjunto es el resumen de la tarjeta: trae el mes entero.
     *
     * No pasa por desdeArchivo() porque el resultado se trata distinto:
     * decenas de consumos que se confirman en bloque, no uno por uno.
     */
    private function manejarResumen(int $userId, Update $update): void
    {
        if (!str_contains($update->mimeType, 'pdf')) {
            $this->telegram->enviarMensaje(
                $update->chatId,
                'Por ahora sólo sé leer el resumen de tarjeta en PDF.'
            );

            return;
        }

        if (!$this->puedeUsarIa($userId, $update->chatId)) {
            return;
        }

        $this->telegram->enviarMensaje(
            $update->chatId,
            '📄 Leyendo el resumen. Puede tardar un minuto.'
        );

        try {
            $binario = $this->telegram->descargarArchivo($update->fileId);
            $extracciones = $this->ia->resumen($userId, $binario, 'application/pdf');
        } catch (\Throwable $e) {
            $this->log->excepcion($e, 'lectura de resumen');
            $this->telegram->enviarMensaje(
                $update->chatId,
                'No pude leer ese archivo. Si pesa mucho, probá con el resumen de un solo mes.'
            );

            return;
        }

        if ($extracciones === []) {
            // La causa más común, y la que el usuario puede resolver: los
            // resúmenes argentinos suelen venir cifrados con el DNI.
            $this->telegram->enviarMensaje(
                $update->chatId,
                "No encontré consumos en ese PDF.\n\n"
                . 'Si está protegido con contraseña, abrilo, guardalo sin contraseña y mandámelo de nuevo.'
            );

            return;
        }

        $this->importarLote($userId, $update->chatId, $extracciones);
    }

    /**
     * Guarda los consumos del resumen como un lote y propone confirmarlo
     * entero. Los que el usuario ya había cargado a mano se saltean: un
     * duplicado arruina el total sin que se note.
     *
     * @param list<Extraction> $extracciones
     */
    private function importarLote(int $userId, int $chatId, array $extracciones): void
    {
        $lote = bin2hex(random_bytes(6));
        $ahora = $this->reloj->ahora();
        $guardados = 0;
        $salteados = 0;
        $fechas = [];

        foreach ($extracciones as $extraccion) {
            $borrador = $extraccion->aBorrador(Draft::FUENTE_API, $ahora);

            if ($this->gastos->yaExiste($userId, $borrador->fecha, $borrador->monto->aDecimal())) {
                $salteados++;

                continue;
            }

            $this->gastos->guardarBorrador(
                $userId,
                $borrador,
                $this->resolverCategoria($userId, $borrador),
                $lote
            );

            $fechas[] = $borrador->fecha->format('d/m');
            $guardados++;
        }

        if ($guardados === 0) {
            $this->telegram->enviarMensaje(
                $chatId,
                sprintf('Ese resumen no trae nada nuevo: los %d consumos ya estaban cargados.', $salteados)
            );

            return;
        }

        $resumen = $this->gastos->resumenDeLote($userId, $lote);

        $this->telegram->enviarMensaje(
            $chatId,
            ExpenseCard::textoDeLote(
                $resumen['cantidad'],
                $resumen['total'],
                $salteados,
                $fechas === [] ? '' : (string) reset($fechas),
                $fechas === [] ? '' : (string) end($fechas)
            ),
            ExpenseCard::tecladoDeLote($lote, $resumen['cantidad'])
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
        if (ExpenseCard::esAccionDeLote($update->callbackData)) {
            $this->manejarCallbackDeLote($userId, $update);

            return;
        }

        if (Recordatorios::esAccion($update->callbackData)) {
            $this->manejarRecurrente($userId, $update);

            return;
        }

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

    private function manejarCallbackDeLote(int $userId, Update $update): void
    {
        $accion = ExpenseCard::decodificarLote($update->callbackData);

        if ($accion === null) {
            $this->telegram->responderCallback($update->callbackQueryId);

            return;
        }

        $lote = $accion['lote'];

        match ($accion['accion']) {
            ExpenseCard::ACCION_LOTE_CONFIRMAR => $this->confirmarLote($userId, $update, $lote),
            ExpenseCard::ACCION_LOTE_DESCARTAR => $this->descartarLote($userId, $update, $lote),
            ExpenseCard::ACCION_LOTE_DETALLE => $this->mostrarLote($userId, $update, $lote),
            default => $this->telegram->responderCallback($update->callbackQueryId),
        };
    }

    /**
     * El usuario responde al recordatorio de un gasto que se repite.
     *
     * Cargarlo lo da por hecho con el monto conocido; saltearlo corre el
     * aviso al mes que viene. En los dos casos el recordatorio no vuelve
     * a aparecer este mes.
     */
    private function manejarRecurrente(int $userId, Update $update): void
    {
        $accion = ExpenseCard::decodificar($update->callbackData);
        $r = $accion === null ? null : $this->recurrentes->porId($userId, $accion['expenseId']);

        if ($accion === null || $r === null) {
            $this->telegram->responderCallback($update->callbackQueryId);

            return;
        }

        $ahora = $this->reloj->ahora();
        $this->recurrentes->posponerAlMesQueViene($userId, (int) $r['id'], $ahora);

        if ($accion['accion'] === Recordatorios::ACCION_SALTEAR) {
            $this->telegram->responderCallback($update->callbackQueryId, 'Salteado');
            $this->telegram->editarMensaje(
                $update->chatId,
                $update->messageId,
                sprintf('⏭ <i>%s: salteado este mes.</i>', ExpenseCard::escapar((string) $r['comercio']))
            );

            return;
        }

        $monto = Money::deDecimal((string) $r['monto_esperado']);

        $borrador = new Draft(
            monto: $monto,
            fecha: $ahora,
            comercio: (string) $r['comercio'],
            descripcion: (string) $r['comercio'],
            categoria: $r['categoria'] === null ? null : (string) $r['categoria'],
            medioPago: '',
            fuente: Draft::FUENTE_MANUAL,
            confianza: 1.0,
            modelo: 'recurrente',
            tipo: Draft::TIPO_GASTO,
            naturaleza: (string) ($r['naturaleza'] ?? Draft::NATURALEZA_FIJO),
        );

        $this->gastos->guardarBorrador(
            $userId,
            $borrador,
            $r['category_id'] === null ? null : (int) $r['category_id'],
            null,
            sprintf('recurrente:%d:%s', (int) $r['id'], $ahora->format('Y-m')),
            ExpenseRepository::ESTADO_CONFIRMADO
        );

        $this->telegram->responderCallback($update->callbackQueryId, 'Cargado');
        $this->telegram->editarMensaje(
            $update->chatId,
            $update->messageId,
            sprintf(
                "✅ <b>%s</b> — <b>%s</b>\n📁 %s",
                ExpenseCard::escapar((string) $r['comercio']),
                ExpenseCard::escapar($monto->formatear()),
                ExpenseCard::escapar((string) ($r['categoria'] ?? 'Sin categoría'))
            )
        );
    }

    private function confirmarLote(int $userId, Update $update, string $lote): void
    {
        $resumen = $this->gastos->resumenDeLote($userId, $lote);
        $cuantos = $this->gastos->confirmarLote($userId, $lote);

        $this->telegram->responderCallback(
            $update->callbackQueryId,
            $cuantos > 0 ? "Guardados {$cuantos}" : 'Ya estaba resuelto'
        );

        if ($cuantos === 0) {
            return;
        }

        $this->telegram->editarMensaje(
            $update->chatId,
            $update->messageId,
            sprintf(
                "✅ <b>Resumen importado</b>\n\n%d consumos — <b>%s</b>",
                $cuantos,
                ExpenseCard::escapar($resumen['total']->formatear())
            )
        );
    }

    private function descartarLote(int $userId, Update $update, string $lote): void
    {
        $cuantos = $this->gastos->descartarLote($userId, $lote);

        $this->telegram->responderCallback($update->callbackQueryId, 'Descartado');
        $this->telegram->editarMensaje(
            $update->chatId,
            $update->messageId,
            sprintf('🗑 <i>Importación descartada: %d consumos.</i>', $cuantos)
        );
    }

    /**
     * El detalle va como texto y no como tarjetas: cuarenta tarjetas son
     * cuarenta mensajes, y el chat queda inutilizable.
     */
    private function mostrarLote(int $userId, Update $update, string $lote): void
    {
        $this->telegram->responderCallback($update->callbackQueryId);
        $filas = $this->gastos->pendientesDeLote($userId, $lote);

        if ($filas === []) {
            return;
        }

        $lineas = ['🧾 <b>Detalle de la importación</b>', ''];

        foreach ($filas as $fila) {
            $monto = Money::deDecimal((string) $fila['monto'], (string) $fila['moneda']);
            $comercio = (string) $fila['comercio'];

            $lineas[] = sprintf(
                '%s  %s  %s — <b>%s</b>',
                (string) $fila['emoji'],
                self::soloDiaYMes((string) $fila['fecha']),
                ExpenseCard::escapar($comercio !== '' ? $comercio : 'Consumo'),
                ExpenseCard::escapar($monto->formatear())
            );
        }

        $lineas[] = '';
        $lineas[] = '<i>Si algo está mal, descartá la importación y mandame el resumen de nuevo.</i>';

        $this->telegram->enviarMensaje($update->chatId, implode("\n", $lineas));
    }

    private static function soloDiaYMes(string $fechaIso): string
    {
        $fecha = \DateTimeImmutable::createFromFormat('Y-m-d', $fechaIso);

        return $fecha === false ? $fechaIso : $fecha->format('d/m');
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
    private static function noEntendi(): string
    {
        return implode("\n", [
            'No pude sacar un gasto de ahí. Probá con alguna de estas:',
            '',
            '• <code>1200 super</code> — un gasto',
            '• <code>cuánto gasté en súper este mes</code> — una pregunta',
            '• Una foto del ticket, un audio o el PDF del resumen',
            '• /mes para el resumen completo',
        ]);
    }

    private function ayuda(): string
    {
        return implode("\n", [
            '<b>Cargar un gasto</b>',
            '',
            '• <code>1200 super</code> · <code>nafta 25k</code> · <code>ayer 45 lucas de prepaga</code>',
            '• Varios de una: <code>30 mil de nafta y 5 mil el café</code>',
            '• Foto del ticket o captura de la app',
            '• Nota de voz',
            '• El PDF del resumen de la tarjeta: cargo el mes entero',
            '',
            '<b>Preguntarme</b>',
            '',
            '• <code>cuánto gasté en súper este mes</code>',
            '• <code>cuánto gasté el mes pasado</code>',
            '• <code>en qué se me va la plata</code>',
            '',
            '<b>Comandos</b>',
            '',
            ...Menu::lineasDeAyuda(),
        ]);
    }
}
