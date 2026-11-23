<?php
declare(strict_types=1);

/**
 * Pruebas de la Etapa 7 sin base de datos: formato firmado del respaldo,
 * cadena de sellos del historial, validación de registros y retención
 * de volcados.
 *   php tests/unidad/respaldos.php
 */

require __DIR__ . '/../lib.php';
spl_autoload_register(static function (string $c): void {
    $r = __DIR__ . '/../../src/' . str_replace(['ServicioSocial\\', '\\'], ['', '/'], $c) . '.php';
    if (is_file($r)) { require $r; }
});

use ServicioSocial\Dominio\SelloHistorial;
use ServicioSocial\Servicios\Respaldos\ExcelRespaldo;
use ServicioSocial\Servicios\Respaldos\FormatoRespaldo;
use ServicioSocial\Servicios\Respaldos\Importador;
use ServicioSocial\Servicios\Respaldos\RespaldoInvalido;
use ServicioSocial\Servicios\Respaldos\Volcado;

$LLAVE = str_repeat('ab', 32);
$datos = [
    'usuarios' => [['id' => 1, 'nombre' => 'Admin', 'correo' => 'admin@ejemplo.test', 'rol' => 'admin', 'activo' => 1]],
    'dias_inhabiles' => [['id' => 3, 'fecha' => '2026-11-16', 'tipo' => 'festivo', 'descripcion' => 'Revolución']],
    'configuracion' => [['clave' => 'horas_requeridas', 'valor' => '480', 'descripcion' => 'x']],
    'alumnos' => [['id' => 7, 'matricula' => 'A-001', 'nombre' => 'Ana', 'apellido_paterno' => 'Núñez', 'apellido_materno' => null,
        'carrera' => 'Ing.', 'institucion' => 'UTN', 'correo' => null, 'telefono' => null, 'archivado' => 0, 'creado_en' => '2026-06-01 10:00:00']],
    'expedientes' => [['id' => 9, 'alumno_id' => 7, 'unidad_administrativa' => 'U', 'responsable' => 'R', 'hora_entrada' => null, 'hora_salida' => null,
        'fecha_inicio' => '2026-06-23', 'fecha_fin_autorizada' => '2026-12-23', 'horas_requeridas' => 480, 'meses_requeridos' => 6,
        'jornada_prevista' => 4, 'feriados_acreditan' => 1, 'horas_por_feriado' => 4, 'estado' => 'activo', 'fecha_conclusion' => null,
        'resumen_conclusion' => null, 'observaciones' => null, 'creado_por' => 1, 'creado_en' => '2026-06-01 10:00:00']],
    'asistencias' => [['id' => 11, 'expediente_id' => 9, 'fecha' => '2026-06-23', 'estado' => 'asistencia', 'horas_trabajadas' => 4,
        'horas_acreditadas' => 4, 'dia_inhabil_id' => null, 'justificacion' => null, 'hora_entrada' => null, 'hora_salida' => null,
        'observaciones' => 'Ñandú "comillas" \\ y emoji 🙂', 'autorizado_por' => null, 'motivo_autorizacion' => null, 'registrado_por' => 1,
        'actualizado_por' => null, 'creado_en' => '2026-06-23 18:00:00']],
    'firmas_semana' => [['id' => 2, 'expediente_id' => 9, 'semana_inicio' => '2026-06-22', 'registrado_por' => 1, 'firmado_en' => '2026-06-26 18:00:00']],
    'historial' => [],
];
$archivo = FormatoRespaldo::escribir($datos, 'manual', '2026-11-20 10:00:00', $LLAVE);
$falla = function (callable $fn, string $esperado): void {
    try {
        $fn();
    } catch (RespaldoInvalido $e) {
        contiene($esperado, $e->getMessage());
        return;
    }
    verdadero(false, "debía rechazarse ($esperado)");
};
/** Reescribe el archivo cambiando el texto descomprimido. */
$alterar = fn(string $gz, callable $cambio) => gzencode($cambio(gzdecode($gz)));

seccion('Formato firmado del respaldo');
prueba('se escribe y se lee igual (acentos, comillas, emoji)', function () use ($archivo, $LLAVE, $datos) {
    $l = FormatoRespaldo::leer($archivo, $LLAVE);
    igual($datos, $l['datos']);
    igual('manual', $l['cabecera']['tipo']);
    igual(1, $l['cabecera']['totales']['asistencias']);
});
prueba('otra llave: firma no coincide', fn() => $falla(fn() => FormatoRespaldo::leer($archivo, str_repeat('cd', 32)), 'firma no coincide'));
prueba('sin llave configurada: mensaje claro', fn() => $falla(fn() => FormatoRespaldo::leer($archivo, ''), 'llave_hmac'));
prueba('datos alterados: la huella no coincide', fn() => $falla(fn() => FormatoRespaldo::leer(
    $alterar($archivo, fn($t) => str_replace('"horas_acreditadas":4', '"horas_acreditadas":6', $t)), $LLAVE), 'huella'));
