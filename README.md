# Servicio Social UTN

Sistema privado para administrar los expedientes y las horas de servicio social. PHP 8 + MariaDB, sin frameworks.

El diseño técnico completo (tablas, reglas, cálculos, seguridad y respaldos) está en el documento **Servicio Social UTN · Diseño técnico (Etapa 2)**.

## Estado

| Etapa | Estado |
| --- | --- |
| 1. Análisis y arquitectura | Aprobada |
| 2. Base de datos | SQL listo y verificado en MariaDB 10.11 (63 pruebas). Falta confirmar la versión del servidor con TI |
| 3. Autenticación | Pendiente |

## Requisitos

- PHP 8.1 o más nuevo, con `pdo_mysql` y soporte Argon2id (`password_hash(..., PASSWORD_ARGON2ID)`).
- MariaDB **10.2.7 o más nuevo** (CHECK, tipo JSON, bloques `BEGIN NOT ATOMIC`); recomendado 10.4 o más nuevo.
- Permiso para crear triggers en la base `servicio_social`.

## Instalar la base de datos

Las contraseñas nunca van en los archivos: se escriben en la sesión del cliente `mariadb` y se borran al terminar.

```bash
cd database/migraciones

# 1. TI, con root: base y usuario de migraciones
mariadb -u root -p
  SET @pw_admin = '...';
  SOURCE 000_base_y_admin.sql;
  exit

# 2. Con ss_admin: esquema, triggers y configuración inicial
mariadb -u ss_admin -p servicio_social < 001_esquema.sql
mariadb -u ss_admin -p servicio_social < 002_triggers.sql
mariadb -u ss_admin -p servicio_social < 003_configuracion_inicial.sql

# 3. TI, con root: usuarios de la aplicación
mariadb -u root -p
  SET @pw_app = '...'; SET @pw_respaldo = '...'; SET @pw_sello = '...';
  SOURCE 004_usuarios_aplicacion.sql;
  exit

# 4. Configuración y primer administrador
cp config/config.ejemplo.php config/config.local.php   # llenar valores, chmod 640
php bin/crear-admin.php
```

| Usuario MariaDB | Uso |
| --- | --- |
| `ss_admin` | Migraciones y reemplazo completo. Dueño de los triggers. La aplicación no lo usa |
| `ss_app` | La aplicación web. Sin `DELETE` en alumnos y expedientes, sin `UPDATE` ni `DELETE` en historial |
| `ss_respaldo` | `mysqldump --single-transaction --triggers --no-tablespaces servicio_social` |
| `ss_sello` | Sellado nocturno del historial (solo columnas `hash` y `sellado_en`) |

## Pruebas de la base de datos

En una MariaDB **local de desarrollo** (el script borra la base):

```bash
export PW_ADMIN=... PW_APP=... PW_RESPALDO=... PW_SELLO=...
tools/instalar_bd_dev.sh
SS_CONFIG=/ruta/config.de.prueba.php php bin/crear-admin.php   # administrador ficticio
php tests/bd/verificar_bd.php
```

Las pruebas usan solo datos ficticios y revisan reglas de captura, conclusión, triggers, permisos, historial y la regla de los 6 meses en PHP y MariaDB.

## Estructura

```
bin/            scripts de consola (crear-admin.php)
config/         config.ejemplo.php (config.local.php solo en el servidor)
database/       migraciones SQL numeradas
src/Dominio/    reglas de negocio sin base de datos (Periodo, Calculadora)
storage/        respaldos, sesiones y logs (fuera de git)
tests/          pruebas
tools/          utilidades de desarrollo
```
