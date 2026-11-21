-- =====================================================================
-- 002 · Triggers de protección e historial
-- Lo ejecuta ss_admin (queda como DEFINER):
--   mariadb -u ss_admin -p servicio_social < 002_triggers.sql
--
-- Variables de sesión que fija la aplicación al iniciar cada transacción
-- (y limpia al terminar):
--   @usuario_app  id del usuario que hace el cambio
--   @motivo_app   motivo (correcciones, autorizaciones, reaperturas)
--   @ip_app       IP real de la petición
--   @agente_app   navegador
-- Si el cambio no viene de la aplicación, @usuario_app queda nulo y
-- usuario_bd (USER()) muestra quién se conectó a MariaDB.
--
-- Los bloques AFTER (historial) se generan con tools/gen_triggers.py.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
DELIMITER $$

-- ---------------------------------------------------------------------
-- asistencias: no se tocan expedientes concluidos o de baja
-- ---------------------------------------------------------------------
CREATE TRIGGER trg_asistencias_bi BEFORE INSERT ON asistencias FOR EACH ROW
BEGIN
  IF (SELECT estado FROM expedientes WHERE id = NEW.expediente_id) IN ('concluido', 'baja') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EXPEDIENTE_CERRADO: el expediente está concluido o de baja; reábrelo primero.';
  END IF;
  SET NEW.version = 1;
END$$

CREATE TRIGGER trg_asistencias_bu BEFORE UPDATE ON asistencias FOR EACH ROW
BEGIN
  IF NEW.expediente_id <> OLD.expediente_id OR NEW.fecha <> OLD.fecha THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REGISTRO_INMOVIBLE: quita el registro y captúralo en la fecha correcta.';
  END IF;
  IF (SELECT estado FROM expedientes WHERE id = OLD.expediente_id) IN ('concluido', 'baja') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EXPEDIENTE_CERRADO: el expediente está concluido o de baja; reábrelo primero.';
  END IF;
  SET NEW.version = OLD.version + 1;
END$$

CREATE TRIGGER trg_asistencias_bd BEFORE DELETE ON asistencias FOR EACH ROW
BEGIN
  IF (SELECT estado FROM expedientes WHERE id = OLD.expediente_id) IN ('concluido', 'baja') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EXPEDIENTE_CERRADO: el expediente está concluido o de baja; reábrelo primero.';
  END IF;
END$$

-- ---------------------------------------------------------------------
-- firmas_semana: igual que asistencias; una firma no se edita, se quita
-- ---------------------------------------------------------------------
CREATE TRIGGER trg_firmas_semana_bi BEFORE INSERT ON firmas_semana FOR EACH ROW
BEGIN
  IF (SELECT estado FROM expedientes WHERE id = NEW.expediente_id) IN ('concluido', 'baja') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EXPEDIENTE_CERRADO: el expediente está concluido o de baja; reábrelo primero.';
  END IF;
END$$

CREATE TRIGGER trg_firmas_semana_bu BEFORE UPDATE ON firmas_semana FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIRMA_INMUTABLE: una firma no se edita; quítala y regístrala de nuevo.';
END$$

CREATE TRIGGER trg_firmas_semana_bd BEFORE DELETE ON firmas_semana FOR EACH ROW
BEGIN
  IF (SELECT estado FROM expedientes WHERE id = OLD.expediente_id) IN ('concluido', 'baja') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EXPEDIENTE_CERRADO: el expediente está concluido o de baja; reábrelo primero.';
  END IF;
END$$

-- ---------------------------------------------------------------------
-- expedientes: mínimos generales, conclusión con horas suficientes,
-- reapertura con motivo, expediente concluido de solo lectura
-- ---------------------------------------------------------------------
CREATE TRIGGER trg_expedientes_bi BEFORE INSERT ON expedientes FOR EACH ROW
BEGIN
  DECLARE v_min_h INT DEFAULT COALESCE((SELECT CAST(valor AS UNSIGNED) FROM configuracion WHERE clave = 'horas_requeridas'), 480);
  DECLARE v_min_m INT DEFAULT COALESCE((SELECT CAST(valor AS UNSIGNED) FROM configuracion WHERE clave = 'meses_requeridos'), 6);
  IF NEW.horas_requeridas < v_min_h THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MINIMO_HORAS: las horas requeridas no pueden ser menores al mínimo general.';
  END IF;
  IF NEW.meses_requeridos < v_min_m THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MINIMO_MESES: los meses requeridos no pueden ser menores al mínimo general.';
  END IF;
  IF NEW.estado = 'concluido' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ALTA_CONCLUIDA: un expediente se crea abierto y se concluye después.';
  END IF;
  SET NEW.version = 1;
