<?php
declare(strict_types=1);

/**
 * Sistema servido en una subcarpeta (p. ej. grasc.utn.edu.mx/servicio-social).
 *   php tests/unidad/ruta_base.php
 */

require __DIR__ . '/../lib.php';
spl_autoload_register(static function (string $c): void {
    $r = __DIR__ . '/../../src/' . str_replace(['ServicioSocial\\', '\\'], ['', '/'], $c) . '.php';
    if (is_file($r)) { require $r; }
});

use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\RutaBase;

seccion('En la raíz del dominio no cambia nada');
prueba('sin ruta en url_base', function () {
    RutaBase::fijar('https://serviciosocial.utn.edu.mx');
    igual('', RutaBase::base());
    igual('/expedientes', RutaBase::quitar('/expedientes'));
    igual('/public/x', RutaBase::quitar('/public/x'));
    igual('<a href="/alumnos">', RutaBase::enHtml('<a href="/alumnos">'));
    igual('/login', Respuesta::redirigir('/login')->cabeceras()['Location']);
});

seccion('En una subcarpeta');
prueba('la base sale de url_base', function () {
    RutaBase::fijar('https://grasc.utn.edu.mx/servicio-social/');
    igual('/servicio-social', RutaBase::base());
});
prueba('la petición se enruta sin la base ni /public', function () {
    RutaBase::fijar('https://grasc.utn.edu.mx/servicio-social');
    igual('/', RutaBase::quitar('/servicio-social'));
    igual('/expedientes/3', RutaBase::quitar('/servicio-social/expedientes/3'));
    igual('/login', RutaBase::quitar('/servicio-social/public/login'));
    igual('/', RutaBase::quitar('/servicio-social/public'));
    igual('/servicio-socialx', RutaBase::quitar('/servicio-socialx'), 'no recorta un prefijo parcial');
});
prueba('redirecciones con la base', function () {
    RutaBase::fijar('https://grasc.utn.edu.mx/servicio-social');
    igual('/servicio-social/expedientes/3?semana=2026-06-22#semana-2026-06-22',
        Respuesta::redirigir('/expedientes/3?semana=2026-06-22#semana-2026-06-22')->cabeceras()['Location']);
    igual('/servicio-social/', Respuesta::redirigir('//malo.example')->cabeceras()['Location'], 'externa → inicio');
});
prueba('enlaces internos con la base, una sola vez', function () {
    RutaBase::fijar('https://grasc.utn.edu.mx/servicio-social');
    $html = '<a href="/">I</a><form action="/logout"></form><script src="/assets/app.js"></script>'
        . '<button formaction="/respaldos/1/excel"></button><a href="https://fonts.googleapis.com/x">F</a>'
        . '<a href="//cdn.example/x">C</a><a href="#ancla">A</a><a href="/historial?p=2">H</a>';
    $una = RutaBase::enHtml($html);
    contiene('href="/servicio-social/"', $una);
    contiene('action="/servicio-social/logout"', $una);
    contiene('src="/servicio-social/assets/app.js"', $una);
    contiene('formaction="/servicio-social/respaldos/1/excel"', $una);
    contiene('href="/servicio-social/historial?p=2"', $una);
    contiene('href="https://fonts.googleapis.com/x"', $una);
    contiene('href="//cdn.example/x"', $una);
    contiene('href="#ancla"', $una);
    igual($una, RutaBase::enHtml($una), 'procesar dos veces no duplica');
});
prueba('una ruta base con caracteres raros se ignora', function () {
    RutaBase::fijar('https://x.test/a b/<script>');
    igual('', RutaBase::base());
    RutaBase::fijar('');
});

resumen();
