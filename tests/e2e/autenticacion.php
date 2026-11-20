<?php
declare(strict_types=1);

/**
 * Pruebas de punta a punta de la Etapa 3 contra un servidor real.
 *
 *   E2E_URL=http://127.0.0.1:8099 E2E_CORREOS=/ruta/correos.log E2E_SESIONES=/ruta/sesiones \
 *   E2E_URL_HTTPS=http://127.0.0.1:8098 php tests/e2e/autenticacion.php
 *
 * Requiere: base de desarrollo recién instalada, el administrador de
 * prueba admin.prueba@ejemplo.test / contrasena-de-prueba, y acceso root
 * local a MariaDB (socket) para preparar casos. Solo datos ficticios.
 */

require __DIR__ . '/../lib.php';

$URL = getenv('E2E_URL') ?: 'http://127.0.0.1:8099';
$CORREOS = getenv('E2E_CORREOS') ?: '';
$SESIONES = getenv('E2E_SESIONES') ?: '';
$ADMIN = 'admin.prueba@ejemplo.test';
$PW = 'contrasena-de-prueba';

$root = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=servicio_social;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

/** Un navegador con sus propias cookies. */
final class Navegador
{
    private string $jar;
    public array $ultima = [];

    public function __construct(private readonly string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function get(string $ruta, array $cabeceras = []): array
    {
        return $this->pedir('GET', $ruta, null, $cabeceras);
    }

    public function post(string $ruta, array $datos, array $cabeceras = []): array
    {
        return $this->pedir('POST', $ruta, http_build_query($datos), $cabeceras);
    }

    /** Token CSRF de la página actual (o de /login si no hay). */
    public function csrf(string $desde = '/login'): string
    {
        $r = $this->get($desde);
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['cuerpo'], $m);
        if (!$m) {
            preg_match('/data-csrf="([a-f0-9]{64})"/', $r['cuerpo'], $m);
        }
        return $m[1] ?? '';
    }

    public function cookieSesion(): ?string
    {
        foreach (file($this->jar) ?: [] as $linea) {
            $c = explode("\t", trim($linea));
            if (count($c) === 7 && $c[5] === 'ss_sesion') {
                return rawurldecode($c[6]);
            }
        }
        return null;
    }

