-- Integración con Mercado Pago.
--
-- `origen_externo` guarda la referencia del movimiento en el sistema de
-- origen ("mp:123456789"). El índice único es lo que hace que una
-- sincronización repetida no vuelva a cargar lo mismo: la garantía la da
-- la base, no la aplicación.
--
-- MySQL permite varios NULL en un índice único, así que los gastos
-- cargados a mano —que no tienen origen externo— no se estorban.

ALTER TABLE expenses
    ADD COLUMN origen_externo VARCHAR(64) NULL AFTER lote,
    ADD UNIQUE KEY uk_expenses_origen (user_id, origen_externo);

-- El token se guarda cifrado. Es una credencial de un tercero sobre la
-- cuenta de dinero de una persona: en claro, cualquier lectura de la
-- base es acceso a sus pagos.
CREATE TABLE mp_cuentas (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    mp_user_id    BIGINT          NOT NULL,
    apodo         VARCHAR(80)     NOT NULL DEFAULT '',
    token_cifrado TEXT            NOT NULL,
    ultima_sync   DATETIME            NULL,
    activo        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_mp_cuentas_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
