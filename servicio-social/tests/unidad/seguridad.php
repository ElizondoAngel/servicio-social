<?php
declare(strict_types=1);

/**
 * Pruebas unitarias de las piezas de seguridad (sin base de datos).
 *   php tests/unidad/seguridad.php
 */

require __DIR__ . '/../lib.php';
spl_autoload_register(static function (string $c): void {
    $r = __DIR__ . '/../../src/' . str_replace(['ServicioSocial\\', '\\'], ['', '/'], $c) . '.php';
    if (is_file($r)) { require $r; }
});

use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Seguridad\Contrasenas;
use ServicioSocial\Seguridad\Csrf;
use ServicioSocial\Seguridad\Permisos;
use ServicioSocial\Seguridad\Sesion;

seccion('Vigencia de la sesión (30 min de inactividad, 10 h máximo)');
$ahora = 1_800_000_000; $inact = 30 * 60; $max = 10 * 3600;
prueba('sin usuario es anónima', fn() => igual(Sesion::ANONIMA, Sesion::evaluar([], $ahora, $inact, $max)));
prueba('29:59 sin actividad sigue válida', fn() => igual(Sesion::VALIDA,
    Sesion::evaluar(['usuario_id' => 1, 'inicio' => $ahora - 3600, 'ultimo_mov' => $ahora - $inact + 1], $ahora, $inact, $max)));
prueba('30:00 sin actividad se cierra', fn() => igual(Sesion::INACTIVA,
    Sesion::evaluar(['usuario_id' => 1, 'inicio' => $ahora - 3600, 'ultimo_mov' => $ahora - $inact], $ahora, $inact, $max)));
prueba('10 h con actividad constante se cierra', fn() => igual(Sesion::VENCIDA,
    Sesion::evaluar(['usuario_id' => 1, 'inicio' => $ahora - $max, 'ultimo_mov' => $ahora - 5], $ahora, $inact, $max)));
prueba('la duración máxima gana a la inactividad', fn() => igual(Sesion::VENCIDA,
    Sesion::evaluar(['usuario_id' => 1, 'inicio' => $ahora - $max - 1, 'ultimo_mov' => $ahora - $inact - 1], $ahora, $inact, $max)));

seccion('CSRF');
prueba('genera un token de 64 caracteres y lo reutiliza', function () {
    $_SESSION = []; $c = new Csrf();
    $t = $c->token();
    igual(64, strlen($t), 'largo');
    igual($t, $c->token(), 'mismo token');
});
prueba('acepta el token correcto', function () { $_SESSION = []; $c = new Csrf(); verdadero($c->valido($c->token()), 'válido'); });
prueba('rechaza vacío, nulo, alterado y sin sesión', function () {
    $_SESSION = []; $c = new Csrf(); $t = $c->token();
    verdadero(!$c->valido(''), 'vacío');
    verdadero(!$c->valido(null), 'nulo');
    verdadero(!$c->valido(substr($t, 0, -1) . 'x'), 'alterado');
    $_SESSION = [];
    verdadero(!$c->valido($t), 'sin sesión');
});

seccion('Permisos');
prueba('admin puede todo', fn() => verdadero(Permisos::puede('admin', 'respaldos.restaurar'), 'admin'));
prueba('rol desconocido o nulo no puede nada', function () {
    verdadero(!Permisos::puede('consulta', 'expedientes.ver'), 'desconocido');
    verdadero(!Permisos::puede(null, 'expedientes.ver'), 'nulo');
});

seccion('Contraseñas');
prueba('menos de 12 caracteres', fn() => igual(1, count(Contrasenas::validar('corta123', 'corta123'))));
prueba('no coinciden', fn() => contiene('no coinciden', implode(' ', Contrasenas::validar('una-contrasena-larga', 'otra-contrasena-larga'))));
prueba('igual al correo', fn() => contiene('correo', implode(' ', Contrasenas::validar('admin@ejemplo.test', 'admin@ejemplo.test', 'ADMIN@ejemplo.test'))));
prueba('válida', fn() => igual([], Contrasenas::validar('frase larga y segura', 'frase larga y segura', 'a@b.mx')));
prueba('hash Argon2id que verifica y no necesita rehash', function () {
    $h = Contrasenas::hash('frase larga y segura');
    verdadero(str_starts_with($h, '$argon2id$'), 'Argon2id');
    verdadero(Contrasenas::verificar('frase larga y segura', $h), 'verifica');
    verdadero(!Contrasenas::verificar('otra', $h), 'rechaza otra');
    verdadero(!Contrasenas::necesitaRehash($h), 'sin rehash');
});
prueba('un hash bcrypt viejo pide rehash', fn() => verdadero(Contrasenas::necesitaRehash(password_hash('x', PASSWORD_BCRYPT)), 'rehash'));
prueba('usuario inexistente tarda lo mismo que uno real', function () {
    $h = Contrasenas::hash('frase larga y segura');
    $t = microtime(true); Contrasenas::verificar('mala', $h); $real = microtime(true) - $t;
    $t = microtime(true); Contrasenas::verificar('mala', null); $falso = microtime(true) - $t;
    verdadero($falso > $real * 0.5 && $falso < $real * 2, sprintf('tiempos parecidos (%.0f ms y %.0f ms)', $real * 1000, $falso * 1000));
});

seccion('Peticiones y respuestas');
prueba('ignora X-Forwarded-For si no viene de un proxy de confianza', function () {
    $p = new Peticion('GET', '/', [], [], ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
    igual('10.0.0.5', $p->ip());
});
prueba('usa X-Forwarded-For de un proxy de confianza', function () {
    $p = new Peticion('GET', '/', [], [], ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 10.0.0.1'], ['10.0.0.1']);
    igual('1.2.3.4', $p->ip());
});
prueba('una redirección nunca sale del sitio', function () {
    igual('/', Respuesta::redirigir('https://otro.sitio/robar')->cabeceras()['Location'], 'URL externa');
    igual('/', Respuesta::redirigir('//otro.sitio')->cabeceras()['Location'], 'URL sin esquema');
    igual('/login?m=salida', Respuesta::redirigir('/login?m=salida')->cabeceras()['Location'], 'ruta interna');
});

resumen();
