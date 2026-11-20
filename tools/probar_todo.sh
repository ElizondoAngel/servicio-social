#!/usr/bin/env bash
# Corre TODAS las pruebas desde cero en una MariaDB LOCAL de desarrollo.
# Borra y reinstala la base servicio_social: nunca usar contra producción.
#
#   PW_ADMIN=... PW_APP=... PW_RESPALDO=... PW_SELLO=... tools/probar_todo.sh
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'kill $(jobs -p) 2>/dev/null || true; rm -rf "$TMP"' EXIT

cat > "$TMP/config.php" <<PHP
<?php return [
 'app' => ['entorno' => 'desarrollo', 'url_base' => 'http://127.0.0.1:8099', 'forzar_https' => false],
 'rutas' => ['logs' => '$TMP'],
 'bd' => ['host' => 'localhost', 'nombre' => 'servicio_social', 'usuario' => 'ss_app', 'password' => '$PW_APP'],
 'sesion' => ['nombre_cookie' => 'ss_sesion', 'ruta_archivos' => '$TMP/sesiones'],
 'correo' => ['driver' => 'archivo', 'archivo' => '$TMP/correos.log'],
];
PHP
sed "s#'forzar_https' => false#'forzar_https' => true#; s#http://127.0.0.1:8099#https://serviciosocial.ejemplo.test#" \
  "$TMP/config.php" > "$TMP/config_https.php"

echo "== Instalando la base de desarrollo"
"$RAIZ/tools/instalar_bd_dev.sh" > /dev/null
printf 'Admin de Prueba\nadmin.prueba@ejemplo.test\ncontrasena-de-prueba\ncontrasena-de-prueba\n' \
  | SS_CONFIG="$TMP/config.php" php "$RAIZ/bin/crear-admin.php" > /dev/null

echo "== Etapa 2: base de datos"
php "$RAIZ/tests/bd/verificar_bd.php" | tail -1

echo "== Etapa 3: unidad"
php "$RAIZ/tests/unidad/seguridad.php" | tail -1

echo "== Etapa 3: punta a punta"
SS_CONFIG="$TMP/config.php" php -S 127.0.0.1:8099 -t "$RAIZ/public" "$RAIZ/tests/e2e/router_dev.php" > "$TMP/servidor.log" 2>&1 &
SS_CONFIG="$TMP/config_https.php" php -S 127.0.0.1:8098 -t "$RAIZ/public" "$RAIZ/tests/e2e/router_dev.php" > /dev/null 2>&1 &
sleep 1
E2E_URL=http://127.0.0.1:8099 E2E_URL_HTTPS=http://127.0.0.1:8098 E2E_CORREOS="$TMP/correos.log" E2E_SESIONES="$TMP/sesiones" \
  php "$RAIZ/tests/e2e/autenticacion.php" | grep -E "FALLA|pruebas:"