END$$

CREATE TRIGGER trg_expedientes_bu BEFORE UPDATE ON expedientes FOR EACH ROW
BEGIN
  DECLARE v_min_h INT DEFAULT COALESCE((SELECT CAST(valor AS UNSIGNED) FROM configuracion WHERE clave = 'horas_requeridas'), 480);
  DECLARE v_min_m INT DEFAULT COALESCE((SELECT CAST(valor AS UNSIGNED) FROM configuracion WHERE clave = 'meses_requeridos'), 6);
  DECLARE v_horas INT DEFAULT 0;

  IF NEW.alumno_id <> OLD.alumno_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ALUMNO_INMOVIBLE: un expediente no cambia de alumno.';
  END IF;
  IF OLD.estado = 'concluido' AND NEW.estado = 'concluido' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EXPEDIENTE_CERRADO: reabre el expediente antes de modificarlo.';
  END IF;
  IF NEW.horas_requeridas <> OLD.horas_requeridas AND NEW.horas_requeridas < v_min_h THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MINIMO_HORAS: las horas requeridas no pueden ser menores al mínimo general.';
  END IF;
  IF NEW.meses_requeridos <> OLD.meses_requeridos AND NEW.meses_requeridos < v_min_m THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MINIMO_MESES: los meses requeridos no pueden ser menores al mínimo general.';
  END IF;

  IF NEW.estado = 'concluido' AND OLD.estado <> 'concluido' THEN
    SET v_horas = (SELECT COALESCE(SUM(horas_acreditadas), 0) FROM asistencias WHERE expediente_id = NEW.id);
    IF v_horas < GREATEST(NEW.horas_requeridas, v_min_h) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HORAS_INSUFICIENTES: no se puede concluir sin las horas requeridas.';
    END IF;
    IF NEW.fecha_conclusion < DATE_ADD(NEW.fecha_inicio, INTERVAL GREATEST(NEW.meses_requeridos, v_min_m) MONTH) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PERIODO_INCOMPLETO: no se puede concluir antes de cumplir los meses requeridos.';
    END IF;
  END IF;

  IF OLD.estado = 'concluido' AND NEW.estado <> 'concluido' AND COALESCE(TRIM(@motivo_app), '') = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MOTIVO_REQUERIDO: reabrir un expediente concluido exige un motivo.';
  END IF;

  SET NEW.version = OLD.version + 1;
END$$

-- ---------------------------------------------------------------------
-- historial: nadie lo edita ni lo borra. La única actualización
-- permitida es el sellado (hash y sellado_en) de una fila sin sellar.
-- ---------------------------------------------------------------------
CREATE TRIGGER trg_historial_bu BEFORE UPDATE ON historial FOR EACH ROW
BEGIN
  IF NOT (OLD.hash IS NULL AND NEW.hash IS NOT NULL AND NEW.sellado_en IS NOT NULL
          AND NEW.id <=> OLD.id AND NEW.origen <=> OLD.origen AND NEW.usuario_id <=> OLD.usuario_id
          AND NEW.usuario_bd <=> OLD.usuario_bd AND NEW.accion <=> OLD.accion AND NEW.entidad <=> OLD.entidad
          AND NEW.entidad_id <=> OLD.entidad_id AND NEW.expediente_id <=> OLD.expediente_id
          AND NEW.antes <=> OLD.antes AND NEW.despues <=> OLD.despues AND NEW.motivo <=> OLD.motivo
          AND NEW.ip <=> OLD.ip AND NEW.agente <=> OLD.agente AND NEW.creado_en <=> OLD.creado_en) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HISTORIAL_INMUTABLE: el historial no se modifica.';
  END IF;
END$$

CREATE TRIGGER trg_historial_bd BEFORE DELETE ON historial FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HISTORIAL_INMUTABLE: el historial no se borra.';
END$$

