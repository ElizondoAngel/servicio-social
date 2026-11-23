# Despliegue en el servidor

Guía para instalar, actualizar y operar el sistema en el servidor de la universidad. Usar el servidor de RH depende de que **TI autorice** su utilización. Lo que todavía falta definir con TI está marcado como **PENDIENTE TI**.

Ninguna contraseña ni llave va en el repositorio. Todas viven en `config/config.local.php` en el servidor, con permisos 640, o se escriben al momento en la consola.

## 1. Requisitos del servidor

| Qué | Mínimo | Para qué |
| --- | --- | --- |
| PHP | 8.1, con `pdo_mysql`, `mbstring`, `openssl`, `zlib`, `curl` y Argon2id | La aplicación |
| MariaDB | 10.2.7 (recomendado 10.6 o más nuevo) | CHECK, JSON, triggers con `SIGNAL` |
| Apache | 2.4 con `mod_rewrite` y HTTPS | Sitio en `public/` |
| `mariadb-client` | `mysqldump` y `mariadb` | Volcados y restauración completa |
| `age` | Solo si se activa la copia externa | Cifrado de la copia externa |
| cron | | Respaldo nocturno y sellado del historial |

**PENDIENTE TI:**
- Versión de MariaDB.
- Permiso para crear triggers.
- Plugin de auditoría (opcional).
- Cuenta SMTP.
- Acceso a cron.
- Destino de la copia externa cifrada.

## 2. Base de datos (una sola vez)

Las contraseñas se escriben en la sesión de `mariadb` y no quedan en archivos. Generar cuatro contraseñas distintas de 16 caracteres o más (por ejemplo `openssl rand -base64 24`) y guardarlas en el gestor de contraseñas de TI.

```bash
cd database/migraciones

# 1. TI, con root: base de datos y usuario de migraciones
mariadb -u root -p
  SET @pw_admin = '...';
  SOURCE 000_base_y_admin.sql;
  exit

# 2. Con ss_admin: esquema, triggers y configuración inicial
mariadb -u ss_admin -p servicio_social < 001_esquema.sql
mariadb -u ss_admin -p servicio_social < 002_triggers.sql
mariadb -u ss_admin -p servicio_social < 003_configuracion_inicial.sql

# 3. TI, con root: usuarios de la aplicación, respaldo y sellado
mariadb -u root -p
  SET @pw_app = '...'; SET @pw_respaldo = '...'; SET @pw_sello = '...';
  SOURCE 004_usuarios_aplicacion.sql;
  exit

# 4. Migraciones posteriores, con ss_admin, en orden
mariadb -u ss_admin -p servicio_social < 005_sesion_version.sql
mariadb -u ss_admin -p servicio_social < 006_respaldos_retencion.sql
```

| Usuario MariaDB | Uso | Dónde va su contraseña |
| --- | --- | --- |
| `ss_admin` | Migraciones y restauración completa. Dueño de los triggers | Solo en el gestor de TI. Se escribe cuando se necesita |
| `ss_app` | La aplicación web. Sin `DELETE` en alumnos, expedientes ni historial | `bd.password` |
| `ss_respaldo` | Volcado nocturno (solo lectura) | `respaldos.volcado.password` |
| `ss_sello` | Sellado del historial (solo columnas `hash` y `sellado_en`) | `sello.password` |

## 3. Archivos de la aplicación

1. En la Mac: `composer install --no-dev`, que crea `vendor/` con PHPMailer.
2. Subir todo con WinSCP a `/var/www/servicio-social`, incluida `vendor/`. WinSCP no sube archivos que empiezan con punto; por eso la configuración es `config.local.php` y no `.env`.
3. En el servidor, con `www-data` como el usuario de Apache:

   ```bash
   cd /var/www/servicio-social
   mkdir -p storage/{logs,sesiones,respaldos,volcados,tmp}
   chown -R www-data:www-data storage
   chmod 750 storage storage/*
   cp config/config.ejemplo.php config/config.local.php
   chown root:www-data config/config.local.php && chmod 640 config/config.local.php
   ```

4. Llenar `config.local.php`:

   | Clave | Valor |
   | --- | --- |
   | `app.entorno` | `produccion` |
   | `app.url_base` | `https://…` definitiva |
   | `app.forzar_https` | `true` |
   | `bd.password` | contraseña de `ss_app` |
   | `correo.*` | SMTP institucional (**PENDIENTE TI**) |
   | `respaldos.llave_hmac` | `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` |
   | `respaldos.volcado` | `activo => true` y la contraseña de `ss_respaldo` |
   | `sello.password` | contraseña de `ss_sello` |

   **Copia de la llave HMAC:** guardar una copia fuera del servidor, en el gestor de TI. Sin ella no se pueden verificar ni restaurar los respaldos JSON.

5. Configurar Apache con `docs/apache.conf`: raíz en `public/`, HTTPS, límites de subida para restaurar respaldos y `display_errors` apagado.
6. Crear el administrador: `php bin/crear-admin.php`.
7. Verificar: `php bin/verificar-instalacion.php`. Debe terminar con **0 fallas**. Los avisos explican qué falta y cómo arreglarlo.

## 4. Tareas programadas (cron del usuario de Apache)

