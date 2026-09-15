-- Fotos del portafolio de inversiones, para poder compararlas.
--
-- Los gastos se miden por flujo: qué salió este mes. Las inversiones no
-- funcionan así: lo que importa es el stock y cómo cambió. Sin una foto
-- del mes pasado no hay con qué comparar, y por eso hace falta guardar
-- la serie en vez de consultar el saldo del día.
--
-- La trampa que esto tiene que poder evitar: entre dos fotos el total
-- puede subir porque los precios subieron o porque el usuario metió
-- plata nueva, y son cosas opuestas. Guardar la cantidad de cada
-- posición además del valor es lo que permite separarlas después: si la
-- cantidad no cambió, todo el movimiento fue precio.
--
-- La cartera no es sólo IOL: en Mercado Pago hay un fondo y dólares.
-- Por eso cada posición dice de dónde sale (`origen`) y qué es
-- (`clase`): una inversión se mide por rendimiento, una reserva por
-- cuánta hay. Mezclarlas daría un "rendimiento" licuado por la plata
-- que está quieta a propósito.
--
-- El saldo de Mercado Pago no se puede leer por API: con el token de la
-- aplicación, `mercadopago_account/balance` devuelve 403 y los
-- endpoints de asset management, 404. Medido, no supuesto. Así que esas
-- posiciones entran a mano hasta que haya por dónde leerlas.

CREATE TABLE patrimonio_snapshot (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    fecha      DATE            NOT NULL,
    total_ars  DECIMAL(16,2)   NOT NULL,
    fuente     VARCHAR(20)     NOT NULL DEFAULT 'iol',
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Una foto por día: correr la toma dos veces la reemplaza en vez de
    -- duplicarla, igual que la importación de Mercado Pago.
    UNIQUE KEY uk_snapshot (user_id, fecha),
    KEY idx_snapshot_serie (user_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE patrimonio_posicion (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    simbolo     VARCHAR(24)     NOT NULL,
    descripcion VARCHAR(120)    NOT NULL DEFAULT '',
    tipo        VARCHAR(30)     NOT NULL DEFAULT '',
    -- iol | mercadopago | manual
    origen      VARCHAR(20)     NOT NULL DEFAULT 'iol',
    -- inversion (se mide por rendimiento) | reserva (se mide por cuánta hay)
    clase       VARCHAR(12)     NOT NULL DEFAULT 'inversion',
    cantidad    DECIMAL(18,4)   NOT NULL,
    precio_ars  DECIMAL(16,4)   NOT NULL,
    valor_ars   DECIMAL(16,2)   NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_posicion (snapshot_id, simbolo),
    CONSTRAINT fk_posicion_snapshot
        FOREIGN KEY (snapshot_id) REFERENCES patrimonio_snapshot (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
