<?php

declare(strict_types=1);

namespace Budget\Repository;

use PDO;

/**
 * Alta y lectura de usuarios.
 *
 * El acceso arranca por invitación: quien no presenta el código no
 * existe para el bot. Es la forma más barata de posponer las
 * obligaciones de un producto abierto sin cerrarse la puerta.
 */
final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function porChat(int $chatId): ?array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT id, telegram_chat_id, nombre, zona_horaria, moneda_base, plan,
                    quota_used, quota_period, estado
             FROM users
             WHERE telegram_chat_id = ?'
        );
        $sentencia->execute([$chatId]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Da vuelta la preferencia de avisos y devuelve cómo quedó.
     *
     * En una sola sentencia y no leyendo-y-escribiendo: dos toques
     * seguidos desde el teclado de Telegram llegan casi juntos, y con
     * lectura previa los dos verían el mismo valor y escribirían lo
     * mismo, dejando la preferencia donde estaba.
     */
    public function alternarAvisos(int $userId): bool
    {
        // IF y no `1 - avisos`: sobre un valor inesperado —un backfill,
        // una carga a mano— la resta da -1 y después 2, y la preferencia
        // no vuelve a valer 1 nunca más.
        $this->pdo
            ->prepare('UPDATE users SET avisos = IF(avisos = 1, 0, 1) WHERE id = ?')
            ->execute([$userId]);

        $sentencia = $this->pdo->prepare('SELECT avisos FROM users WHERE id = ?');
        $sentencia->execute([$userId]);

        return (int) $sentencia->fetchColumn() === 1;
    }

    /** @return array<string,mixed>|null */
    public function porId(int $userId): ?array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT id, telegram_chat_id, nombre, zona_horaria, moneda_base, avisos, estado
             FROM users WHERE id = ?'
        );
        $sentencia->execute([$userId]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : $fila;
    }

    public function crear(int $chatId, string $nombre, string $zona, string $moneda): int
    {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO users (telegram_chat_id, nombre, zona_horaria, moneda_base)
             VALUES (?, ?, ?, ?)'
        );
        $sentencia->execute([$chatId, $nombre, $zona, $moneda]);

        return (int) $this->pdo->lastInsertId();
    }


    /**
     * Consume una operación de IA del cupo mensual.
     *
     * Las capas gratuitas se miden por API key, no por usuario final: sin
     * este tope, un solo usuario intensivo deja sin servicio a todos los
     * demás. Devuelve false cuando ya no queda cupo, y ahí el bot sigue
     * andando en modo texto en vez de romperse.
     */
    public function consumirCupoIa(int $userId, int $cupoMensual, string $periodo): bool
    {
        if ($cupoMensual <= 0) {
            return true;
        }

        $this->pdo->beginTransaction();

        try {
            $lectura = $this->pdo->prepare(
                'SELECT quota_used, quota_period FROM users WHERE id = ? FOR UPDATE'
            );
            $lectura->execute([$userId]);
            $fila = $lectura->fetch();

            if ($fila === false) {
                $this->pdo->rollBack();

                return false;
            }

            $usadas = (string) $fila['quota_period'] === $periodo ? (int) $fila['quota_used'] : 0;

            if ($usadas >= $cupoMensual) {
                $this->pdo->rollBack();

                return false;
            }

            $escritura = $this->pdo->prepare(
                'UPDATE users SET quota_used = ?, quota_period = ? WHERE id = ?'
            );
            $escritura->execute([$usadas + 1, $periodo, $userId]);

            $this->pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
