<?php
declare(strict_types=1);

/**
 * Pruebas de punta a punta de la Etapa 5: calendario de asistencia,
 * correcciones, autorizaciones, firmas, feriados, datos, estados,
 * nuevo periodo y catálogo de días inhábiles.
 *   E2E_URL=http://127.0.0.1:8099 php tests/e2e/expediente.php
 * Requiere el administrador de prueba y acceso root local a MariaDB.
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
const JSON = ['Accept: application/json'];

// Fechas relativas a hoy: el expediente empieza el lunes de hace 5 semanas.
$hoy = new DateTimeImmutable('today');
$lunesHoy = $hoy->modify('-' . ((int)$hoy->format('N') - 1) . ' day');
$inicio = $lunesHoy->modify('-5 week');
/** n-ésimo día hábil desde el inicio (0 = lunes de la semana 1); todos ya pasaron. */
$habil = fn(int $i) => $inicio->modify('+' . (intdiv($i, 5) * 7 + $i % 5) . ' day')->format('Y-m-d');
$sabado = $inicio->modify('+5 day')->format('Y-m-d');
$antesInicio = $inicio->modify('-3 day')->format('Y-m-d'); // viernes anterior
$manana = $hoy->modify('+1 day')->format('Y-m-d');

// Alumno y expediente de prueba.
$M = 'E2E5-' . substr((string)time(), -6);
$n->post('/alumnos/nuevo', [
    '_csrf' => $CSRF, 'matricula' => $M, 'nombre' => 'Valeria', 'apellido_paterno' => 'Etapa', 'apellido_materno' => 'Cinco',
    'carrera' => 'Ing. de prueba', 'institucion' => 'UTN', 'unidad_administrativa' => 'Unidad de prueba',
    'responsable' => 'Responsable de prueba', 'hora_entrada' => '16:00', 'hora_salida' => '20:00',
    'fecha_inicio' => $inicio->format('Y-m-d'), 'jornada_prevista' => '4', 'feriados_acreditan' => '1', 'horas_por_feriado' => '4',
]);
$alumnoId = (int)$root->query("SELECT id FROM alumnos WHERE matricula = '$M'")->fetchColumn();
$E = (int)$root->query("SELECT id FROM expedientes WHERE alumno_id = $alumnoId")->fetchColumn();
if (!$E) { fwrite(STDERR, "No se pudo crear el expediente de prueba\n"); exit(1); }

$dia = fn(string $f) => $root->query("SELECT * FROM asistencias WHERE expediente_id = $E AND fecha = '$f'")->fetch() ?: null;
$guardar = fn(array $d, array $cab = []) => $n->post("/expedientes/$E/dia", $d + ['_csrf' => $CSRF], $cab);
$ultimoHist = fn(string $entidad) => $root->query("SELECT * FROM historial WHERE expediente_id = $E AND entidad = '$entidad' ORDER BY id DESC LIMIT 1")->fetch();
$exp = fn() => $root->query("SELECT * FROM expedientes WHERE id = $E")->fetch();

