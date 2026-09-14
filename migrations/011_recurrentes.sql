-- Gastos que se repiten todos los meses.
--
-- El alquiler es el gasto más grande y el bot no lo ve: se paga por
-- fuera de todo lo que tiene conectado. Esperar a que aparezca solo no
-- va a funcionar nunca, así que el bot lo anticipa: sabe el monto, el
-- día y la categoría, y el día que toca pregunta.
--
-- Anticipar en vez de esperar es lo que convierte un registro pasivo en
-- algo que sirve.

ALTER TABLE recurring
    ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER comercio,
    ADD COLUMN naturaleza  VARCHAR(12) NOT NULL DEFAULT 'fijo' AFTER category_id,
    ADD COLUMN nota        VARCHAR(160) NOT NULL DEFAULT '' AFTER naturaleza,
    ADD KEY idx_recurring_usuario (user_id, activo);
