-- Presupuestos y gastos recurrentes (fase 3). El esquema entra ahora
-- para que las migraciones no toquen tablas en producción después.

CREATE TABLE budgets (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    monto       DECIMAL(14,2)   NOT NULL,
    periodo     VARCHAR(10)     NOT NULL DEFAULT 'mensual',
    alerta_pct  TINYINT UNSIGNED NOT NULL DEFAULT 80,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_budgets (user_id, category_id, periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recurring (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    comercio       VARCHAR(160)    NOT NULL,
    monto_esperado DECIMAL(14,2)   NOT NULL,
    dia_del_mes    TINYINT UNSIGNED NOT NULL,
    proximo_aviso  DATE                NULL,
    activo         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recurring_aviso (activo, proximo_aviso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
