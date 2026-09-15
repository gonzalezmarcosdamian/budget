-- El índice de la serie de snapshots era el mismo que el único.
--
-- `uk_snapshot (user_id, fecha)` e `idx_snapshot_serie (user_id, fecha)`
-- tienen las mismas columnas en el mismo orden: el segundo no aporta
-- nada al optimizador y se paga en cada escritura. Confirmado con
-- SHOW INDEX sobre la base ya migrada.
--
-- Va en migración nueva y no editando la 012, que ya está aplicada.

ALTER TABLE patrimonio_snapshot DROP INDEX idx_snapshot_serie;
