<?php
declare(strict_types=1);

/**
 * Pruebas de punta a punta de la Etapa 6: proyección del expediente,
 * tablero (orden y filtros) y configuración.
 *   E2E_URL=http://127.0.0.1:8099 php tests/e2e/proyeccion.php
 */

require __DIR__ . '/../lib.php';
require __DIR__ . '/comun.php';

date_default_timezone_set('America/Mexico_City');
$URL = getenv('E2E_URL') ?: 'http://127.0.0.1:8099';
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
$configOriginal = $root->query('SELECT clave, valor FROM configuracion')->fetchAll(PDO::FETCH_KEY_PAIR);

// Dos alumnos: uno a mitad del periodo con algunas horas y otro recién iniciado.
$hoy = new DateTimeImmutable('today');
$suf = substr((string)time(), -6);
$alta = function (string $mat, string $apellido, DateTimeImmutable $inicio) use ($n, $CSRF, $root): int {
    $n->post('/alumnos/nuevo', ['_csrf' => $CSRF, 'matricula' => $mat, 'nombre' => 'Proy', 'apellido_paterno' => $apellido,
        'carrera' => 'Ing.', 'institucion' => 'UTN', 'unidad_administrativa' => 'Unidad', 'responsable' => 'Resp',
        'fecha_inicio' => $inicio->format('Y-m-d'), 'jornada_prevista' => '4', 'feriados_acreditan' => '1', 'horas_por_feriado' => '4']);
    return (int)$root->query("SELECT e.id FROM expedientes e JOIN alumnos a ON a.id = e.alumno_id WHERE a.matricula = '$mat'")->fetchColumn();
};
$inicioA = $hoy->modify('-' . ((int)$hoy->format('N') - 1) . ' day')->modify('-8 week');
$EA = $alta("E2E6A-$suf", 'Aaaproy', $inicioA);
$EB = $alta("E2E6B-$suf", 'Bbbproy', $hoy->modify('+3 day'));
// 20 días hábiles de 4 h en las primeras 4 semanas del alumno A (directo en la base).
$ins = $root->prepare("INSERT INTO asistencias (expediente_id, fecha, estado, horas_trabajadas, horas_acreditadas, registrado_por) VALUES (?, ?, 'asistencia', 4, 4, ?)");
for ($d = $inicioA, $k = 0; $k < 20; $d = $d->modify('+1 day')) {
    if ((int)$d->format('N') <= 5) { $ins->execute([$EA, $d->format('Y-m-d'), $adminId]); $k++; }
}

seccion('Proyección del expediente');
prueba('muestra conclusión probable, ritmo y escenarios', function () use ($n, $EA) {
    $c = $n->get("/expedientes/$EA")['cuerpo'];
    contiene('Conclusión probable', $c);
    contiene('id="conclusion-probable"', $c);
    contiene('Ritmo real', $c);
    contiene('Si cambia la jornada', $c);
    igual(3, preg_match_all('#<td>\d h por día#', $c), 'tres jornadas');
    verdadero((bool)preg_match('#<tr class="actual">\s*<td>4 h por día#', $c), 'marca la jornada actual');
});
prueba('el ritmo se mide en las últimas 4 semanas completas', function () use ($n, $EA) {
    // Las horas están en las semanas 1–4; las 4 semanas anteriores a esta (5–8) no tienen.
    contiene('0.0 <small>h por semana</small>', $n->get("/expedientes/$EA")['cuerpo']);
});
prueba('avance por mes natural suma lo registrado', function () use ($n, $EA) {
    $c = $n->get("/expedientes/$EA")['cuerpo'];
    contiene('Avance por mes natural', $c);
    verdadero((bool)preg_match('#<tfoot><tr><th scope="row">Total</th><td class="num">20</td><td class="num">0</td><td class="num">0</td><td class="num">80 h</td>#', $c), 'total 20 asistencias, 80 h');
});
prueba('aún sin semanas completas no hay ritmo', fn() => contiene('Aún no hay semanas completas', $n->get("/expedientes/$EB")['cuerpo']));
prueba('suspendido: sin escenarios', function () use ($n, $EA, $CSRF, $root) {
    $v = (string)$root->query("SELECT version FROM expedientes WHERE id = $EA")->fetchColumn();
    $n->post("/expedientes/$EA/estado", ['_csrf' => $CSRF, 'accion' => 'suspender', 'motivo' => 'Prueba', 'version' => $v]);
    $c = $n->get("/expedientes/$EA")['cuerpo'];
    noContiene('Si cambia la jornada', $c);
    contiene('Sin proyección mientras el expediente no esté activo', $c);
    $v = (string)$root->query("SELECT version FROM expedientes WHERE id = $EA")->fetchColumn();
    $n->post("/expedientes/$EA/estado", ['_csrf' => $CSRF, 'accion' => 'reactivar', 'motivo' => 'Prueba', 'version' => $v]);
});

