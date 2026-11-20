-- =====================================================================
-- 005 · Versión de sesión por usuario
-- Lo ejecuta ss_admin. Cada sesión guarda la versión con la que se abrió;
-- al cambiar la contraseña la versión sube y todas las sesiones abiertas
-- de ese usuario dejan de valer en su siguiente petición.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE usuarios
  ADD COLUMN sesion_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER activo;

INSERT INTO schema_migraciones (version) VALUES ('005_sesion_version');