    private function pedir(string $metodo, string $ruta, ?string $cuerpo, array $cabeceras): array
    {
        $ch = curl_init($this->base . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_HTTPHEADER     => $cabeceras,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $raw = (string)curl_exec($ch);
        $tam = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $estado = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $h = [];
        foreach (explode("\r\n", substr($raw, 0, $tam)) as $l) {
            if (str_contains($l, ':')) {
                [$k, $v] = explode(':', $l, 2);
                $h[strtolower(trim($k))] = trim($v);
            }
        }
        return $this->ultima = ['estado' => $estado, 'cabeceras' => $h, 'cuerpo' => substr($raw, $tam)];
    }
}

function entrar(Navegador $n, string $correo, string $password): array
{
    return $n->post('/login', ['_csrf' => $n->csrf(), 'correo' => $correo, 'password' => $password]);
}

function ultimoEnlace(string $archivo): ?string
{
    $lineas = is_file($archivo) ? file($archivo, FILE_IGNORE_NEW_LINES) : [];
    for ($i = count($lineas) - 1; $i >= 0; $i--) {
        $c = json_decode($lineas[$i], true);
        if (preg_match('#(/restablecer\?t=[a-f0-9]{64})#', $c['texto'] ?? '', $m)) {
            return $m[1];
        }
    }
    return null;
}

function correosEnviados(string $archivo): int
{
    return is_file($archivo) ? count(file($archivo)) : 0;
}

function ultimoHistorial(PDO $root, string $accion): ?array
{
    $q = $root->prepare('SELECT * FROM historial WHERE accion = ? ORDER BY id DESC LIMIT 1');
    $q->execute([$accion]);
    return $q->fetch() ?: null;
}

// --- Preparación -----------------------------------------------------------
$root->exec('DELETE FROM intentos_login');
$root->exec('DELETE FROM tokens_reset');
@unlink($CORREOS);
// Las pruebas de la base pueden haber cambiado la contraseña del administrador de prueba.
$root->prepare('UPDATE usuarios SET password_hash = ?, activo = 1 WHERE correo = ?')
    ->execute([password_hash($PW, PASSWORD_ARGON2ID), $ADMIN]);
$crear = $root->prepare("INSERT IGNORE INTO usuarios (nombre, correo, password_hash, activo) VALUES (?, ?, ?, ?)");
$crear->execute(['Usuario Bloqueo', 'bloqueo@ejemplo.test', password_hash($PW, PASSWORD_ARGON2ID), 1]);
$crear->execute(['Usuario Inactivo', 'inactivo@ejemplo.test', password_hash($PW, PASSWORD_ARGON2ID), 0]);

seccion('Rutas protegidas y cabeceras');
prueba('sin sesión, / redirige a /login', function () use ($URL) {
    $r = (new Navegador($URL))->get('/');
    igual(303, $r['estado'], 'estado');
    igual('/login', $r['cabeceras']['location'] ?? '', 'destino');
});
prueba('cabeceras de seguridad y cookie protegida', function () use ($URL) {
    $r = (new Navegador($URL))->get('/login');
    contiene("frame-ancestors 'none'", $r['cabeceras']['content-security-policy'] ?? '', 'CSP');
    igual('DENY', $r['cabeceras']['x-frame-options'] ?? '', 'X-Frame-Options');
    igual('nosniff', $r['cabeceras']['x-content-type-options'] ?? '', 'nosniff');
    contiene('HttpOnly', $r['cabeceras']['set-cookie'] ?? '', 'cookie');
    contiene('SameSite=Lax', $r['cabeceras']['set-cookie'] ?? '', 'cookie');
    verdadero(!isset($r['cabeceras']['x-powered-by']), 'sin X-Powered-By');
});
prueba('ruta inexistente responde 404', fn() => igual(404, (new Navegador($URL))->get('/no-existe')['estado']));
prueba('GET a una ruta solo POST responde 405', fn() => igual(405, (new Navegador($URL))->get('/logout')['estado']));
prueba('API sin sesión responde 401 en JSON', function () use ($URL) {
    $r = (new Navegador($URL))->post('/sesion/renovar', [], ['Accept: application/json']);
    igual(401, $r['estado']);
    contiene('no_autenticado', $r['cuerpo']);
});

seccion('Inicio de sesión');
prueba('POST sin token CSRF se rechaza', function () use ($URL, $ADMIN, $PW) {
    $r = (new Navegador($URL))->post('/login', ['correo' => $ADMIN, 'password' => $PW]);
    igual(403, $r['estado']);
});
prueba('contraseña incorrecta: mensaje genérico', function () use ($URL, $ADMIN) {
    $r = entrar(new Navegador($URL), $ADMIN, 'equivocada-123');
    igual(422, $r['estado']);
    contiene('Correo o contraseña incorrectos', $r['cuerpo']);
});
prueba('correo inexistente: el mismo mensaje', function () use ($URL) {
    $r = entrar(new Navegador($URL), 'nadie@ejemplo.test', 'cualquier-cosa');
    igual(422, $r['estado']);
    contiene('Correo o contraseña incorrectos', $r['cuerpo']);
});
prueba('usuario desactivado: el mismo mensaje', function () use ($URL, $PW) {
    $r = entrar(new Navegador($URL), 'inactivo@ejemplo.test', $PW);
    igual(422, $r['estado']);
    contiene('Correo o contraseña incorrectos', $r['cuerpo']);
});
prueba('el formulario conserva el correo escapado', function () use ($URL) {
    $r = entrar(new Navegador($URL), '"><script>x</script>', 'x');
    noContiene('<script>x</script>', $r['cuerpo'], 'HTML');
});
prueba('5 fallos bloquean el correo, aun con la contraseña correcta', function () use ($URL, $PW, $root) {
    $n = new Navegador($URL);
    for ($i = 0; $i < 5; $i++) { entrar($n, 'bloqueo@ejemplo.test', 'mala-' . $i); }
    $r = entrar($n, 'bloqueo@ejemplo.test', $PW);
    igual(429, $r['estado']);
    contiene('Demasiados intentos', $r['cuerpo']);
    verdadero(ultimoHistorial($root, 'bloqueo_por_intentos') !== null, 'evento en historial');
});

$admin = new Navegador($URL);
prueba('credenciales correctas entran y cambian el id de sesión', function () use ($admin, $ADMIN, $PW, $root) {
    $admin->get('/login');
    $antes = $admin->cookieSesion();
    $r = entrar($admin, $ADMIN, $PW);
    igual(303, $r['estado']);
    igual('/', $r['cabeceras']['location'] ?? '');
    verdadero($admin->cookieSesion() !== $antes, 'id de sesión nuevo');
    $inicio = $admin->get('/');
    igual(200, $inicio['estado']);
    contiene('Admin de Prueba', $inicio['cuerpo']);
    verdadero($root->query("SELECT ultimo_acceso FROM usuarios WHERE id = 1")->fetchColumn() !== null, 'último acceso');
    igual('1', (string)ultimoHistorial($root, 'iniciar_sesion')['usuario_id'], 'historial');
});
prueba('con sesión, /login lleva al inicio', fn() => igual(303, $admin->get('/login')['estado']));
prueba('renovar la sesión con CSRF responde 204', function () use ($admin) {
    $csrf = $admin->csrf('/');
    igual(204, $admin->post('/sesion/renovar', [], ['X-CSRF-Token: ' . $csrf, 'Accept: application/json'])['estado']);
});
prueba('renovar sin CSRF responde 403', fn() => igual(403, $admin->post('/sesion/renovar', [], ['Accept: application/json'])['estado']));
prueba('se regresa a la página pedida después de entrar', function () use ($URL, $ADMIN, $PW) {
    $n = new Navegador($URL);
    $n->get('/');
    igual('/', entrar($n, $ADMIN, $PW)['cabeceras']['location'] ?? '');
});

seccion('Cierre de sesión');
prueba('cerrar sesión sin CSRF se rechaza', fn() => igual(403, $admin->post('/logout', [])['estado']));
prueba('cerrar sesión con CSRF termina la sesión', function () use ($admin, $root) {
    $r = $admin->post('/logout', ['_csrf' => $admin->csrf('/')]);
    igual('/login?m=salida', $r['cabeceras']['location'] ?? '');
    igual(303, $admin->get('/')['estado'], 'ya no entra');
    igual('manual', json_decode(ultimoHistorial($root, 'cerrar_sesion')['despues'], true)['motivo'] ?? '', 'motivo');
});

seccion('Cierre por inactividad');
prueba('30 min sin actividad cierran la sesión', function () use ($URL, $ADMIN, $PW, $SESIONES, $root) {
    $n = new Navegador($URL);
    entrar($n, $ADMIN, $PW);
    $archivo = $SESIONES . '/sess_' . $n->cookieSesion();
    verdadero(is_file($archivo), 'archivo de sesión');
    $datos = (string)file_get_contents($archivo);
    $datos = preg_replace('/ultimo_mov\|i:(\d+);/', 'ultimo_mov|i:' . (time() - 31 * 60) . ';', $datos);
    file_put_contents($archivo, $datos);
    $r = $n->get('/');
    igual('/login?m=expirada', $r['cabeceras']['location'] ?? '');
    igual('inactividad', json_decode(ultimoHistorial($root, 'cerrar_sesion')['despues'], true)['motivo'] ?? '');
    contiene('inactividad', $n->get('/login?m=expirada')['cuerpo'], 'aviso');
});

seccion('Recuperación de contraseña');
$sesionVieja = new Navegador($URL);
entrar($sesionVieja, $ADMIN, $PW);
prueba('correo inexistente: misma respuesta y ningún correo', function () use ($URL, $CORREOS) {
    $antes = correosEnviados($CORREOS);
    $n = new Navegador($URL);
    $r = $n->post('/recuperar', ['_csrf' => $n->csrf('/recuperar'), 'correo' => 'nadie@ejemplo.test']);
    igual('/login?m=enviado', $r['cabeceras']['location'] ?? '');
    igual($antes, correosEnviados($CORREOS), 'correos');
});
prueba('correo de un usuario desactivado: ningún correo', function () use ($URL, $CORREOS) {
    $antes = correosEnviados($CORREOS);
    $n = new Navegador($URL);
    $n->post('/recuperar', ['_csrf' => $n->csrf('/recuperar'), 'correo' => 'inactivo@ejemplo.test']);
    igual($antes, correosEnviados($CORREOS), 'correos');
});
$enlace = null;
prueba('correo registrado: envía un enlace y guarda solo el hash', function () use ($URL, $CORREOS, $ADMIN, $root, &$enlace) {
    $n = new Navegador($URL);
    $r = $n->post('/recuperar', ['_csrf' => $n->csrf('/recuperar'), 'correo' => strtoupper($ADMIN)]);
    igual('/login?m=enviado', $r['cabeceras']['location'] ?? '');
    $enlace = ultimoEnlace($CORREOS);
    verdadero($enlace !== null, 'enlace en el correo');
    $token = substr($enlace, strpos($enlace, '=') + 1);
    $guardado = $root->query('SELECT token_hash FROM tokens_reset ORDER BY id DESC LIMIT 1')->fetchColumn();
    igual(hash('sha256', $token), $guardado, 'hash');
    verdadero($guardado !== $token, 'el token no se guarda');
});
$reset = new Navegador($URL);
prueba('abrir el enlace quita el token de la URL', function () use ($reset, &$enlace) {
    $r = $reset->get($enlace);
    igual(303, $r['estado']);
    igual('/restablecer', $r['cabeceras']['location'] ?? '');
    igual('no-referrer', $r['cabeceras']['referrer-policy'] ?? '');
    $f = $reset->get('/restablecer');
    igual(200, $f['estado']);
    contiene('Nueva contraseña', $f['cuerpo']);
});
prueba('contraseñas que no coinciden', function () use ($reset) {
    $r = $reset->post('/restablecer', ['_csrf' => $reset->csrf('/restablecer'), 'password' => 'nueva-contrasena-1', 'confirmacion' => 'nueva-contrasena-2']);
    igual(422, $r['estado']);
    contiene('no coinciden', $r['cuerpo']);
});
prueba('contraseña corta', function () use ($reset) {
    $r = $reset->post('/restablecer', ['_csrf' => $reset->csrf('/restablecer'), 'password' => 'corta', 'confirmacion' => 'corta']);
    igual(422, $r['estado']);
    contiene('12 caracteres', $r['cuerpo']);
});
prueba('contraseña válida: se guarda y avisa por correo', function () use ($reset, $CORREOS, $root) {
    $antes = correosEnviados($CORREOS);
    $r = $reset->post('/restablecer', ['_csrf' => $reset->csrf('/restablecer'), 'password' => 'nueva-contrasena-segura', 'confirmacion' => 'nueva-contrasena-segura']);
    igual('/login?m=restablecida', $r['cabeceras']['location'] ?? '');
    igual($antes + 1, correosEnviados($CORREOS), 'aviso enviado');
    $h = $root->query("SELECT despues, motivo, usuario_id FROM historial WHERE entidad = 'usuarios' AND accion = 'actualizar' ORDER BY id DESC LIMIT 1")->fetch();
    igual(true, json_decode($h['despues'], true)['password_cambiada'] ?? null, 'historial');
    igual('Restablecimiento de contraseña por correo', $h['motivo'], 'motivo');
});
prueba('el enlace no se puede usar dos veces', fn() => igual(410, (new Navegador($URL))->get($enlace)['estado']));
prueba('la sesión abierta antes del cambio ya no sirve', function () use ($sesionVieja) {
    igual('/login?m=expirada', $sesionVieja->get('/')['cabeceras']['location'] ?? '');
});
prueba('la contraseña anterior ya no entra y la nueva sí', function () use ($URL, $ADMIN, $PW) {
    igual(422, entrar(new Navegador($URL), $ADMIN, $PW)['estado'], 'anterior');
    igual(303, entrar(new Navegador($URL), $ADMIN, 'nueva-contrasena-segura')['estado'], 'nueva');
});
prueba('un enlace vencido se rechaza', function () use ($URL, $CORREOS, $ADMIN, $root) {
    $n = new Navegador($URL);
    $n->post('/recuperar', ['_csrf' => $n->csrf('/recuperar'), 'correo' => $ADMIN]);
    $root->exec('UPDATE tokens_reset SET creado_en = NOW() - INTERVAL 40 MINUTE, expira_en = NOW() - INTERVAL 10 MINUTE ORDER BY id DESC LIMIT 1');
    igual(410, $n->get(ultimoEnlace($CORREOS))['estado']);
});
prueba('un enlace inventado se rechaza', fn() => igual(410, (new Navegador($URL))->get('/restablecer?t=' . str_repeat('a', 64))['estado']));
prueba('pedir un enlace nuevo invalida el anterior', function () use ($URL, $CORREOS, $root) {
    $root->exec('DELETE FROM tokens_reset');
    $n = new Navegador($URL);
    $n->post('/recuperar', ['_csrf' => $n->csrf('/recuperar'), 'correo' => 'bloqueo@ejemplo.test']);
    $primero = ultimoEnlace($CORREOS);
    $n->post('/recuperar', ['_csrf' => $n->csrf('/recuperar'), 'correo' => 'bloqueo@ejemplo.test']);
    igual(410, (new Navegador($URL))->get($primero)['estado'], 'el primero');
    igual(303, (new Navegador($URL))->get(ultimoEnlace($CORREOS))['estado'], 'el segundo');
});
prueba('máximo 3 enlaces por hora para el mismo usuario', function () use ($URL, $CORREOS) {
    $n = new Navegador($URL);
    $n->post('/recuperar', ['_csrf' => $n->csrf('/recuperar'), 'correo' => 'bloqueo@ejemplo.test']);
    $antes = correosEnviados($CORREOS);
    $n->post('/recuperar', ['_csrf' => $n->csrf('/recuperar'), 'correo' => 'bloqueo@ejemplo.test']);
    igual($antes, correosEnviados($CORREOS), 'el cuarto no se envía');
});

$https = getenv('E2E_URL_HTTPS');
if ($https) {
    seccion('HTTPS obligatorio (servidor con forzar_https)');
    prueba('HTTP redirige a la URL HTTPS de la configuración, no a la cabecera Host', function () use ($https) {
        $r = (new Navegador($https))->get('/login', ['Host: sitio-malicioso.test']);
        igual(301, $r['estado']);
        igual('https://serviciosocial.ejemplo.test/login', $r['cabeceras']['location'] ?? '');
    });
}

resumen();
