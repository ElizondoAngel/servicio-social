<?php
declare(strict_types=1);

/**
 * Pruebas de seguridad de la Etapa 8, contra un servidor real:
 *  - TODA ruta privada exige sesión y TODO POST exige el token CSRF
 *    (las rutas se leen de src/aplicacion.php: una ruta nueva queda cubierta sola);
 *  - cabeceras de seguridad, sin scripts ni estilos en línea (CSP);
 *  - archivos fuera de public/ no se sirven;
 *  - entradas maliciosas o malformadas no rompen nada ni se reflejan sin escapar.
 *   E2E_URL=http://127.0.0.1:8099 php tests/e2e/seguridad.php
 */

require __DIR__ . '/../lib.php';
require __DIR__ . '/comun.php';

$URL = getenv('E2E_URL') ?: 'http://127.0.0.1:8099';
$ADMIN = 'admin.prueba@ejemplo.test';
$PW = 'contrasena-de-prueba';
$root = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=servicio_social;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$root->exec('DELETE FROM intentos_login');
$root->prepare('UPDATE usuarios SET password_hash = ?, activo = 1 WHERE correo = ?')->execute([password_hash($PW, PASSWORD_ARGON2ID), $ADMIN]);

// Rutas declaradas en la aplicación.
preg_match_all("#\\\$r->(get|post)\\('([^']+)',\\s*\\[[^\\]]+\\](?:,\\s*\\[([^\\]]*)\\])?\\)#", file_get_contents(__DIR__ . '/../../src/aplicacion.php'), $m, PREG_SET_ORDER);
$rutas = array_map(fn($x) => ['metodo' => strtoupper($x[1]), 'ruta' => $x[2], 'publica' => str_contains($x[3] ?? '', "'publico' => true")], $m);
$concreta = fn(string $r) => str_replace('{id}', '1', $r);
$privadas = array_values(array_filter($rutas, fn($r) => !$r['publica']));
$posts = array_values(array_filter($rutas, fn($r) => $r['metodo'] === 'POST'));

seccion('Inventario de rutas');
prueba('se encontraron las rutas de la aplicación', function () use ($rutas, $privadas, $posts) {
    $declaradas = preg_match_all('#\$r->(get|post)\(#', file_get_contents(__DIR__ . '/../../src/aplicacion.php'));
    igual($declaradas, count($rutas), 'todas las rutas declaradas se reconocen');
    verdadero(count($privadas) >= 30, 'privadas');
    verdadero(count($posts) >= 20, 'POST');
});
prueba('las únicas rutas públicas son las de acceso (y el buzón de desarrollo)', function () use ($rutas) {
    $publicas = array_unique(array_map(fn($r) => $r['ruta'], array_filter($rutas, fn($r) => $r['publica'])));
    sort($publicas);
    igual(['/dev/correos', '/login', '/recuperar', '/restablecer'], array_values($publicas));
});

