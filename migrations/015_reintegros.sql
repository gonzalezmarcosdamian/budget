-- Quién te devuelve plata, y quién te paga.
--
-- El gasto real venía calculado con el neto por contraparte y piso en
-- cero: a quien le mandaste $100.000 y te devolvió $70.000, te costó
-- $30.000; a quien te paga todos los meses y nunca le mandaste nada, el
-- piso lo deja en cero y su plata queda como ingreso.
--
-- Esa regla usa "¿también le mandaste?" como forma de adivinar si lo
-- que entró es una devolución o un cobro. Funciona cuando la devolución
-- es de una transferencia que hiciste, y falla cuando pagaste vos en el
-- comercio: una amiga que te devuelve su parte de varias cenas nunca
-- recibió una transferencia tuya, así que el piso deja su devolución en
-- cero y esas cenas quedan contadas enteras.
--
-- No hay nada en el movimiento que distinga un caso del otro. Así que
-- se declara: `reintegra` marca a las contrapartes cuya plata entrante
-- es devolución de algo que pagaste vos.
--
-- El default es 0 —tratarlo como ingreso— porque equivocarse hacia "no
-- descuenta" deja el gasto alto, que se nota. Al revés lo escondería.

ALTER TABLE contrapartes
    ADD COLUMN reintegra TINYINT(1) NOT NULL DEFAULT 0 AFTER es_propia;
