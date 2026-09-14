-- Núcleo: usuarios, categorías y reglas de comercio aprendidas.
-- Todo cuelga de user_id desde el día uno: agregar multi-tenancy
-- después implicaría reescribir cada consulta.

CREATE TABLE users (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    telegram_chat_id  BIGINT          NOT NULL,
    nombre            VARCHAR(120)    NOT NULL DEFAULT '',
    zona_horaria      VARCHAR(64)     NOT NULL DEFAULT 'America/Argentina/Buenos_Aires',
    moneda_base       CHAR(3)         NOT NULL DEFAULT 'ARS',
    plan              VARCHAR(20)     NOT NULL DEFAULT 'free',
    quota_used        INT UNSIGNED    NOT NULL DEFAULT 0,
    quota_period      CHAR(7)         NOT NULL DEFAULT '',
    estado            VARCHAR(10)     NOT NULL DEFAULT 'activo',
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_chat (telegram_chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_id = 0 significa categoría semilla, compartida por todos.
-- Se usa 0 y no NULL porque MySQL permite múltiples NULL en un
-- índice único, lo que rompería la unicidad de las semillas.
CREATE TABLE categories (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    nombre     VARCHAR(60)     NOT NULL,
    emoji      VARCHAR(8)      NOT NULL DEFAULT '',
    orden      SMALLINT        NOT NULL DEFAULT 100,
    PRIMARY KEY (id),
    UNIQUE KEY uk_categories_nombre (user_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categories (user_id, nombre, emoji, orden) VALUES
    (0, 'Supermercado',      '🛒', 10),
    (0, 'Comida y delivery', '🍔', 20),
    (0, 'Transporte',        '🚗', 30),
    (0, 'Servicios',         '💡', 40),
    (0, 'Hogar',             '🏠', 50),
    (0, 'Salud',             '💊', 60),
    (0, 'Entretenimiento',   '🎬', 70),
    (0, 'Compras',           '🛍️', 80),
    (0, 'Educación',         '📚', 90),
    (0, 'Otros',             '📦', 999);

-- Aprendidas de las correcciones del usuario. A partir de la segunda
-- corrección el bot resuelve el comercio sin llamar a ningún modelo.
CREATE TABLE merchant_rules (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    patron      VARCHAR(120)    NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    aciertos    INT UNSIGNED    NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rules_patron (user_id, patron)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