seccion('Sin sesión no se entra a nada privado');
$anonimo = new Navegador($URL);
prueba('buzón de prueba en desarrollo: muestra el enlace y el enlace funciona', function () use ($anonimo, $ADMIN, $root) {
    $root->exec('DELETE FROM tokens_reset');
    $csrf = $anonimo->csrf('/recuperar');
    contiene('Buzón de prueba (desarrollo)', $anonimo->get('/recuperar')['cuerpo']);
    $r = $anonimo->post('/recuperar', ['_csrf' => $csrf, 'correo' => $ADMIN]);
    contiene('abrir el buzón de prueba', $anonimo->get($r['cabeceras']['location'])['cuerpo']);
    $b = $anonimo->get('/dev/correos');
    igual(200, $b['estado']);
    verdadero((bool)preg_match('#href="https?://[^"]+(/restablecer\?t=[0-9a-f]{64})"#', $b['cuerpo'], $m), 'enlace en el buzón');
    $x = $anonimo->get($m[1]);
    igual(303, $x['estado'], 'el enlace abre el formulario');
    igual('/restablecer', $x['cabeceras']['location'] ?? '');
});
prueba('buzón de prueba con configuración de producción: 404', function () {
    $config = getenv('E2E_CONFIG') ?: '';
    if (!is_file($config)) {
        verdadero(true, 'sin E2E_CONFIG: se omite');
        return;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'cfg');
    file_put_contents($tmp, '<?php $c = require ' . var_export($config, true)
        . "; \$c['app']['entorno'] = 'produccion'; \$c['app']['forzar_https'] = false; \$c['correo']['driver'] = 'smtp'; return \$c;");
    $raiz = dirname(__DIR__, 2);
    $srv = proc_open(['php', '-S', '127.0.0.1:8097', '-t', "$raiz/public", "$raiz/tests/e2e/router_dev.php"],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubos, null, ['SS_CONFIG' => $tmp] + getenv());
    usleep(700000);
    $prod = new Navegador('http://127.0.0.1:8097');
    $estado = $prod->get('/dev/correos')['estado'];
    $recuperar = $prod->get('/recuperar')['cuerpo'];
    proc_terminate($srv);
    proc_close($srv);
    unlink($tmp);
    igual(404, $estado);
    noContiene('Buzón de prueba', $recuperar);
});
prueba('cada GET privado redirige al inicio de sesión', function () use ($anonimo, $privadas, $concreta) {
    foreach (array_filter($privadas, fn($r) => $r['metodo'] === 'GET') as $r) {
        $x = $anonimo->get($concreta($r['ruta']));
        igual(303, $x['estado'], 'GET ' . $r['ruta']);
        igual('/login', $x['cabeceras']['location'] ?? '', 'GET ' . $r['ruta']);
    }
});
prueba('cada POST privado se rechaza (aun con token de una sesión anónima)', function () use ($anonimo, $privadas, $concreta) {
    $token = $anonimo->csrf('/login');
    foreach (array_filter($privadas, fn($r) => $r['metodo'] === 'POST') as $r) {
        $x = $anonimo->post($concreta($r['ruta']), ['_csrf' => $token]);
        verdadero(in_array($x['estado'], [401, 403], true), 'POST ' . $r['ruta'] . ' → ' . $x['estado']);
    }
});
prueba('pedido JSON sin sesión: 401 en JSON, no la página de inicio', function () use ($anonimo) {
    $x = $anonimo->post('/expedientes/1/dia', [], ['Accept: application/json']);
    igual(401, $x['estado']);
    contiene('application/json', $x['cabeceras']['content-type'] ?? '');
});

$n = new Navegador($URL);
entrar($n, $ADMIN, $PW);
seccion('CSRF en todos los POST');
prueba('sin token: 403 en cada POST, sin cambiar nada', function () use ($n, $posts, $concreta, $root) {
    $antes = (int)$root->query('SELECT COUNT(*) FROM historial')->fetchColumn();
    foreach ($posts as $r) {
        if ($r['ruta'] === '/logout') {
            continue; // se prueba aparte para no cerrar la sesión
        }
        $x = $n->post($concreta($r['ruta']), ['motivo' => 'x']);
        igual(403, $x['estado'], 'POST ' . $r['ruta']);
    }
    igual($antes, (int)$root->query('SELECT COUNT(*) FROM historial')->fetchColumn(), 'el historial no cambió');
});
prueba('token equivocado o de otra sesión: 403', function () use ($n, $URL) {
    igual(403, $n->post('/respaldos', ['_csrf' => str_repeat('a', 64)])['estado']);
    $otro = new Navegador($URL);
    $tokenAjeno = $otro->csrf('/login');
    igual(403, $n->post('/respaldos', ['_csrf' => $tokenAjeno])['estado']);
});
prueba('el token en la cabecera X-CSRF-Token también vale', fn() => igual(204, $n->post('/sesion/renovar', [], ['X-CSRF-Token: ' . $n->csrf('/'), 'Accept: application/json'])['estado']));
prueba('logout sin token no cierra la sesión', function () use ($n) {
    igual(403, $n->post('/logout', [])['estado']);
    igual(200, $n->get('/')['estado']);
});

seccion('Cabeceras y contenido');
$paginas = ['/', '/alumnos/nuevo', '/dias-inhabiles', '/historial', '/respaldos', '/configuracion'];
$expediente = (int)$root->query('SELECT id FROM expedientes ORDER BY id DESC LIMIT 1')->fetchColumn();
if ($expediente) {
    $paginas[] = "/expedientes/$expediente";
}
prueba('cabeceras de seguridad en cada página', function () use ($n, $paginas) {
    foreach ($paginas as $p) {
        $h = $n->get($p)['cabeceras'];
        contiene("default-src 'self'", $h['content-security-policy'] ?? '', "CSP $p");
        noContiene('unsafe-inline', $h['content-security-policy'] ?? '', "CSP $p");
        contiene("frame-ancestors 'none'", $h['content-security-policy'] ?? '', $p);
        igual('DENY', $h['x-frame-options'] ?? '', $p);
        igual('nosniff', $h['x-content-type-options'] ?? '', $p);
        igual('no-store', $h['cache-control'] ?? '', $p);
        verdadero(!isset($h['x-powered-by']), "sin X-Powered-By en $p");
    }
});
prueba('sin scripts ni estilos en línea (los bloquearía la CSP)', function () use ($n, $paginas, $URL) {
    $ls = ['/login', '/recuperar'];
    $anon = new Navegador($URL);
    foreach (array_merge($paginas, $ls) as $p) {
        $c = (in_array($p, $ls, true) ? $anon : $n)->get($p)['cuerpo'];
        verdadero(!preg_match('#<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>#i', $c), "script en línea en $p");
        verdadero(!preg_match('#\sstyle="#i', $c), "atributo style en $p");
        verdadero(!preg_match('#\son[a-z]+="#i', $c), "manejador onX en $p");
    }
});
prueba('la cookie de sesión es HttpOnly y SameSite=Lax', function () use ($URL) {
    $ch = curl_init("$URL/login");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
    $h = (string)curl_exec($ch);
    verdadero((bool)preg_match('/^set-cookie: ss_sesion=[^\r\n]*httponly[^\r\n]*samesite=lax/im', $h), 'cookie');
});

