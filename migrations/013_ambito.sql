-- Plata propia moviéndose, contra plata que entra o sale de verdad.
--
-- Una transferencia de Mercado Pago a tu propio banco no es un gasto:
-- la plata sigue siendo tuya, cambió de bolsillo. Una transferencia a
-- un amigo sí sale. Mezclarlas es lo que hace que el total de gastos no
-- se parezca a la realidad.
--
-- Mercado Pago no puede resolver esto solo: un CBU ajeno y un CBU
-- propio le llegan iguales. Lo único que sabe la API es a qué cuenta
-- fue. Así que la marca la pone el usuario una vez por contraparte, y
-- de ahí en adelante todos los movimientos con esa contraparte quedan
-- clasificados.
--
-- El default es `0` (terceros) a propósito: es lo que es la enorme
-- mayoría, y equivocarse hacia "es un gasto" infla el total, que es un
-- error que se nota. Al revés lo escondería.

ALTER TABLE contrapartes
    ADD COLUMN es_propia TINYINT(1) NOT NULL DEFAULT 0 AFTER alias;

-- Las que ya tienen nombre de persona son terceros, y quedan así por el
-- default. No se toca ninguna fila: marcar cuáles son propias lo hace
-- el usuario, que es el único que lo sabe.
