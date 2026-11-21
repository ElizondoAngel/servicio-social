<?php
declare(strict_types=1);

/**
 * Pruebas de la Calculadora: casos A–I del diseño y los de la auditoría.
 *   php tests/unidad/calculadora.php
 */

require __DIR__ . '/../lib.php';
spl_autoload_register(static function (string $c): void {
    $r = __DIR__ . '/../../src/' . str_replace(['ServicioSocial\\', '\\'], ['', '/'], $c) . '.php';
    if (is_file($r)) { require $r; }
});

use ServicioSocial\Dominio\Calculadora;
use ServicioSocial\Dominio\Resumen;

function expediente(array $cambios = []): array
{
    return $cambios + [
        'fecha_inicio' => '2026-06-23', 'fecha_fin_autorizada' => '2026-12-23',
        'horas_requeridas' => 480, 'meses_requeridos' => 6, 'jornada_prevista' => 4,
        'feriados_acreditan' => 1, 'horas_por_feriado' => 4, 'estado' => 'activo',
    ];
}

/** Asistencias de lunes a viernes entre dos fechas, con excepciones por fecha. */
function dias(string $desde, string $hasta, int $horas = 4, array $especiales = []): array
{
    $filas = [];
    for ($d = new DateTimeImmutable($desde); $d <= new DateTimeImmutable($hasta); $d = $d->modify('+1 day')) {
        if ((int)$d->format('N') > 5) { continue; }
        $f = $d->format('Y-m-d');
        $filas[] = $especiales[$f] ?? ['fecha' => $f, 'estado' => 'asistencia', 'horas_trabajadas' => $horas, 'horas_acreditadas' => $horas];
    }
    return $filas;
}

function falta(string $f): array { return ['fecha' => $f, 'estado' => 'falta', 'horas_trabajadas' => 0, 'horas_acreditadas' => 0]; }
function feriado(string $f, int $h = 4): array { return ['fecha' => $f, 'estado' => 'feriado', 'horas_trabajadas' => 0, 'horas_acreditadas' => $h]; }
function calc(array $exp, array $reg, string $hoy, array $inhabiles = [], int $umbral = 40): Resumen
{
    return Calculadora::calcular($exp, $reg, $inhabiles, new DateTimeImmutable($hoy), ['horas' => 480, 'meses' => 6], $umbral);
}
function fecha(?DateTimeImmutable $d): ?string { return $d?->format('Y-m-d'); }

// Escenario base A: el caso real que se calculó a mano
$faltas = ['2026-06-25', '2026-08-06', '2026-09-04', '2026-10-02', '2026-10-05'];
$feriados = ['2026-09-16', '2026-11-02', '2026-11-16'];
$esp = [];
foreach ($faltas as $f) { $esp[$f] = falta($f); }
foreach ($feriados as $f) { $esp[$f] = feriado($f); }
$baseA = dias('2026-06-23', '2026-11-20', 4, $esp);

seccion('Casos del diseño');
prueba('A. feriados que acreditan: 416 h, 64 restantes, 16 días, 14-dic', function () use ($baseA) {
    $r = calc(expediente(), $baseA, '2026-11-23');
    igual(416, $r->acreditadas, 'acreditadas');
    igual(404, $r->trabajadas, 'trabajadas');
    igual(64, $r->restantes, 'restantes');
    igual(5, $r->faltas, 'faltas');
    igual(16, $r->diasHabilesRestantes, 'días');
    igual('2026-12-14', fecha($r->fechaEstimada), 'fecha estimada');
    igual('2026-12-23', fecha($r->fechaPeriodo), 'fecha de los 6 meses');
});
prueba('B. mismo caso sin acreditar feriados: 404 h, 19 días, 17-dic', function () use ($faltas, $feriados) {
    $esp = [];
    foreach ($faltas as $f) { $esp[$f] = falta($f); }
    foreach ($feriados as $f) { $esp[$f] = feriado($f, 0); }
    $r = calc(expediente(['feriados_acreditan' => 0]), dias('2026-06-23', '2026-11-20', 4, $esp), '2026-11-23', ['2026-12-25' => 'festivo']);
    igual(404, $r->acreditadas, 'acreditadas');
    igual(404, $r->trabajadas, 'trabajadas');
    igual(76, $r->restantes, 'restantes');
    igual(19, $r->diasHabilesRestantes, 'días');
    igual('2026-12-17', fecha($r->fechaEstimada), 'fecha estimada');
});
prueba('C. jornadas variables: 10 de 6 h y 5 de 4 h = 80 h', function () {
    $reg = array_merge(dias('2026-06-23', '2026-07-06', 6), dias('2026-07-07', '2026-07-13', 4));
    $r = calc(expediente(), $reg, '2026-07-14');
    igual(80, $r->acreditadas, 'acreditadas');
    igual(80, $r->trabajadas, 'trabajadas');
});
prueba('D. 480 h antes de los 6 meses: horas cumplidas, falta el periodo', function () {
    $r = calc(expediente(['fecha_inicio' => '2026-01-05', 'fecha_fin_autorizada' => '2026-07-05', 'jornada_prevista' => 6]),
        dias('2026-01-05', '2026-04-24', 6), '2026-05-01');
    igual(480, $r->acreditadas, 'acreditadas');
    igual('2026-04-24', fecha($r->fechaHorasCumplidas), 'fecha de las 480 h');
    igual(Resumen::HORAS_CUMPLIDAS, $r->situacion, 'situación');
    igual('2026-07-05', fecha($r->fechaPeriodo), 'fecha de los 6 meses');
    verdadero(!$r->puedeConcluir, 'no puede concluir');
});
prueba('E. periodo vencido con 400 h: faltan 80', function () {
    $r = calc(expediente(['fecha_inicio' => '2026-01-05', 'fecha_fin_autorizada' => '2026-07-05']),
        array_slice(dias('2026-01-05', '2026-06-30'), 0, 100), '2026-07-06');
    igual(400, $r->acreditadas, 'acreditadas');
    igual(80, $r->restantes, 'restantes');
    igual(Resumen::PERIODO_VENCIDO, $r->situacion, 'situación');
});
prueba('F. inicio el 31 de agosto: los 6 meses se cumplen el 28 de febrero', fn() =>
    igual('2027-02-28', fecha(calc(expediente(['fecha_inicio' => '2026-08-31', 'fecha_fin_autorizada' => '2027-02-28']), [], '2026-09-01')->fechaPeriodo)));
