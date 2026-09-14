<?php

declare(strict_types=1);

namespace Budget\Support;

use Budget\Database\Migrator;
use Budget\Handler\HealthCheck;
use PDO;
use Throwable;

/**
 * Diagnóstico del entorno.
 *
 * En hosting compartido no se ve nada: no hay acceso a los logs del
 * servidor, ni a la configuración de PHP, ni forma de saber si el
 * webhook quedó bien apuntado. Este comando reemplaza a esa ceguera.
 *
 * Cada verificación responde una pregunta que, si se contesta mal en
 * producción, cuesta una tarde de depuración a ciegas.
 */
final class Doctor
{
    public const PHP_MINIMO = '8.2.0';

    private const EXTENSIONES = ['pdo_mysql', 'curl', 'mbstring', 'json'];

    /**
     * Permisos máximos aceptables para el .env: nadie más que el dueño.
     * En hosting compartido, "todo el mundo" incluye a los otros clientes
     * del mismo servidor.
     */
    private const PERMISOS_MAXIMOS_ENV = 0o600;

    /** @return list<Check> */
    public static function diagnosticar(
        string $raiz,
        Config $config,
        ?PDO $pdo,
        ?callable $consultaTelegram = null,
    ): array {
        return [
            self::versionDePhp(PHP_VERSION),
            ...self::extensiones(),
            self::procesoEnSegundoPlano(),
            self::ubicacionDelEnv($raiz),
            self::permisosDelEnv($raiz . '/.env'),
            self::almacenamiento($raiz . '/storage'),
            self::baseDeDatos($pdo),
            self::migraciones($pdo, $raiz . '/migrations'),
            self::invitacion($config),
            self::avisoAlDueno($config),
            self::claveDeIa($config),
            ...self::telegram($consultaTelegram),
        ];
    }

    public static function versionDePhp(string $version): Check
    {
        if (version_compare($version, self::PHP_MINIMO, '>=')) {
            return Check::ok('Versión de PHP', $version);
        }

        return Check::falla(
            'Versión de PHP',
            "{$version}, se necesita " . self::PHP_MINIMO . ' o superior',
            'Cambiar la versión de PHP en cPanel (MultiPHP Manager) para este dominio.'
        );
    }

    /** @return list<Check> */
    private static function extensiones(): array
    {
        $checks = [];

        foreach (self::EXTENSIONES as $extension) {
            $checks[] = extension_loaded($extension)
                ? Check::ok("Extensión {$extension}")
                : Check::falla(
                    "Extensión {$extension}",
                    'no está cargada',
                    "Activarla en cPanel (Select PHP Version → Extensions) y recargar."
                );
        }

        return $checks;
    }

    private static function procesoEnSegundoPlano(): Check
    {
        if (Background::disponible()) {
            return Check::ok('Respuesta rápida a Telegram', 'fastcgi_finish_request disponible');
        }

        // No es fatal: el webhook funciona igual, pero el usuario espera
        // con el mensaje "enviando" hasta que termine la extracción.
        return Check::aviso(
            'Respuesta rápida a Telegram',
            'fastcgi_finish_request no está disponible en este SAPI',
            'El bot anda igual, pero responde más lento. Si el hosting ofrece PHP-FPM, conviene usarlo.'
        );
    }

    private static function ubicacionDelEnv(string $raiz): Check
    {
        $enPublic = is_file($raiz . '/public/.env');

        if (!$enPublic) {
            return Check::ok('Ubicación del .env', 'fuera del document root');
        }

        return Check::falla(
            'Ubicación del .env',
            'hay un .env dentro de public/, accesible por HTTP',
            'Borrarlo YA y rotar el token del bot y las claves de IA: hay que asumir que se filtraron.'
        );
    }

    private static function permisosDelEnv(string $ruta): Check
    {
        if (!is_file($ruta)) {
            return Check::falla(
                'Archivo .env',
                'no existe',
                'Copiar .env.example a .env y completar token, base y código de invitación.'
            );
        }

        $permisos = fileperms($ruta) & 0o777;

        if ($permisos <= self::PERMISOS_MAXIMOS_ENV) {
            return Check::ok('Permisos del .env', sprintf('0%o', $permisos));
        }

        return Check::aviso(
            'Permisos del .env',
            sprintf('0%o: lo puede leer alguien más', $permisos),
            "Correr: chmod 600 {$ruta}"
        );
    }

