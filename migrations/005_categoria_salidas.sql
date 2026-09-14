-- Categoría propia para salidas y fiestas.
--
-- Estaba todo dentro de "Entretenimiento", junto con el cine y el
-- gimnasio. La gente no piensa una salida de noche como entretenimiento,
-- y mezclarlas hace que el reporte mensual no sirva para decidir nada:
-- el número grande queda escondido entre gastos chicos y regulares.

INSERT IGNORE INTO categories (user_id, nombre, emoji, orden)
VALUES (0, 'Salidas y fiestas', '🎉', 75);
