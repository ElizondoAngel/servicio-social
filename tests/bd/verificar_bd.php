<?php
declare(strict_types=1);

/**
 * Verificación de la base de datos contra los casos de la auditoría.
 *
 * Corre SOLO contra una base de desarrollo recién instalada con
 * tools/instalar_bd_dev.sh y un administrador de prueba (id 1). Usa datos
 * ficticios (matrículas PRUEBA-xxx) y prueba CHECK, triggers, permisos e
 * historial con tres conexiones: ss_app, ss_admin y ss_sello.
 *
 *   PW_ADMIN=... PW_APP=... PW_SELLO=... php tests/bd/verificar_bd.php
 */

require __DIR__ . '/../../src/Dominio/Periodo.php';

use ServicioSocial\Dominio\Periodo;

function conectar(string $usuario, string $password): PDO
{
    $pdo = new PDO('mysql:host=localhost;dbname=servicio_social;charset=utf8mb4', $usuario, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

$app   = conectar('ss_app', getenv('PW_APP') ?: '');
$admin = conectar('ss_admin', getenv('PW_ADMIN') ?: '');
$sello = conectar('ss_sello', getenv('PW_SELLO') ?: '');

$ok = 0; $fallas = [];

/** El bloque debe ejecutarse sin error. */
function debePasar(string $nombre, callable $fn): void
{
    global $ok, $fallas;
    try { $fn(); $ok++; echo "  ok    $nombre\n"; }
    catch (Throwable $e) { $fallas[] = $nombre; echo "  FALLA $nombre -> {$e->getMessage()}\n"; }
}

/** El bloque debe fallar y el mensaje debe contener $esperado. */
function debeFallar(string $nombre, string $esperado, callable $fn): void
{
    global $ok, $fallas;
    try { $fn(); $fallas[] = $nombre; echo "  FALLA $nombre -> no hubo error (se esperaba $esperado)\n"; }
    catch (PDOException $e) {
        if (str_contains($e->getMessage(), $esperado)) { $ok++; echo "  ok    $nombre ($esperado)\n"; }
        else { $fallas[] = $nombre; echo "  FALLA $nombre -> {$e->getMessage()}\n"; }
    }
}

function igual(mixed $esperado, mixed $real, string $que): void
{
    if ($esperado != $real) {
        throw new RuntimeException("$que: se esperaba " . var_export($esperado, true) . ', llegó ' . var_export($real, true));
    }
}

function asistencia(PDO $pdo, int $exp, string $fecha, array $campos = []): void
{
    $fila = array_merge(['expediente_id' => $exp, 'fecha' => $fecha, 'estado' => 'asistencia',
        'horas_trabajadas' => 4, 'horas_acreditadas' => 4, 'registrado_por' => 1], $campos);
    $cols = implode(', ', array_keys($fila));
    $marcas = implode(', ', array_fill(0, count($fila), '?'));
    $pdo->prepare("INSERT INTO asistencias ($cols) VALUES ($marcas)")->execute(array_values($fila));
}

function contexto(PDO $pdo, ?string $motivo = null): void
{
    $pdo->prepare('SET @usuario_app = 1, @motivo_app = ?, @ip_app = ?, @agente_app = ?')
        ->execute([$motivo, '127.0.0.1', 'verificar_bd.php']);
}

contexto($app);

// --- Datos ficticios ---------------------------------------------------------
$app->exec("INSERT INTO alumnos (matricula, nombre, apellido_paterno, carrera) VALUES
  ('PRUEBA-001', 'Alumno', 'Uno', 'Carrera de prueba'),
  ('PRUEBA-002', 'Alumno', 'Dos', 'Carrera de prueba')");
$app->exec("INSERT INTO dias_inhabiles (fecha, tipo, descripcion) VALUES
  ('2026-09-16', 'festivo', 'Festivo de prueba'), ('2026-12-21', 'cierre', 'Cierre de prueba')");
$app->exec("INSERT INTO expedientes (alumno_id, unidad_administrativa, responsable, fecha_inicio, fecha_fin_autorizada, creado_por)
  VALUES (1, 'Unidad de prueba', 'Responsable de prueba', '2026-06-23', '2026-12-23', 1)");
$E1 = (int)$app->lastInsertId();
$festivo = (int)$app->query("SELECT id FROM dias_inhabiles WHERE fecha = '2026-09-16'")->fetchColumn();

echo "\nAsistencias: reglas de captura\n";
debePasar('asistencia de 4 h', fn() => asistencia($app, $E1, '2026-06-23'));
debePasar('asistencia de 6 h', fn() => asistencia($app, $E1, '2026-06-24', ['horas_trabajadas' => 6, 'horas_acreditadas' => 6]));
debeFallar('asistencia de 3 h', 'chk_asis_jornada', fn() => asistencia($app, $E1, '2026-06-25', ['horas_trabajadas' => 3, 'horas_acreditadas' => 3]));
debeFallar('asistencia de 7 h', 'chk_asis_jornada', fn() => asistencia($app, $E1, '2026-06-25', ['horas_trabajadas' => 7, 'horas_acreditadas' => 6]));
debeFallar('horas negativas', 'Out of range', fn() => asistencia($app, $E1, '2026-06-25', ['horas_trabajadas' => -4]));
debeFallar('fecha inexistente 2026-02-30', 'Incorrect date', fn() => asistencia($app, $E1, '2026-02-30'));
debeFallar('mismo día dos veces', 'uq_asistencias_expediente_fecha', fn() => asistencia($app, $E1, '2026-06-23'));
debeFallar('falta con horas acreditadas', 'chk_asis_falta', fn() => asistencia($app, $E1, '2026-06-25', ['estado' => 'falta', 'horas_trabajadas' => 0, 'horas_acreditadas' => 4, 'justificacion' => 'x']));
debePasar('falta sin horas', fn() => asistencia($app, $E1, '2026-06-25', ['estado' => 'falta', 'horas_trabajadas' => 0, 'horas_acreditadas' => 0]));
debeFallar('feriado con horas trabajadas', 'chk_asis_feriado', fn() => asistencia($app, $E1, '2026-06-26', ['estado' => 'feriado', 'horas_trabajadas' => 4]));
debeFallar('acreditadas distintas sin justificación', 'chk_asis_justificacion', fn() => asistencia($app, $E1, '2026-06-26', ['horas_acreditadas' => 6]));
debePasar('acreditadas distintas con justificación', fn() => asistencia($app, $E1, '2026-06-26', ['horas_acreditadas' => 6, 'justificacion' => 'Actividad extra autorizada (prueba)']));
debeFallar('feriado acreditado sin catálogo ni justificación', 'chk_asis_justificacion', fn() => asistencia($app, $E1, '2026-09-15', ['estado' => 'feriado', 'horas_trabajadas' => 0]));
debePasar('feriado acreditado con día del catálogo', fn() => asistencia($app, $E1, '2026-09-16', ['estado' => 'feriado', 'horas_trabajadas' => 0, 'dia_inhabil_id' => $festivo]));
debeFallar('día del catálogo en una asistencia normal', 'chk_asis_dia_inhabil', fn() => asistencia($app, $E1, '2026-09-17', ['dia_inhabil_id' => $festivo]));
debeFallar('autorización sin motivo', 'chk_asis_autorizacion', fn() => asistencia($app, $E1, '2026-06-27', ['autorizado_por' => 1]));
debePasar('sábado con autorización y motivo', fn() => asistencia($app, $E1, '2026-06-27', ['autorizado_por' => 1, 'motivo_autorizacion' => 'Evento institucional (prueba)']));
debeFallar('entrada y salida que no cuadran', 'chk_asis_horario', fn() => asistencia($app, $E1, '2026-06-29', ['horas_trabajadas' => 6, 'horas_acreditadas' => 6, 'hora_entrada' => '16:00', 'hora_salida' => '20:00']));
debePasar('entrada y salida correctas', fn() => asistencia($app, $E1, '2026-06-29', ['hora_entrada' => '16:00', 'hora_salida' => '20:00']));

echo "\nAsistencias: modificaciones\n";
debePasar('editar sube la versión y queda en historial', function () use ($app, $E1) {
    contexto($app, 'Corrección de prueba');
    $app->exec("UPDATE asistencias SET horas_trabajadas = 5, horas_acreditadas = 5 WHERE expediente_id = $E1 AND fecha = '2026-06-23'");
    igual(2, $app->query("SELECT version FROM asistencias WHERE expediente_id = $E1 AND fecha = '2026-06-23'")->fetchColumn(), 'versión');
    $h = $app->query("SELECT antes, despues, motivo, usuario_id FROM historial WHERE entidad = 'asistencias' AND accion = 'actualizar' ORDER BY id DESC LIMIT 1")->fetch();
    igual(4, json_decode($h['antes'], true)['horas_trabajadas'], 'antes');
    igual(5, json_decode($h['despues'], true)['horas_trabajadas'], 'después');
    igual('Corrección de prueba', $h['motivo'], 'motivo');
    igual(1, $h['usuario_id'], 'usuario');
    contexto($app);
});
debePasar('edición con versión vieja no cambia nada (409)', function () use ($app, $E1) {
    $n = $app->exec("UPDATE asistencias SET horas_trabajadas = 6, horas_acreditadas = 6 WHERE expediente_id = $E1 AND fecha = '2026-06-23' AND version = 1");
    igual(0, $n, 'filas afectadas');
});
debeFallar('mover un registro a otra fecha', 'REGISTRO_INMOVIBLE', fn() => $app->exec("UPDATE asistencias SET fecha = '2026-06-30' WHERE expediente_id = $E1 AND fecha = '2026-06-29'"));
debePasar('quitar un registro deja evidencia', function () use ($app, $E1) {
    $app->exec("DELETE FROM asistencias WHERE expediente_id = $E1 AND fecha = '2026-06-29'");
    igual('quitar', $app->query("SELECT accion FROM historial WHERE entidad = 'asistencias' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'acción');
});

echo "\nExpedientes\n";
debeFallar('segundo expediente abierto del mismo alumno', 'uq_expedientes_abierto', fn() => $app->exec("INSERT INTO expedientes (alumno_id, unidad_administrativa, responsable, fecha_inicio, fecha_fin_autorizada, creado_por) VALUES (1, 'U', 'R', '2027-01-04', '2027-07-04', 1)"));
debeFallar('fecha autorizada antes del inicio', 'chk_exp_fin_autorizada', fn() => $app->exec("INSERT INTO expedientes (alumno_id, unidad_administrativa, responsable, fecha_inicio, fecha_fin_autorizada, creado_por) VALUES (2, 'U', 'R', '2026-01-05', '2026-01-05', 1)"));
debeFallar('menos de 480 horas requeridas', 'MINIMO_HORAS', fn() => $app->exec("INSERT INTO expedientes (alumno_id, unidad_administrativa, responsable, fecha_inicio, fecha_fin_autorizada, horas_requeridas, creado_por) VALUES (2, 'U', 'R', '2026-01-05', '2026-07-05', 100, 1)"));
debeFallar('menos de 6 meses requeridos', 'MINIMO_MESES', fn() => $app->exec("INSERT INTO expedientes (alumno_id, unidad_administrativa, responsable, fecha_inicio, fecha_fin_autorizada, meses_requeridos, creado_por) VALUES (2, 'U', 'R', '2026-01-05', '2026-07-05', 1, 1)"));
debeFallar('bajar horas requeridas después', 'MINIMO_HORAS', fn() => $app->exec("UPDATE expedientes SET horas_requeridas = 100 WHERE id = $E1"));
debeFallar('jornada prevista de 3 h', 'chk_exp_jornada', fn() => $app->exec("UPDATE expedientes SET jornada_prevista = 3 WHERE id = $E1"));
debeFallar('crear un expediente ya concluido', 'ALTA_CONCLUIDA', fn() => $app->exec("INSERT INTO expedientes (alumno_id, unidad_administrativa, responsable, fecha_inicio, fecha_fin_autorizada, estado, fecha_conclusion, resumen_conclusion, creado_por) VALUES (2, 'U', 'R', '2026-01-05', '2026-07-05', 'concluido', '2026-07-06', '{}', 1)"));

// Expediente 2: 120 días de 4 h desde el 5 de enero = 480 h
$app->exec("INSERT INTO expedientes (alumno_id, unidad_administrativa, responsable, fecha_inicio, fecha_fin_autorizada, creado_por) VALUES (2, 'Unidad de prueba', 'Responsable de prueba', '2026-01-05', '2026-07-05', 1)");
$E2 = (int)$app->lastInsertId();
$d = new DateTimeImmutable('2026-01-05'); $n = 0;
while ($n < 119) {
    if ((int)$d->format('N') <= 5) { asistencia($app, $E2, $d->format('Y-m-d')); $n++; }
    $d = $d->modify('+1 day');
}
$concluir = fn(string $fecha) => $app->exec("UPDATE expedientes SET estado = 'concluido', fecha_conclusion = '$fecha', resumen_conclusion = '{\"horas\":480}' WHERE id = $E2");

echo "\nConclusión\n";
debeFallar('476 h con el periodo cumplido', 'HORAS_INSUFICIENTES', fn() => $concluir('2026-07-06'));
while ((int)$d->format('N') > 5) { $d = $d->modify('+1 day'); }
asistencia($app, $E2, $d->format('Y-m-d'));
debeFallar('480 h un día antes de los 6 meses', 'PERIODO_INCOMPLETO', fn() => $concluir('2026-07-04'));
debeFallar('concluir sin resumen', 'chk_exp_resumen', fn() => $app->exec("UPDATE expedientes SET estado = 'concluido', fecha_conclusion = '2026-07-05' WHERE id = $E2"));
debePasar('480 h el día exacto de los 6 meses', fn() => $concluir('2026-07-05'));
debePasar('el historial registra "concluir"', fn() => igual('concluir', $app->query("SELECT accion FROM historial WHERE entidad = 'expedientes' AND entidad_id = $E2 ORDER BY id DESC LIMIT 1")->fetchColumn(), 'acción'));
debeFallar('capturar en un expediente concluido', 'EXPEDIENTE_CERRADO', fn() => asistencia($app, $E2, '2026-07-06'));
debeFallar('editar una asistencia de un expediente concluido', 'EXPEDIENTE_CERRADO', fn() => $app->exec("UPDATE asistencias SET observaciones = 'x' WHERE expediente_id = $E2 LIMIT 1"));
debeFallar('quitar una asistencia de un expediente concluido', 'EXPEDIENTE_CERRADO', fn() => $app->exec("DELETE FROM asistencias WHERE expediente_id = $E2 LIMIT 1"));
debeFallar('lo mismo directo en SQL con ss_admin', 'EXPEDIENTE_CERRADO', fn() => $admin->exec("DELETE FROM asistencias WHERE expediente_id = $E2 LIMIT 1"));
debeFallar('editar datos del expediente concluido', 'EXPEDIENTE_CERRADO', fn() => $app->exec("UPDATE expedientes SET observaciones = 'x' WHERE id = $E2"));
debeFallar('reabrir sin motivo', 'MOTIVO_REQUERIDO', fn() => $app->exec("UPDATE expedientes SET estado = 'activo', fecha_conclusion = NULL, resumen_conclusion = NULL WHERE id = $E2"));
debePasar('reabrir con motivo queda como "reabrir"', function () use ($app, $E2) {
    contexto($app, 'Corrección de un registro (prueba)');
    $app->exec("UPDATE expedientes SET estado = 'activo', fecha_conclusion = NULL, resumen_conclusion = NULL WHERE id = $E2");
    igual('reabrir', $app->query("SELECT accion FROM historial WHERE entidad = 'expedientes' AND entidad_id = $E2 ORDER BY id DESC LIMIT 1")->fetchColumn(), 'acción');
    contexto($app);
});
debeFallar('concluir con fecha antes de 6 meses, directo en SQL', 'PERIODO_INCOMPLETO', fn() => $admin->exec("UPDATE expedientes SET estado = 'concluido', fecha_conclusion = '2026-06-30', resumen_conclusion = '{}' WHERE id = $E2"));

echo "\nFirmas semanales\n";
debeFallar('firma en martes', 'chk_firmas_lunes', fn() => $app->exec("INSERT INTO firmas_semana (expediente_id, semana_inicio, registrado_por) VALUES ($E1, '2026-06-23', 1)"));
debePasar('firma en lunes', fn() => $app->exec("INSERT INTO firmas_semana (expediente_id, semana_inicio, registrado_por) VALUES ($E1, '2026-06-22', 1)"));
debeFallar('editar una firma', 'FIRMA_INMUTABLE', fn() => $admin->exec("UPDATE firmas_semana SET firmado_en = NOW() WHERE expediente_id = $E1"));

echo "\nHistorial y permisos\n";
debePasar('cambio directo en SQL deja evidencia del usuario de MariaDB', function () use ($admin, $app, $E1) {
    $admin->exec("UPDATE asistencias SET observaciones = 'editado fuera de la app' WHERE expediente_id = $E1 AND fecha = '2026-06-24'");
    $h = $app->query("SELECT usuario_id, usuario_bd FROM historial WHERE entidad = 'asistencias' ORDER BY id DESC LIMIT 1")->fetch();
    igual(null, $h['usuario_id'], 'usuario de la app');
    igual('ss_admin@localhost', $h['usuario_bd'], 'usuario de MariaDB');
});
debeFallar('ss_app no puede editar el historial', 'UPDATE command denied', fn() => $app->exec('UPDATE historial SET motivo = NULL WHERE id = 1'));
debeFallar('ss_app no puede borrar el historial', 'DELETE command denied', fn() => $app->exec('DELETE FROM historial WHERE id = 1'));
debeFallar('ss_admin tampoco puede editarlo', 'HISTORIAL_INMUTABLE', fn() => $admin->exec("UPDATE historial SET motivo = 'alterado' WHERE id = 1"));
debeFallar('ss_admin tampoco puede borrarlo', 'HISTORIAL_INMUTABLE', fn() => $admin->exec('DELETE FROM historial WHERE id = 1'));
debePasar('ss_sello sella una fila sin sellar', fn() => $sello->exec("UPDATE historial SET hash = REPEAT('a', 64), sellado_en = NOW() WHERE id = 1"));
debeFallar('ss_sello no puede volver a sellarla', 'HISTORIAL_INMUTABLE', fn() => $sello->exec("UPDATE historial SET hash = REPEAT('b', 64), sellado_en = NOW() WHERE id = 1"));
debeFallar('ss_sello no puede cambiar otras columnas', 'denied', fn() => $sello->exec("UPDATE historial SET motivo = 'x' WHERE id = 2"));
debeFallar('ss_app no puede borrar alumnos', 'DELETE command denied', fn() => $app->exec('DELETE FROM alumnos WHERE id = 1'));
debeFallar('ss_app no puede borrar expedientes', 'DELETE command denied', fn() => $app->exec("DELETE FROM expedientes WHERE id = $E1"));
debeFallar('ss_app no puede alterar tablas', 'denied', fn() => $app->exec('ALTER TABLE asistencias DROP CONSTRAINT chk_asis_jornada'));

echo "\nUsuarios\n";
debePasar('actualizar solo el último acceso no llena el historial', function () use ($app) {
    $antes = (int)$app->query("SELECT COUNT(*) FROM historial WHERE entidad = 'usuarios'")->fetchColumn();
    $app->exec('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = 1');
    igual($antes, (int)$app->query("SELECT COUNT(*) FROM historial WHERE entidad = 'usuarios'")->fetchColumn(), 'filas de historial');
});
debePasar('cambio de contraseña queda registrado sin el hash', function () use ($app) {
    $app->prepare('UPDATE usuarios SET password_hash = ? WHERE id = 1')->execute([password_hash('otra-contrasena-prueba', PASSWORD_DEFAULT)]);
    $h = $app->query("SELECT despues FROM historial WHERE entidad = 'usuarios' ORDER BY id DESC LIMIT 1")->fetchColumn();
    igual(true, json_decode($h, true)['password_cambiada'], 'password_cambiada');
    if (str_contains($h, '$argon') || str_contains($h, '$2y$')) { throw new RuntimeException('el hash quedó en el historial'); }
});
debeFallar('usuario activo sin contraseña', 'chk_usuarios_activo_con_pw', fn() => $app->exec("INSERT INTO usuarios (nombre, correo, activo) VALUES ('Restaurado', 'restaurado@ejemplo.test', 1)"));
debePasar('usuario restaurado desactivado y sin contraseña', fn() => $app->exec("INSERT INTO usuarios (nombre, correo, activo) VALUES ('Restaurado', 'restaurado@ejemplo.test', 0)"));

echo "\nFechas: PHP y MariaDB dan el mismo día de cumplimiento\n";
debePasar('todas las fechas de 2026 a 2028, con 6 meses', function () use ($app) {
    $d = new DateTimeImmutable('2026-01-01'); $fin = new DateTimeImmutable('2029-01-01');
    $q = $app->prepare('SELECT DATE_ADD(?, INTERVAL 6 MONTH)');
    $revisadas = 0;
    while ($d < $fin) {
        $q->execute([$d->format('Y-m-d')]);
        igual($q->fetchColumn(), Periodo::fechaCumplimiento($d, 6)->format('Y-m-d'), 'inicio ' . $d->format('Y-m-d'));
        $d = $d->modify('+1 day'); $revisadas++;
    }
    echo "        ($revisadas fechas comparadas)\n";
});
debePasar('casos de la auditoría', function () {
    igual('2026-12-23', Periodo::fechaCumplimiento(new DateTimeImmutable('2026-06-23'), 6)->format('Y-m-d'), '23-jun');
    igual('2027-02-28', Periodo::fechaCumplimiento(new DateTimeImmutable('2026-08-31'), 6)->format('Y-m-d'), '31-ago');
    igual('2028-02-29', Periodo::fechaCumplimiento(new DateTimeImmutable('2027-08-31'), 6)->format('Y-m-d'), '31-ago bisiesto');
});

echo "\n" . ($ok + count($fallas)) . " pruebas: $ok correctas, " . count($fallas) . " fallidas\n";
exit($fallas ? 1 : 0);
