-- =====================================================================
-- 001 · Esquema
-- Lo ejecuta ss_admin:  mariadb -u ss_admin -p servicio_social < 001_esquema.sql
-- InnoDB, utf8mb4_unicode_ci declarado en cada tabla.
-- Nada se borra en cascada: alumnos, expedientes y usuarios se archivan
-- o desactivan; las llaves foráneas usan RESTRICT.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS schema_migraciones (
  version     VARCHAR(50) NOT NULL,
  aplicada_en DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- usuarios: administradores del sistema
-- password_hash es nulo solo en usuarios creados desactivados al
-- restaurar un respaldo (los respaldos nunca llevan contraseñas).
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(120) NOT NULL,
  correo         VARCHAR(190) NOT NULL,
  password_hash  VARCHAR(255) NULL,
  rol            VARCHAR(30)  NOT NULL DEFAULT 'admin',
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  ultimo_acceso  DATETIME     NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_correo (correo),
  CONSTRAINT chk_usuarios_rol            CHECK (rol IN ('admin')),
  CONSTRAINT chk_usuarios_activo_bool    CHECK (activo IN (0, 1)),
  CONSTRAINT chk_usuarios_activo_con_pw  CHECK (activo = 0 OR password_hash IS NOT NULL),
  CONSTRAINT chk_usuarios_nombre         CHECK (TRIM(nombre) <> ''),
  CONSTRAINT chk_usuarios_correo         CHECK (correo LIKE '%_@_%._%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- alumnos
-- ---------------------------------------------------------------------
CREATE TABLE alumnos (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  matricula        VARCHAR(20)  NOT NULL,
  nombre           VARCHAR(80)  NOT NULL,
  apellido_paterno VARCHAR(80)  NOT NULL,
  apellido_materno VARCHAR(80)  NULL,
  carrera          VARCHAR(150) NOT NULL,
  institucion      VARCHAR(150) NOT NULL DEFAULT 'Universidad Tecnológica de Nezahualcóyotl',
  correo           VARCHAR(190) NULL,
  telefono         VARCHAR(15)  NULL,
  archivado        TINYINT(1)   NOT NULL DEFAULT 0,
  creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alumnos_matricula (matricula),
  KEY ix_alumnos_nombre (apellido_paterno, apellido_materno, nombre),
  CONSTRAINT chk_alumnos_matricula CHECK (TRIM(matricula) <> ''),
  CONSTRAINT chk_alumnos_nombre    CHECK (TRIM(nombre) <> '' AND TRIM(apellido_paterno) <> ''),
  CONSTRAINT chk_alumnos_carrera   CHECK (TRIM(carrera) <> ''),
  CONSTRAINT chk_alumnos_archivado CHECK (archivado IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- dias_inhabiles: catálogo general
-- festivo: puede acreditar horas según el expediente
-- cierre:  vacaciones o cierre de la UTN; nunca acredita
-- ---------------------------------------------------------------------
CREATE TABLE dias_inhabiles (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha          DATE         NOT NULL,
  tipo           VARCHAR(10)  NOT NULL,
  descripcion    VARCHAR(150) NOT NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dias_inhabiles_fecha (fecha),
  CONSTRAINT chk_dias_tipo        CHECK (tipo IN ('festivo', 'cierre')),
  CONSTRAINT chk_dias_descripcion CHECK (TRIM(descripcion) <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- expedientes: un periodo de servicio social de un alumno
-- fecha_fin_autorizada solo limita la captura. La fecha en que se
-- cumplen los meses se calcula siempre: fecha_inicio + meses_requeridos.
-- ---------------------------------------------------------------------
CREATE TABLE expedientes (
  id                    INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  alumno_id             INT UNSIGNED      NOT NULL,
  unidad_administrativa VARCHAR(150)      NOT NULL,
  responsable           VARCHAR(150)      NOT NULL,
  hora_entrada          TIME              NULL,
  hora_salida           TIME              NULL,
  fecha_inicio          DATE              NOT NULL,
  fecha_fin_autorizada  DATE              NOT NULL,
  horas_requeridas      SMALLINT UNSIGNED NOT NULL DEFAULT 480,
  meses_requeridos      TINYINT UNSIGNED  NOT NULL DEFAULT 6,
  jornada_prevista      TINYINT UNSIGNED  NOT NULL DEFAULT 4,
  feriados_acreditan    TINYINT(1)        NOT NULL DEFAULT 1,
  horas_por_feriado     TINYINT UNSIGNED  NOT NULL DEFAULT 4,
  estado                VARCHAR(20)       NOT NULL DEFAULT 'activo',
  fecha_conclusion      DATE              NULL,
  resumen_conclusion    JSON              NULL,
  observaciones         TEXT              NULL,
  abierto_unico         INT UNSIGNED AS (IF(estado IN ('activo', 'suspendido'), alumno_id, NULL)) PERSISTENT,
  version               INT UNSIGNED      NOT NULL DEFAULT 1,
  creado_por            INT UNSIGNED      NOT NULL,
  creado_en             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expedientes_alumno_inicio (alumno_id, fecha_inicio),
  UNIQUE KEY uq_expedientes_abierto (abierto_unico),
  KEY ix_expedientes_estado (estado),
  CONSTRAINT fk_expedientes_alumno     FOREIGN KEY (alumno_id)  REFERENCES alumnos (id)  ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_expedientes_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_exp_textos          CHECK (TRIM(unidad_administrativa) <> '' AND TRIM(responsable) <> ''),
  CONSTRAINT chk_exp_fin_autorizada  CHECK (fecha_fin_autorizada > fecha_inicio),
  CONSTRAINT chk_exp_horas_req       CHECK (horas_requeridas > 0),
  CONSTRAINT chk_exp_meses_req       CHECK (meses_requeridos > 0),
  CONSTRAINT chk_exp_jornada         CHECK (jornada_prevista BETWEEN 4 AND 6),
  CONSTRAINT chk_exp_feriados_bool   CHECK (feriados_acreditan IN (0, 1)),
  CONSTRAINT chk_exp_horas_feriado   CHECK (horas_por_feriado BETWEEN 0 AND 6),
  CONSTRAINT chk_exp_horario         CHECK ((hora_entrada IS NULL AND hora_salida IS NULL)
                                            OR (hora_entrada IS NOT NULL AND hora_salida IS NOT NULL AND hora_salida > hora_entrada)),
  CONSTRAINT chk_exp_estado          CHECK (estado IN ('activo', 'suspendido', 'concluido', 'baja')),
  CONSTRAINT chk_exp_conclusion      CHECK ((estado = 'concluido') = (fecha_conclusion IS NOT NULL)),
  CONSTRAINT chk_exp_seis_meses      CHECK (fecha_conclusion IS NULL
                                            OR fecha_conclusion >= DATE_ADD(fecha_inicio, INTERVAL meses_requeridos MONTH)),
  CONSTRAINT chk_exp_resumen         CHECK (estado <> 'concluido' OR resumen_conclusion IS NOT NULL),
  CONSTRAINT chk_exp_resumen_json    CHECK (resumen_conclusion IS NULL OR JSON_VALID(resumen_conclusion))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- asistencias: un registro por expediente y día. Día pendiente = sin fila.
-- ---------------------------------------------------------------------
CREATE TABLE asistencias (
  id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  expediente_id       INT UNSIGNED     NOT NULL,
  fecha               DATE             NOT NULL,
  estado              VARCHAR(12)      NOT NULL,
  horas_trabajadas    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  horas_acreditadas   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  dia_inhabil_id      INT UNSIGNED     NULL,
  justificacion       VARCHAR(255)     NULL,
  hora_entrada        TIME             NULL,
  hora_salida         TIME             NULL,
  observaciones       VARCHAR(500)     NULL,
  autorizado_por      INT UNSIGNED     NULL,
  motivo_autorizacion VARCHAR(255)     NULL,
  version             INT UNSIGNED     NOT NULL DEFAULT 1,
  registrado_por      INT UNSIGNED     NOT NULL,
  actualizado_por     INT UNSIGNED     NULL,
  creado_en           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_asistencias_expediente_fecha (expediente_id, fecha),
  KEY ix_asistencias_fecha (fecha),
  CONSTRAINT fk_asis_expediente      FOREIGN KEY (expediente_id)   REFERENCES expedientes (id)    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_asis_dia_inhabil     FOREIGN KEY (dia_inhabil_id)  REFERENCES dias_inhabiles (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_asis_autorizado_por  FOREIGN KEY (autorizado_por)  REFERENCES usuarios (id)       ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_asis_registrado_por  FOREIGN KEY (registrado_por)  REFERENCES usuarios (id)       ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_asis_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)       ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_asis_estado         CHECK (estado IN ('asistencia', 'falta', 'feriado')),
  CONSTRAINT chk_asis_jornada        CHECK (estado <> 'asistencia' OR horas_trabajadas BETWEEN 4 AND 6),
  CONSTRAINT chk_asis_falta          CHECK (estado <> 'falta' OR (horas_trabajadas = 0 AND horas_acreditadas = 0)),
  CONSTRAINT chk_asis_feriado        CHECK (estado <> 'feriado' OR horas_trabajadas = 0),
  CONSTRAINT chk_asis_acreditadas    CHECK (horas_acreditadas <= 6),
  CONSTRAINT chk_asis_justificacion  CHECK (horas_acreditadas = horas_trabajadas
                                            OR (justificacion IS NOT NULL AND TRIM(justificacion) <> '')
                                            OR (estado = 'feriado' AND dia_inhabil_id IS NOT NULL)),
  CONSTRAINT chk_asis_dia_inhabil    CHECK (dia_inhabil_id IS NULL OR estado = 'feriado'),
  CONSTRAINT chk_asis_autorizacion   CHECK ((autorizado_por IS NULL AND motivo_autorizacion IS NULL)
                                            OR (autorizado_por IS NOT NULL AND motivo_autorizacion IS NOT NULL
                                                AND TRIM(motivo_autorizacion) <> '')),
  CONSTRAINT chk_asis_horario        CHECK (hora_entrada IS NULL OR hora_salida IS NULL
                                            OR TIME_TO_SEC(TIMEDIFF(hora_salida, hora_entrada)) = horas_trabajadas * 3600)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- firmas_semana: firma semanal del responsable (lunes de cada semana)
-- ---------------------------------------------------------------------
CREATE TABLE firmas_semana (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  expediente_id  INT UNSIGNED NOT NULL,
  semana_inicio  DATE         NOT NULL,
  registrado_por INT UNSIGNED NOT NULL,
  firmado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_firmas_expediente_semana (expediente_id, semana_inicio),
  CONSTRAINT fk_firmas_expediente     FOREIGN KEY (expediente_id)  REFERENCES expedientes (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_firmas_registrado_por FOREIGN KEY (registrado_por) REFERENCES usuarios (id)    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_firmas_lunes         CHECK (WEEKDAY(semana_inicio) = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- historial: solo se agregan filas. Los cambios de datos los escriben
-- los triggers (002); la aplicación solo agrega eventos sin cambio de
-- datos. usuario_id usa RESTRICT (no SET NULL) para que ninguna acción
-- en cascada altere filas ya selladas.
-- ---------------------------------------------------------------------
CREATE TABLE historial (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  origen        VARCHAR(10)     NOT NULL,
  usuario_id    INT UNSIGNED    NULL,
  usuario_bd    VARCHAR(190)    NULL,
  accion        VARCHAR(40)     NOT NULL,
  entidad       VARCHAR(40)     NOT NULL,
  entidad_id    BIGINT UNSIGNED NULL,
  expediente_id INT UNSIGNED    NULL,
  antes         JSON            NULL,
  despues       JSON            NULL,
  motivo        VARCHAR(255)    NULL,
  ip            VARCHAR(45)     NULL,
  agente        VARCHAR(255)    NULL,
  creado_en     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  hash          CHAR(64)        NULL,
  sellado_en    DATETIME        NULL,
  PRIMARY KEY (id),
  KEY ix_historial_expediente (expediente_id, creado_en),
  KEY ix_historial_entidad (entidad, entidad_id),
  KEY ix_historial_sello (sellado_en),
  CONSTRAINT fk_historial_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_historial_origen  CHECK (origen IN ('trigger', 'app')),
  CONSTRAINT chk_historial_antes   CHECK (antes IS NULL OR JSON_VALID(antes)),
  CONSTRAINT chk_historial_despues CHECK (despues IS NULL OR JSON_VALID(despues)),
  CONSTRAINT chk_historial_sello   CHECK ((hash IS NULL) = (sellado_en IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- tokens_reset: recuperación de contraseña (solo el SHA-256 del token)
-- ---------------------------------------------------------------------
CREATE TABLE tokens_reset (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  expira_en  DATETIME     NOT NULL,
  usado_en   DATETIME     NULL,
  ip         VARCHAR(45)  NULL,
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY ix_tokens_usuario (usuario_id),
  CONSTRAINT fk_tokens_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT chk_tokens_expira CHECK (expira_en > creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- intentos_login: límite de intentos; se depuran después de 30 días
-- ---------------------------------------------------------------------
CREATE TABLE intentos_login (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  correo    VARCHAR(190)    NOT NULL,
  ip        VARCHAR(45)     NOT NULL,
  exito     TINYINT(1)      NOT NULL,
  creado_en DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_intentos_correo (correo, creado_en),
  KEY ix_intentos_ip (ip, creado_en),
  CONSTRAINT chk_intentos_exito CHECK (exito IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- respaldos
-- ---------------------------------------------------------------------
CREATE TABLE respaldos (
  id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  tipo              VARCHAR(25)     NOT NULL,
  archivo           VARCHAR(255)    NOT NULL,
  tamano_bytes      BIGINT UNSIGNED NOT NULL,
  sha256            CHAR(64)        NOT NULL,
  firma_hmac        CHAR(64)        NOT NULL,
  verificado_en     DATETIME        NULL,
  version_formato   VARCHAR(10)     NOT NULL,
  total_expedientes INT UNSIGNED    NOT NULL,
  total_asistencias INT UNSIGNED    NOT NULL,
  creado_por        INT UNSIGNED    NULL,
  creado_en         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_respaldos_archivo (archivo),
  KEY ix_respaldos_creado (creado_en),
  CONSTRAINT fk_respaldos_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_respaldos_tipo CHECK (tipo IN ('manual', 'automatico', 'previo_restauracion'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- configuracion: valores generales (clave → valor)
-- ---------------------------------------------------------------------
CREATE TABLE configuracion (
  clave           VARCHAR(60)  NOT NULL,
  valor           VARCHAR(255) NOT NULL,
  descripcion     VARCHAR(255) NOT NULL,
  actualizado_por INT UNSIGNED NULL,
  actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave),
  CONSTRAINT fk_config_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migraciones (version) VALUES ('001_esquema');
