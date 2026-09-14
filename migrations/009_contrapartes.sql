-- Destinatarios de transferencias.
--
-- Mercado Pago oculta el nombre de quien cobra, pero expone un id
-- estable (`collector.id`). Sin nombre, 180 transferencias caen en
-- "Otros" y se pierden $12.388.167 de información.
--
-- Medido antes de construir: 85 destinatarios distintos, pero nombrar
-- cinco cubre el 59% de la plata. La concentración justifica la tabla.

ALTER TABLE expenses
    ADD COLUMN contraparte VARCHAR(40) NULL AFTER origen_externo,
    ADD KEY idx_expenses_contraparte (user_id, contraparte);

CREATE TABLE contrapartes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    externo     VARCHAR(40)     NOT NULL,
    alias       VARCHAR(120)    NOT NULL,
    category_id BIGINT UNSIGNED     NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_contrapartes (user_id, externo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
