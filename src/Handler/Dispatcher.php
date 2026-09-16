<?php

declare(strict_types=1);

namespace Budget\Handler;

use Budget\Ai\Extraction;
use Budget\Ai\Router;
use Budget\Expense\Draft;
use Budget\Expense\CategoryGuesser;
use Budget\Expense\FastParser;
use Budget\Expense\Periodo;
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
use Throwable;

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
        private readonly Rankings $rankings,
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
            '/flujo', '/caja' => $this->reportes->flujo($userId, $hoy),
            '/anio', '/año' => $this->reportes->delPeriodo(
                $userId,
                Periodo::desde(Periodo::ANIO, $hoy)
            ),
            '/trimestre' => $this->reportes->delPeriodo(
                $userId,
                Periodo::desde(Periodo::TRIMESTRE, $hoy)
            ),
            '/topgastos' => $this->rankings->topGastos($userId, Periodo::desde(Periodo::MES, $hoy)),
            '/topentrantes' => $this->rankings->topEntrantes($userId, Periodo::desde(Periodo::MES, $hoy)),
            '/topsalientes' => $this->rankings->topSalientes($userId, Periodo::desde(Periodo::MES, $hoy)),
            '/mercadopago' => MercadoPagoWizard::pasos(),
            '/desvincular' => $this->wizardMp()->desvincular($userId),
            '/avisos' => $this->alternarAvisos($userId),
            '/recurrentes' => $this->reportes->recurrentes($userId),
            '/revisar' => $this->tarjetas()->revisar($userId, $update->chatId),
            '/inversiones' => $this->reportes->inversiones($userId, $hoy),
            default => 'No conozco ese comando. Probá /ayuda.',
        };

        // Un comando que ya mandó su propio mensaje —porque necesitaba
        // teclado— devuelve vacío para no mandar otro en blanco.
        if ($respuesta !== '') {
            $this->telegram->enviarMensaje($update->chatId, $respuesta);
        }
    }

    private function manejarTexto(int $userId, Update $update): void
    {
        // Antes que nada, y por substring y no por patrón exacto: un
        // mensaje que contenga un token de Mercado Pago no puede seguir
        // de largo. Si no calza exacto —"Access Token: APP_USR-...", o
        // pegado junto a la public key— terminaría en el parser y de ahí
        // en el proveedor de IA, que es mandarle a un tercero una
        // credencial de pleno acceso a la cuenta de dinero del usuario.
        //
        // Cortar acá es la diferencia entre un vínculo que falla y una
        // credencial filtrada.
        if (MercadoPagoWizard::mencionaUnToken($update->texto)) {
            $this->wizardMp()->vincular($userId, $update);

            return;
        }

        // Primero la pregunta y después el gasto, y el orden no es un
        // detalle: "pasame el detalle de agosto 2026" tiene un número
        // adentro, así que el parser rápido lo tomaba por un gasto de
        // $2.026 y la pregunta no se miraba nunca.
        $pregunta = Pregunta::desde($update->texto, new CategoryGuesser(), $this->reloj->ahora());

        if ($pregunta !== null) {
            $this->telegram->enviarMensaje(
                $update->chatId,
                $this->reportes->responder($userId, $pregunta, $this->reloj->ahora())
            );

            return;
        }

        $rapido = $this->parser->parsear($update->texto);

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
            $this->recordatorios()->manejarRespuesta($userId, $update);

            return;
        }

        $accion = ExpenseCard::decodificar($update->callbackData);

        if ($accion === null) {
            $this->telegram->responderCallback($update->callbackQueryId);

            return;
        }

        match ($accion['accion']) {
            ExpenseCard::ACCION_CONFIRMAR => $this->tarjetas()->confirmar($userId, $update, $accion['expenseId']),
            ExpenseCard::ACCION_DESCARTAR => $this->tarjetas()->descartar($userId, $update, $accion['expenseId']),
            ExpenseCard::ACCION_ELEGIR_CATEGORIA
                => $this->tarjetas()->ofrecerCategorias($userId, $update, $accion['expenseId']),
            ExpenseCard::ACCION_FIJAR_CATEGORIA => $this->tarjetas()->fijarCategoria($userId, $update, $accion),
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
            ExpenseCard::ACCION_LOTE_CONFIRMAR => $this->lotes()->confirmar($userId, $update, $lote),
            ExpenseCard::ACCION_LOTE_DESCARTAR => $this->lotes()->descartar($userId, $update, $lote),
            ExpenseCard::ACCION_LOTE_DETALLE => $this->lotes()->mostrar($userId, $update, $lote),
            default => $this->telegram->responderCallback($update->callbackQueryId),
        };
    }

    private function tarjetas(): Tarjetas
    {
        return new Tarjetas($this->telegram, $this->gastos, $this->categorias, $this->reportes);
    }

    private function recordatorios(): Recordatorios
    {
        return new Recordatorios(
            $this->recurrentes,
            $this->gastos,
            $this->telegram,
            $this->reloj,
            $this->log
        );
    }

    private function lotes(): Lotes
    {
        return new Lotes($this->telegram, $this->gastos);
    }

    private function wizardMp(): MercadoPagoWizard
    {
        return new MercadoPagoWizard(
            $this->telegram,
            $this->gastos->pdo(),
            $this->config->claveDeCifrado(),
            $this->log
        );
    }

    /** Prende o apaga los mensajes que el bot manda sin que se los pidan. */
    private function alternarAvisos(int $userId): string
    {
        $prendidos = $this->usuarios->alternarAvisos($userId);

        return $prendidos
            ? "🔔 Avisos <b>prendidos</b>.\n\n"
                . '<i>Te voy a avisar cuando importe movimientos nuevos de Mercado Pago.</i>'
            : "🔕 Avisos <b>apagados</b>.\n\n"
                . '<i>Importo en silencio. Los recordatorios de gastos que se repiten '
                . 'siguen andando: ésos los pediste vos.</i>';
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
