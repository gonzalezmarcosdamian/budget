-- "Préstamos y ayuda" era una categoría que no describía nada.
--
-- Lo que hay entre dos personas es una transferencia, y el neteo por
-- contraparte ya la resuelve: lo que le mandaste menos lo que te
-- devolvió. Llamarlo préstamo agregaba una afirmación sobre la
-- intención —que va a volver— que el bot no puede saber, y encima abría
-- un balde en /flujo que competía con la sección de transferencias
-- diciendo lo mismo de otra forma.
--
-- Los movimientos vuelven a quedar sin categoría, no en otra inventada:
-- así salen en /revisar y los clasifica quien sabe qué fueron. Una
-- categoría equivocada es peor que ninguna, porque nadie la vuelve a
-- mirar.

UPDATE expenses e
    JOIN categories c ON c.id = e.category_id
   SET e.category_id = NULL
 WHERE c.nombre = 'Préstamos y ayuda';

DELETE FROM categories WHERE user_id = 0 AND nombre = 'Préstamos y ayuda';
