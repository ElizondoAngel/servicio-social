<?php
// Solo para `php -S` en desarrollo: sirve los archivos de public/ y manda lo demás a index.php.
$archivo = __DIR__ . '/../../public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (PHP_SAPI === 'cli-server' && is_file($archivo) && !str_ends_with($archivo, '.php')) {
    return false;
}
require __DIR__ . '/../../public/index.php';
