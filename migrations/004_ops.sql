-- Operación: idempotencia del webhook y métricas de IA.

-- Telegram reintenta los updates que no responden rápido. Sin esta
-- tabla, un reintento duplica el gasto. Es el bug más caro del
-- proyecto y el más barato de prevenir.
CREATE TABLE updates_seen (
    update_id   BIGINT   NOT NULL,
    recibido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (update_id),
    KEY idx_updates_limpieza (recibido_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sin estas filas no se puede saber cuánto consume un usuario
-- promedio, y sin ese número no se puede decidir si el modelo
-- gratuito cierra.
CREATE TABLE ai_calls (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    proveedor  VARCHAR(30)     NOT NULL,
    modelo     VARCHAR(60)     NOT NULL DEFAULT '',
    tarea      VARCHAR(20)     NOT NULL,
    tokens_in  INT UNSIGNED    NOT NULL DEFAULT 0,
    tokens_out INT UNSIGNED    NOT NULL DEFAULT 0,
    ms         INT UNSIGNED    NOT NULL DEFAULT 0,
    exito      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    error      VARCHAR(255)    NOT NULL DEFAULT '',
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_cuota (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
