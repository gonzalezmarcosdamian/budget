-- Importación de resúmenes de tarjeta.
--
-- Un resumen trae decenas de consumos. Mandar una tarjeta de
-- confirmación por cada uno vuelve el chat inusable, así que los gastos
-- de una misma importación se agrupan en un lote y se confirman juntos.
--
-- El lote también es lo que permite descartar una importación entera
-- cuando el modelo leyó mal el PDF, sin ir gasto por gasto.

ALTER TABLE expenses
    ADD COLUMN lote CHAR(12) NULL AFTER estado,
    ADD KEY idx_expenses_lote (user_id, lote, estado);
