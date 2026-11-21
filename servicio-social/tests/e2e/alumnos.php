<?php
declare(strict_types=1);

/**
 * Pruebas de punta a punta de la Etapa 4: tablero, alta y edición de alumnos.
 *   E2E_URL=http://127.0.0.1:8099 php tests/e2e/alumnos.php
 * Requiere el administrador de prueba y acceso root local a MariaDB.
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
$adminId = (int)$root->query("SELECT id FROM usuarios WHERE correo = '$ADMIN'")->fetchColumn();

$n = new Navegador($URL);
entrar($n, $ADMIN, $PW);

function alta(Navegador $n, array $campos): array
{
    $csrf = $n->csrf('/alumnos/nuevo');
    return $n->post('/alumnos/nuevo', $campos + ['_csrf' => $csrf]);
}

function alumnoValido(string $matricula, array $cambios = []): array
{
    return $cambios + [
        'matricula' => $matricula, 'nombre' => 'Ana Sofía', 'apellido_paterno' => 'Núñez', 'apellido_materno' => 'Prueba',
        'carrera' => 'Ing. de prueba', 'institucion' => 'Universidad Tecnológica de Nezahualcóyotl',
        'correo' => 'ana.prueba@ejemplo.test', 'telefono' => '55 1234 5678',
        'unidad_administrativa' => 'Unidad de prueba', 'responsable' => 'Responsable de prueba',
        'hora_entrada' => '16:00', 'hora_salida' => '20:00', 'fecha_inicio' => '2026-08-31', 'fecha_fin_autorizada' => '',
        'jornada_prevista' => '4', 'feriados_acreditan' => '1', 'horas_por_feriado' => '4', 'observaciones' => '',
    ];
}

$sufijo = substr((string)time(), -5);
$M1 = "E2E-$sufijo-A";
$M2 = "E2E-$sufijo-B";
$alumnosAntes = (int)$root->query('SELECT COUNT(*) FROM alumnos WHERE archivado = 0')->fetchColumn();

seccion('Tablero');
prueba('el tablero carga con estadísticas y buscador', function () use ($n) {
    $r = $n->get('/');
    igual(200, $r['estado']);
    contiene('Alumnos registrados', $r['cuerpo']);
    contiene('Próximos a concluir', $r['cuerpo']);
    contiene('name="q"', $r['cuerpo']);
});

seccion('Alta de alumno');
prueba('el formulario trae los valores iniciales', function () use ($n, $root, $adminId) {
    $c = $n->get('/alumnos/nuevo')['cuerpo'];
    contiene('value="Universidad Tecnológica de Nezahualcóyotl"', $c, 'institución');
    $nombre = (string)$root->query("SELECT nombre FROM usuarios WHERE id = $adminId")->fetchColumn();
    contiene('value="' . htmlspecialchars($nombre) . '"', $c, 'responsable = usuario');
    verdadero((bool)preg_match('/id="feriados_acreditan"[^>]*checked/', $c), 'feriados marcados');
});
prueba('sin token CSRF se rechaza', fn() => igual(403, $n->post('/alumnos/nuevo', alumnoValido($M1))['estado']));
prueba('vacío: 422 con un error por campo obligatorio', function () use ($n) {
    $r = alta($n, ['institucion' => '']);
    igual(422, $r['estado']);
    foreach (['Escribe la matrícula', 'Escribe el nombre', 'Escribe el apellido paterno', 'Escribe la carrera', 'Escribe la unidad administrativa', 'Escribe la fecha de inicio'] as $m) {
        contiene($m, $r['cuerpo']);
    }
});
prueba('datos con formato inválido', function () use ($n, $M1) {
    $r = alta($n, alumnoValido($M1, ['correo' => 'no-es-correo', 'telefono' => '123', 'hora_salida' => '15:00', 'fecha_inicio' => '2026-02-30', 'nombre' => 'Ana<script>']));
    igual(422, $r['estado']);
    contiene('Escribe un correo válido', $r['cuerpo']);
    contiene('10 dígitos', $r['cuerpo']);
    contiene('salida debe ser después', $r['cuerpo']);
    contiene('no es una fecha válida', $r['cuerpo']);
    contiene('solo lleva letras', $r['cuerpo']);
    noContiene('Ana<script>', $r['cuerpo'], 'HTML');
});
prueba('término autorizado antes del inicio', function () use ($n, $M1) {
    $r = alta($n, alumnoValido($M1, ['fecha_fin_autorizada' => '2026-08-01']));
    igual(422, $r['estado']);
    contiene('posterior a la fecha de inicio', $r['cuerpo']);
});
prueba('jornada fuera de 4, 5 o 6 h', fn() => contiene('debe ser 4, 5, 6', alta($n, alumnoValido($M1, ['jornada_prevista' => '8']))['cuerpo']));
prueba('si hay error, lo escrito se conserva', function () use ($n, $M1) {
    $c = alta($n, alumnoValido($M1, ['telefono' => '1']))['cuerpo'];
    contiene('value="Núñez"', $c);
    contiene('value="' . $M1 . '"', $c);
});

$alumnoId = 0;
prueba('alta correcta: alumno y expediente en una sola operación', function () use ($n, $M1, $root, $adminId, &$alumnoId) {
    $r = alta($n, alumnoValido(strtolower($M1)));
    igual(303, $r['estado']);
    verdadero((bool)preg_match('#^/alumnos/(\d+)$#', $r['cabeceras']['location'] ?? '', $m), 'redirige a la ficha');
    $alumnoId = (int)$m[1];
    $a = $root->query("SELECT * FROM alumnos WHERE id = $alumnoId")->fetch();
    igual($M1, $a['matricula'], 'matrícula en mayúsculas');
    igual('5512345678', $a['telefono'], 'teléfono limpio');
    $e = $root->query("SELECT * FROM expedientes WHERE alumno_id = $alumnoId")->fetch();
    igual('480', (string)$e['horas_requeridas'], 'horas');
    igual('6', (string)$e['meses_requeridos'], 'meses');
    igual('2027-02-28', $e['fecha_fin_autorizada'], 'término = inicio + 6 meses con fin de mes');
    igual('activo', $e['estado'], 'estado');
    $h = $root->query("SELECT usuario_id, motivo FROM historial WHERE entidad = 'expedientes' AND entidad_id = {$e['id']} AND accion = 'crear'")->fetch();
    igual($adminId, (int)$h['usuario_id'], 'historial: usuario');
    igual('Alta de alumno y apertura de su expediente', $h['motivo'], 'historial: motivo');
});
prueba('la ficha muestra el aviso una sola vez', function () use ($n, &$alumnoId) {
    contiene('Alumno registrado y expediente abierto', $n->get("/alumnos/$alumnoId")['cuerpo'], 'primera vez');
    noContiene('Alumno registrado y expediente abierto', $n->get("/alumnos/$alumnoId")['cuerpo'], 'segunda vez');
});
prueba('matrícula repetida (sin importar mayúsculas)', function () use ($n, $M1) {
    $r = alta($n, alumnoValido(strtolower($M1)));
    igual(422, $r['estado']);
    contiene('Ya existe un alumno con esa matrícula', $r['cuerpo']);
});
prueba('el texto se muestra escapado', function () use ($n, $M2, $root) {
    alta($n, alumnoValido($M2, ['carrera' => '<img src=x onerror=alert(1)>', 'nombre' => 'Óscar', 'apellido_paterno' => 'López']));
    $id = (int)$root->query("SELECT id FROM alumnos WHERE matricula = '$M2'")->fetchColumn();
    $c = $n->get("/alumnos/$id")['cuerpo'];
    noContiene('<img src=x', $c, 'HTML crudo');
    contiene('&lt;img src=x', $c, 'escapado');
});

seccion('Ficha y expediente');
prueba('el expediente nuevo muestra 0 h y 480 restantes', function () use ($n, $root, &$alumnoId) {
    $eid = (int)$root->query("SELECT id FROM expedientes WHERE alumno_id = $alumnoId")->fetchColumn();
    $c = $n->get("/expedientes/$eid")['cuerpo'];
    contiene('4:00 pm – 8:00 pm', $c, 'horario en am/pm');
    contiene('480', $c);
    contiene('28 feb 2027', $c, 'fecha de los 6 meses');
});
prueba('alumno o expediente inexistente: 404', function () use ($n) {
    igual(404, $n->get('/alumnos/999999')['estado'], 'alumno');
    igual(404, $n->get('/expedientes/999999')['estado'], 'expediente');
    igual(404, $n->get('/alumnos/1abc')['estado'], 'id no numérico');
    igual(404, $n->get('/alumnos/0')['estado'], 'id cero');
});

seccion('Edición');
prueba('el formulario de edición trae los datos actuales', fn() => contiene('value="Ing. de prueba"', $n->get("/alumnos/$alumnoId/editar")['cuerpo']));
prueba('editar guarda y deja el motivo en el historial', function () use ($n, $root, &$alumnoId, $M1) {
    $csrf = $n->csrf("/alumnos/$alumnoId/editar");
    $r = $n->post("/alumnos/$alumnoId/editar", alumnoValido($M1, ['carrera' => 'Ing. corregida', '_csrf' => $csrf, 'motivo' => 'Corrección de carrera (prueba)']));
    igual(303, $r['estado']);
    igual('Ing. corregida', $root->query("SELECT carrera FROM alumnos WHERE id = $alumnoId")->fetchColumn(), 'carrera');
    $h = $root->query("SELECT motivo, antes, despues FROM historial WHERE entidad = 'alumnos' AND entidad_id = $alumnoId AND accion = 'actualizar' ORDER BY id DESC LIMIT 1")->fetch();
    igual('Corrección de carrera (prueba)', $h['motivo'], 'motivo');
    igual('Ing. de prueba', json_decode($h['antes'], true)['carrera'], 'antes');
});
prueba('no se puede usar la matrícula de otro alumno', function () use ($n, &$alumnoId, $M2) {
    $csrf = $n->csrf("/alumnos/$alumnoId/editar");
    $r = $n->post("/alumnos/$alumnoId/editar", alumnoValido($M2, ['_csrf' => $csrf]));
    igual(422, $r['estado']);
    contiene('Ya existe otro alumno con esa matrícula', $r['cuerpo']);
});

seccion('Búsqueda y filtros');
prueba('buscar por matrícula', function () use ($n, $M1, $M2) {
    $c = $n->get('/?q=' . urlencode($M1))['cuerpo'];
    contiene($M1, $c);
    noContiene($M2, $c);
});
prueba('buscar sin acentos encuentra con acentos', fn() => contiene('Óscar López', $n->get('/?q=oscar+lopez')['cuerpo']));
prueba('buscar por apellido primero', fn() => contiene($M2, $n->get('/?q=' . urlencode('López Prueba'))['cuerpo']));
prueba('los comodines de SQL no rompen la búsqueda', fn() => contiene('No hay expedientes que coincidan', $n->get('/?q=%25_%25')['cuerpo']));
prueba('el filtro de concluidos no muestra activos', fn() => noContiene($M1, $n->get('/?filtro=concluidos')['cuerpo']));
prueba('un filtro desconocido se trata como "todos"', fn() => contiene($M1, $n->get('/?filtro=<script>')['cuerpo']));
prueba('el conteo de alumnos sube con las altas', function () use ($n, $alumnosAntes) {
    preg_match('#Alumnos registrados</span><span class="cifra">(\d+)#', $n->get('/')['cuerpo'], $m);
    igual($alumnosAntes + 2, (int)($m[1] ?? -1), 'alumnos');
});

resumen();