seccion('Página del expediente');
prueba('datos de la bitácora arriba, antes del calendario', function () use ($n, $E) {
    $c = $n->get("/expedientes/$E")['cuerpo'];
    $datos = strpos($c, 'Datos de la bitácora y ajustes');
    $cal = strpos($c, 'Calendario de asistencia');
    verdadero($datos !== false && $cal !== false && $datos < $cal, 'orden');
    contiene('id="dialogo-dia"', $c);
    contiene('Historial del expediente', $c);
});
prueba('las hojas son desplegables y solo una viene abierta', function () use ($n, $E) {
    $c = $n->get("/expedientes/$E")['cuerpo'];
    verdadero(substr_count($c, '<details class="hoja"') >= 1, 'hojas');
    igual(1, preg_match_all('#<details class="hoja" id="hoja-\d+" open>#', $c), 'una abierta');
});
prueba('?semana= abre la hoja de esa semana', function () use ($n, $E, $inicio) {
    $semana = $inicio->modify('+13 week')->format('Y-m-d');
    $c = $n->get("/expedientes/$E?semana=$semana")['cuerpo'];
    verdadero((bool)preg_match('#<details class="hoja" id="hoja-2" open>#', $c), 'hoja 2 abierta');
});
prueba('la zona parcial sale sin el layout', function () use ($n, $E) {
    $c = $n->get("/expedientes/$E?parcial=zona")['cuerpo'];
    contiene('Calendario de asistencia', $c);
    noContiene('<html', $c);
});
prueba('navegación de regreso al tablero', fn() => contiene('<a href="/">Expedientes</a>', $n->get("/expedientes/$E")['cuerpo']));
prueba('formulario del día: página completa y fragmento', function () use ($n, $E, $habil) {
    contiene('¿Qué pasó este día?', $n->get("/expedientes/$E/dia?fecha=" . $habil(0))['cuerpo']);
    $f = $n->get("/expedientes/$E/dia?fecha=" . $habil(0) . '&parcial=1')['cuerpo'];
    contiene('js-form-dia', $f);
    noContiene('<html', $f);
});
prueba('fecha inválida en el formulario: 404', function () use ($n, $E) {
    igual(404, $n->get("/expedientes/$E/dia?fecha=2026-02-30")['estado']);
    igual(404, $n->get("/expedientes/$E/dia?fecha=<script>")['estado']);
});

