-- =====================================================================
-- 004 · Usuarios de MariaDB de la aplicación
-- Lo ejecuta TI con root DESPUÉS de 001-003 (los permisos por tabla
-- necesitan que las tablas existan).
--
-- En la MISMA sesión del cliente mariadb:
--   SET @pw_app = '...';  SET @pw_respaldo = '...';  SET @pw_sello = '...';
--   SOURCE database/migraciones/004_usuarios_aplicacion.sql;
-- Cada contraseña: distinta y de 16 caracteres o más. No se guardan aquí.
-- =====================================================================

DELIMITER $$
BEGIN NOT ATOMIC
  IF @pw_app IS NULL OR CHAR_LENGTH(@pw_app) < 16
     OR @pw_respaldo IS NULL OR CHAR_LENGTH(@pw_respaldo) < 16
     OR @pw_sello IS NULL OR CHAR_LENGTH(@pw_sello) < 16 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Define @pw_app, @pw_respaldo y @pw_sello (mínimo 16 caracteres cada una).';
  END IF;
  IF @pw_app = @pw_respaldo OR @pw_app = @pw_sello OR @pw_respaldo = @pw_sello THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las tres contraseñas deben ser distintas.';
  END IF;

  SET @sql = CONCAT('CREATE USER IF NOT EXISTS ''ss_app''@''localhost'' IDENTIFIED BY ', QUOTE(@pw_app));
  PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  SET @sql = CONCAT('CREATE USER IF NOT EXISTS ''ss_respaldo''@''localhost'' IDENTIFIED BY ', QUOTE(@pw_respaldo));
  PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  SET @sql = CONCAT('CREATE USER IF NOT EXISTS ''ss_sello''@''localhost'' IDENTIFIED BY ', QUOTE(@pw_sello));
  PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

  -- ss_app: la aplicación web
  GRANT SELECT, INSERT, UPDATE         ON servicio_social.usuarios        TO 'ss_app'@'localhost';
  GRANT SELECT, INSERT, UPDATE         ON servicio_social.alumnos         TO 'ss_app'@'localhost';
  GRANT SELECT, INSERT, UPDATE         ON servicio_social.expedientes     TO 'ss_app'@'localhost';
  GRANT SELECT, INSERT, UPDATE, DELETE ON servicio_social.asistencias     TO 'ss_app'@'localhost';
  GRANT SELECT, INSERT, DELETE         ON servicio_social.firmas_semana   TO 'ss_app'@'localhost';
  GRANT SELECT, INSERT, UPDATE, DELETE ON servicio_social.dias_inhabiles  TO 'ss_app'@'localhost';
  GRANT SELECT, INSERT, UPDATE         ON servicio_social.configuracion   TO 'ss_app'@'localhost';
  GRANT SELECT, INSERT, UPDATE, DELETE ON servicio_social.tokens_reset    TO 'ss_app'@'localhost';
  GRANT SELECT, INSERT, DELETE         ON servicio_social.intentos_login  TO 'ss_app'@'localhost';
  GRANT SELECT, INSERT, UPDATE         ON servicio_social.respaldos       TO 'ss_app'@'localhost';
  GRANT SELECT, INSERT                 ON servicio_social.historial       TO 'ss_app'@'localhost';
  GRANT SELECT                         ON servicio_social.schema_migraciones TO 'ss_app'@'localhost';

  -- ss_respaldo: volcado nocturno (mysqldump --single-transaction --triggers)
  GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER ON servicio_social.* TO 'ss_respaldo'@'localhost';

  -- ss_sello: sellado nocturno del historial (solo hash y sellado_en)
  GRANT SELECT ON servicio_social.historial TO 'ss_sello'@'localhost';
  GRANT UPDATE (hash, sellado_en) ON servicio_social.historial TO 'ss_sello'@'localhost';

  INSERT INTO servicio_social.schema_migraciones (version) VALUES ('004_usuarios_aplicacion');
END$$
DELIMITER ;

SET @pw_app = NULL; SET @pw_respaldo = NULL; SET @pw_sello = NULL; SET @sql = NULL;
