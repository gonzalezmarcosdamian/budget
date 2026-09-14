-- Base separada para los tests de integración.
--
-- Sin esto, la suite corre contra la misma base que el entorno de
-- desarrollo y su TRUNCATE borra los gastos que uno acaba de cargar a
-- mano para probar. Un test que destruye datos de trabajo se deja de
-- correr, y una suite que no se corre no protege nada.
CREATE DATABASE IF NOT EXISTS budget_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON budget_test.* TO 'budget'@'%';
FLUSH PRIVILEGES;
