-- =====================================================================
-- 006 · Retención de respaldos
-- Lo ejecuta ss_admin:
--   mariadb -u ss_admin -p servicio_social < 006_respaldos_retencion.sql
--
-- La retención borra el ARCHIVO de los respaldos viejos pero conserva la
-- fila como registro (ss_app no tiene DELETE sobre respaldos).
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE respaldos
  ADD COLUMN archivo_eliminado_en DATETIME NULL AFTER verificado_en,
  ADD COLUMN total_alumnos INT UNSIGNED NOT NULL DEFAULT 0 AFTER version_formato;

INSERT INTO schema_migraciones (version) VALUES ('006_respaldos_retencion');
