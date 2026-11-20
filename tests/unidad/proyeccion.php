<?php
declare(strict_types=1);

/**
 * Pruebas de la Proyección: conclusión probable, escenarios, ritmo real y avance por mes.
 *   php tests/unidad/proyeccion.php
 */

require __DIR__ . '/../lib.php';
spl_autoload_register(static function (string $c): void {
    $r = __DIR__ . '/../../src/' . str_replace(['ServicioSocial\\', '\\'], ['', '/'], $c) . '.php';
    if (is_file($r)) { require $r; }
});

use ServicioSocial\Dominio\Calculadora;
use ServicioSocial\Dominio\Proyeccion;

$exp = ['fecha_inicio' => '2026-06-23', 'fecha_fin_autorizada' => '2026-12-23', 'horas_requeridas' => 480,
    'meses_requeridos' => 6, 'jornada_prevista' => 4, 'feriados_acreditan' => 1, 'horas_por_feriado' => 4, 'estado' => 'activo'];
$con = fn(array $c) => array_merge($exp, $c);
/** Asistencias de lunes a viernes entre dos fechas. */
$dias = function (string $desde, string $hasta, int $h = 4): array {
    $f = [];
    for ($d = new DateTimeImmutable($desde); $d <= new DateTimeImmutable($hasta); $d = $d->modify('+1 day')) {
        if ((int)$d->format('N') <= 5) {
            $f[] = ['fecha' => $d->format('Y-m-d'), 'estado' => 'asistencia', 'horas_trabajadas' => $h, 'horas_acreditadas' => $h];
        }
    }
    return $f;
};

seccion('Conclusión probable');
prueba('desde el inicio con 4 h la marca el periodo (120 días hábiles caen antes de los 6 meses)', function () use ($exp) {
    $r = Calculadora::calcular($exp, [], [], new DateTimeImmutable('2026-06-23'));
    $c = Proyeccion::conclusionProbable($r);
    igual('periodo', $c['limitante']);
    igual('2026-12-23', $c['fecha']->format('Y-m-d'));
});
prueba('sin horas a mitad del periodo la marcan las horas', function () use ($exp) {
    $r = Calculadora::calcular($exp, [], [], new DateTimeImmutable('2026-10-01'));
    $c = Proyeccion::conclusionProbable($r);
    igual(['horas', '2027-03-17'], [$c['limitante'], $c['fecha']->format('Y-m-d')]);
});
prueba('con jornada de 6 h también la marca el periodo', function () use ($con) {
    $r = Calculadora::calcular($con(['jornada_prevista' => 6]), [], [], new DateTimeImmutable('2026-06-23'));
    $c = Proyeccion::conclusionProbable($r);
    igual('periodo', $c['limitante']);
    igual('2026-12-23', $c['fecha']->format('Y-m-d'));
});
prueba('horas cumplidas antes del periodo: la fecha del periodo', function () use ($exp, $dias) {
    $r = Calculadora::calcular($exp, $dias('2026-06-23', '2026-11-30', 6), [], new DateTimeImmutable('2026-12-01'));
    igual(0, $r->restantes);
    $c = Proyeccion::conclusionProbable($r);
    igual(['periodo', '2026-12-23'], [$c['limitante'], $c['fecha']->format('Y-m-d')]);
});
prueba('suspendido: sin proyección', function () use ($con) {
    $r = Calculadora::calcular($con(['estado' => 'suspendido']), [], [], new DateTimeImmutable('2026-07-01'));
    igual(['fecha' => null, 'limitante' => 'ninguno'], Proyeccion::conclusionProbable($r));
});

