<?php
declare(strict_types=1);

/**
 * Pruebas de punta a punta de la Etapa 7: historial, sellado, respaldos
 * (crear, verificar, descargar, retención), restauración agregando lo que
 * falta, candado, mantenimiento y los scripts del servidor.
 *   E2E_URL=... E2E_CONFIG=/ruta/config.php PW_ADMIN=... php tests/e2e/respaldos.php
 */

require __DIR__ . '/../lib.php';
require __DIR__ . '/comun.php';

date_default_timezone_set('America/Mexico_City');
$URL = getenv('E2E_URL') ?: 'http://127.0.0.1:8099';
$CONFIG = getenv('E2E_CONFIG') ?: '';
$PW_ADMIN = getenv('PW_ADMIN') ?: '';
if (!is_file($CONFIG)) { fwrite(STDERR, "Falta E2E_CONFIG\n"); exit(1); }
$cfg = require $CONFIG;
$ADMIN = 'admin.prueba@ejemplo.test';
$PW = 'contrasena-de-prueba';
$root = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=servicio_social;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$root->exec('DELETE FROM intentos_login');
$root->prepare('UPDATE usuarios SET password_hash = ?, activo = 1 WHERE correo = ?')->execute([password_hash($PW, PASSWORD_ARGON2ID), $ADMIN]);
$adminId = (int)$root->query("SELECT id FROM usuarios WHERE correo = '$ADMIN'")->fetchColumn();
$n = new Navegador($URL);
entrar($n, $ADMIN, $PW);
$CSRF = $n->csrf('/');
$RAIZ = dirname(__DIR__, 2);
$cli = function (string $script, string $args = '', array $env = []) use ($CONFIG, $RAIZ): array {
    $pre = 'SS_CONFIG=' . escapeshellarg($CONFIG);
    foreach ($env as $k => $v) { $pre .= " $k=" . escapeshellarg($v); }
    exec("$pre php " . escapeshellarg("$RAIZ/bin/$script") . " $args 2>&1", $salida, $codigo);
    return ['codigo' => $codigo, 'salida' => implode("\n", $salida)];
};
/** Texto de todos los avisos de la página. */
$aviso = function (string $html): string {
    preg_match_all('#class="aviso aviso-(?:exito|error|info|aviso)"[^>]*>(.*?)</(?:p|div)>#s', $html, $m);
    return trim(strip_tags(implode(' | ', $m[1])));
};
$ultimoRespaldo = fn() => $root->query('SELECT * FROM respaldos ORDER BY id DESC LIMIT 1')->fetch();

// Datos propios: un alumno con expediente, tres días y una firma.
$hoy = new DateTimeImmutable('today');
$lunes = $hoy->modify('-' . ((int)$hoy->format('N') - 1) . ' day')->modify('-2 week');
$M = 'E2E7-' . substr((string)time(), -6);
$n->post('/alumnos/nuevo', ['_csrf' => $CSRF, 'matricula' => $M, 'nombre' => 'Respaldo', 'apellido_paterno' => 'Prueba', 'carrera' => 'Ing.',
    'institucion' => 'UTN', 'unidad_administrativa' => 'U', 'responsable' => 'R', 'fecha_inicio' => $lunes->format('Y-m-d'),
    'jornada_prevista' => '4', 'feriados_acreditan' => '1', 'horas_por_feriado' => '4']);
$A = (int)$root->query("SELECT id FROM alumnos WHERE matricula = '$M'")->fetchColumn();
$E = (int)$root->query("SELECT id FROM expedientes WHERE alumno_id = $A")->fetchColumn();
foreach ([0, 1, 2] as $i) {
    $n->post("/expedientes/$E/dia", ['_csrf' => $CSRF, 'fecha' => $lunes->modify("+$i day")->format('Y-m-d'), 'estado' => 'asistencia',
        'horas_trabajadas' => '4', 'observaciones' => $i === 0 ? 'Ñandú «comillas» 🙂' : '']);
}
$n->post("/expedientes/$E/firmas", ['_csrf' => $CSRF, 'semana' => $lunes->format('Y-m-d')]);