seccion('Captura de un día');
prueba('sin token CSRF se rechaza', fn() => igual(403, $n->post("/expedientes/$E/dia", ['fecha' => $habil(0), 'estado' => 'asistencia'])['estado']));
prueba('asistencia de 4 h: se guarda, historial con usuario y vuelve a la semana', function () use ($guardar, $habil, $dia, $ultimoHist, $adminId) {
    $r = $guardar(['fecha' => $habil(0), 'estado' => 'asistencia', 'horas_trabajadas' => '4']);
    igual(303, $r['estado']);
    contiene('?semana=', $r['cabeceras']['location'] ?? '');
    contiene('#semana-', $r['cabeceras']['location'] ?? '');
    $d = $dia($habil(0));
    igual(['asistencia', 4, 4], [$d['estado'], (int)$d['horas_trabajadas'], (int)$d['horas_acreditadas']]);
    $h = $ultimoHist('asistencias');
    igual('crear', $h['accion']);
    igual($adminId, (int)$h['usuario_id']);
});
prueba('el calendario muestra el día registrado', fn() => verdadero((bool)preg_match('#data-s="a" href="/expedientes/\d+/dia\?fecha=' . $habil(0) . '"#', $n->get("/expedientes/$E")['cuerpo']), 'marcado'));
prueba('no se duplica: otra captura del mismo día sin versión es conflicto', function () use ($guardar, $habil, $root, $E) {
    $r = $guardar(['fecha' => $habil(0), 'estado' => 'falta'], JSON);
    igual(409, $r['estado']);
    contiene('cambió mientras lo editabas', $r['cuerpo']);
    igual(1, (int)$root->query("SELECT COUNT(*) FROM asistencias WHERE expediente_id = $E AND fecha = '" . $habil(0) . "'")->fetchColumn());
});
prueba('corregir sin motivo: 422', function () use ($guardar, $habil, $dia) {
    $r = $guardar(['fecha' => $habil(0), 'estado' => 'asistencia', 'horas_trabajadas' => '5', 'version' => (string)$dia($habil(0))['version']]);
    igual(422, $r['estado']);
    contiene('motivo de la corrección', $r['cuerpo']);
});
prueba('corregir con motivo: guarda, sube la versión y deja antes/después', function () use ($guardar, $habil, $dia, $ultimoHist) {
    $r = $guardar(['fecha' => $habil(0), 'estado' => 'asistencia', 'horas_trabajadas' => '5', 'version' => '1', 'motivo' => 'Se quedó una hora más']);
    igual(303, $r['estado']);
    $d = $dia($habil(0));
    igual([5, 5, 2], [(int)$d['horas_trabajadas'], (int)$d['horas_acreditadas'], (int)$d['version']]);
    $h = $ultimoHist('asistencias');
    igual(['actualizar', 'Se quedó una hora más'], [$h['accion'], $h['motivo']]);
    igual(4, json_decode($h['antes'], true)['horas_acreditadas'], 'antes');
});
prueba('versión vieja (otra pestaña): 409 y no pisa', function () use ($guardar, $habil, $dia) {
    $r = $guardar(['fecha' => $habil(0), 'estado' => 'falta', 'version' => '1', 'motivo' => 'x']);
    igual(409, $r['estado']);
    igual('asistencia', $dia($habil(0))['estado']);
});
prueba('horas fuera de 4, 5 o 6: 422', fn() => contiene('debe ser 4, 5, 6', $guardar(['fecha' => $habil(1), 'estado' => 'asistencia', 'horas_trabajadas' => '8'])['cuerpo']));
prueba('acreditar distinto de lo trabajado exige justificación', function () use ($guardar, $habil, $dia) {
    $r = $guardar(['fecha' => $habil(1), 'estado' => 'asistencia', 'horas_trabajadas' => '6', 'horas_acreditadas' => '4']);
    igual(422, $r['estado']);
    contiene('escribe la justificación', $r['cuerpo']);
    igual(303, $guardar(['fecha' => $habil(1), 'estado' => 'asistencia', 'horas_trabajadas' => '6', 'horas_acreditadas' => '4', 'justificacion' => 'Dos horas fuera del área'])['estado']);
    igual([6, 4], [(int)$dia($habil(1))['horas_trabajadas'], (int)$dia($habil(1))['horas_acreditadas']]);
});
prueba('el horario debe coincidir con las horas', function () use ($guardar, $habil, $dia) {
    $r = $guardar(['fecha' => $habil(2), 'estado' => 'asistencia', 'horas_trabajadas' => '4', 'hora_entrada' => '16:00', 'hora_salida' => '19:00']);
    igual(422, $r['estado']);
    contiene('no son 4 h', $r['cuerpo']);
    contiene('value="19:00"', $r['cuerpo'], 'conserva lo escrito');
    igual(303, $guardar(['fecha' => $habil(2), 'estado' => 'asistencia', 'horas_trabajadas' => '4', 'hora_entrada' => '16:00', 'hora_salida' => '20:00'])['estado']);
    igual('16:00:00', $dia($habil(2))['hora_entrada']);
});
prueba('falta: 0 h y observaciones escapadas', function () use ($guardar, $habil, $dia, $n, $E) {
    igual(303, $guardar(['fecha' => $habil(3), 'estado' => 'falta', 'observaciones' => '<script>alert(1)</script>'])['estado']);
    igual([0, 0], [(int)$dia($habil(3))['horas_trabajadas'], (int)$dia($habil(3))['horas_acreditadas']]);
    $c = $n->get("/expedientes/$E/dia?fecha=" . $habil(3))['cuerpo'];
    noContiene('<script>alert(1)', $c);
    contiene('&lt;script&gt;', $c);
});
prueba('un día que no ha llegado no se registra', function () use ($guardar, $manana, $dia) {
    $r = $guardar(['fecha' => $manana, 'estado' => 'asistencia', 'horas_trabajadas' => '4']);
    igual(422, $r['estado']);
    contiene('todavía no llega', $r['cuerpo']);
    igual(null, $dia($manana));
});
prueba('fin de semana: pide autorización con motivo', function () use ($guardar, $sabado, $dia, $adminId) {
    $r = $guardar(['fecha' => $sabado, 'estado' => 'asistencia', 'horas_trabajadas' => '4']);
    igual(422, $r['estado']);
    contiene('es fin de semana', $r['cuerpo']);
    igual(422, $guardar(['fecha' => $sabado, 'estado' => 'asistencia', 'horas_trabajadas' => '4', 'autorizar' => '1'])['estado'], 'sin motivo');
    igual(303, $guardar(['fecha' => $sabado, 'estado' => 'asistencia', 'horas_trabajadas' => '4', 'autorizar' => '1', 'motivo_autorizacion' => 'Evento de la unidad'])['estado']);
    $d = $dia($sabado);
    igual([$adminId, 'Evento de la unidad'], [(int)$d['autorizado_por'], $d['motivo_autorizacion']]);
});
prueba('antes del periodo autorizado: pide autorización', function () use ($guardar, $antesInicio, $dia) {
    $r = $guardar(['fecha' => $antesInicio, 'estado' => 'asistencia', 'horas_trabajadas' => '4']);
    igual(422, $r['estado']);
    contiene('fuera del periodo autorizado', $r['cuerpo']);
    igual(null, $dia($antesInicio));
});
prueba('feriado fuera del catálogo: horas solo con justificación', function () use ($guardar, $habil, $dia) {
    $r = $guardar(['fecha' => $habil(4), 'estado' => 'feriado', 'horas_acreditadas' => '4']);
    igual(422, $r['estado']);
    contiene('no está en el catálogo', $r['cuerpo']);
    igual(303, $guardar(['fecha' => $habil(4), 'estado' => 'feriado'])['estado']);
    igual(0, (int)$dia($habil(4))['horas_acreditadas'], 'automático = 0');
});
prueba('estado desconocido: 422', fn() => contiene('Elige qué pasó', $guardar(['fecha' => $habil(5), 'estado' => 'vacaciones'])['cuerpo']));
prueba('dejar pendiente quita el registro (con motivo) y queda en el historial', function () use ($guardar, $habil, $dia, $ultimoHist) {
    $v = (string)$dia($habil(3))['version'];
    igual(422, $guardar(['fecha' => $habil(3), 'estado' => 'pendiente', 'version' => $v])['estado'], 'sin motivo');
    igual(303, $guardar(['fecha' => $habil(3), 'estado' => 'pendiente', 'version' => $v, 'motivo' => 'Se capturó por error'])['estado']);
    igual(null, $dia($habil(3)));
    $h = $ultimoHist('asistencias');
    igual(['quitar', 'Se capturó por error'], [$h['accion'], $h['motivo']]);
});

