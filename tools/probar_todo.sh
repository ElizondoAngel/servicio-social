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
 'rutas' => ['logs' => '$TMP', 'temporal' => '$TMP/tmp', 'mantenimiento' => '$TMP/mantenimiento'],
 'sello' => ['usuario' => 'ss_sello', 'password' => '$PW_SELLO'],
 'respaldos' => ['ruta' => '$TMP/respaldos', 'llave_hmac' => '$(php -r 'echo bin2hex(random_bytes(32));')',
   'volcado' => ['activo' => true, 'ruta' => '$TMP/volcados', 'usuario' => 'ss_respaldo', 'password' => '$PW_RESPALDO'],
   'externo' => ['activo' => false]],
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
php "$RAIZ/tests/bd/verificar_bd.php" | grep -E "FALLA|pruebas:"

echo "== Unidad: seguridad, calculadora, calendario, proyección y respaldos"
php "$RAIZ/tests/unidad/seguridad.php" | grep -E "FALLA|pruebas:"
php "$RAIZ/tests/unidad/calculadora.php" | grep -E "FALLA|pruebas:"
php "$RAIZ/tests/unidad/calendario.php" | grep -E "FALLA|pruebas:"
php "$RAIZ/tests/unidad/proyeccion.php" | grep -E "FALLA|pruebas:"
php "$RAIZ/tests/unidad/respaldos.php" | grep -E "FALLA|pruebas:"

echo "== Punta a punta: autenticación, alumnos, expediente, proyección, seguridad y respaldos"
SS_CONFIG="$TMP/config.php" php -S 127.0.0.1:8099 -t "$RAIZ/public" "$RAIZ/tests/e2e/router_dev.php" > "$TMP/servidor.log" 2>&1 &
SS_CONFIG="$TMP/config_https.php" php -S 127.0.0.1:8098 -t "$RAIZ/public" "$RAIZ/tests/e2e/router_dev.php" > /dev/null 2>&1 &
sleep 1
E2E_URL=http://127.0.0.1:8099 E2E_URL_HTTPS=http://127.0.0.1:8098 E2E_CORREOS="$TMP/correos.log" E2E_SESIONES="$TMP/sesiones" \
  php "$RAIZ/tests/e2e/autenticacion.php" | grep -E "FALLA|pruebas:"
E2E_URL=http://127.0.0.1:8099 php "$RAIZ/tests/e2e/alumnos.php" | grep -E "FALLA|pruebas:"
E2E_URL=http://127.0.0.1:8099 php "$RAIZ/tests/e2e/expediente.php" | grep -E "FALLA|pruebas:"
E2E_URL=http://127.0.0.1:8099 php "$RAIZ/tests/e2e/proyeccion.php" | grep -E "FALLA|pruebas:"
E2E_URL=http://127.0.0.1:8099 E2E_CONFIG="$TMP/config.php" php "$RAIZ/tests/e2e/seguridad.php" | grep -E "FALLA|pruebas:"
# Va al final: reemplaza la base completa con un volcado.
E2E_URL=http://127.0.0.1:8099 E2E_CONFIG="$TMP/config.php" PW_ADMIN="$PW_ADMIN" php "$RAIZ/tests/e2e/respaldos.php" | grep -E "FALLA|pruebas:"

echo "== Hoja de estilos: llaves balanceadas"
php -r '$c = preg_replace("#/\*.*?\*/#s", "", file_get_contents($argv[1])); $d = 0; $mal = false;
  foreach (str_split($c) as $x) { $d += ($x === "{") - ($x === "}"); $mal = $mal || $d < 0; }
  echo ($d === 0 && !$mal) ? "1 pruebas: 1 correctas, 0 fallidas\n" : "FALLA: app.css tiene llaves sin cerrar ($d)\n";' "$RAIZ/public/assets/app.css"

echo "== Advertencias de PHP durante las pruebas"
if grep -hE "PHP (Warning|Notice|Deprecated|Fatal)" "$TMP"/*.log 2>/dev/null; then
  echo "FALLA: hubo advertencias de PHP (arriba)"
else
  echo "1 pruebas: 1 correctas, 0 fallidas"
fi