prueba('G. cierre institucional que cruza el año: 10 días, 8-ene-2027', function () {
    $cierre = [];
    for ($d = new DateTimeImmutable('2026-12-21'); $d <= new DateTimeImmutable('2027-01-01'); $d = $d->modify('+1 day')) {
        $cierre[$d->format('Y-m-d')] = 'cierre';
    }
    $reg = array_slice(dias('2026-06-01', '2026-12-11'), 0, 110);
    $r = calc(expediente(['fecha_inicio' => '2026-06-01', 'fecha_fin_autorizada' => '2027-01-31']), $reg, '2026-12-14', $cierre);
    igual(40, $r->restantes, 'restantes');
    igual(10, $r->diasHabilesRestantes, 'días');
    igual('2027-01-08', fecha($r->fechaEstimada), 'fecha estimada');
});
prueba('H. una falta suma 0; un feriado suma 4 acreditadas y 0 trabajadas', function () {
    $r = calc(expediente(), [falta('2026-06-23'), feriado('2026-06-24')], '2026-06-25');
    igual(4, $r->acreditadas, 'acreditadas');
    igual(0, $r->trabajadas, 'trabajadas');
    igual(1, $r->faltas, 'faltas');
});
prueba('I. 480 h antes de los 6 meses no se pueden concluir, con el motivo', function () {
    $r = calc(expediente(), array_slice(dias('2026-06-23', '2026-12-31'), 0, 120), '2026-12-15');
    igual(480, $r->acreditadas, '480 h');
    verdadero(!$r->puedeConcluir, 'no puede concluir');
    contiene('23/12/2026', (string)$r->motivoNoConcluir, 'motivo');
});

seccion('Límites');
prueba('el mismo día de los 6 meses ya cuenta como cumplido', function () {
    igual(false, calc(expediente(), [], '2026-12-22')->periodoCumplido, 'un día antes');
    igual(true, calc(expediente(), [], '2026-12-23')->periodoCumplido, 'el mismo día');
});
prueba('480 h y periodo cumplido: listo para concluir', function () {
    $r = calc(expediente(), array_slice(dias('2026-06-23', '2026-12-31'), 0, 120), '2026-12-23');
    igual(Resumen::LISTO, $r->situacion, 'situación');
    verdadero($r->puedeConcluir, 'puede concluir');
});
prueba('479 h con periodo cumplido no se puede concluir', function () {
    $reg = array_slice(dias('2026-06-23', '2026-12-31'), 0, 119);
    $reg[] = ['fecha' => '2026-12-14', 'estado' => 'asistencia', 'horas_trabajadas' => 4, 'horas_acreditadas' => 3, 'justificacion' => 'x'];
    $r = calc(expediente(), $reg, '2026-12-23');
    igual(479, $r->acreditadas, 'acreditadas');
    igual(Resumen::PERIODO_VENCIDO, $r->situacion, 'situación');
    verdadero(!$r->puedeConcluir, 'no puede concluir');
});
prueba('484 h: el avance se queda en 100 %', function () {
    $r = calc(expediente(), array_slice(dias('2026-06-23', '2026-12-31'), 0, 121), '2026-12-23');
    igual(484, $r->acreditadas, 'acreditadas');
    igual(100.0, $r->avance, 'avance');
    igual(0, $r->restantes, 'restantes');
});
prueba('restantes 40 = próximo; 41 = en curso', function () {
    $reg = array_slice(dias('2026-06-23', '2026-12-01'), 0, 110);
    igual(Resumen::PROXIMO, calc(expediente(), $reg, '2026-12-02')->situacion, '40');
    $reg[0]['horas_acreditadas'] = 3; $reg[0]['justificacion'] = 'x';
    igual(Resumen::EN_CURSO, calc(expediente(), $reg, '2026-12-02')->situacion, '41');
});
prueba('el umbral es configurable', fn() =>
    igual(Resumen::PROXIMO, calc(expediente(), array_slice(dias('2026-06-23', '2026-12-01'), 0, 100), '2026-12-02', [], 80)->situacion));
