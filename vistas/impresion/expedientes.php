<?php
/**
 * Plantilla de impresión de expedientes (uno o varios). Cada expediente
 * empieza en una hoja nueva.
 * @var array $expedientes [['expediente','alumno','resumen','hojas','registros'], …]
 * @var int $total @var string $volver @var DateTimeImmutable $impreso
 */
use ServicioSocial\Dominio\Resumen;

$estados = ['activo' => 'Activo', 'suspendido' => 'Suspendido', 'concluido' => 'Concluido', 'baja' => 'Baja'];
$hora = fn(?string $h) => $h ? substr($h, 0, 5) : '—';
$rango = fn(DateTimeInterface $a, DateTimeInterface $b) => $a->format('j') . '/' . $a->format('n') . ' – ' . $b->format('j') . '/' . $b->format('n');
// Lo que se escribe en cada día de la bitácora impresa.
$celda = function (array $d): string {
    return match ($d['estado']) {
        'asistencia' => (string)$d['acreditadas'],
        'falta'      => 'X',
        'feriado'    => 'Fe' . ($d['acreditadas'] > 0 ? ' ' . $d['acreditadas'] : ''),
        default      => in_array('cierre', $d['clases'], true) ? 'C' : (in_array('fuera', $d['clases'], true) ? '·' : ''),
    };
};
?>
<div class="barra-impresion">
  <a class="btn" href="<?= e($volver) ?>">← Volver</a>
  <p><?= count($expedientes) ?> expediente(s)<?= $total > count($expedientes) ? ' de ' . (int)$total . ' (se imprimen como máximo ' . count($expedientes) . '; usa la búsqueda o el filtro para elegir los demás)' : '' ?>.
     Para guardarlo como PDF, elige «Guardar como PDF» en el diálogo de impresión.</p>
  <button type="button" class="btn primario js-imprimir" hidden>Imprimir</button>
</div>

<?php if ($expedientes === []): ?>
  <p class="vacio">No hay expedientes con esa búsqueda o filtro.</p>
<?php endif; ?>

<?php foreach ($expedientes as $item):
    $x = $item['expediente'];
    $a = $item['alumno'];
    /** @var Resumen $r */
    $r = $item['resumen'];
    $notas = array_filter($item['registros'], fn($g) => ($g['justificacion'] ?? '') !== '' || ($g['observaciones'] ?? '') !== ''
        || ($g['motivo_autorizacion'] ?? '') !== '');
    ksort($notas);
