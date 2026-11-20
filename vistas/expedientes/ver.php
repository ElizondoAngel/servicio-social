<?php
/** @var array $expediente @var array $alumno @var \ServicioSocial\Dominio\Resumen $resumen */
$r = $resumen;
$tonos = ['ok' => 'pill-ok', 'info' => 'pill-info', 'aviso' => 'pill-aviso', 'mal' => 'pill-mal', 'neutro' => 'pill-neutro'];
?>
<p class="miga"><a href="/">Expedientes</a> / <a href="/alumnos/<?= (int)$alumno['id'] ?>"><?= e(nombre_completo($alumno)) ?></a> / Expediente</p>
<div class="encabezado">
  <div><div class="eyebrow">Matrícula <?= e($alumno['matricula']) ?> · <?= e($alumno['carrera']) ?></div><h1><?= e(nombre_completo($alumno)) ?></h1></div>
  <span class="pill grande <?= e($tonos[$r->tono()]) ?>"><?= e($r->etiqueta()) ?></span>
</div>

<section class="panel">
  <h2>Datos de la bitácora</h2>
  <dl class="datos">
    <div><dt>Unidad administrativa</dt><dd><?= e($expediente['unidad_administrativa']) ?></dd></div>
    <div><dt>Responsable</dt><dd><?= e($expediente['responsable']) ?></dd></div>
    <div><dt>Horario</dt><dd><?= $expediente['hora_entrada'] ? e(hora12($expediente['hora_entrada']) . ' – ' . hora12($expediente['hora_salida'])) : '—' ?></dd></div>
    <div><dt>Periodo autorizado</dt><dd><?= e(fecha_corta($expediente['fecha_inicio'])) ?> – <?= e(fecha_corta($expediente['fecha_fin_autorizada'])) ?></dd></div>
    <div><dt>Jornada prevista</dt><dd><?= (int)$expediente['jornada_prevista'] ?> h por día</dd></div>
    <div><dt>Feriados</dt><dd><?= (int)$expediente['feriados_acreditan'] === 1 ? 'Acreditan ' . (int)$expediente['horas_por_feriado'] . ' h' : 'No acreditan horas' ?></dd></div>
  </dl>
</section>

<section class="estadisticas seis" aria-label="Avance">
  <div class="estadistica ancha">
    <span class="eyebrow">Horas acreditadas</span>
    <span class="cifra"><?= (int)$r->acreditadas ?> <small>/ <?= (int)$r->horasRequeridas ?> h</small></span>
    <progress class="barra-mini grande" value="<?= e($r->avance) ?>" max="100"><?= e($r->avance) ?> %</progress>
    <span class="pista"><?= e(number_format($r->avance, 1)) ?> % · <?= (int)$r->trabajadas ?> h trabajadas</span>
  </div>
  <div class="estadistica"><span class="eyebrow">Restantes</span><span class="cifra"><?= (int)$r->restantes ?> <small>h</small></span><span class="pista"><?= (int)$r->diasHabilesRestantes ?> días de <?= (int)$expediente['jornada_prevista'] ?> h</span></div>
  <div class="estadistica"><span class="eyebrow">Fecha estimada</span><span class="cifra chica"><?= $r->restantes === 0 ? e(fecha_corta($r->fechaHorasCumplidas)) : e(fecha_corta($r->fechaEstimada)) ?></span><span class="pista"><?= $r->restantes === 0 ? 'Horas completas' : 'Yendo de lunes a viernes' ?></span></div>
  <div class="estadistica"><span class="eyebrow"><?= (int)$r->mesesRequeridos ?> meses</span><span class="cifra chica"><?= e(fecha_corta($r->fechaPeriodo)) ?></span><span class="pista"><?= $r->periodoCumplido ? 'Periodo cumplido' : 'Periodo en curso' ?></span></div>
  <div class="estadistica"><span class="eyebrow">Faltas</span><span class="cifra"><?= (int)$r->faltas ?></span></div>
</section>

<?php if ($r->feriadosPorConfirmar): ?>
  <p class="aviso aviso-aviso">Hay <?= count($r->feriadosPorConfirmar) ?> feriado(s) del catálogo sin registrar: <?= e(implode(', ', array_map('fecha_corta', $r->feriadosPorConfirmar))) ?>.</p>
<?php endif; ?>
<?php if ($r->rebasaAutorizada): ?>
  <p class="aviso aviso-aviso">Con la jornada prevista, las horas se completarían después del término autorizado (<?= e(fecha_corta($expediente['fecha_fin_autorizada'])) ?>).</p>
<?php endif; ?>
<?php if (!$r->puedeConcluir && $r->motivoNoConcluir): ?>
  <p class="aviso aviso-info">Todavía no se puede concluir: <?= e($r->motivoNoConcluir) ?></p>
<?php endif; ?>

<section class="panel proximamente">
  <h2>Calendario de asistencia</h2>
  <p class="nota">El calendario semanal para registrar asistencias, horas, faltas y feriados llega en la Etapa 5.</p>
</section>