seccion('Escenarios por jornada');
// Sin horas el 1 de octubre y término autorizado al 15 de febrero.
$esc = Proyeccion::escenarios($con(['fecha_fin_autorizada' => '2027-02-15']), [], [], new DateTimeImmutable('2026-10-01'), ['horas' => 480, 'meses' => 6], 40, [4, 5, 6]);
prueba('uno por jornada y marca la actual', function () use ($esc) {
    igual([4, 5, 6], array_column($esc, 'jornada'));
    igual([true, false, false], array_column($esc, 'actual'));
});
prueba('más horas por día terminan antes', function () use ($esc) {
    verdadero($esc[0]['fecha_horas'] > $esc[1]['fecha_horas'] && $esc[1]['fecha_horas'] > $esc[2]['fecha_horas'], 'orden');
    igual([120, 96, 80], array_column($esc, 'dias'));
    igual(['2027-03-17', '2027-02-11', '2027-01-20'], array_map(fn($e) => $e['fecha_horas']->format('Y-m-d'), $esc));
});
prueba('4 h no alcanza el término autorizado; 5 y 6 sí', fn() => igual([false, true, true], array_column($esc, 'dentro_autorizada')));
prueba('no cambia el expediente original', fn() => igual(4, $exp['jornada_prevista']));

seccion('Ritmo real');
prueba('promedio de las 4 semanas completas anteriores', function () use ($dias) {
    // 4 semanas de 4 h diarias = 80 h; la semana en curso no cuenta.
    $reg = array_merge($dias('2026-08-03', '2026-08-28'), $dias('2026-08-31', '2026-09-01', 6));
    $r = Proyeccion::ritmo($reg, new DateTimeImmutable('2026-09-02'), new DateTimeImmutable('2026-06-23'), 100);
    igual([4, 80, 20.0], [$r['semanas'], $r['horas'], $r['por_semana']]);
    igual('2026-10-07', $r['fecha']->format('Y-m-d'), '100 h a 20 h por semana = 35 días');
});
prueba('si empezó hace poco, solo las semanas transcurridas', function () use ($dias) {
    $r = Proyeccion::ritmo($dias('2026-06-22', '2026-07-03'), new DateTimeImmutable('2026-07-08'), new DateTimeImmutable('2026-06-22'), 400);
    igual([2, 40, 20.0], [$r['semanas'], $r['horas'], $r['por_semana']]);
});
prueba('primera semana: todavía no hay ritmo', function () {
    $r = Proyeccion::ritmo([], new DateTimeImmutable('2026-06-25'), new DateTimeImmutable('2026-06-23'), 480);
    igual([0, null], [$r['semanas'], $r['fecha']]);
});
prueba('si empieza en el futuro: sin ritmo (no cuenta semanas hacia atrás)', function () {
    $r = Proyeccion::ritmo([], new DateTimeImmutable('2026-06-10'), new DateTimeImmutable('2026-07-20'), 480);
    igual([0, null], [$r['semanas'], $r['fecha']]);
});
prueba('sin horas en las últimas semanas: sin fecha', function () {
    $r = Proyeccion::ritmo([], new DateTimeImmutable('2026-09-02'), new DateTimeImmutable('2026-06-23'), 100);
    igual([4, 0.0, null], [$r['semanas'], $r['por_semana'], $r['fecha']]);
});

seccion('Avance por mes natural');
prueba('cuenta asistencias, faltas, feriados y horas por mes', function () {
    $m = Proyeccion::porMes([
        ['fecha' => '2026-07-01', 'estado' => 'asistencia', 'horas_acreditadas' => 4],
        ['fecha' => '2026-06-30', 'estado' => 'asistencia', 'horas_acreditadas' => 5],
        ['fecha' => '2026-07-02', 'estado' => 'falta', 'horas_acreditadas' => 0],
        ['fecha' => '2026-07-03', 'estado' => 'feriado', 'horas_acreditadas' => 4],
    ]);
    igual(['2026-06', '2026-07'], array_keys($m), 'ordenado');
    igual(['asistencias' => 1, 'faltas' => 1, 'feriados' => 1, 'horas' => 8], $m['2026-07']);
});

resumen();