prueba('cabecera alterada: la firma no coincide', fn() => $falla(fn() => FormatoRespaldo::leer(
    $alterar($archivo, fn($t) => str_replace('"tipo":"manual"', '"tipo":"automatico"', $t)), $LLAVE), 'firma'));
prueba('sha256 recalculado por un atacante: la firma lo delata', function () use ($archivo, $LLAVE, $alterar, $falla) {
    $gz = $alterar($archivo, function ($t) {
        [$cab, $cuerpo] = explode("\n", $t, 2);
        $cuerpo = str_replace('"horas_acreditadas":4', '"horas_acreditadas":6', $cuerpo);
        $c = json_decode($cab, true);
        $c['sha256'] = hash('sha256', $cuerpo);
        return json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" . $cuerpo;
    });
    $falla(fn() => FormatoRespaldo::leer($gz, $LLAVE), 'firma');
});
prueba('archivo truncado o que no es gzip', function () use ($archivo, $LLAVE, $falla) {
    $falla(fn() => FormatoRespaldo::leer(substr($archivo, 0, 40), $LLAVE), 'comprimido');
    $falla(fn() => FormatoRespaldo::leer('hola', $LLAVE), 'comprimido');
});
prueba('otro tipo de archivo o versión desconocida', function () use ($archivo, $LLAVE, $alterar, $falla) {
    $falla(fn() => FormatoRespaldo::leer(gzencode("{\"a\":1}\n{}"), $LLAVE), 'no es un respaldo de este sistema');
    $falla(fn() => FormatoRespaldo::leer($alterar($archivo, fn($t) => str_replace('"version_formato":"1"', '"version_formato":"9"', $t)), $LLAVE), 'Versión');
});

seccion('Validación de registros del respaldo');
prueba('un respaldo correcto no tiene errores', fn() => igual([], Importador::validar($datos)));
prueba('referencias rotas y valores fuera de regla', function () use ($datos) {
    $d = $datos;
    $d['asistencias'][0]['expediente_id'] = 999;
    $d['expedientes'][0]['jornada_prevista'] = 9;
    $d['alumnos'][] = ['matricula' => 'A-001'] + $d['alumnos'][0];
    $d['alumnos'][1]['id'] = 8;
    $d['firmas_semana'][0]['semana_inicio'] = '2026-06-23';
    $e = implode(' | ', Importador::validar($d));
    contiene('su expediente no está en el archivo', $e);
    contiene('fuera de rango', $e);
    contiene('matrícula repetida', $e);
    contiene('Firma 2', $e);
});
prueba('falta un campo', function () use ($datos) {
    $d = $datos;
    unset($d['asistencias'][0]['fecha']);
    contiene('falta «fecha»', implode(' ', Importador::validar($d)));
});
prueba('reglas de captura: falta con horas, asistencia de 2 h', function () use ($datos) {
    $d = $datos;
    $d['asistencias'][0]['estado'] = 'falta';
    contiene('estado u horas no válidos', implode(' ', Importador::validar($d)));
    $d = $datos;
    $d['asistencias'][0]['horas_trabajadas'] = 2;
    contiene('estado u horas no válidos', implode(' ', Importador::validar($d)));
});

seccion('Cadena de sellos del historial');
$fila = fn(int $id, string $motivo = 'm') => ['id' => $id, 'origen' => 'trigger', 'usuario_id' => 1, 'usuario_bd' => 'ss_app@localhost',
    'accion' => 'crear', 'entidad' => 'asistencias', 'entidad_id' => $id, 'expediente_id' => 9, 'antes' => null,
    'despues' => '{"fecha": "2026-06-23"}', 'motivo' => $motivo, 'ip' => '127.0.0.1', 'agente' => 'x', 'creado_en' => '2026-06-23 18:00:00.123', 'hash' => null];
$sellar = function (array $filas): array {
    $ant = SelloHistorial::INICIO;
    foreach ($filas as &$f) {
        $f['hash'] = $ant = SelloHistorial::encadenar($ant, $f);
    }
    return $filas;
};
$cadena = $sellar([$fila(1), $fila(2), $fila(4), $fila(5)]); // el 3 no existe: un id saltado por un rollback es normal
prueba('cadena íntegra (los ids saltados no son problema)', function () use ($cadena) {
    $v = SelloHistorial::verificar($cadena);
    verdadero($v['ok'], 'ok');
    igual([4, 4, 0], [$v['revisadas'], $v['selladas'], $v['sin_sellar']]);
});
prueba('una fila sellada alterada se detecta', function () use ($cadena) {
    $c = $cadena;
    $c[1]['motivo'] = 'cambiado';
    $v = SelloHistorial::verificar($c);
    verdadero(!$v['ok'], 'falla');
    igual(2, $v['problemas'][0]['id']);
});
prueba('una fila sellada borrada se detecta en la siguiente', function () use ($cadena) {
    $c = $cadena;
    unset($c[1]);
    $v = SelloHistorial::verificar(array_values($c));
    igual([['id' => 4, 'tipo' => 'no_coincide']], $v['problemas']);
});
prueba('filas pendientes al final no son problema', function () use ($cadena, $fila) {
    $v = SelloHistorial::verificar(array_merge($cadena, [$fila(6), $fila(7)]));
    verdadero($v['ok'], 'ok');
    igual(2, $v['sin_sellar']);
});
prueba('una sellada después de una pendiente se señala', function () use ($cadena, $fila) {
    $c = array_merge($cadena, [$fila(6)]);
    $s = $fila(7);
    $s['hash'] = SelloHistorial::encadenar($cadena[3]['hash'], $s);
    $v = SelloHistorial::verificar(array_merge($c, [$s]));
    igual('sellada_despues_de_pendiente', $v['problemas'][0]['tipo']);
});
prueba('ids numéricos o en texto dan el mismo sello', function () use ($fila) {
    $a = $fila(1);
    $b = array_map(fn($v) => is_int($v) ? (string)$v : $v, $a);
    igual(SelloHistorial::encadenar(SelloHistorial::INICIO, $a), SelloHistorial::encadenar(SelloHistorial::INICIO, $b));
});

