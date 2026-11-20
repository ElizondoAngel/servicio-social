-- =====================================================================
-- 003 · Valores iniciales de configuración
-- Lo ejecuta ss_admin. Son las reglas iniciales del sistema, sujetas a la
-- validación del Lic. César Leal Guerrero; se cambian después desde la
-- aplicación y cada cambio queda en el historial.
-- No contiene datos de alumnos ni de usuarios.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @motivo_app = 'Valores iniciales de configuración (migración 003)';

INSERT INTO configuracion (clave, valor, descripcion) VALUES
  ('horas_requeridas',         '480',   'Horas mínimas para concluir un expediente'),
  ('meses_requeridos',         '6',     'Meses naturales mínimos desde la fecha de inicio'),
  ('jornada_prevista',         '4',     'Horas por día que se usan para proyectar los días futuros'),
  ('jornadas_permitidas',      '4,5,6', 'Horas que se pueden registrar en una asistencia'),
  ('feriados_acreditan',       '1',     'Valor inicial para expedientes nuevos: 1 = los feriados acreditan horas'),
  ('horas_por_feriado',        '4',     'Valor inicial para expedientes nuevos: horas que acredita un feriado'),
  ('umbral_proximo_horas',     '40',    'Horas restantes a partir de las cuales un expediente está próximo a concluir'),
  ('inactividad_minutos',      '30',    'Minutos sin actividad antes de cerrar la sesión'),
  ('sesion_max_horas',         '10',    'Duración máxima de una sesión'),
  ('respaldos_retener',        '30',    'Respaldos JSON que se conservan'),
  ('intentos_max_correo',      '5',     'Intentos fallidos por correo antes de bloquear'),
  ('intentos_max_ip',          '20',    'Intentos fallidos por IP antes de bloquear'),
  ('intentos_ventana_minutos', '15',    'Ventana para contar intentos fallidos');

SET @motivo_app = NULL;

INSERT INTO schema_migraciones (version) VALUES ('003_configuracion_inicial');