    private static function almacenamiento(string $ruta): Check
    {
        if (is_dir($ruta) && is_writable($ruta)) {
            return Check::ok('Carpeta storage/', 'escribible');
        }

        return Check::falla(
            'Carpeta storage/',
            is_dir($ruta) ? 'no es escribible' : 'no existe',
            "Crearla y dar permisos: mkdir -p {$ruta} && chmod 755 {$ruta}"
        );
    }

    private static function baseDeDatos(?PDO $pdo): Check
    {
        if ($pdo === null) {
            return Check::falla(
                'Base de datos',
                'no se pudo conectar',
                'Revisar DB_HOST, DB_NAME, DB_USER y DB_PASS en el .env. En cPanel el usuario tiene que estar asignado a la base.'
            );
        }

        try {
            $version = (string) $pdo->query('SELECT VERSION()')?->fetchColumn();

            return Check::ok('Base de datos', $version);
        } catch (Throwable $e) {
            return Check::falla('Base de datos', $e->getMessage(), 'Revisar credenciales y permisos del usuario.');
        }
    }

    private static function migraciones(?PDO $pdo, string $directorio): Check
    {
        if ($pdo === null) {
            return Check::falla('Migraciones', 'sin base no se pueden verificar', 'Arreglar la conexión primero.');
        }

        try {
            $pendientes = (new Migrator($pdo, $directorio))->pendientes();
        } catch (Throwable $e) {
            return Check::falla('Migraciones', $e->getMessage(), 'Correr: php bin/migrate.php');
        }

        if ($pendientes === []) {
            return Check::ok('Migraciones', 'al día');
        }

        return Check::falla(
            'Migraciones',
            count($pendientes) . ' pendientes: ' . implode(', ', $pendientes),
            'Correr: php bin/migrate.php'
        );
    }

    private static function invitacion(Config $config): Check
    {
        if ($config->codigoInvitacion !== '') {
            return Check::ok('Código de invitación', 'configurado');
        }

        // Sin código nadie puede darse de alta: el bot queda cerrado, que
        // es el default seguro, pero probablemente no lo que se quería.
        return Check::falla(
            'Código de invitación',
            'vacío: nadie puede darse de alta, ni vos',
            'Poner INVITE_CODE en el .env con una cadena difícil de adivinar.'
        );
    }

    private static function avisoAlDueno(Config $config): Check
    {
        if ($config->chatIdDelDueno() !== 0) {
            return Check::ok('Aviso al dueño', 'OWNER_CHAT_ID configurado');
        }

        return Check::aviso(
            'Aviso al dueño',
            'OWNER_CHAT_ID vacío',
            'Sin esto, si el bot se cae nadie se entera. Conseguilo con @userinfobot y ponelo en el .env.'
        );
    }

    private static function claveDeIa(Config $config): Check
    {
        if ($config->claveIa('GEMINI_API_KEY') === '') {
            return Check::aviso(
                'Clave de IA',
                'GEMINI_API_KEY vacía',
                'El bot anda igual por texto, pero no va a poder leer fotos ni audios.'
            );
        }

        return Check::ok('Clave de IA', 'Gemini: ' . implode(' → ', $config->modelosGemini()));
    }

    /**
     * @param callable():array<string,mixed>|null $consulta devuelve getWebhookInfo
     * @return list<Check>
     */
    private static function telegram(?callable $consulta): array
    {
        if ($consulta === null) {
            return [Check::aviso('Webhook', 'no verificado', 'Correr con acceso a internet.')];
        }

        try {
            $info = $consulta();
        } catch (Throwable $e) {
            return [Check::falla(
                'Webhook',
                'Telegram rechazó la consulta: ' . $e->getMessage(),
                'Revisar TELEGRAM_BOT_TOKEN en el .env.'
            )];
        }

        $alerta = HealthCheck::alerta($info);

        if ($alerta === null) {
            return [Check::ok('Webhook', (string) ($info['url'] ?? ''))];
        }

        return [Check::falla(
            'Webhook',
            strip_tags(str_replace("\n", ' ', $alerta)),
            'Correr: php bin/webhook.php set https://TU-SUBDOMINIO/webhook.php'
        )];
    }
}
