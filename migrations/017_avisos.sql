-- El bot deja de hablar solo por defecto.
--
-- La sincronización de Mercado Pago avisaba una vez por lote. Cargando
-- un año de historial eso fueron seis mensajes seguidos sin que el
-- usuario hubiera hecho nada, y la reacción fue la esperable: "¿por qué
-- se manda mensajes solos?".
--
-- El aviso tenía sentido cuando se pensó —tres movimientos nuevos,
-- mirá— pero la información ya está cuando el usuario pregunta. Un bot
-- que interrumpe para decir algo que igual vas a ver es ruido.
--
-- Arranca en 0: el silencio es el default, y quien quiera los avisos los
-- prende con /avisos. Los recordatorios de gastos recurrentes son otra
-- cosa y siguen andando: ésos el usuario los pidió explícitamente, y sin
-- el aviso la función no existe.

ALTER TABLE users
    ADD COLUMN avisos TINYINT(1) NOT NULL DEFAULT 0 AFTER estado;
