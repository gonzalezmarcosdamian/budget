-- Taxonomía financiera: no todo movimiento es un gasto.
--
-- Hasta acá la tabla se llamaba expenses y todo lo que entraba era un
-- gasto. Eso es media herramienta: comprar un CEDEAR por $368.000 no es
-- gastar, es mover plata de efectivo a activos, y contarlo como gasto
-- destruye el reporte mensual.
--
--   tipo:       gasto | ingreso | inversion
--   naturaleza: fijo  | variable
--
-- El alquiler y la prepaga son fijos: se sabe que vienen y cuánto. El
-- súper y las salidas son variables. La distinción importa porque sobre
-- el gasto variable se puede decidir, y sobre el fijo casi no.

ALTER TABLE expenses
    ADD COLUMN tipo       VARCHAR(12) NOT NULL DEFAULT 'gasto'    AFTER user_id,
    ADD COLUMN naturaleza VARCHAR(12) NOT NULL DEFAULT 'variable' AFTER tipo,
    ADD KEY idx_expenses_tipo (user_id, tipo, estado, fecha);

-- La categoría trae la naturaleza por defecto: un gasto de Alquiler nace
-- fijo sin que nadie lo marque. Se puede cambiar por movimiento.
ALTER TABLE categories
    ADD COLUMN naturaleza VARCHAR(12) NOT NULL DEFAULT 'variable' AFTER emoji,
    ADD COLUMN tipo       VARCHAR(12) NOT NULL DEFAULT 'gasto'     AFTER naturaleza;

UPDATE categories SET naturaleza = 'fijo'
 WHERE user_id = 0 AND nombre IN ('Servicios', 'Salud', 'Educación');

-- Categorías nuevas que la taxonomía vuelve necesarias.
INSERT IGNORE INTO categories (user_id, nombre, emoji, naturaleza, tipo, orden) VALUES
    (0, 'Alquiler',     '🔑', 'fijo',     'gasto',     5),
    (0, 'Impuestos',    '🏛️', 'fijo',     'gasto',    45),
    (0, 'Comisiones',   '🧮', 'variable', 'gasto',    95),
    (0, 'Sueldo',       '💼', 'fijo',     'ingreso',  200),
    (0, 'Otros ingresos','💰', 'variable', 'ingreso', 210),
    (0, 'Inversiones',  '📈', 'variable', 'inversion', 300);
