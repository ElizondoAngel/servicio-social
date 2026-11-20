<?php
declare(strict_types=1);

/**
 * Pruebas del armado del calendario (hojas, meses, semanas, estados de cada día).
 *   php tests/unidad/calendario.php
 */

require __DIR__ . '/../lib.php';
spl_autoload_register(static function (string $c): void {
    $r = __DIR__ . '/../../src/' . str_replace(['ServicioSocial\\', '\\'], ['', '/'], $c) . '.php';
    if (is_file($r)) { require $r; }
});

use ServicioSocial\Dominio\Calculadora;
use ServicioSocial\Dominio\Calendario;

$exp = ['fecha_inicio' => '2026-06-23', 'fecha_fin_autorizada' => '2026-12-23', 'horas_requeridas' => 480,
    'meses_requeridos' => 6, 'jornada_prevista' => 4, 'feriados_acreditan' => 1, 'horas_por_feriado' => 4, 'estado' => 'activo'];
$reg = fn(string $f, string $e, int $h, array $extra = []) => $extra + ['fecha' => $f, 'estado' => $e,
    'horas_trabajadas' => $e === 'asistencia' ? $h : 0, 'horas_acreditadas' => $h, 'observaciones' => null, 'justificacion' => null, 'autorizado_por' => null];
$registros = [
    '2026-06-23' => $reg('2026-06-23', 'asistencia', 4),
    '2026-06-24' => $reg('2026-06-24', 'asistencia', 6, ['horas_trabajadas' => 6]),
    '2026-06-25' => $reg('2026-06-25', 'falta', 0),
    '2026-06-26' => $reg('2026-06-26', 'feriado', 4, ['observaciones' => 'Nota']),
    '2026-06-20' => $reg('2026-06-20', 'asistencia', 4, ['autorizado_por' => 1]), // sábado autorizado, antes del inicio
];
$hoy = new DateTimeImmutable('2026-06-29');
$inhabiles = ['2026-07-01' => 'festivo', '2026-07-02' => 'cierre'];
$hojas = Calendario::construir($exp, $registros, $inhabiles, ['2026-06-29', '2026-06-30', '2026-07-01'], ['2026-06-22' => true], $hoy);
$todas = [];
foreach ($hojas as $h) { foreach ($h['meses'] as $m) { foreach ($m['semanas'] as $s) { $todas[] = $s; } } }
$dia = function (string $f) use ($todas): array {
    foreach ($todas as $s) { foreach ($s['dias'] as $d) { if ($d['fecha'] === $f) { return $d; } } }
    throw new RuntimeException("no está $f");
};

seccion('Estructura');
prueba('empieza el lunes de la semana del primer registro', fn() => igual('2026-06-15', $todas[0]['lunes']->format('Y-m-d')));
prueba('hojas completas de 12 semanas en 3 meses de 4', function () use ($hojas, $todas) {
    igual(0, count($todas) % 12, 'múltiplo de 12');
    foreach ($hojas as $h) { igual(3, count($h['meses'])); foreach ($h['meses'] as $m) { igual(4, count($m['semanas'])); } }
});
prueba('cubre hasta el término autorizado', fn() => verdadero(end($todas)['viernes'] >= new DateTimeImmutable('2026-12-23'), 'cubre'));
prueba('cada semana de lunes a viernes', fn() => igual(['L', 'M', 'M', 'J', 'V'], array_column($todas[1]['dias'], 'letra')));

seccion('Estados de los días');
prueba('asistencia de la jornada prevista: ✓', fn() => igual(['a', '✓'], [$dia('2026-06-23')['codigo'], $dia('2026-06-23')['marca']]));
prueba('asistencia con otras horas: muestra las horas', fn() => igual('6h', $dia('2026-06-24')['marca']));
prueba('falta: ✗', fn() => igual(['f', '✗'], [$dia('2026-06-25')['codigo'], $dia('2026-06-25')['marca']]));
prueba('feriado: F con nota', function () use ($dia) {
    igual('h', $dia('2026-06-26')['codigo']);
    verdadero(in_array('con-nota', $dia('2026-06-26')['clases'], true), 'nota');
});
prueba('fuera del periodo marcado', fn() => verdadero(in_array('fuera', $dia('2026-06-22')['clases'], true), 'fuera'));
prueba('hoy y proyectados', function () use ($dia) {
    verdadero(in_array('hoy', $dia('2026-06-29')['clases'], true), 'hoy');
    verdadero(in_array('proyectado', $dia('2026-06-30')['clases'], true), 'proyectado');
    igual('·', $dia('2026-06-30')['marca']);
});
prueba('festivo y cierre del catálogo', function () use ($dia) {
    verdadero(in_array('festivo', $dia('2026-07-01')['clases'], true), 'festivo');
    igual('C', $dia('2026-07-02')['marca']);
});
prueba('etiqueta accesible con fecha y estado', fn() => contiene('martes 23 jun: asistencia, 4 h', $dia('2026-06-23')['etiqueta']));

seccion('Totales y firmas');
prueba('horas por semana = acreditadas de lunes a viernes', fn() => igual(14, $todas[1]['horas'])); // 4 + 6 + 0 + 4
prueba('el registro de sábado no se pinta pero no rompe', fn() => igual(0, $todas[0]['horas']));
prueba('firma de la semana', fn() => verdadero($todas[1]['firmada'], 'firmada'));
prueba('semana actual y firmable', function () use ($todas) {
    verdadero($todas[2]['actual'], 'actual');
    verdadero($todas[2]['firmable'], 'empezó');
    verdadero(!$todas[3]['firmable'], 'futura');
});
prueba('total de la hoja = suma de sus meses', function () use ($hojas) {
    igual(array_sum(array_column($hojas[0]['meses'], 'horas')), $hojas[0]['horas']);
});

seccion('Proyección para el calendario');
prueba('la Calculadora devuelve los días que proyecta', function () use ($exp) {
    $r = Calculadora::calcular($exp, [], ['2026-06-24' => 'festivo', '2026-06-25' => 'cierre'], new DateTimeImmutable('2026-06-23'));
    igual('2026-06-23', $r->diasProyectados[0]);
    verdadero(in_array('2026-06-24', $r->diasProyectados, true), 'festivo que acredita');
    verdadero(!in_array('2026-06-25', $r->diasProyectados, true), 'cierre fuera');
    igual(480, (count($r->diasProyectados)) * 4 - $r->excedente, 'cubre exactamente las horas');
});
prueba('sin horas pendientes no hay proyección', function () use ($exp) {
    $r = Calculadora::calcular(array_merge($exp, ['horas_requeridas' => 4]), [['fecha' => '2026-06-23', 'estado' => 'asistencia', 'horas_trabajadas' => 4, 'horas_acreditadas' => 4]],
        [], new DateTimeImmutable('2026-06-24'), ['horas' => 4, 'meses' => 6]);
    igual([], $r->diasProyectados);
});

resumen();
