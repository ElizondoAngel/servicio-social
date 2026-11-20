<?php
declare(strict_types=1);

/**
 * Punto de entrada único. Apache debe apuntar la raíz del sitio a public/
 * y enviar aquí toda ruta que no sea un archivo de assets/.
 */

$config = require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/aplicacion.php';

use ServicioSocial\Nucleo\Peticion;

$peticion = Peticion::desdeGlobales((array)$config->get('app.proxies_confianza', []));
try {
    $app = crearAplicacion($config);
} catch (Throwable $e) {
    error_log('No se pudo iniciar la aplicación: ' . $e->getMessage());
    http_response_code(503);
    exit('El sistema no está disponible en este momento.');
}
$app->manejar($peticion)->enviar($peticion->esHttps());