seccion('Tablero: conclusión, orden y filtros');
prueba('columna de conclusión probable', fn() => contiene('Conclusión probable', $n->get('/')['cuerpo']));
prueba('ordenar por conclusión: el que termina antes va primero', function () use ($n, $suf) {
    // A lleva 8 semanas y 80 h; B empieza en 3 días: A termina antes.
    $c = $n->get('/?orden=conclusion&q=' . urlencode('proy'))['cuerpo'];
    $a = strpos($c, "E2E6A-$suf");
    $b = strpos($c, "E2E6B-$suf");
    verdadero($a !== false && $b !== false, 'ambos');
    verdadero($a < $b, 'A antes que B');
    $c = $n->get('/?orden=avance&q=' . urlencode('proy'))['cuerpo'];
    verdadero(strpos($c, "E2E6A-$suf") < strpos($c, "E2E6B-$suf"), 'por avance: A (17 %) antes que B (0 %)');
    contiene('aria-current="true">Avance', $c);
});
prueba('ordenar por nombre (por omisión)', function () use ($n, $suf) {
    $c = $n->get('/?q=proy')['cuerpo'];
    verdadero(strpos($c, "E2E6A-$suf") < strpos($c, "E2E6B-$suf"), 'Aaa antes que Bbb');
});
prueba('un orden desconocido se trata como por nombre', fn() => igual(200, $n->get('/?orden=<script>')['estado']));
prueba('filtros nuevos responden', function () use ($n) {
    contiene('Listos para concluir', $n->get('/?filtro=listos')['cuerpo']);
    igual(200, $n->get('/?filtro=vencidos')['estado']);
});
prueba('el orden se conserva al buscar', fn() => contiene('name="orden" value="avance"', $n->get('/?orden=avance')['cuerpo']));

seccion('Configuración');
$enviar = fn(array $cambios) => $n->post('/configuracion', $cambios + ['_csrf' => $CSRF] + array_intersect_key($configOriginal, array_flip([
    'umbral_proximo_horas', 'inactividad_minutos', 'sesion_max_horas', 'respaldos_retener', 'intentos_max_correo', 'intentos_max_ip', 'intentos_ventana_minutos'])));
prueba('la página muestra las reglas pendientes sin poder editarlas', function () use ($n) {
    $c = $n->get('/configuracion')['cuerpo'];
    contiene('pendientes de autorización', $c);
    contiene('name="umbral_proximo_horas"', $c);
    noContiene('name="horas_requeridas"', $c);
    noContiene('name="meses_requeridos"', $c);
});
prueba('sin motivo: 422', fn() => igual(422, $enviar(['umbral_proximo_horas' => '30'])['estado']));
prueba('fuera de rango o no numérico: 422', function () use ($enviar) {
    $r = $enviar(['umbral_proximo_horas' => '0', 'intentos_max_ip' => 'abc', 'motivo' => 'x']);
    igual(422, $r['estado']);
    contiene('entre 1 y 480', $r['cuerpo']);
    contiene('entre 5 y 200', $r['cuerpo']);
});
prueba('la inactividad debe ser menor que la sesión', fn() => contiene('menor que la duración máxima', $enviar(['inactividad_minutos' => '60', 'sesion_max_horas' => '1', 'motivo' => 'x'])['cuerpo']));
prueba('guardar: cambia el valor y deja historial con motivo', function () use ($enviar, $root, $adminId, $n) {
    igual(303, $enviar(['umbral_proximo_horas' => '60', 'motivo' => 'Avisar con más anticipación'])['estado']);
    igual('60', $root->query("SELECT valor FROM configuracion WHERE clave = 'umbral_proximo_horas'")->fetchColumn());
    $h = $root->query("SELECT usuario_id, motivo FROM historial WHERE entidad = 'configuracion' ORDER BY id DESC LIMIT 1")->fetch();
    igual([$adminId, 'Avisar con más anticipación'], [(int)$h['usuario_id'], $h['motivo']]);
    contiene('≤ 60 h restantes', $n->get('/')['cuerpo'], 'el tablero usa el nuevo umbral');
});
prueba('las reglas pendientes no cambian aunque se envíen', function () use ($enviar, $root) {
    $enviar(['horas_requeridas' => '100', 'meses_requeridos' => '1', 'motivo' => 'intento']);
    igual('480', $root->query("SELECT valor FROM configuracion WHERE clave = 'horas_requeridas'")->fetchColumn());
    igual('6', $root->query("SELECT valor FROM configuracion WHERE clave = 'meses_requeridos'")->fetchColumn());
});
prueba('sin cambios no escribe historial', function () use ($enviar, $root) {
    $antes = (int)$root->query("SELECT COUNT(*) FROM historial WHERE entidad = 'configuracion'")->fetchColumn();
    $enviar(['umbral_proximo_horas' => (string)$root->query("SELECT valor FROM configuracion WHERE clave = 'umbral_proximo_horas'")->fetchColumn(), 'motivo' => 'igual']);
    igual($antes, (int)$root->query("SELECT COUNT(*) FROM historial WHERE entidad = 'configuracion'")->fetchColumn());
});
// Deja la configuración como estaba.
$enviar(['motivo' => 'Restaurar valores después de las pruebas']);

resumen();