seccion('Historial general');
prueba('la página carga con filtros y estado del sellado', function () use ($n) {
    $c = $n->get('/historial')['cuerpo'];
    contiene('Sellado del historial', $c);
    contiene('name="entidad"', $c);
});
prueba('filtrar por expediente y entidad', function () use ($n, $E) {
    $c = $n->get("/historial?expediente_id=$E&entidad=asistencias")['cuerpo'];
    contiene('3 movimiento(s)', $c);
});
prueba('detalle de un movimiento con antes y después', function () use ($n, $root, $E) {
    $id = (int)$root->query("SELECT id FROM historial WHERE entidad = 'expedientes' AND entidad_id = $E AND accion = 'crear'")->fetchColumn();
    $c = $n->get("/historial/$id")['cuerpo'];
    contiene('Movimiento ' . $id, $c);
    contiene('fecha inicio', $c);
});
prueba('filtros inválidos no rompen la página', fn() => igual(200, $n->get('/historial?entidad=x;drop&desde=2026-99-99&usuario_id=abc')['estado']));
prueba('movimiento inexistente: 404', fn() => igual(404, $n->get('/historial/99999999')['estado']));

seccion('Sellado nocturno (ss_sello)');
prueba('sella todo y la cadena queda íntegra', function () use ($cli) {
    $r = $cli('sellar-historial.php', '--antiguedad=0');
    igual(0, $r['codigo'], $r['salida']);
    contiene('Cadena íntegra', $r['salida']);
});
prueba('verificar desde la web', function () use ($n, $CSRF, $aviso) {
    igual(303, $n->post('/historial/verificar', ['_csrf' => $CSRF])['estado']);
    contiene('Cadena íntegra', $aviso($n->get('/historial')['cuerpo']));
});
prueba('la verificación queda en el historial', fn() => igual(1, (int)$root->query("SELECT COUNT(*) > 0 FROM historial WHERE accion = 'verificar_historial'")->fetchColumn()));
prueba('sin contraseña de ss_sello: error claro', function () use ($cli, $CONFIG) {
    $tmp = tempnam(sys_get_temp_dir(), 'cfg');
    file_put_contents($tmp, '<?php $c = require ' . var_export($CONFIG, true) . "; \$c['sello']['password'] = ''; return \$c;");
    exec('SS_CONFIG=' . escapeshellarg($tmp) . ' php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/sellar-historial.php') . ' 2>&1', $s, $codigo);
    unlink($tmp);
    igual(2, $codigo);
    contiene('sello.password', implode("\n", $s));
});