seccion('Archivos fuera de public/');
prueba('código, configuración, almacenamiento y migraciones no se sirven', function () use ($anonimo) {
    foreach (['/../config/config.ejemplo.php', '/config/config.ejemplo.php', '/src/bootstrap.php', '/storage/README.md',
              '/database/migraciones/001_esquema.sql', '/.git/config', '/composer.json', '/index.php/../config/config.ejemplo.php',
              '/%2e%2e/config/config.ejemplo.php'] as $u) {
        $x = $anonimo->get($u);
        noContiene('<?php', $x['cuerpo'], $u);
        noContiene('CREATE TABLE', $x['cuerpo'], $u);
        noContiene("'password'", $x['cuerpo'], $u);
    }
});
prueba('los recursos estáticos sí se sirven', fn() => igual(200, $anonimo->get('/assets/app.css')['estado']));

seccion('Entradas maliciosas o malformadas');
prueba('inyección SQL en búsquedas y filtros: sin errores', function () use ($n) {
    foreach (['/?q=%27%20OR%201%3D1%20--', '/?q=%27%3B%20DROP%20TABLE%20alumnos%3B--', '/?orden=1;DROP', '/historial?texto=%27%29%20OR%20%271%27%3D%271',
              '/historial?expediente_id=1%20OR%201=1', '/historial?entidad=alumnos%27--'] as $u) {
        $x = $n->get($u);
        igual(200, $x['estado'], $u);
        noContiene('SQLSTATE', $x['cuerpo'], $u);
    }
});
prueba('texto reflejado siempre escapado', function () use ($n) {
    $c = $n->get('/?q=' . urlencode('"><script>alert(1)</script>'))['cuerpo'];
    noContiene('<script>alert(1)', $c);
    contiene('&lt;script&gt;', $c);
});
prueba('arreglos donde se espera texto: validación, no error 500', function () use ($n, $expediente) {
    $csrf = $n->csrf('/');
    $x = $n->post('/alumnos/nuevo', ['_csrf' => $csrf, 'matricula' => ['ABCDE'], 'nombre' => ['x' => 'y']]);
    igual(422, $x['estado']);
    contiene('Escribe la matrícula', $x['cuerpo'], 'un arreglo no cuenta como matrícula');
    noContiene('Array', $x['cuerpo']);
    if ($expediente) {
        $x = $n->post("/expedientes/$expediente/dia", ['_csrf' => $csrf, 'fecha' => ['2026-01-01'], 'estado' => ['asistencia']]);
        verdadero($x['estado'] < 500, 'día: ' . $x['estado']);
    }
    $x = $n->post('/configuracion', ['_csrf' => $csrf, 'umbral_proximo_horas' => ['1'], 'motivo' => 'x']);
    igual(422, $x['estado']);
});
prueba('ids no numéricos o enormes: 404, nunca 500', function () use ($n) {
    foreach (['/expedientes/abc', '/expedientes/-1', '/expedientes/99999999999999999999', '/alumnos/1e3', '/historial/0x10'] as $u) {
        igual(404, $n->get($u)['estado'], $u);
    }
});
prueba('métodos no permitidos: 405', function () use ($n) {
    igual(405, $n->get('/respaldos/restaurar')['estado']);
    igual(405, $n->post('/historial/5', ['_csrf' => $n->csrf('/')])['estado']);
});
prueba('redirección externa imposible tras el login (destino)', function () use ($URL, $ADMIN, $PW) {
    $x = new Navegador($URL);
    $x->get('//evil.example.com/');
    $r = entrar($x, $ADMIN, $PW);
    $destino = $r['cabeceras']['location'] ?? '';
    verdadero(str_starts_with($destino, '/') && !str_starts_with($destino, '//'), "destino interno ($destino)");
});

resumen();
