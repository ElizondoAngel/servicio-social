<?php
declare(strict_types=1);

/**
 * Respaldo nocturno. Para cron, por ejemplo a las 2:00 am:
 *   0 2 * * *  php /ruta/servicio-social/bin/respaldo-nocturno.php >> /ruta/storage/logs/respaldos.log 2>&1
 *
 * 1. Respaldo JSON firmado (nivel 1), con la retención de la configuración.
 * 2. Volcado de MariaDB con ss_respaldo (nivel 2), si 'respaldos.volcado.activo'.
 * 3. Copia cifrada con age a la carpeta externa (nivel 3), los domingos o con --externo,
 *    si 'respaldos.externo.activo'. PENDIENTE de que TI autorice el destino.
 * 4. Limpieza: sesiones vencidas y archivos subidos para restaurar con más de un día.
 * Sale con código 1 si algo falla (cron avisa por correo).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$config = require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/aplicacion.php';

use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Servicios\Respaldos\Volcado;

$fallas = 0;
$log = fn(string $m) => print('[' . date('Y-m-d H:i:s') . "] $m\n");

// 1. JSON
try {
    $bd = new BaseDatos($config);
    $bd->fijarContexto(null, null, 'respaldo-nocturno');
    $r = crearServicioRespaldos($config, $bd)->crear('automatico', null, null, 'respaldo-nocturno');
    $log("JSON: {$r['archivo']} ({$r['total_alumnos']} alumnos, {$r['total_expedientes']} expedientes, {$r['total_asistencias']} días)");
} catch (Throwable $e) {
    $fallas++;
    $log('FALLA en el respaldo JSON: ' . $e->getMessage());
}

// 2. Volcado
$ultimoVolcado = null;
if ($config->get('respaldos.volcado.activo', false)) {
    try {
        $v = new Volcado((string)$config->get('bd.host', 'localhost'), (int)$config->get('bd.puerto', 3306),
            (string)$config->get('bd.nombre', 'servicio_social'), (string)$config->get('respaldos.volcado.usuario', 'ss_respaldo'),
            (string)$config->get('respaldos.volcado.password', ''));
        $ruta = (string)$config->get('respaldos.volcado.ruta', __DIR__ . '/../storage/volcados');
        $ultimoVolcado = $v->crear($ruta);
        $log('Volcado: ' . basename($ultimoVolcado) . ' (' . number_format(filesize($ultimoVolcado) / 1024, 0) . ' KB, verificado)');
        $borrados = Volcado::retencion($ruta, (int)$config->get('respaldos.volcado.diarios', 14), (int)$config->get('respaldos.volcado.semanales', 8));
        if ($borrados) {
            $log('Retención: se borraron ' . count($borrados) . ' volcados viejos.');
        }
    } catch (Throwable $e) {
        $fallas++;
        $log('FALLA en el volcado: ' . $e->getMessage());
    }
} else {
    $log('Volcado: desactivado en la configuración.');
}

// 3. Copia externa cifrada
$esDomingo = date('N') === '7';
if ($config->get('respaldos.externo.activo', false) && ($esDomingo || in_array('--externo', $argv, true))) {
    try {
        if ($ultimoVolcado === null) {
            throw new RuntimeException('No hay volcado de hoy para copiar.');
        }
        $copia = Volcado::cifrar($ultimoVolcado, (string)$config->get('respaldos.externo.age_destinatario', ''),
            (string)$config->get('respaldos.externo.destino', ''));
        $log('Copia externa cifrada: ' . $copia);
    } catch (Throwable $e) {
        $fallas++;
        $log('FALLA en la copia externa: ' . $e->getMessage());
    }
}

// 4. Limpieza (en muchos servidores PHP no limpia sesiones de carpetas propias)
$limpiar = function (string $carpeta, string $patron, int $segundos): int {
    $borrados = 0;
    foreach (glob(rtrim($carpeta, '/') . '/' . $patron) ?: [] as $a) {
        if (is_file($a) && filemtime($a) < time() - $segundos && unlink($a)) {
            $borrados++;
        }
    }
    return $borrados;
};
try {
    $maxHoras = 10;
    try {
        $maxHoras = max(1, (int)(new BaseDatos($config))->pdo()->query("SELECT valor FROM configuracion WHERE clave = 'sesion_max_horas'")->fetchColumn());
    } catch (Throwable) {
    }
    $s = $limpiar((string)$config->get('sesion.ruta_archivos', __DIR__ . '/../storage/sesiones'), 'sess_*', ($maxHoras + 1) * 3600);
    $t = $limpiar((string)$config->get('rutas.temporal', __DIR__ . '/../storage/tmp'), 'restaurar-*.json.gz', 86400);
    $log("Limpieza: $s sesiones vencidas y $t archivos temporales.");
} catch (Throwable $e) {
    $fallas++;
    $log('FALLA en la limpieza: ' . $e->getMessage());
}

exit($fallas > 0 ? 1 : 0);
