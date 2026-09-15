<?php

declare(strict_types=1);

namespace Budget;

use Budget\Ai\GeminiProvider;
use Budget\Ai\Router;
use Budget\Database\Connection;
use Budget\Expense\CategoryGuesser;
use Budget\Expense\FastParser;
use Budget\Handler\Dispatcher;
use Budget\Expense\CategoryGuesser as Categorizador;
use Budget\Handler\Reports;
use Budget\Integracion\SincronizadorMp;
use Budget\Handler\Recordatorios;
use Budget\Repository\MercadoPagoRepository;
use Budget\Repository\PatrimonioRepository;
use Budget\Repository\RecurringRepository;
use Budget\Support\Cifrado;
use Budget\Repository\CategoryRepository;
use Budget\Repository\ExpenseRepository;
use Budget\Repository\UpdateLog;
use Budget\Repository\UserRepository;
use Budget\Support\Clock;
use Budget\Support\Config;
use Budget\Support\Env;
use Budget\Support\Http;
use Budget\Support\Logger;
use Budget\Support\SystemClock;
use Budget\Telegram\Client;
use PDO;

/**
 * Cableado de la aplicación.
 *
 * Sin contenedor de inyección: son veinte objetos y un grafo que entra
 * en una pantalla. Todo es perezoso para que un comando de consola que
 * sólo migra no abra una conexión a Telegram.
 */
final class App
{
    private ?PDO $pdo = null;
    private ?Client $telegram = null;

    private function __construct(
        public readonly Config $config,
        public readonly Logger $log,
        public readonly Clock $reloj,
        private readonly string $raiz,
    ) {
    }

    public static function crear(string $raiz): self
    {
        $config = Config::desdeEnv(Env::cargar($raiz . '/.env'));

        date_default_timezone_set($config->zona->getName());

        return new self(
            config: $config,
            log: new Logger($raiz . '/storage/app.log'),
            reloj: new SystemClock($config->zona),
            raiz: $raiz,
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Connection::abrir($this->config);
    }

    public function telegram(): Client
    {
        return $this->telegram ??= new Client($this->config->botToken());
    }

    public function sincronizadorMp(): SincronizadorMp
    {
        $pdo = $this->pdo();

        return new SincronizadorMp(
            cuentas: new MercadoPagoRepository($pdo),
            gastos: new ExpenseRepository($pdo),
            categorias: new CategoryRepository($pdo),
            usuarios: new UserRepository($pdo),
            categorizador: new Categorizador(),
            cifrado: Cifrado::conClaveHex($this->config->claveDeCifrado()),
            telegram: $this->telegram(),
            http: new Http(),
            reloj: $this->reloj,
            log: $this->log,
        );
    }

    public function recordatorios(): Recordatorios
    {
        return new Recordatorios(
            new RecurringRepository($this->pdo()),
            $this->telegram(),
            $this->reloj,
            $this->log,
        );
    }

    public function updates(): UpdateLog
    {
        return new UpdateLog($this->pdo());
    }

    public function migrationsDir(): string
    {
        return $this->raiz . '/migrations';
    }

    public function dispatcher(): Dispatcher
    {
        $pdo = $this->pdo();

        return new Dispatcher(
            telegram: $this->telegram(),
            usuarios: new UserRepository($pdo),
            gastos: new ExpenseRepository($pdo),
            categorias: new CategoryRepository($pdo),
            recurrentes: new RecurringRepository($pdo),
            parser: new FastParser($this->reloj, new CategoryGuesser()),
            ia: $this->router(),
            reportes: new Reports(
                new ExpenseRepository($pdo),
                new CategoryRepository($pdo),
                new RecurringRepository($pdo),
                new PatrimonioRepository($pdo),
            ),
            config: $this->config,
            reloj: $this->reloj,
            log: $this->log,
        );
    }

    /**
     * La cadena de proveedores, en orden de preferencia. Agregar Groq u
     * OpenRouter es sumar una línea acá, no tocar la lógica.
     */
    private function router(): Router
    {
        $http = new Http();
        $clave = $this->config->claveIa('GEMINI_API_KEY');

        $proveedores = array_map(
            fn (string $modelo): GeminiProvider => new GeminiProvider($clave, $http, $this->reloj, $modelo),
            $this->config->modelosGemini()
        );

        return new Router($proveedores, $this->pdo(), $this->log);
    }
}
