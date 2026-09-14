-- Préstamos y ayuda a otra persona.
--
-- Prestarle plata a un amigo no es consumo: no compraste nada. Si vuelve
-- es un ingreso, y si no vuelve recién ahí fue un gasto. Meterlo junto
-- con el supermercado hace que el total del mes no signifique nada.
--
-- Aparece porque el mayor destinatario de transferencias del usuario
-- ($2.308.000 en 15 movimientos) mezcla gastos compartidos con dos
-- transferencias grandes de ayuda.

INSERT IGNORE INTO categories (user_id, nombre, emoji, naturaleza, tipo, orden)
VALUES (0, 'Préstamos y ayuda', '🤝', 'variable', 'gasto', 96);
