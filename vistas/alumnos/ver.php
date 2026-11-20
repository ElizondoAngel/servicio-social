<?php
/** @var array $alumno @var array $expedientes */
$tonos = ['ok' => 'pill-ok', 'info' => 'pill-info', 'aviso' => 'pill-aviso', 'mal' => 'pill-mal', 'neutro' => 'pill-neutro'];
?>
<p class="miga"><a href="/">Expedientes</a> / <?= e(nombre_completo($alumno)) ?></p>
<div class="encabezado">
  <div><div class="eyebrow">Matrícula <?= e($alumno['matricula']) ?></div><h1><?= e(nombre_completo($alumno)) ?></h1></div>
  <a class="btn" href="/alumnos/<?= (int)$alumno['id'] ?>/editar">Editar datos</a>
</div>
<?php if (!empty($aviso)): ?><p class="aviso aviso-exito" role="status"><?= e($aviso) ?></p><?php endif; ?>

<section class="panel">
  <dl class="datos">
    <div><dt>Carrera</dt><dd><?= e($alumno['carrera']) ?></dd></div>
    <div><dt>Institución</dt><dd><?= e($alumno['institucion']) ?></dd></div>
    <div><dt>Correo</dt><dd><?= e($alumno['correo'] ?: '—') ?></dd></div>
    <div><dt>Teléfono</dt><dd><?= e($alumno['telefono'] ?: '—') ?></dd></div>
  </dl>
</section>

<h2>Expedientes</h2>
<div class="tarjetas">
<?php foreach ($expedientes as $x): $r = $x['resumen']; ?>
  <article class="panel tarjeta-expediente">
    <div class="fila-entre">
      <h3><?= e(fecha_corta($x['fecha_inicio'])) ?> – <?= e(fecha_corta($x['fecha_fin_autorizada'])) ?></h3>
      <span class="pill <?= e($tonos[$r->tono()]) ?>"><?= e($r->etiqueta()) ?></span>
    </div>
    <p class="nota"><?= e($x['unidad_administrativa']) ?> · <?= e($x['responsable']) ?></p>
    <div class="avance grande"><progress class="barra-mini" value="<?= e($r->avance) ?>" max="100"><?= e($r->avance) ?> %</progress><span><?= (int)$r->acreditadas ?> / <?= (int)$r->horasRequeridas ?> h</span></div>
    <a class="btn" href="/expedientes/<?= (int)$x['id'] ?>">Ver expediente</a>
  </article>
<?php endforeach; ?>
</div>
