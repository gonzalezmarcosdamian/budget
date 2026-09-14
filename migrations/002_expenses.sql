-- Un gasto nace como 'borrador' y sólo pasa a 'confirmado' cuando el
-- usuario toca el botón. El bot nunca guarda a ciegas.

CREATE TABLE expenses (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    monto               DECIMAL(14,2)   NOT NULL,
    moneda              CHAR(3)         NOT NULL DEFAULT 'ARS',
    -- Congelado al tipo de cambio del día del gasto. Reconvertir
    -- históricos al valor de hoy haría que los reportes del pasado
    -- cambien cada mañana, algo inservible con esta inflación.
    monto_ars           DECIMAL(14,2)   NOT NULL,
    tipo_cambio         DECIMAL(14,4)       NULL,
    fecha               DATE            NOT NULL,
    comercio            VARCHAR(160)    NOT NULL DEFAULT '',
    descripcion         VARCHAR(255)    NOT NULL DEFAULT '',
    category_id         BIGINT UNSIGNED     NULL,
    medio_pago          VARCHAR(40)     NOT NULL DEFAULT '',
    fuente              VARCHAR(12)     NOT NULL DEFAULT 'texto',
    confianza           DECIMAL(3,2)        NULL,
    modelo              VARCHAR(60)     NOT NULL DEFAULT '',
    estado              VARCHAR(12)     NOT NULL DEFAULT 'borrador',
    telegram_message_id BIGINT              NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_expenses_periodo (user_id, estado, fecha),
    KEY idx_expenses_categoria (user_id, category_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