seccion('Respuestas para el diálogo (JSON)');
prueba('éxito: JSON con aviso', function () use ($guardar, $habil) {
    $r = $guardar(['fecha' => $habil(5), 'estado' => 'asistencia', 'horas_trabajadas' => '4'], JSON);
    igual(200, $r['estado']);
    igual(true, json_decode($r['cuerpo'], true)['ok'] ?? null);
});
prueba('error: 422 con el formulario para volver a mostrarlo', function () use ($guardar, $habil) {
    $r = $guardar(['fecha' => $habil(6), 'estado' => 'asistencia', 'horas_trabajadas' => '9'], JSON);
    igual(422, $r['estado']);
    $j = json_decode($r['cuerpo'], true);
    igual(false, $j['ok']);
    contiene('js-form-dia', $j['html']);
    contiene('aria-invalid="true"', $j['html']);
});

seccion('Firmas semanales');
prueba('firmar y quitar la firma de una semana', function () use ($n, $E, $CSRF, $inicio, $root) {
    $lunes = $inicio->format('Y-m-d');
    igual(303, $n->post("/expedientes/$E/firmas", ['_csrf' => $CSRF, 'semana' => $lunes])['estado']);
    igual(1, (int)$root->query("SELECT COUNT(*) FROM firmas_semana WHERE expediente_id = $E AND semana_inicio = '$lunes'")->fetchColumn());
    contiene('Firmada ✓', $n->get("/expedientes/$E")['cuerpo']);
    igual(200, $n->post("/expedientes/$E/firmas", ['_csrf' => $CSRF, 'semana' => $lunes], JSON)['estado']);
    igual(0, (int)$root->query("SELECT COUNT(*) FROM firmas_semana WHERE expediente_id = $E AND semana_inicio = '$lunes'")->fetchColumn());
});
prueba('no se firma una semana futura ni un día que no es lunes', function () use ($n, $E, $CSRF, $lunesHoy, $inicio) {
    igual(422, $n->post("/expedientes/$E/firmas", ['_csrf' => $CSRF, 'semana' => $lunesHoy->modify('+1 week')->format('Y-m-d')], JSON)['estado']);
    igual(422, $n->post("/expedientes/$E/firmas", ['_csrf' => $CSRF, 'semana' => $inicio->modify('+1 day')->format('Y-m-d')], JSON)['estado']);
});