?>
<article class="documento">
  <header class="doc-encabezado">
    <div class="doc-sello" aria-hidden="true">SS</div>
    <div>
      <div class="doc-institucion">Universidad Tecnológica de Nezahualcóyotl</div>
      <h1>Expediente de servicio social</h1>
    </div>
    <div class="doc-fecha">Impreso el <?= e(fecha_corta($impreso)) ?><br>Expediente núm. <?= (int)$x['id'] ?></div>
  </header>

  <section class="bloque">
    <h2>Datos del alumno</h2>
    <dl class="datos">
      <div><dt>Nombre</dt><dd><?= e(nombre_completo($a)) ?></dd></div>
      <div><dt>Matrícula</dt><dd><?= e($a['matricula']) ?></dd></div>
      <div><dt>Carrera</dt><dd><?= e($a['carrera']) ?></dd></div>
      <div><dt>Institución</dt><dd><?= e($a['institucion'] ?? '—') ?: '—' ?></dd></div>
      <div><dt>Correo</dt><dd><?= e($a['correo'] ?? '') ?: '—' ?></dd></div>
      <div><dt>Teléfono</dt><dd><?= e($a['telefono'] ?? '') ?: '—' ?></dd></div>
    </dl>
  </section>

  <section class="bloque">
    <h2>Datos del periodo</h2>
    <dl class="datos">
      <div><dt>Unidad administrativa</dt><dd><?= e($x['unidad_administrativa']) ?></dd></div>
      <div><dt>Responsable</dt><dd><?= e($x['responsable']) ?></dd></div>
      <div><dt>Horario</dt><dd><?= e($hora($x['hora_entrada'])) ?> a <?= e($hora($x['hora_salida'])) ?> · <?= (int)$x['jornada_prevista'] ?> h por día</dd></div>
      <div><dt>Inicio</dt><dd><?= e(fecha_corta($x['fecha_inicio'])) ?></dd></div>
      <div><dt>Término autorizado</dt><dd><?= e(fecha_corta($x['fecha_fin_autorizada'])) ?></dd></div>
      <div><dt>Requisito</dt><dd><?= (int)$x['horas_requeridas'] ?> horas y <?= (int)$x['meses_requeridos'] ?> meses</dd></div>
      <div><dt>Estado</dt><dd><?= e($estados[$x['estado']] ?? $x['estado']) ?> · <?= e($r->etiqueta()) ?><?= $x['estado'] === 'concluido' ? ' el ' . e(fecha_corta($x['fecha_conclusion'])) : '' ?></dd></div>
    </dl>
  </section>

  <section class="bloque">
    <h2>Resumen de horas</h2>
    <table class="resumen">
      <thead><tr><th>Acreditadas</th><th>Trabajadas</th><th>Restantes</th><th>Avance</th><th>Faltas</th><th>Se cumplen los <?= (int)$r->mesesRequeridos ?> meses</th><th>Fecha estimada de horas</th></tr></thead>
      <tbody><tr>
        <td><b><?= (int)$r->acreditadas ?></b> de <?= (int)$r->horasRequeridas ?> h</td>
        <td><?= (int)$r->trabajadas ?> h</td>
        <td><?= (int)$r->restantes ?> h</td>
        <td><?= e(number_format($r->avance, 1)) ?> %</td>
        <td><?= (int)$r->faltas ?></td>
        <td><?= e(fecha_corta($r->fechaPeriodo)) ?></td>
        <td><?= $r->restantes === 0 ? 'Cumplidas el ' . e(fecha_corta($r->fechaHorasCumplidas)) : e(fecha_corta($r->fechaEstimada)) ?></td>
      </tr></tbody>
    </table>
  </section>

  <section class="bloque">
    <h2>Bitácora de asistencia</h2>
    <p class="leyenda">Número: horas acreditadas · X: falta · Fe: feriado (con sus horas) · C: cierre de oficina · ·: fuera del periodo autorizado · vacío: sin registro.</p>
    <?php foreach ($item['hojas'] as $hoja): ?>
    <table class="bitacora">
      <caption>Hoja <?= (int)$hoja['numero'] ?> · <?= e(fecha_corta($hoja['desde'])) ?> – <?= e(fecha_corta($hoja['hasta'])) ?> · <b><?= (int)$hoja['horas'] ?> h</b></caption>
      <thead><tr><th>Mes</th><th>Semana</th><th>Fechas</th><th>L</th><th>M</th><th>M</th><th>J</th><th>V</th><th>Horas</th><th>Firma</th></tr></thead>
      <tbody>
      <?php foreach ($hoja['meses'] as $mes): ?>
        <?php foreach ($mes['semanas'] as $i => $sem): ?>
        <tr>
          <?php if ($i === 0): ?><th rowspan="<?= count($mes['semanas']) ?>" class="mes">Mes <?= (int)$mes['numero'] ?><br><span><?= (int)$mes['horas'] ?> h</span></th><?php endif; ?>
          <td class="c"><?= (int)$sem['numero'] ?></td>
          <td class="fechas"><?= e($rango($sem['lunes'], $sem['viernes'])) ?></td>
          <?php foreach ($sem['dias'] as $d): ?><td class="c dia-<?= e($d['codigo'] ?: (in_array('cierre', $d['clases'], true) ? 'c' : 'v')) ?>"><?= e($celda($d)) ?></td><?php endforeach; ?>
          <td class="c"><b><?= (int)$sem['horas'] ?></b></td>
          <td class="c"><?= $sem['firmada'] ? 'Firmada' : '' ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endforeach; ?>
  </section>

  <?php if ($notas): ?>
  <section class="bloque">
    <h2>Notas de los registros</h2>
    <table class="notas">
      <thead><tr><th>Fecha</th><th>Registro</th><th>Nota</th></tr></thead>
      <tbody>
      <?php foreach ($notas as $fecha => $g): ?>
        <tr>
          <td><?= e(fecha_corta($fecha)) ?></td>
          <td><?= e(ucfirst((string)$g['estado'])) ?><?= $g['estado'] !== 'falta' ? ' · ' . (int)$g['horas_acreditadas'] . ' h' : '' ?></td>
          <td><?= e(implode(' · ', array_filter([
              ($g['justificacion'] ?? '') !== '' ? 'Justificación: ' . $g['justificacion'] : '',
              ($g['motivo_autorizacion'] ?? '') !== '' ? 'Autorizado: ' . $g['motivo_autorizacion'] : '',
              ($g['observaciones'] ?? '') !== '' ? 'Observaciones: ' . $g['observaciones'] : '',
          ]))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

  <?php if (trim((string)($x['observaciones'] ?? '')) !== ''): ?>
  <section class="bloque">
    <h2>Observaciones del expediente</h2>
    <p><?= nl2br(e($x['observaciones'])) ?></p>
  </section>
  <?php endif; ?>

  <section class="firmas">
    <div><span class="linea"></span><?= e(nombre_completo($a)) ?><br><small>Alumno</small></div>
    <div><span class="linea"></span><?= e($x['responsable']) ?><br><small>Responsable de la unidad administrativa</small></div>
    <div><span class="linea"></span>&nbsp;<br><small>Vo. Bo. Coordinación de Servicio Social</small></div>
  </section>
</article>
<?php endforeach; ?>
