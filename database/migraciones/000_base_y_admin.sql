-- =====================================================================
-- 000 · Base de datos y usuario de migraciones
-- Lo ejecuta TI con un usuario administrador de MariaDB (root).
--
-- Antes de ejecutarlo, en la MISMA sesión del cliente mariadb:
--   SET @pw_admin = '<contraseña larga, mínimo 16 caracteres>';
--   SOURCE database/migraciones/000_base_y_admin.sql;
--
-- La contraseña nunca se guarda en este archivo ni en el repositorio.
-- Requiere MariaDB 10.2.7 o más nuevo (CHECK, tipo JSON y bloques
-- compuestos fuera de procedimientos).
-- =====================================================================

-- Todo dentro de un solo bloque: si falta la contraseña, no se crea nada.
DELIMITER $$
BEGIN NOT ATOMIC
  IF @pw_admin IS NULL OR CHAR_LENGTH(@pw_admin) < 16 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Define @pw_admin (mínimo 16 caracteres) antes de ejecutar este archivo.';
  END IF;

  CREATE DATABASE IF NOT EXISTS servicio_social
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

  -- ss_admin: migraciones y reemplazo completo, solo desde el servidor.
  -- Es el DEFINER de los triggers, por eso necesita TRIGGER.
  SET @sql = CONCAT('CREATE USER IF NOT EXISTS ''ss_admin''@''localhost'' IDENTIFIED BY ', QUOTE(@pw_admin));
  PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  GRANT ALL PRIVILEGES ON servicio_social.* TO 'ss_admin'@'localhost';
END$$
DELIMITER ;

SET @pw_admin = NULL;
SET @sql = NULL;