seccion('Catálogo de días inhábiles');
// Un día hábil del periodo, ya pasado, sin registro y libre en el catálogo.
$libre = null;
for ($i = 10; $i < 25 && $libre === null; $i++) {
    if ($dia($habil($i)) === null && !$root->query("SELECT 1 FROM dias_inhabiles WHERE fecha = '" . $habil($i) . "'")->fetchColumn()) {
        $libre = $habil($i);
    }
}
$cierre = null;
for ($i = 24; $i > 10 && $cierre === null; $i--) {
    if ($habil($i) !== $libre && $dia($habil($i)) === null && !$root->query("SELECT 1 FROM dias_inhabiles WHERE fecha = '" . $habil($i) . "'")->fetchColumn()) {
        $cierre = $habil($i);
    }
}
prueba('agregar: validaciones', function () use ($n, $CSRF, $libre) {
    $r = $n->post('/dias-inhabiles', ['_csrf' => $CSRF, 'fecha' => $libre, 'tipo' => 'otro', 'descripcion' => '']);
    igual(422, $r['estado']);
    contiene('Elige si es festivo o cierre', $r['cuerpo']);
    contiene('Escribe la descripción', $r['cuerpo']);
});
prueba('agregar un festivo y un cierre', function () use ($n, $CSRF, $libre, $cierre, $root) {
    igual(303, $n->post('/dias-inhabiles', ['_csrf' => $CSRF, 'fecha' => $libre, 'tipo' => 'festivo', 'descripcion' => 'E2E festivo'])['estado']);
    igual(303, $n->post('/dias-inhabiles', ['_csrf' => $CSRF, 'fecha' => $cierre, 'tipo' => 'cierre', 'descripcion' => 'E2E cierre'])['estado']);
    contiene('E2E festivo', $n->get('/dias-inhabiles')['cuerpo']);
});
prueba('fecha repetida en el catálogo', fn() => contiene('ya está en el catálogo', $n->post('/dias-inhabiles', ['_csrf' => $CSRF, 'fecha' => $libre, 'tipo' => 'festivo', 'descripcion' => 'Otra'])['cuerpo']));
prueba('el expediente avisa del festivo sin registrar y lo confirma', function () use ($n, $E, $CSRF, $libre, $dia) {
    contiene('festivo(s) del catálogo sin registrar', $n->get("/expedientes/$E")['cuerpo']);
    igual(303, $n->post("/expedientes/$E/feriados", ['_csrf' => $CSRF])['estado']);
    $d = $dia($libre);
    igual(['feriado', 4], [$d['estado'], (int)$d['horas_acreditadas']]);
    verdadero($d['dia_inhabil_id'] !== null, 'ligado al catálogo');
    noContiene('festivo(s) del catálogo sin registrar', $n->get("/expedientes/$E")['cuerpo']);
});
prueba('un día del catálogo en uso no se puede quitar', function () use ($n, $CSRF, $libre, $root) {
    $id = (int)$root->query("SELECT id FROM dias_inhabiles WHERE fecha = '$libre'")->fetchColumn();
    igual(303, $n->post("/dias-inhabiles/$id/quitar", ['_csrf' => $CSRF])['estado']);
    contiene('ya se usó', $n->get('/dias-inhabiles')['cuerpo']);
    igual(1, (int)$root->query("SELECT COUNT(*) FROM dias_inhabiles WHERE id = $id")->fetchColumn());
});
prueba('día de cierre: pide autorización', function () use ($guardar, $cierre) {
    $r = $guardar(['fecha' => $cierre, 'estado' => 'asistencia', 'horas_trabajadas' => '4']);
    igual(422, $r['estado']);
    contiene('día de cierre del catálogo', $r['cuerpo']);
});
prueba('quitar un día del catálogo sin usar', function () use ($n, $CSRF, $cierre, $root) {
    $id = (int)$root->query("SELECT id FROM dias_inhabiles WHERE fecha = '$cierre'")->fetchColumn();
    $n->post("/dias-inhabiles/$id/quitar", ['_csrf' => $CSRF]);
    igual(0, (int)$root->query("SELECT COUNT(*) FROM dias_inhabiles WHERE id = $id")->fetchColumn());
});