seccion('Respaldos JSON');
$R = null;
prueba('crear un respaldo manual: archivo firmado, verificado y registrado', function () use ($n, $CSRF, $ultimoRespaldo, $cfg, &$R, $root) {
    igual(303, $n->post('/respaldos', ['_csrf' => $CSRF])['estado']);
    $R = $ultimoRespaldo();
    igual('manual', $R['tipo']);
    verdadero($R['verificado_en'] !== null, 'verificado');
    verdadero(is_file($cfg['respaldos']['ruta'] . '/' . $R['archivo']), 'archivo en disco');
    igual('0640', substr(sprintf('%o', fileperms($cfg['respaldos']['ruta'] . '/' . $R['archivo'])), -4), 'permisos');
    verdadero((int)$R['total_asistencias'] >= 3, 'totales');
    igual(1, (int)$root->query("SELECT COUNT(*) FROM historial WHERE accion = 'respaldar' AND entidad_id = {$R['id']}")->fetchColumn());
});
prueba('el archivo no contiene contraseñas ni tokens', function () use ($cfg, &$R) {
    $t = gzdecode(file_get_contents($cfg['respaldos']['ruta'] . '/' . $R['archivo']));
    noContiene('password_hash', $t);
    noContiene('$argon2', $t);
    noContiene('token', $t);
    contiene('Ñandú «comillas» 🙂', $t);
});
prueba('verificar', function () use ($n, $CSRF, &$R, $aviso) {
    $n->post("/respaldos/{$R['id']}/verificar", ['_csrf' => $CSRF]);
    contiene('íntegro', $aviso($n->get('/respaldos')['cuerpo']));
});
prueba('un archivo alterado en disco se detecta al verificar', function () use ($n, $CSRF, $cfg, $aviso) {
    $n->post('/respaldos', ['_csrf' => $CSRF]);
    $X = $GLOBALS['ultimoRespaldo']();
    $ruta = $cfg['respaldos']['ruta'] . '/' . $X['archivo'];
    $t = gzdecode(file_get_contents($ruta));
    file_put_contents($ruta, gzencode(str_replace('"horas_acreditadas":4', '"horas_acreditadas":6', $t)));
    $n->post("/respaldos/{$X['id']}/verificar", ['_csrf' => $CSRF]);
    contiene('huella', $aviso($n->get('/respaldos')['cuerpo']));
    file_put_contents($ruta, gzencode($t)); // se deja como estaba
});
prueba('descargar exige confirmar los datos personales', function () use ($n, $CSRF, &$R, $aviso) {
    igual(303, $n->post("/respaldos/{$R['id']}/descargar", ['_csrf' => $CSRF])['estado']);
    contiene('datos personales', $aviso($n->get('/respaldos')['cuerpo']));
});
prueba('descargar: archivo adjunto y evento en el historial', function () use ($n, $CSRF, &$R, $root) {
    $d = $n->post("/respaldos/{$R['id']}/descargar", ['_csrf' => $CSRF, 'entiendo' => '1']);
    igual(200, $d['estado']);
    igual('application/gzip', $d['cabeceras']['content-type'] ?? '');
    contiene('attachment; filename="' . $R['archivo'] . '"', $d['cabeceras']['content-disposition'] ?? '');
    igual('no-store', $d['cabeceras']['cache-control'] ?? '');
    igual(1, (int)$root->query("SELECT COUNT(*) FROM historial WHERE accion = 'descargar_respaldo' AND entidad_id = {$R['id']}")->fetchColumn());
});
prueba('Excel: exige confirmar, es un .xlsx válido y deja evento', function () use ($n, $CSRF, &$R, $root) {
    igual(303, $n->post("/respaldos/{$R['id']}/excel", ['_csrf' => $CSRF])['estado']);
    $d = $n->post("/respaldos/{$R['id']}/excel", ['_csrf' => $CSRF, 'entiendo' => '1']);
    igual(200, $d['estado']);
    igual('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $d['cabeceras']['content-type'] ?? '');
    contiene('filename="' . str_replace('.json.gz', '.xlsx', $R['archivo']) . '"', $d['cabeceras']['content-disposition'] ?? '');
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, $d['cuerpo']);
    $z = new ZipArchive();
    igual(true, $z->open($tmp, ZipArchive::CHECKCONS));
    contiene('name="Expedientes"', $z->getFromName('xl/workbook.xml'));
    $z->close();
    unlink($tmp);
    igual(1, (int)$root->query("SELECT COUNT(*) FROM historial WHERE accion = 'descargar_respaldo_excel' AND entidad_id = {$R['id']}")->fetchColumn());
    igual(405, $n->get("/respaldos/{$R['id']}/excel")['estado']);
});
prueba('los archivos no tienen URL pública', function () use ($n, &$R) {
    igual(404, $n->get('/respaldos/' . $R['archivo'])['estado']);
    igual(404, $n->get('/storage/respaldos/' . $R['archivo'])['estado']);
    igual(405, $n->get("/respaldos/{$R['id']}/descargar")['estado']);
});
prueba('retención: conserva los últimos N y deja el registro de los demás', function () use ($n, $CSRF, $root, $cfg) {
    $root->exec("UPDATE configuracion SET valor = '5' WHERE clave = 'respaldos_retener'");
    for ($i = 0; $i < 6; $i++) { // 6 nuevos: siempre sobra al menos uno
        $n->post('/respaldos', ['_csrf' => $CSRF]);
    }
    igual(5, (int)$root->query('SELECT COUNT(*) FROM respaldos WHERE archivo_eliminado_en IS NULL')->fetchColumn());
    $viejo = $root->query('SELECT archivo FROM respaldos WHERE archivo_eliminado_en IS NOT NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
    verdadero($viejo !== false && !is_file($cfg['respaldos']['ruta'] . '/' . $viejo), 'archivo viejo borrado');
    contiene('Archivo eliminado', $n->get('/respaldos')['cuerpo']);
    $root->exec("UPDATE configuracion SET valor = '30' WHERE clave = 'respaldos_retener'");
});

seccion('Restaurar agregando lo que falta');
$n->post('/respaldos', ['_csrf' => $CSRF]);
$R = $ultimoRespaldo();
// Simula una pérdida: root borra las firmas, días, expediente y alumno de prueba,
// y un día de otro expediente que sigue existiendo.
$otro = $root->query("SELECT s.id, s.expediente_id, s.fecha FROM asistencias s JOIN expedientes e ON e.id = s.expediente_id
                       WHERE e.estado = 'activo' AND s.expediente_id <> $E ORDER BY s.id LIMIT 1")->fetch();
$root->exec("DELETE FROM firmas_semana WHERE expediente_id = $E");
$root->exec("DELETE FROM asistencias WHERE expediente_id = $E");
$root->exec("DELETE FROM expedientes WHERE id = $E");
$root->exec("DELETE FROM alumnos WHERE id = $A");
if ($otro) {
    $root->exec("DELETE FROM asistencias WHERE id = {$otro['id']}");
}
$preparar = fn(array $extra = []) => $n->post('/respaldos/restaurar', $extra + ['_csrf' => $CSRF, 'respaldo_id' => (string)$R['id']]);
prueba('vista previa: muestra lo que falta sin cambiar nada', function () use ($preparar, $otro, $root, $M) {
    $c = $preparar()['cuerpo'];
    contiene('Todavía no se ha cambiado nada', $c);
    verdadero((bool)preg_match('#Alumnos</th><td class="num">1</td>#', $c), '1 alumno');
    verdadero((bool)preg_match('#Días de asistencia</th><td class="num">' . (3 + ($otro ? 1 : 0)) . '</td>#', $c), 'días');
    verdadero((bool)preg_match('#Firmas</th><td class="num">1</td>#', $c), '1 firma');
    igual(0, (int)$root->query("SELECT COUNT(*) FROM alumnos WHERE matricula = '$M'")->fetchColumn(), 'sin cambios todavía');
});
prueba('confirmar sin motivo ni casilla, o con contraseña equivocada: 422', function () use ($n, $CSRF) {
    $r = $n->post('/respaldos/restaurar/confirmar', ['_csrf' => $CSRF, 'password' => 'mala']);
    igual(422, $r['estado']);
    contiene('Escribe por qué se restaura', $r['cuerpo']);
    contiene('La contraseña no es correcta', $r['cuerpo']);
    contiene('Marca la casilla', $r['cuerpo']);
});
prueba('tres contraseñas equivocadas cancelan la restauración', function () use ($n, $CSRF, $preparar, $aviso) {
    $n->post('/respaldos/restaurar/confirmar', ['_csrf' => $CSRF, 'password' => 'mala', 'motivo' => 'x', 'confirmo' => '1']);
    $r = $n->post('/respaldos/restaurar/confirmar', ['_csrf' => $CSRF, 'password' => 'mala', 'motivo' => 'x', 'confirmo' => '1']);
    igual(303, $r['estado']);
    contiene('demasiadas veces', $aviso($n->get('/respaldos')['cuerpo']));
    igual(303, $n->post('/respaldos/restaurar/confirmar', ['_csrf' => $CSRF, 'password' => 'x'])['estado'], 'ya no hay restauración pendiente');
});
prueba('restaurar: agrega lo que faltaba, con respaldo previo y registro', function () use ($n, $CSRF, $preparar, $PW, $root, $M, $otro, $aviso) {
    $antes = (int)$root->query('SELECT COUNT(*) FROM respaldos')->fetchColumn();
    $preparar();
    $r = $n->post('/respaldos/restaurar/confirmar', ['_csrf' => $CSRF, 'password' => $PW, 'motivo' => 'Prueba de restauración', 'confirmo' => '1']);
    igual(303, $r['estado']);
    contiene('Restauración terminada', $n->get('/respaldos')['cuerpo']);
    $a = (int)$root->query("SELECT id FROM alumnos WHERE matricula = '$M'")->fetchColumn();
    verdadero($a > 0, 'alumno de vuelta (con id nuevo)');
    $e = (int)$root->query("SELECT id FROM expedientes WHERE alumno_id = $a")->fetchColumn();
    igual(3, (int)$root->query("SELECT COUNT(*) FROM asistencias WHERE expediente_id = $e")->fetchColumn(), 'días');
    igual(1, (int)$root->query("SELECT COUNT(*) FROM firmas_semana WHERE expediente_id = $e")->fetchColumn(), 'firma');
    igual('Ñandú «comillas» 🙂', $root->query("SELECT observaciones FROM asistencias WHERE expediente_id = $e AND observaciones IS NOT NULL")->fetchColumn());
    if ($otro) {
        igual(1, (int)$root->query("SELECT COUNT(*) FROM asistencias WHERE expediente_id = {$otro['expediente_id']} AND fecha = '{$otro['fecha']}'")->fetchColumn(), 'día del otro expediente');
    }
    igual('previo_restauracion', $root->query("SELECT tipo FROM respaldos WHERE id > (SELECT MAX(id) FROM respaldos) - 1 ORDER BY id DESC LIMIT 1")->fetchColumn());
    verdadero((int)$root->query('SELECT COUNT(*) FROM respaldos')->fetchColumn() === $antes + 1, 'un respaldo previo');
    $h = $root->query("SELECT motivo, despues FROM historial WHERE accion = 'restaurar' ORDER BY id DESC LIMIT 1")->fetch();
    igual('Prueba de restauración', $h['motivo']);
    igual(1, json_decode($h['despues'], true)['agregados']['alumnos']);
    igual(1, (int)$root->query("SELECT COUNT(*) > 0 FROM historial WHERE entidad = 'asistencias' AND accion = 'crear' AND motivo LIKE 'Restauración desde %'")->fetchColumn(), 'los triggers registran con motivo');
});
prueba('restaurar de nuevo: no hay nada que agregar', fn() => contiene('No hay nada que agregar', $preparar()['cuerpo']));
prueba('archivo subido alterado: se rechaza', function () use ($n, $CSRF, $cfg, &$R, $aviso) {
    $t = gzdecode(file_get_contents($cfg['respaldos']['ruta'] . '/' . $R['archivo']));
    $tmp = tempnam(sys_get_temp_dir(), 'resp');
    file_put_contents($tmp, gzencode(str_replace('"horas_acreditadas":4', '"horas_acreditadas":5', $t)));
    $r = subir($GLOBALS['URL'], $n, $CSRF, $tmp);
    unlink($tmp);
    igual(303, $r);
    contiene('No se puede restaurar', $aviso($n->get('/respaldos')['cuerpo']));
});
prueba('archivo subido válido: vista previa', function () use ($n, $CSRF, $cfg, &$R) {
    $tmp = tempnam(sys_get_temp_dir(), 'resp');
    copy($cfg['respaldos']['ruta'] . '/' . $R['archivo'], $tmp);
    $codigo = subir($GLOBALS['URL'], $n, $CSRF, $tmp, $cuerpo);
    unlink($tmp);
    igual(200, $codigo);
    contiene('Firma y huella verificadas', $cuerpo);
});

seccion('Candado y mantenimiento');
prueba('durante una restauración no se captura (423)', function () use ($n, $CSRF, $root, $E, $M, $lunes) {
    $root->query("SELECT GET_LOCK('ss_restauracion', 0)");
    $e = (int)$root->query("SELECT e.id FROM expedientes e JOIN alumnos a ON a.id = e.alumno_id WHERE a.matricula = '$M'")->fetchColumn();
    $r = $n->post("/expedientes/$e/dia", ['_csrf' => $CSRF, 'fecha' => $lunes->modify('+3 day')->format('Y-m-d'), 'estado' => 'falta'], ['Accept: application/json']);
    $root->query("SELECT RELEASE_LOCK('ss_restauracion')");
    igual(423, $r['estado']);
    contiene('restauración', $r['cuerpo']);
});
prueba('modo mantenimiento: 503 en todas las páginas', function () use ($n, $cfg) {
    file_put_contents($cfg['rutas']['mantenimiento'], 'prueba');
    $r = $n->get('/');
    $l = $n->get('/login');
    unlink($cfg['rutas']['mantenimiento']);
    igual(503, $r['estado']);
    igual(503, $l['estado']);
    igual('300', $r['cabeceras']['retry-after'] ?? '');
    igual(200, $n->get('/')['estado'], 'vuelve al quitarlo');
});

seccion('Scripts del servidor');
prueba('respaldo nocturno: JSON y volcado verificados', function () use ($cli, $cfg) {
    $r = $cli('respaldo-nocturno.php');
    igual(0, $r['codigo'], $r['salida']);
    contiene('JSON: respaldo-', $r['salida']);
    contiene('verificado', $r['salida']);
    verdadero(count(glob($cfg['respaldos']['volcado']['ruta'] . '/volcado-*.sql.gz')) >= 1, 'volcado en disco');
});
prueba('limpieza nocturna: sesiones vencidas y temporales viejos, nunca los vigentes', function () use ($cli, $cfg) {
    $ses = $cfg['sesion']['ruta_archivos'];
    $tmp = $cfg['rutas']['temporal'];
    @mkdir($tmp, 0750, true);
    touch("$ses/sess_vieja_prueba", time() - 20 * 3600);
    touch("$ses/sess_reciente_prueba");
    touch("$tmp/restaurar-viejo.json.gz", time() - 2 * 86400);
    touch("$tmp/restaurar-nuevo.json.gz");
    $r = $cli('respaldo-nocturno.php');
    contiene('Limpieza:', $r['salida']);
    verdadero(!is_file("$ses/sess_vieja_prueba") && is_file("$ses/sess_reciente_prueba"), 'sesiones');
    verdadero(!is_file("$tmp/restaurar-viejo.json.gz") && is_file("$tmp/restaurar-nuevo.json.gz"), 'temporales');
    @unlink("$ses/sess_reciente_prueba");
    @unlink("$tmp/restaurar-nuevo.json.gz");
});
prueba('copia externa cifrada con age (llave pública)', function () use ($CONFIG, $RAIZ) {
    $dir = sys_get_temp_dir() . '/ss-ext-' . bin2hex(random_bytes(3));
    mkdir($dir);
    exec('age-keygen -o ' . escapeshellarg("$dir/llave.txt") . ' 2>&1', $s);
    preg_match('/age1[0-9a-z]+/', file_get_contents("$dir/llave.txt"), $m);
    mkdir("$dir/destino");
    $tmp = "$dir/config.php";
    file_put_contents($tmp, '<?php $c = require ' . var_export($CONFIG, true) . "; \$c['respaldos']['externo'] = ['activo' => true, 'destino' => "
        . var_export("$dir/destino", true) . ", 'age_destinatario' => " . var_export($m[0], true) . ']; return $c;');
    exec('SS_CONFIG=' . escapeshellarg($tmp) . ' php ' . escapeshellarg("$RAIZ/bin/respaldo-nocturno.php") . ' --externo 2>&1', $out, $codigo);
    igual(0, $codigo, implode("\n", $out));
    $copias = glob("$dir/destino/*.age");
    igual(1, count($copias));
    exec('age -d -i ' . escapeshellarg("$dir/llave.txt") . ' ' . escapeshellarg($copias[0]) . ' | gzip -dc | tail -1', $fin);
    contiene('Dump completed', implode('', $fin), 'se descifra con la llave privada');
    exec('rm -rf ' . escapeshellarg($dir));
});
prueba('verificar-instalacion: sin fallas en desarrollo', function () use ($cli) {
    $r = $cli('verificar-instalacion.php');
    igual(0, $r['codigo'], $r['salida']);
    contiene('Triggers de protección activos', $r['salida']);
    contiene('ss_app no puede borrar historial', $r['salida']);
});
prueba('verificar-instalacion: en producción exige HTTPS, SMTP y permisos', function () use ($CONFIG, $RAIZ) {
    $tmp = tempnam(sys_get_temp_dir(), 'cfg');
    file_put_contents($tmp, '<?php $c = require ' . var_export($CONFIG, true) . "; \$c['app']['entorno'] = 'produccion'; return \$c;");
    exec('SS_CONFIG=' . escapeshellarg($tmp) . ' php ' . escapeshellarg("$RAIZ/bin/verificar-instalacion.php") . ' 2>&1', $s, $codigo);
    unlink($tmp);
    $salida = implode("\n", $s);
    igual(1, $codigo);
    contiene('✗ url_base con HTTPS', $salida);
    contiene('✗ Correo SMTP configurado', $salida);
});
prueba('restaurar.php --json: nada que agregar', function () use ($cli, $cfg, &$R) {
    $r = $cli('restaurar.php', '--json=' . escapeshellarg($cfg['respaldos']['ruta'] . '/' . $R['archivo']));
    igual(0, $r['codigo'], $r['salida']);
    contiene('No hay nada que agregar', $r['salida']);
});
prueba('restaurar.php --volcado: sin escribir RESTAURAR no cambia nada', function () use ($cli, $cfg, $PW_ADMIN) {
    $v = glob($cfg['respaldos']['volcado']['ruta'] . '/volcado-*.sql.gz');
    rsort($v);
    $r = $cli('restaurar.php', '--volcado=' . escapeshellarg($v[0]) . ' --confirmar=no', ['SS_ADMIN_PASSWORD' => $PW_ADMIN]);
    igual(1, $r['codigo']);
    contiene('Cancelado', $r['salida']);
    verdadero(!is_file($cfg['rutas']['mantenimiento']), 'sin mantenimiento');
});
prueba('restaurar.php --volcado: reemplaza toda la base y conserva sellos y permisos', function () use ($cli, $cfg, $PW_ADMIN, $root, $n, $CSRF, $M) {
    $v = glob($cfg['respaldos']['volcado']['ruta'] . '/volcado-*.sql.gz');
    rsort($v);
    // Algo capturado DESPUÉS del volcado debe desaparecer.
    $root->exec("UPDATE configuracion SET valor = '41' WHERE clave = 'umbral_proximo_horas'");
    $r = $cli('restaurar.php', '--volcado=' . escapeshellarg($v[0]) . ' --confirmar=RESTAURAR', ['SS_ADMIN_PASSWORD' => $PW_ADMIN]);
    igual(0, $r['codigo'], $r['salida']);
    contiene('Volcado previo creado y verificado', $r['salida']);
    igual('30', $root->query("SELECT valor FROM configuracion WHERE clave = 'umbral_proximo_horas'")->fetchColumn() === '41' ? 'no' : '30');
    verdadero(!is_file($cfg['rutas']['mantenimiento']), 'mantenimiento retirado');
    verdadero(count(glob($cfg['respaldos']['volcado']['ruta'] . '/previo-restauracion-*.sql.gz')) >= 1, 'volcado previo');
    // La aplicación sigue funcionando con ss_app (permisos por tabla intactos) y la cadena sigue íntegra.
    igual(200, $n->get('/')['estado']);
    $s = $cli('sellar-historial.php', '--verificar');
    igual(0, $s['codigo'], $s['salida']);
    $e = (int)$root->query("SELECT e.id FROM expedientes e JOIN alumnos a ON a.id = e.alumno_id WHERE a.matricula = '$M'")->fetchColumn();
    igual(200, $n->post("/expedientes/$e/firmas", ['_csrf' => $CSRF, 'semana' => (new DateTimeImmutable('monday this week'))->format('Y-m-d')], ['Accept: application/json'])['estado'], 'ss_app escribe');
    // Los triggers siguen activos después de importar.
    try {
        $root->exec('DELETE FROM historial WHERE id = 1');
        verdadero(false, 'el historial debió seguir protegido');
    } catch (PDOException $ex) {
        contiene('HISTORIAL_INMUTABLE', $ex->getMessage());
    }
});
prueba('restaurar.php con un volcado dañado no toca nada', function () use ($cli, $PW_ADMIN, $cfg) {
    $tmp = tempnam(sys_get_temp_dir(), 'vol') . '.sql.gz';
    file_put_contents($tmp, gzencode("-- incompleto\nCREATE TABLE x;"));
    $r = $cli('restaurar.php', '--volcado=' . escapeshellarg($tmp) . ' --confirmar=RESTAURAR', ['SS_ADMIN_PASSWORD' => $PW_ADMIN]);
    unlink($tmp);
    igual(1, $r['codigo']);
    contiene('incompleto', $r['salida']);
    verdadero(!is_file($cfg['rutas']['mantenimiento']), 'sin mantenimiento');
});

/** Sube un archivo al formulario de restauración (multipart). */
function subir(string $url, Navegador $n, string $csrf, string $ruta, ?string &$cuerpo = null): int
{
    $jar = (new ReflectionProperty(Navegador::class, 'jar'))->getValue($n);
    $ch = curl_init("$url/respaldos/restaurar");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_POSTFIELDS => ['_csrf' => $csrf, 'archivo' => new CURLFile($ruta, 'application/gzip', 'respaldo.json.gz')]]);
    $cuerpo = (string)curl_exec($ch);
    return (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
}

resumen();