prueba('jornada de 6 h con 64 restantes: 11 días y 2 h de excedente', function () {
    $r = calc(expediente(['jornada_prevista' => 6]), array_slice(dias('2026-06-23', '2026-12-01'), 0, 104), '2026-12-02');
    igual(64, $r->restantes, 'restantes');
    igual(11, $r->diasHabilesRestantes, 'días');
    igual(2, $r->excedente, 'excedente');
});
prueba('expediente suspendido: sin proyección', function () {
    $r = calc(expediente(['estado' => 'suspendido']), array_slice(dias('2026-06-23', '2026-12-01'), 0, 10), '2026-08-01');
    igual(null, $r->fechaEstimada, 'fecha');
    igual('suspendido', $r->situacion, 'situación');
});
prueba('expediente que no ha iniciado: proyecta desde la fecha de inicio', function () {
    $r = calc(expediente(['fecha_inicio' => '2026-06-29']), [], '2026-06-20');
    igual(Resumen::POR_INICIAR, $r->situacion, 'situación');
    igual(120, $r->diasHabilesRestantes, 'días');
});
prueba('inicio en sábado: el primer día proyectado es el lunes', function () {
    $r = calc(expediente(['fecha_inicio' => '2026-06-27', 'horas_requeridas' => 480]), [], '2026-06-27');
    igual(120, $r->diasHabilesRestantes, 'días');
    igual('2026-12-11', fecha($r->fechaEstimada), 'fecha');
});
prueba('hoy ya registrado no se cuenta dos veces', function () {
    $sin = calc(expediente(), dias('2026-06-23', '2026-06-30'), '2026-06-30');
    $con = calc(expediente(), dias('2026-06-23', '2026-06-29'), '2026-06-30');
    igual(fecha($con->fechaEstimada), fecha($sin->fechaEstimada), 'misma fecha');
});
prueba('festivo futuro: suma si acredita, se salta si no', function () {
    $reg = dias('2026-06-23', '2026-11-30');   // 115 días = 460 h, faltan 20
    $cat = ['2026-12-03' => 'festivo'];
    $si = calc(expediente(), $reg, '2026-12-01', $cat);
    igual('2026-12-07', fecha($si->fechaEstimada), 'acredita: fecha');
    igual(4, $si->diasHabilesRestantes, 'acredita: días de asistencia');
    $no = calc(expediente(['feriados_acreditan' => 0]), $reg, '2026-12-01', $cat);
    igual('2026-12-08', fecha($no->fechaEstimada), 'no acredita: fecha');
    igual(5, $no->diasHabilesRestantes, 'no acredita: días');
});
prueba('feriados del catálogo ya pasados sin registro quedan por confirmar', function () {
    $r = calc(expediente(), dias('2026-06-23', '2026-09-15'), '2026-09-20', ['2026-09-16' => 'festivo', '2026-06-01' => 'festivo', '2026-12-25' => 'festivo']);
    igual(['2026-09-16'], $r->feriadosPorConfirmar);
});
prueba('aviso si la proyección rebasa la fecha autorizada', function () {
    verdadero(calc(expediente(['fecha_fin_autorizada' => '2026-08-01']), [], '2026-07-01')->rebasaAutorizada, 'rebasa');
    verdadero(!calc(expediente(), [], '2026-06-23')->rebasaAutorizada, 'no rebasa');
});
prueba('horas o meses por debajo del mínimo general se suben al mínimo', function () {
    $r = calc(expediente(['horas_requeridas' => 100, 'meses_requeridos' => 1]), [], '2026-06-23');
    igual(480, $r->horasRequeridas, 'horas');
    igual(6, $r->mesesRequeridos, 'meses');
});

resumen();