```cron
# Respaldo JSON + volcado + limpieza, cada noche
0 2 * * *   php /var/www/servicio-social/bin/respaldo-nocturno.php >> /var/www/servicio-social/storage/logs/respaldos.log 2>&1
# Sellado y verificación del historial
30 2 * * *  php /var/www/servicio-social/bin/sellar-historial.php >> /var/www/servicio-social/storage/logs/sello.log 2>&1
```

Los dos scripts salen con código 1 si algo falla. Con `MAILTO=` en el crontab, cron avisa por correo al responsable.

## 5. Respaldos

| Nivel | Qué | Cuándo | Cuántos | Dónde |
| --- | --- | --- | --- | --- |
| 1 | JSON firmado (HMAC-SHA256) de todas las tablas, sin contraseñas. Se puede descargar también en Excel para consulta (no sirve para restaurar) | Cada noche y con el botón «Crear respaldo ahora» | Últimos 30 (Configuración) | `storage/respaldos/` |
| 2 | Volcado completo de MariaDB, triggers incluidos | Cada noche | 14 diarios y 8 dominicales | `storage/volcados/` |
| 3 | Volcado cifrado con `age` (llave pública) | Domingos | Lo que decida TI | **PENDIENTE TI** |

Para activar el nivel 3:

1. En una computadora segura (no en el servidor), generar las llaves con `age-keygen -o llave-servicio-social.txt`.
2. Guardar la llave **privada** fuera del servidor.
3. Poner la línea `age1…` en `respaldos.externo.age_destinatario` y la carpeta autorizada en `destino`.
4. Probar con `php bin/respaldo-nocturno.php --externo`.

### Restaurar

- **Agregar lo que falta (pantalla Respaldos).**
  - Valida la firma y cada registro, muestra una vista previa y pide la contraseña.
  - Antes de restaurar crea un respaldo y nunca borra ni sobrescribe nada.
  - Si la web no está disponible: `php bin/restaurar.php --json=ARCHIVO.json.gz`.
- **Recuperación de desastre (reemplaza toda la base):**

  ```bash
  php bin/restaurar.php --volcado=storage/volcados/volcado-AAAAMMDD-HHMMSS.sql.gz
  ```

  1. Pide la contraseña de `ss_admin`.
  2. Pone la aplicación en mantenimiento.
  3. Hace y verifica un volcado previo.
  4. Pide escribir `RESTAURAR`.
  5. Restaura las tablas, los triggers y el historial con sus sellos.
  6. Si falla, deja el sistema en mantenimiento e indica cómo volver al volcado previo.

  Al terminar: `php bin/sellar-historial.php --verificar`.

**Prueba mensual:** restaurar un volcado en una base de prueba para confirmar que los respaldos sí sirven:

```bash
mariadb -u root -p -e "CREATE DATABASE prueba_restauracion"
gzip -dc storage/volcados/volcado-….sql.gz | mariadb -u root -p prueba_restauracion
mariadb -u root -p -e "SELECT COUNT(*) FROM prueba_restauracion.asistencias; DROP DATABASE prueba_restauracion"
```

## 6. Actualizar a una versión nueva

1. `php bin/respaldo-nocturno.php`, para tener un respaldo justo antes.
2. Subir los archivos con WinSCP. `config.local.php` y `storage/` no se tocan.
3. Aplicar las migraciones nuevas de `database/migraciones/`, en orden, con `ss_admin`. `schema_migraciones` dice cuáles ya están.
4. `php bin/verificar-instalacion.php`.

Si algo sale mal: volver a subir la versión anterior y, si una migración cambió datos, usar `bin/restaurar.php --volcado` con el volcado del paso 1.

## 7. Seguridad: qué protege a qué

| Riesgo | Protección |
| --- | --- |
| Robo de contraseña | Argon2id; bloqueo tras 5 intentos por correo o 20 por IP en 15 min; mismo tiempo de respuesta exista o no el correo |
| Sesión olvidada abierta | Cierre a los 30 min de inactividad y a las 10 h. Cambiar la contraseña cierra las demás sesiones |
| Robo de la cookie | HttpOnly, SameSite=Lax, Secure con HTTPS; HSTS; id de sesión nuevo al entrar |
| Formularios falsificados (CSRF) | Token en todos los POST (`tests/e2e/seguridad.php` revisa todas las rutas) |
| Inyección SQL | Solo consultas preparadas; el SQL solo vive en `src/Repositorios/` |
| XSS | Todo se imprime escapado; CSP sin `unsafe-inline`; sin scripts ni estilos en línea |
| Edición directa de la base | Triggers que protegen reglas e historial; `ss_app` sin `DELETE` en datos clave; sellado en cadena que detecta alteraciones |
| Pérdida de datos | 3 niveles de respaldo; restauración con respaldo previo, candado y una sola transacción |
| Archivos privados expuestos | Solo `public/` es visible; respaldos y logs fuera; descargas con sesión, permiso y registro |
| Errores que muestran detalles | En producción, mensaje genérico; detalle solo en `storage/logs/php-errores.log` |

Riesgos que quedan:
- Un usuario con root de MariaDB puede hacer cualquier cosa. El sellado lo **detecta**, pero no lo **impide**; por eso conviene el plugin de auditoría (**PENDIENTE TI**).
- Los respaldos descargados salen del servidor con datos personales; la descarga pide confirmación y queda en el historial.