seccion('Datos y ajustes del expediente');
$datosExp = fn(array $cambios = []) => $cambios + [
    '_csrf' => $CSRF, 'version' => (string)$exp()['version'], 'unidad_administrativa' => 'Unidad de prueba', 'responsable' => 'Responsable de prueba',
    'hora_entrada' => '16:00', 'hora_salida' => '20:00', 'fecha_inicio' => $inicio->format('Y-m-d'),
    'fecha_fin_autorizada' => $exp()['fecha_fin_autorizada'], 'jornada_prevista' => '4', 'feriados_acreditan' => '1', 'horas_por_feriado' => '4',
];
prueba('cambiar datos exige motivo', function () use ($n, $E, $datosExp) {
    $r = $n->post("/expedientes/$E/datos", $datosExp(['jornada_prevista' => '5']));
    igual(422, $r['estado']);
    contiene('Escribe el motivo del cambio', $r['cuerpo']);
    contiene('<details class="editar-datos" open', $r['cuerpo'], 'formulario abierto');
});
prueba('cambiar la jornada con motivo', function () use ($n, $E, $datosExp, $exp, $root) {
    igual(303, $n->post("/expedientes/$E/datos", $datosExp(['jornada_prevista' => '5', 'motivo' => 'Cambio de horario']))['estado']);
    igual(5, (int)$exp()['jornada_prevista']);
    igual('Cambio de horario', $root->query("SELECT motivo FROM historial WHERE entidad = 'expedientes' AND entidad_id = $E ORDER BY id DESC LIMIT 1")->fetchColumn());
});
prueba('versión vieja del expediente: 409', function () use ($n, $E, $datosExp) {
    igual(409, $n->post("/expedientes/$E/datos", $datosExp(['version' => '1', 'jornada_prevista' => '6', 'motivo' => 'x']))['estado']);
});
prueba('mover el inicio deja registros fuera: pide autorizarlos', function () use ($n, $E, $datosExp, $habil, $dia, $adminId) {
    $nuevoInicio = $habil(1);
    $r = $n->post("/expedientes/$E/datos", $datosExp(['fecha_inicio' => $nuevoInicio, 'motivo' => 'Inicio corregido']));
    igual(422, $r['estado']);
    contiene('quedarían fuera del periodo', $r['cuerpo']);
    igual(303, $n->post("/expedientes/$E/datos", $datosExp(['fecha_inicio' => $nuevoInicio, 'motivo' => 'Inicio corregido', 'autorizar_fuera' => '1']))['estado']);
    igual($adminId, (int)$dia($habil(0))['autorizado_por'], 'registro autorizado');
});
prueba('la regla de feriados no cambia los ya registrados si no se pide', function () use ($n, $E, $datosExp, $libre, $dia, $habil) {
    $base = ['fecha_inicio' => $habil(1), 'motivo' => 'Ajuste de la regla de feriados'];
    igual(303, $n->post("/expedientes/$E/datos", $datosExp(['horas_por_feriado' => '3'] + $base))['estado']);
    igual(4, (int)$dia($libre)['horas_acreditadas'], 'sin aplicar');
    igual(303, $n->post("/expedientes/$E/datos", $datosExp(['horas_por_feriado' => '2', 'aplicar_feriados' => '1'] + $base))['estado']);
    igual(2, (int)$dia($libre)['horas_acreditadas'], 'aplicada');
});

seccion('Estados del expediente');
$estado = fn(string $accion, string $motivo = '', array $cab = []) => $n->post("/expedientes/$E/estado",
    ['_csrf' => $CSRF, 'accion' => $accion, 'motivo' => $motivo, 'version' => (string)$exp()['version']], $cab);
