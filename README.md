# Servicio Social UTN

Sistema privado para administrar los expedientes y las horas de servicio social. PHP 8 + MariaDB, sin frameworks.

El diseño técnico completo (tablas, reglas, cálculos, seguridad y respaldos) está en el documento **Servicio Social UTN · Diseño técnico (Etapa 2)**.

## Estado

| Etapa | Estado |
| --- | --- |
| 1. Análisis y arquitectura | Aprobada |
| 2. Base de datos | SQL listo y verificado en MariaDB 10.11 (63 pruebas). Falta confirmar la versión del servidor con TI |
| 3. Autenticación | Lista: inicio y cierre de sesión, inactividad, protección de rutas, CSRF, límite de intentos y recuperación por correo (54 pruebas) |
| 4. Dashboard y alumnos | Pendiente |

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

# 4. Migración 005 (versión de sesión), con ss_admin
mariadb -u ss_admin -p servicio_social < 005_sesion_version.sql

# 5. Configuración y primer administrador
cp config/config.ejemplo.php config/config.local.php   # llenar valores, chmod 640
php bin/crear-admin.php
```

## Poner la aplicación en el servidor

1. En tu Mac, instala PHPMailer: `composer install --no-dev` (crea la carpeta `vendor/`).
2. Sube el proyecto completo con WinSCP, incluida `vendor/`. WinSCP no sube archivos que empiezan con punto, por eso la configuración es `config.local.php` y no `.env`.
3. En el servidor: `mkdir -p storage/{respaldos,sesiones,logs}` y `chmod 750 storage storage/*`, con el usuario de Apache como dueño.
4. Apunta el sitio a la carpeta `public/` (ejemplo en `docs/apache.conf`) con HTTPS. Si no se puede editar el VirtualHost, sube a mano `public/.htaccess`.
5. En `config.local.php`: `entorno` = `produccion`, `forzar_https` = `true`, `correo.driver` = `smtp` y los datos del SMTP institucional.

| Usuario MariaDB | Uso |
| --- | --- |
| `ss_admin` | Migraciones y reemplazo completo. Dueño de los triggers. La aplicación no lo usa |
| `ss_app` | La aplicación web. Sin `DELETE` en alumnos y expedientes, sin `UPDATE` ni `DELETE` en historial |
| `ss_respaldo` | `mysqldump --single-transaction --triggers --no-tablespaces servicio_social` |
| `ss_sello` | Sellado nocturno del historial (solo columnas `hash` y `sellado_en`) |

## Pruebas

Todo de una vez, en una MariaDB **local de desarrollo** (el script borra y reinstala la base):

```bash
export PW_ADMIN=... PW_APP=... PW_RESPALDO=... PW_SELLO=...
tools/probar_todo.sh
```

| Grupo | Archivo | Qué revisa |
| --- | --- | --- |
| Base de datos | `tests/bd/verificar_bd.php` | Reglas de captura, conclusión, triggers, permisos, historial y la regla de los 6 meses en PHP y MariaDB |
| Unidad | `tests/unidad/seguridad.php` | Vigencia de sesión, CSRF, permisos, contraseñas, IP real, redirecciones seguras |
| Punta a punta | `tests/e2e/autenticacion.php` | Un servidor real con `php -S`: login, bloqueo por intentos, cierre por inactividad, recuperación de contraseña, HTTPS |

Solo usan datos ficticios. Las pruebas no necesitan Composer.

## Estructura

```
bin/               scripts de consola (crear-admin.php)
config/            config.ejemplo.php (config.local.php solo en el servidor)
database/          migraciones SQL numeradas
docs/              ejemplo de VirtualHost de Apache
public/            única carpeta visible: index.php y assets/
src/Nucleo/        arranque, router, peticiones, respuestas, vistas, base de datos
src/Seguridad/     sesión, CSRF, permisos, límite de intentos, contraseñas
src/Repositorios/  únicas clases que escriben SQL
src/Servicios/     reglas de negocio con transacciones (autenticación, recuperación, correo)
src/Controladores/ reciben la petición, validan y responden
src/Dominio/       reglas sin base de datos (Periodo; Calculadora en la Etapa 6)
storage/           respaldos, sesiones y logs (fuera de git)
tests/             pruebas
tools/             utilidades de desarrollo
vistas/            plantillas PHP
```
