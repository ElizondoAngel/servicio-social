<?php
declare(strict_types=1);

/**
 * Revisa que el servidor esté listo y bien configurado. Correr después de
 * instalar, después de cada actualización y cuando algo falle:
 *   php bin/verificar-instalacion.php
 *
 * No cambia nada. Sale con código 1 si hay fallas. En desarrollo, lo que
 * solo importa en producción se marca como aviso.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$config = require __DIR__ . '/../src/bootstrap.php';

use ServicioSocial\Servicios\Respaldos\FormatoRespaldo;

$prod = $config->esProduccion();
$resultados = ['ok' => 0, 'aviso' => 0, 'falla' => 0];
$seccion = fn(string $t) => print("\n$t\n");
$marca = function (string $nivel, string $texto, string $arreglo = '') use (&$resultados): void {
    $resultados[$nivel]++;
    $icono = ['ok' => '  ✓', 'aviso' => '  !', 'falla' => '  ✗'][$nivel];
    echo "$icono $texto\n";
    if ($arreglo !== '' && $nivel !== 'ok') {
        echo "      → $arreglo\n";
    }
};
$check = function (bool $bien, string $texto, string $arreglo = '', bool $soloProduccion = false) use ($marca, $prod): void {
    $marca($bien ? 'ok' : ($soloProduccion && !$prod ? 'aviso' : 'falla'), $texto, $arreglo);
};
$raiz = realpath(__DIR__ . '/..');

echo 'Servicio Social UTN · verificación de la instalación (' . ($prod ? 'PRODUCCIÓN' : 'desarrollo') . ")\n";

// ----------------------------------------------------------------------
$seccion('PHP');
$check(PHP_VERSION_ID >= 80100, 'PHP ' . PHP_VERSION . ' (se requiere 8.1 o mayor)', 'Instalar PHP 8.1+');
foreach (['pdo_mysql', 'mbstring', 'openssl', 'zlib', 'json', 'curl'] as $ext) {
    $check(extension_loaded($ext), "Extensión $ext", "Instalar la extensión php-$ext");
}
$check(defined('PASSWORD_ARGON2ID'), 'Contraseñas con Argon2id disponibles', 'PHP debe compilarse con libargon2 (paquete php-common en Debian/Ubuntu ya lo trae)');
$check(!filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) || PHP_SAPI === 'cli', 'display_errors apagado', 'php_admin_flag display_errors off', true);
$check(!filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN), 'expose_php apagado', 'expose_php = Off en php.ini', true);
$aBytes = function (string $v): int {
    $n = (int)$v;
    return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
};
$check($aBytes((string)ini_get('upload_max_filesize')) >= 20 << 20 && $aBytes((string)ini_get('post_max_size')) >= 21 << 20,
    'Subida de archivos ≥ 20 MB (restaurar respaldos): upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size'),
    'php_admin_value upload_max_filesize 50M y post_max_size 55M en el VirtualHost', true);
$check(date_default_timezone_get() === (string)$config->get('app.zona_horaria', 'America/Mexico_City'), 'Zona horaria ' . date_default_timezone_get());

// ----------------------------------------------------------------------
$seccion('Configuración (config/config.local.php)');
$local = $raiz . '/config/config.local.php';
if (is_file($local)) {
    $permisos = fileperms($local) & 0777;
    $check(($permisos & 0007) === 0, sprintf('config.local.php no es legible por otros (%o)', $permisos), 'chmod 640 config/config.local.php', true);
}
$url = (string)$config->get('app.url_base', '');
$check(str_starts_with($url, 'https://'), "url_base con HTTPS ($url)", "Poner la URL https:// definitiva en app.url_base", true);
$check((bool)$config->get('app.forzar_https', $prod), 'HTTPS obligatorio (forzar_https)', "'forzar_https' => true", true);
$check($config->get('correo.driver', 'smtp') === 'smtp' && (string)$config->get('correo.smtp_host', '') !== '',
    'Correo SMTP configurado (recuperación de contraseña)', 'Pedir a TI la cuenta SMTP y llenar correo.smtp_*', true);
$check(FormatoRespaldo::llaveValida((string)$config->get('respaldos.llave_hmac', '')), 'Llave de firma de respaldos',
    'php -r "echo bin2hex(random_bytes(32)), PHP_EOL;" → respaldos.llave_hmac (guardar una copia fuera del servidor)');
$check((string)$config->get('sello.password', '') !== '', 'Contraseña de ss_sello para el sellado nocturno', "Llenar 'sello' => ['password' => ...]");
$check((bool)$config->get('respaldos.volcado.activo', false), 'Volcado nocturno de la base (nivel 2) activo',
    "'respaldos' => ['volcado' => ['activo' => true, 'password' => contraseña de ss_respaldo]]", true);
if ($config->get('respaldos.externo.activo', false)) {
    $check(trim((string)shell_exec('command -v age')) !== '', 'age instalado (copia externa cifrada)', 'apt install age');
    $check(is_dir((string)$config->get('respaldos.externo.destino', '')) && is_writable((string)$config->get('respaldos.externo.destino', '')),
        'Carpeta externa accesible', 'Revisar respaldos.externo.destino con TI');
} else {
    $marca('aviso', 'Copia externa cifrada (nivel 3) desactivada', 'Pendiente: destino que autorice TI');
}

// ----------------------------------------------------------------------
$seccion('Carpetas');
$carpetas = [
    'logs'      => (string)$config->get('rutas.logs', $raiz . '/storage/logs'),
    'sesiones'  => (string)$config->get('sesion.ruta_archivos', $raiz . '/storage/sesiones'),
    'respaldos' => (string)$config->get('respaldos.ruta', $raiz . '/storage/respaldos'),
    'temporal'  => (string)$config->get('rutas.temporal', $raiz . '/storage/tmp'),
];
if ($config->get('respaldos.volcado.activo', false)) {
    $carpetas['volcados'] = (string)$config->get('respaldos.volcado.ruta', $raiz . '/storage/volcados');
}
$publica = realpath($raiz . '/public');
foreach ($carpetas as $nombre => $ruta) {
    $real = realpath($ruta);
    if ($real === false) {
        $marca('falla', "Carpeta de $nombre: no existe ($ruta)", "mkdir -p $ruta && chmod 750 $ruta (dueño: el usuario de Apache)");
        continue;
    }
    $check(is_writable($real), "Carpeta de $nombre se puede escribir", "chown al usuario de Apache: $real");
    $check(!str_starts_with($real, (string)$publica), "Carpeta de $nombre fuera de public/", 'Moverla fuera de public/');
    $check((fileperms($real) & 0007) === 0, sprintf('Carpeta de %s sin acceso para otros (%o)', $nombre, fileperms($real) & 0777), "chmod 750 $real", true);
}
$check(!is_file((string)$config->get('rutas.mantenimiento', $raiz . '/storage/mantenimiento')), 'Sin modo mantenimiento activo',
    'Si no hay una restauración en curso, borrar ' . $config->get('rutas.mantenimiento'));

// ----------------------------------------------------------------------
$seccion('Base de datos');
try {
    $bd = new ServicioSocial\Nucleo\BaseDatos($config);
    $pdo = $bd->pdo();
    $marca('ok', 'Conexión como ' . $config->get('bd.usuario'));
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $v);
    $check(str_contains(strtolower($version), 'mariadb') && version_compare("$v[1].$v[2].$v[3]", '10.2.7', '>='),
        "MariaDB $version (se requiere 10.2.7 o mayor)", 'Confirmar con TI la versión del servidor');
    $migraciones = $pdo->query('SELECT version FROM schema_migraciones')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['001_esquema', '002_triggers', '003_configuracion_inicial', '004_usuarios_aplicacion', '005_sesion_version', '006_respaldos_retencion'] as $m) {
        $check(in_array($m, $migraciones, true), "Migración $m", "Aplicar database/migraciones/$m.sql (ver docs/despliegue.md)");
    }
    // ss_app no puede listar los triggers (no tiene ese privilegio, a propósito), así que se prueba uno:
    // crear un expediente ya concluido debe rechazarlo el trigger. Todo se deshace al final.
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO expedientes (alumno_id, unidad_administrativa, responsable, fecha_inicio, fecha_fin_autorizada, estado,
                    fecha_conclusion, resumen_conclusion, creado_por) VALUES (0, 'x', 'x', '2000-01-01', '2000-12-31', 'concluido', '2000-12-31', '{}', 0)");
        $mensajeTrigger = '';
    } catch (PDOException $e) {
        $mensajeTrigger = $e->getMessage();
    } finally {
        $pdo->rollBack();
    }
    $check(str_contains($mensajeTrigger, 'ALTA_CONCLUIDA'), 'Triggers de protección activos (prueba sin guardar nada)', 'Aplicar 002_triggers.sql con ss_admin');
    $usuario = (string)$pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    $check(str_starts_with($usuario, 'ss_app@'), "La aplicación usa ss_app ($usuario), no un usuario con más permisos", "bd.usuario debe ser ss_app");
    $permisos = implode("\n", $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
    $check(!preg_match('/ALL PRIVILEGES|GRANT OPTION|\bDROP\b|\bALTER\b|\bSUPER\b/', $permisos), 'ss_app sin privilegios de administración');
    $check(!preg_match('/DELETE[^`]*ON `servicio_social`\.`(historial|alumnos|expedientes)`/', $permisos), 'ss_app no puede borrar historial, alumnos ni expedientes');
    $check((int)$pdo->query('SELECT COUNT(*) FROM usuarios WHERE activo = 1')->fetchColumn() > 0, 'Hay al menos un administrador activo', 'php bin/crear-admin.php');
    $check(substr((string)$pdo->query('SELECT @@session.time_zone')->fetchColumn(), 0, 3) === substr((new DateTimeImmutable())->format('P'), 0, 3),
        'Zona horaria de la conexión igual a la de PHP');

    $ultimo = $pdo->query('SELECT MAX(creado_en) FROM respaldos WHERE archivo_eliminado_en IS NULL')->fetchColumn();
    $check($ultimo !== null && strtotime((string)$ultimo) > time() - 48 * 3600, 'Respaldo de las últimas 48 horas' . ($ultimo ? " (último: $ultimo)" : ''),
        'Programar bin/respaldo-nocturno.php en cron (docs/despliegue.md)', true);
    $sello = $pdo->query('SELECT MAX(sellado_en) AS ultimo, SUM(hash IS NULL AND creado_en < NOW() - INTERVAL 2 DAY) AS atrasadas FROM historial')->fetch();
    $check((int)$sello['atrasadas'] === 0, 'Historial sellado al día' . ($sello['ultimo'] ? " (último: {$sello['ultimo']})" : ''),
        'Programar bin/sellar-historial.php en cron', true);
} catch (Throwable $e) {
    $marca('falla', 'No se pudo conectar a la base: ' . $e->getMessage(), 'Revisar bd.* en config.local.php y que MariaDB esté encendido');
}

// ----------------------------------------------------------------------
$seccion('Herramientas');
$check(trim((string)shell_exec('command -v mysqldump || command -v mariadb-dump')) !== '', 'mysqldump disponible', 'apt install mariadb-client', true);
$check(trim((string)shell_exec('command -v gzip')) !== '', 'gzip disponible');
$check(is_file($raiz . '/vendor/autoload.php') || $config->get('correo.driver') !== 'smtp', 'PHPMailer instalado (vendor/)', 'composer install --no-dev', true);

echo "\nResultado: {$resultados['ok']} bien, {$resultados['aviso']} avisos, {$resultados['falla']} fallas.\n";
exit($resultados['falla'] > 0 ? 1 : 0);