prueba('suspender exige motivo', fn() => igual(422, $estado('suspender', '', JSON)['estado']));
prueba('suspendido: no se capturan días', function () use ($estado, $exp, $guardar, $habil) {
    igual(303, $estado('suspender', 'Incapacidad médica')['estado']);
    igual('suspendido', $exp()['estado']);
    contiene('suspendido', $guardar(['fecha' => $habil(7), 'estado' => 'asistencia', 'horas_trabajadas' => '4'])['cuerpo']);
});
prueba('reactivar', function () use ($estado, $exp) {
    igual(303, $estado('reactivar', 'Regresa')['estado']);
    igual('activo', $exp()['estado']);
});
prueba('no se concluye sin horas ni periodo', function () use ($estado, $exp) {
    $r = $estado('concluir', '', JSON);
    igual(422, $r['estado']);
    contiene('Todavía no se puede concluir', $r['cuerpo']);
    igual('activo', $exp()['estado']);
});
prueba('acción desconocida o versión vieja', function () use ($n, $E, $CSRF) {
    igual(422, $n->post("/expedientes/$E/estado", ['_csrf' => $CSRF, 'accion' => 'borrar', 'version' => '1'], JSON)['estado']);
    igual(409, $n->post("/expedientes/$E/estado", ['_csrf' => $CSRF, 'accion' => 'suspender', 'motivo' => 'x', 'version' => '1'], JSON)['estado']);
});
prueba('baja: el calendario queda de solo consulta', function () use ($estado, $exp, $guardar, $habil, $n, $E) {
    igual(303, $estado('baja', 'Dejó la unidad')['estado']);
    igual('baja', $exp()['estado']);
    igual(422, $guardar(['fecha' => $habil(8), 'estado' => 'asistencia', 'horas_trabajadas' => '4'])['estado']);
    $c = $n->get("/expedientes/$E")['cuerpo'];
    contiene('solo consulta', $c);
    noContiene('href="/expedientes/' . $E . '/dia?', $c);
});

seccion('Nuevo periodo');
$E2 = 0;
prueba('con el expediente de baja se puede abrir otro periodo', function () use ($n, $alumnoId, $CSRF, $root, &$E2, $hoy) {
    $f = $n->get("/alumnos/$alumnoId/expedientes/nuevo")['cuerpo'];
    contiene('value="Unidad de prueba"', $f, 'copia la unidad');
    $r = $n->post("/alumnos/$alumnoId/expedientes/nuevo", ['_csrf' => $CSRF, 'unidad_administrativa' => 'Otra unidad',
        'responsable' => 'Otro responsable', 'fecha_inicio' => $hoy->format('Y-m-d'), 'jornada_prevista' => '4', 'feriados_acreditan' => '1', 'horas_por_feriado' => '4']);
    igual(303, $r['estado']);
    verdadero((bool)preg_match('#^/expedientes/(\d+)$#', $r['cabeceras']['location'] ?? '', $m), 'redirige');
    $E2 = (int)$m[1];
    igual('Apertura de un nuevo periodo', $root->query("SELECT motivo FROM historial WHERE entidad = 'expedientes' AND entidad_id = $E2 AND accion = 'crear'")->fetchColumn());
});
prueba('con uno abierto no se abre otro', function () use ($n, $alumnoId, $CSRF, $hoy) {
    contiene('ya tiene un expediente abierto', $n->get("/alumnos/$alumnoId/expedientes/nuevo")['cuerpo']);
    $r = $n->post("/alumnos/$alumnoId/expedientes/nuevo", ['_csrf' => $CSRF, 'unidad_administrativa' => 'X', 'responsable' => 'Y',
        'fecha_inicio' => $hoy->modify('+1 day')->format('Y-m-d')]);
    igual(422, $r['estado']);
});
prueba('reabrir el anterior se impide mientras haya otro abierto', function () use ($estado, $exp) {
    $r = $estado('reabrir', 'Se dio de baja por error', JSON);
    igual(422, $r['estado']);
    contiene('otro expediente abierto', $r['cuerpo']);
    igual('baja', $exp()['estado']);
});
prueba('la página muestra los dos periodos', function () use ($n, $E, $E2) {
    $c = $n->get("/expedientes/$E")['cuerpo'];
    contiene('href="/expedientes/' . $E2 . '"', $c);
});