-- ---------------------------------------------------------------------
-- Historial automático de cambios de datos
-- ---------------------------------------------------------------------
CREATE TRIGGER trg_asistencias_ai AFTER INSERT ON asistencias FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'crear', 'asistencias', NEW.id, NEW.expediente_id, NULL, JSON_OBJECT('expediente_id', NEW.expediente_id, 'fecha', NEW.fecha, 'estado', NEW.estado, 'horas_trabajadas', NEW.horas_trabajadas, 'horas_acreditadas', NEW.horas_acreditadas, 'dia_inhabil_id', NEW.dia_inhabil_id, 'justificacion', NEW.justificacion, 'hora_entrada', NEW.hora_entrada, 'hora_salida', NEW.hora_salida, 'observaciones', NEW.observaciones, 'autorizado_por', NEW.autorizado_por, 'motivo_autorizacion', NEW.motivo_autorizacion, 'registrado_por', NEW.registrado_por, 'actualizado_por', NEW.actualizado_por, 'version', NEW.version), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_asistencias_au AFTER UPDATE ON asistencias FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'actualizar', 'asistencias', NEW.id, NEW.expediente_id, JSON_OBJECT('expediente_id', OLD.expediente_id, 'fecha', OLD.fecha, 'estado', OLD.estado, 'horas_trabajadas', OLD.horas_trabajadas, 'horas_acreditadas', OLD.horas_acreditadas, 'dia_inhabil_id', OLD.dia_inhabil_id, 'justificacion', OLD.justificacion, 'hora_entrada', OLD.hora_entrada, 'hora_salida', OLD.hora_salida, 'observaciones', OLD.observaciones, 'autorizado_por', OLD.autorizado_por, 'motivo_autorizacion', OLD.motivo_autorizacion, 'registrado_por', OLD.registrado_por, 'actualizado_por', OLD.actualizado_por, 'version', OLD.version), JSON_OBJECT('expediente_id', NEW.expediente_id, 'fecha', NEW.fecha, 'estado', NEW.estado, 'horas_trabajadas', NEW.horas_trabajadas, 'horas_acreditadas', NEW.horas_acreditadas, 'dia_inhabil_id', NEW.dia_inhabil_id, 'justificacion', NEW.justificacion, 'hora_entrada', NEW.hora_entrada, 'hora_salida', NEW.hora_salida, 'observaciones', NEW.observaciones, 'autorizado_por', NEW.autorizado_por, 'motivo_autorizacion', NEW.motivo_autorizacion, 'registrado_por', NEW.registrado_por, 'actualizado_por', NEW.actualizado_por, 'version', NEW.version), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_asistencias_ad AFTER DELETE ON asistencias FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'quitar', 'asistencias', OLD.id, OLD.expediente_id, JSON_OBJECT('expediente_id', OLD.expediente_id, 'fecha', OLD.fecha, 'estado', OLD.estado, 'horas_trabajadas', OLD.horas_trabajadas, 'horas_acreditadas', OLD.horas_acreditadas, 'dia_inhabil_id', OLD.dia_inhabil_id, 'justificacion', OLD.justificacion, 'hora_entrada', OLD.hora_entrada, 'hora_salida', OLD.hora_salida, 'observaciones', OLD.observaciones, 'autorizado_por', OLD.autorizado_por, 'motivo_autorizacion', OLD.motivo_autorizacion, 'registrado_por', OLD.registrado_por, 'actualizado_por', OLD.actualizado_por, 'version', OLD.version), NULL, NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_expedientes_ai AFTER INSERT ON expedientes FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'crear', 'expedientes', NEW.id, NEW.id, NULL, JSON_OBJECT('alumno_id', NEW.alumno_id, 'unidad_administrativa', NEW.unidad_administrativa, 'responsable', NEW.responsable, 'hora_entrada', NEW.hora_entrada, 'hora_salida', NEW.hora_salida, 'fecha_inicio', NEW.fecha_inicio, 'fecha_fin_autorizada', NEW.fecha_fin_autorizada, 'horas_requeridas', NEW.horas_requeridas, 'meses_requeridos', NEW.meses_requeridos, 'jornada_prevista', NEW.jornada_prevista, 'feriados_acreditan', NEW.feriados_acreditan, 'horas_por_feriado', NEW.horas_por_feriado, 'estado', NEW.estado, 'fecha_conclusion', NEW.fecha_conclusion, 'resumen_conclusion', JSON_EXTRACT(NEW.resumen_conclusion, '$'), 'observaciones', NEW.observaciones, 'creado_por', NEW.creado_por, 'version', NEW.version), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_expedientes_au AFTER UPDATE ON expedientes FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), CASE WHEN NEW.estado = 'concluido' AND OLD.estado <> 'concluido' THEN 'concluir' WHEN OLD.estado = 'concluido' AND NEW.estado <> 'concluido' THEN 'reabrir' WHEN NEW.estado <> OLD.estado THEN 'cambiar_estado' ELSE 'actualizar' END, 'expedientes', NEW.id, NEW.id, JSON_OBJECT('alumno_id', OLD.alumno_id, 'unidad_administrativa', OLD.unidad_administrativa, 'responsable', OLD.responsable, 'hora_entrada', OLD.hora_entrada, 'hora_salida', OLD.hora_salida, 'fecha_inicio', OLD.fecha_inicio, 'fecha_fin_autorizada', OLD.fecha_fin_autorizada, 'horas_requeridas', OLD.horas_requeridas, 'meses_requeridos', OLD.meses_requeridos, 'jornada_prevista', OLD.jornada_prevista, 'feriados_acreditan', OLD.feriados_acreditan, 'horas_por_feriado', OLD.horas_por_feriado, 'estado', OLD.estado, 'fecha_conclusion', OLD.fecha_conclusion, 'resumen_conclusion', JSON_EXTRACT(OLD.resumen_conclusion, '$'), 'observaciones', OLD.observaciones, 'creado_por', OLD.creado_por, 'version', OLD.version), JSON_OBJECT('alumno_id', NEW.alumno_id, 'unidad_administrativa', NEW.unidad_administrativa, 'responsable', NEW.responsable, 'hora_entrada', NEW.hora_entrada, 'hora_salida', NEW.hora_salida, 'fecha_inicio', NEW.fecha_inicio, 'fecha_fin_autorizada', NEW.fecha_fin_autorizada, 'horas_requeridas', NEW.horas_requeridas, 'meses_requeridos', NEW.meses_requeridos, 'jornada_prevista', NEW.jornada_prevista, 'feriados_acreditan', NEW.feriados_acreditan, 'horas_por_feriado', NEW.horas_por_feriado, 'estado', NEW.estado, 'fecha_conclusion', NEW.fecha_conclusion, 'resumen_conclusion', JSON_EXTRACT(NEW.resumen_conclusion, '$'), 'observaciones', NEW.observaciones, 'creado_por', NEW.creado_por, 'version', NEW.version), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_expedientes_ad AFTER DELETE ON expedientes FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'quitar', 'expedientes', OLD.id, OLD.id, JSON_OBJECT('alumno_id', OLD.alumno_id, 'unidad_administrativa', OLD.unidad_administrativa, 'responsable', OLD.responsable, 'hora_entrada', OLD.hora_entrada, 'hora_salida', OLD.hora_salida, 'fecha_inicio', OLD.fecha_inicio, 'fecha_fin_autorizada', OLD.fecha_fin_autorizada, 'horas_requeridas', OLD.horas_requeridas, 'meses_requeridos', OLD.meses_requeridos, 'jornada_prevista', OLD.jornada_prevista, 'feriados_acreditan', OLD.feriados_acreditan, 'horas_por_feriado', OLD.horas_por_feriado, 'estado', OLD.estado, 'fecha_conclusion', OLD.fecha_conclusion, 'resumen_conclusion', JSON_EXTRACT(OLD.resumen_conclusion, '$'), 'observaciones', OLD.observaciones, 'creado_por', OLD.creado_por, 'version', OLD.version), NULL, NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_alumnos_ai AFTER INSERT ON alumnos FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'crear', 'alumnos', NEW.id, NULL, NULL, JSON_OBJECT('matricula', NEW.matricula, 'nombre', NEW.nombre, 'apellido_paterno', NEW.apellido_paterno, 'apellido_materno', NEW.apellido_materno, 'carrera', NEW.carrera, 'institucion', NEW.institucion, 'correo', NEW.correo, 'telefono', NEW.telefono, 'archivado', NEW.archivado), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_alumnos_au AFTER UPDATE ON alumnos FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'actualizar', 'alumnos', NEW.id, NULL, JSON_OBJECT('matricula', OLD.matricula, 'nombre', OLD.nombre, 'apellido_paterno', OLD.apellido_paterno, 'apellido_materno', OLD.apellido_materno, 'carrera', OLD.carrera, 'institucion', OLD.institucion, 'correo', OLD.correo, 'telefono', OLD.telefono, 'archivado', OLD.archivado), JSON_OBJECT('matricula', NEW.matricula, 'nombre', NEW.nombre, 'apellido_paterno', NEW.apellido_paterno, 'apellido_materno', NEW.apellido_materno, 'carrera', NEW.carrera, 'institucion', NEW.institucion, 'correo', NEW.correo, 'telefono', NEW.telefono, 'archivado', NEW.archivado), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_alumnos_ad AFTER DELETE ON alumnos FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'quitar', 'alumnos', OLD.id, NULL, JSON_OBJECT('matricula', OLD.matricula, 'nombre', OLD.nombre, 'apellido_paterno', OLD.apellido_paterno, 'apellido_materno', OLD.apellido_materno, 'carrera', OLD.carrera, 'institucion', OLD.institucion, 'correo', OLD.correo, 'telefono', OLD.telefono, 'archivado', OLD.archivado), NULL, NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_firmas_semana_ai AFTER INSERT ON firmas_semana FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'crear', 'firmas_semana', NEW.id, NEW.expediente_id, NULL, JSON_OBJECT('expediente_id', NEW.expediente_id, 'semana_inicio', NEW.semana_inicio, 'registrado_por', NEW.registrado_por, 'firmado_en', NEW.firmado_en), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_firmas_semana_ad AFTER DELETE ON firmas_semana FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'quitar', 'firmas_semana', OLD.id, OLD.expediente_id, JSON_OBJECT('expediente_id', OLD.expediente_id, 'semana_inicio', OLD.semana_inicio, 'registrado_por', OLD.registrado_por, 'firmado_en', OLD.firmado_en), NULL, NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_dias_inhabiles_ai AFTER INSERT ON dias_inhabiles FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'crear', 'dias_inhabiles', NEW.id, NULL, NULL, JSON_OBJECT('fecha', NEW.fecha, 'tipo', NEW.tipo, 'descripcion', NEW.descripcion), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_dias_inhabiles_au AFTER UPDATE ON dias_inhabiles FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'actualizar', 'dias_inhabiles', NEW.id, NULL, JSON_OBJECT('fecha', OLD.fecha, 'tipo', OLD.tipo, 'descripcion', OLD.descripcion), JSON_OBJECT('fecha', NEW.fecha, 'tipo', NEW.tipo, 'descripcion', NEW.descripcion), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_dias_inhabiles_ad AFTER DELETE ON dias_inhabiles FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'quitar', 'dias_inhabiles', OLD.id, NULL, JSON_OBJECT('fecha', OLD.fecha, 'tipo', OLD.tipo, 'descripcion', OLD.descripcion), NULL, NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_configuracion_ai AFTER INSERT ON configuracion FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'crear', 'configuracion', NULL, NULL, NULL, JSON_OBJECT('clave', NEW.clave, 'valor', NEW.valor, 'descripcion', NEW.descripcion, 'actualizado_por', NEW.actualizado_por), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_configuracion_au AFTER UPDATE ON configuracion FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'actualizar', 'configuracion', NULL, NULL, JSON_OBJECT('clave', OLD.clave, 'valor', OLD.valor, 'descripcion', OLD.descripcion, 'actualizado_por', OLD.actualizado_por), JSON_OBJECT('clave', NEW.clave, 'valor', NEW.valor, 'descripcion', NEW.descripcion, 'actualizado_por', NEW.actualizado_por), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_configuracion_ad AFTER DELETE ON configuracion FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'quitar', 'configuracion', NULL, NULL, JSON_OBJECT('clave', OLD.clave, 'valor', OLD.valor, 'descripcion', OLD.descripcion, 'actualizado_por', OLD.actualizado_por), NULL, NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_usuarios_ai AFTER INSERT ON usuarios FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'crear', 'usuarios', NEW.id, NULL, NULL, JSON_OBJECT('nombre', NEW.nombre, 'correo', NEW.correo, 'rol', NEW.rol, 'activo', NEW.activo), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

