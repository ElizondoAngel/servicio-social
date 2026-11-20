<?php
declare(strict_types=1);

/**
 * Arranque común para la web y la consola: autoload, configuración,
 * zona horaria y manejo de errores. Devuelve la configuración cargada.
 */

use ServicioSocial\Nucleo\Config;

// Autoload PSR-4 propio para ServicioSocial\ → src/. Si existe vendor/
// (PHPMailer instalado con Composer), también se carga.
spl_autoload_register(static function (string $clase): void {
    $prefijo = 'ServicioSocial\\';
    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
        return;
    }
    $ruta = __DIR__ . '/' . str_replace('\\', '/', substr($clase, strlen($prefijo))) . '.php';
    if (is_file($ruta)) {
        require $ruta;
    }
});
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

$rutaConfig = getenv('SS_CONFIG') ?: __DIR__ . '/../config/config.local.php';
if (!is_file($rutaConfig)) {
    http_response_code(500);
    exit('Falta config/config.local.php. Copia config/config.ejemplo.php y llénalo.');
}
$config = new Config(require $rutaConfig);

date_default_timezone_set($config->get('app.zona_horaria', 'America/Mexico_City'));
mb_internal_encoding('UTF-8');

$esProduccion = $config->get('app.entorno') === 'produccion';
ini_set('display_errors', $esProduccion ? '0' : '1');
ini_set('log_errors', '1');
$rutaLogs = $config->get('rutas.logs', __DIR__ . '/../storage/logs');
if (is_dir($rutaLogs) && is_writable($rutaLogs)) {
    ini_set('error_log', $rutaLogs . '/php-errores.log');
}
error_reporting(E_ALL);

/** Escapa texto para HTML (lo usan las vistas). */
if (!function_exists('e')) {
    function e(mixed $valor): string
    {
        return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('fecha_corta')) {
    /** 23 jun 2026 */
    function fecha_corta(DateTimeInterface|string|null $fecha): string
    {
        if ($fecha === null || $fecha === '') {
            return '—';
        }
        $d = $fecha instanceof DateTimeInterface ? $fecha : new DateTimeImmutable($fecha);
        $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        return $d->format('j') . ' ' . $meses[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
    }

    /** 16:00 → 4:00 pm */
    function hora12(?string $hora): string
    {
        if ($hora === null || $hora === '') {
            return '—';
        }
        [$h, $m] = array_map('intval', explode(':', $hora));
        return ($h % 12 === 0 ? 12 : $h % 12) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ($h < 12 ? ' am' : ' pm');
    }

    function nombre_completo(array $a): string
    {
        return trim($a['nombre'] . ' ' . $a['apellido_paterno'] . ' ' . ($a['apellido_materno'] ?? ''));
    }
}

return $config;