seccion('Retención de volcados');
prueba('14 diarios y 8 domingos más viejos', function () {
    $dir = sys_get_temp_dir() . '/ss-ret-' . bin2hex(random_bytes(4));
    mkdir($dir);
    for ($d = new DateTimeImmutable('2026-06-01'); $d <= new DateTimeImmutable('2026-11-20'); $d = $d->modify('+1 day')) {
        touch("$dir/volcado-" . $d->format('Ymd') . '-020000.sql.gz');
    }
    touch("$dir/previo-restauracion-20260101-000000.sql.gz");
    Volcado::retencion($dir, 14, 8);
    $quedan = array_map('basename', glob("$dir/volcado-*.sql.gz"));
    igual(22, count($quedan), '14 + 8');
    rsort($quedan);
    igual('volcado-20261120-020000.sql.gz', $quedan[0]);
    foreach (array_slice($quedan, 14) as $q) {
        igual('7', date('N', strtotime(substr($q, 8, 8))), "$q es domingo");
    }
    verdadero(is_file("$dir/previo-restauracion-20260101-000000.sql.gz"), 'no toca los previos');
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
});

seccion('Respaldo en Excel');
prueba('el .xlsx es un ZIP válido con una hoja por tabla y XML bien formado', function () use ($archivo, $LLAVE) {
    $xlsx = ExcelRespaldo::generar(FormatoRespaldo::leer($archivo, $LLAVE), 'respaldo-prueba.json.gz');
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, $xlsx);
    $z = new ZipArchive();
    igual(true, $z->open($tmp, ZipArchive::CHECKCONS));
    $libro = $z->getFromName('xl/workbook.xml');
    foreach (['Resumen', 'Alumnos', 'Expedientes', 'Asistencias', 'Firmas semanales', 'Días inhábiles', 'Usuarios', 'Configuración', 'Historial'] as $h) {
        contiene('name="' . $h . '"', $libro);
    }
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        verdadero(simplexml_load_string($z->getFromIndex($i)) !== false, "$n es XML válido");
    }
    $exp = $z->getFromName('xl/worksheets/sheet3.xml'); // Expedientes
    contiene('Ana Núñez', $exp, 'nombre del alumno junto a su ID');
    contiene('>Sí<', $exp, 'feriados_acreditan como Sí');
    $asis = $z->getFromName('xl/worksheets/sheet4.xml');
    contiene('Ñandú &quot;comillas&quot; \\ y emoji 🙂', $asis, 'texto escapado');
    contiene('<v>4</v>', $asis, 'horas como número');
    $alum = $z->getFromName('xl/worksheets/sheet2.xml');
    contiene('t="inlineStr"><is><t xml:space="preserve">A-001', $alum, 'matrícula como texto');
    $z->close();
    unlink($tmp);
});
prueba('un texto que empieza con = no se vuelve fórmula y se quitan caracteres inválidos', function () {
    $l = new \ServicioSocial\Nucleo\LibroExcel();
    $l->agregarHoja('Hoja: rara/[x]', ['A'], [["=HYPERLINK(\"http://x\")\x01"]]);
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, $l->generar());
    $z = new ZipArchive();
    $z->open($tmp);
    $h = $z->getFromName('xl/worksheets/sheet1.xml');
    verdadero(!str_contains($h, '<f>'), 'sin fórmulas');
    contiene('=HYPERLINK(&quot;http://x&quot;)</t>', $h);
    contiene('name="Hoja  rara  x "', $z->getFromName('xl/workbook.xml'), 'nombre de hoja sin caracteres prohibidos');
    $z->close();
    unlink($tmp);
});
prueba('no se convierte un respaldo alterado', function () use ($archivo, $alterar, $falla) {
    $malo = $alterar($archivo, fn($t) => str_replace('Ana', 'Eva', $t));
    $falla(fn() => FormatoRespaldo::leer($malo, str_repeat('ab', 32)), 'huella');
});

resumen();
