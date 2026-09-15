-- Ingresos que no son sueldo ni changa suelta.
--
-- El canon mensual de un sistema propio no entra en ninguna de las dos
-- categorías que había: no es un sueldo en relación de dependencia, y
-- llamarlo "Otros ingresos" esconde el ingreso recurrente más estable
-- que hay, que es justamente el que conviene mirar.
--
-- Va como `fijo` porque cumple la definición: se sabe que viene y
-- cuánto.

INSERT IGNORE INTO categories (user_id, nombre, emoji, naturaleza, tipo, orden)
VALUES (0, 'Honorarios', '🧾', 'fijo', 'ingreso', 205);