seccion('Concluir y reabrir');
// Expediente que ya cumplió periodo y horas (insertado directo en la base).
$alumno3 = 'E2E5C-' . substr((string)time(), -6);
$root->prepare("INSERT INTO alumnos (matricula, nombre, apellido_paterno, carrera, institucion) VALUES (?, 'Concluye', 'Prueba', 'Ing.', 'UTN')")->execute([$alumno3]);
$a3 = (int)$root->lastInsertId();
$ini3 = $hoy->modify('-8 month');
$root->prepare("INSERT INTO expedientes (alumno_id, unidad_administrativa, responsable, fecha_inicio, fecha_fin_autorizada, creado_por)
                VALUES (?, 'Unidad', 'Resp', ?, ?, ?)")->execute([$a3, $ini3->format('Y-m-d'), $hoy->modify('-1 day')->format('Y-m-d'), $adminId]);
$E3 = (int)$root->lastInsertId();
$ins = $root->prepare("INSERT INTO asistencias (expediente_id, fecha, estado, horas_trabajadas, horas_acreditadas, registrado_por) VALUES (?, ?, 'asistencia', 4, 4, ?)");
for ($d = $ini3, $k = 0; $k < 120; $d = $d->modify('+1 day')) {
    if ((int)$d->format('N') <= 5) { $ins->execute([$E3, $d->format('Y-m-d'), $adminId]); $k++; }
}
$version3 = fn() => (string)$root->query("SELECT version FROM expedientes WHERE id = $E3")->fetchColumn();
prueba('listo para concluir: muestra el botón', fn() => contiene('Concluir servicio', $n->get("/expedientes/$E3")['cuerpo']));
prueba('concluir guarda fecha y resumen', function () use ($n, $E3, $CSRF, $version3, $root, $hoy) {
    igual(303, $n->post("/expedientes/$E3/estado", ['_csrf' => $CSRF, 'accion' => 'concluir', 'version' => $version3()])['estado']);
    $x = $root->query("SELECT * FROM expedientes WHERE id = $E3")->fetch();
    igual(['concluido', $hoy->format('Y-m-d')], [$x['estado'], $x['fecha_conclusion']]);
    igual(480, json_decode($x['resumen_conclusion'], true)['horas_acreditadas']);
});
prueba('concluido: no se captura ni se editan datos', function () use ($n, $E3, $CSRF, $root) {
    $f = $root->query("SELECT MAX(fecha) FROM asistencias WHERE expediente_id = $E3")->fetchColumn();
    igual(422, $n->post("/expedientes/$E3/dia", ['_csrf' => $CSRF, 'fecha' => $f, 'estado' => 'falta', 'version' => '1', 'motivo' => 'x'])['estado']);
    contiene('reábrelo', $n->post("/expedientes/$E3/datos", ['_csrf' => $CSRF, 'motivo' => 'x'])['cuerpo']);
});
prueba('reabrir exige motivo y queda en el historial', function () use ($n, $E3, $CSRF, $version3, $root) {
    igual(422, $n->post("/expedientes/$E3/estado", ['_csrf' => $CSRF, 'accion' => 'reabrir', 'version' => $version3()], JSON)['estado']);
    igual(303, $n->post("/expedientes/$E3/estado", ['_csrf' => $CSRF, 'accion' => 'reabrir', 'motivo' => 'Falta una firma', 'version' => $version3()])['estado']);
    $h = $root->query("SELECT accion, motivo FROM historial WHERE entidad = 'expedientes' AND entidad_id = $E3 ORDER BY id DESC LIMIT 1")->fetch();
    igual(['reabrir', 'Falta una firma'], [$h['accion'], $h['motivo']]);
});
prueba('el historial se ve en la página', function () use ($n, $E3) {
    $c = $n->get("/expedientes/$E3")['cuerpo'];
    contiene('Reabrió el expediente', $c);
    contiene('Falta una firma', $c);
});

resumen();
