#!/usr/bin/env bash
# Instala la base desde cero en una MariaDB LOCAL de desarrollo.
# Uso: PW_ADMIN=... PW_APP=... PW_RESPALDO=... PW_SELLO=... tools/instalar_bd_dev.sh
# Nunca usar contra producción: borra la base servicio_social.
set -euo pipefail
cd "$(dirname "$0")/../database/migraciones"
: "${PW_ADMIN:?}" "${PW_APP:?}" "${PW_RESPALDO:?}" "${PW_SELLO:?}"
mariadb -e "DROP DATABASE IF EXISTS servicio_social; DROP USER IF EXISTS ss_admin@localhost, ss_app@localhost, ss_respaldo@localhost, ss_sello@localhost;"
mariadb -e "SET @pw_admin='$PW_ADMIN'; SOURCE 000_base_y_admin.sql;"
for f in 001_esquema.sql 002_triggers.sql 003_configuracion_inicial.sql; do
  mariadb -u ss_admin -p"$PW_ADMIN" servicio_social < "$f"
done
mariadb -e "SET @pw_app='$PW_APP'; SET @pw_respaldo='$PW_RESPALDO'; SET @pw_sello='$PW_SELLO'; SOURCE 004_usuarios_aplicacion.sql;"
mariadb servicio_social -e "SELECT version, aplicada_en FROM schema_migraciones ORDER BY version;"