CREATE TRIGGER trg_usuarios_au AFTER UPDATE ON usuarios FOR EACH ROW
BEGIN
  IF NOT (NEW.nombre <=> OLD.nombre AND NEW.correo <=> OLD.correo AND NEW.rol <=> OLD.rol
          AND NEW.activo <=> OLD.activo AND NEW.password_hash <=> OLD.password_hash) THEN
    INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
    VALUES ('trigger', @usuario_app, USER(), 'actualizar', 'usuarios', NEW.id, NULL, JSON_OBJECT('nombre', OLD.nombre, 'correo', OLD.correo, 'rol', OLD.rol, 'activo', OLD.activo), JSON_INSERT(JSON_OBJECT('nombre', NEW.nombre, 'correo', NEW.correo, 'rol', NEW.rol, 'activo', NEW.activo), '$.password_cambiada', IF(NEW.password_hash <=> OLD.password_hash, FALSE, TRUE)), NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
  END IF;
END$$

CREATE TRIGGER trg_usuarios_ad AFTER DELETE ON usuarios FOR EACH ROW
BEGIN
  INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, expediente_id, antes, despues, motivo, ip, agente)
  VALUES ('trigger', @usuario_app, USER(), 'quitar', 'usuarios', OLD.id, NULL, JSON_OBJECT('nombre', OLD.nombre, 'correo', OLD.correo, 'rol', OLD.rol, 'activo', OLD.activo), NULL, NULLIF(TRIM(@motivo_app), ''), @ip_app, @agente_app);
END$$

DELIMITER ;

INSERT INTO schema_migraciones (version) VALUES ('002_triggers');
