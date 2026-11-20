<?php
/**
 * @var array $expediente @var array $alumno @var string $zona @var array $vecinos @var array $periodos
 * @var array $form ['datos' => ?array, 'errores' => array, 'general' => ?string]
 */
$x = $expediente;
$abierto = in_array($x['estado'], ['activo', 'suspendido'], true);
$datos = $form['datos'] ?? array_merge($x, [
    'hora_entrada' => $x['hora_entrada'] ? substr($x['hora_entrada'], 0, 5) : '',
    'hora_salida'  => $x['hora_salida'] ? substr($x['hora_salida'], 0, 5) : '',
]);
$errores = $form['errores'];
$v = fn(string $c) => e($datos[$c] ?? '');
$error = function (string $c) use ($errores): string {
    return isset($errores[$c]) ? '<p class="campo-error" id="error-' . e($c) . '">' . e($errores[$c]) . '</p>' : '';
};
$aria = fn(string $c) => isset($errores[$c]) ? ' aria-invalid="true" aria-describedby="error-' . e($c) . '"' : '';
$abrirForm = $errores !== [] || $form['general'] !== null;
$meses = (int)$x['meses_requeridos'];
?>
<nav class="navegacion-exp" aria-label="Navegación entre expedientes">
  <p class="miga"><a href="/">Expedientes</a> / <a href="/alumnos/<?= (int)$alumno['id'] ?>"><?= e(nombre_completo($alumno)) ?></a> / Expediente</p>
  <div class="vecinos">
    <?php if ($vecinos['anterior']): $a = $vecinos['anterior']; ?><a class="btn chico" href="/expedientes/<?= (int)$a['expediente_id'] ?>" rel="prev">← <?= e($a['apellido_paterno'] . ' ' . $a['nombre']) ?></a><?php endif; ?>
    <?php if ($vecinos['siguiente']): $s = $vecinos['siguiente']; ?><a class="btn chico" href="/expedientes/<?= (int)$s['expediente_id'] ?>" rel="next"><?= e($s['apellido_paterno'] . ' ' . $s['nombre']) ?> →</a><?php endif; ?>
  </div>
</nav>

<div class="encabezado">
  <div>
    <div class="eyebrow">Matrícula <?= e($alumno['matricula']) ?> · <?= e($alumno['carrera']) ?></div>
    <h1><?= e(nombre_completo($alumno)) ?></h1>
  </div>
  <?php if (count($periodos) > 1): ?>
  <div class="periodos" aria-label="Periodos del alumno">
    <?php foreach ($periodos as $per): ?>
      <a class="filtro<?= (int)$per['id'] === (int)$x['id'] ? ' activo' : '' ?>" href="/expedientes/<?= (int)$per['id'] ?>"<?= (int)$per['id'] === (int)$x['id'] ? ' aria-current="page"' : '' ?>><?= e(fecha_corta($per['fecha_inicio'])) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($aviso)): ?><p class="aviso aviso-exito" role="status"><?= e($aviso) ?></p><?php endif; ?>
<?php if (!empty($falla)): ?><p class="aviso aviso-error" role="alert"><?= e($falla) ?></p><?php endif; ?>

<section class="panel" id="datos-bitacora" aria-labelledby="titulo-datos">
  <h2 id="titulo-datos">Datos de la bitácora y ajustes</h2>
  <dl class="datos">
    <div><dt>Unidad administrativa</dt><dd><?= e($x['unidad_administrativa']) ?></dd></div>
    <div><dt>Responsable</dt><dd><?= e($x['responsable']) ?></dd></div>
    <div><dt>Horario</dt><dd><?= $x['hora_entrada'] ? e(hora12($x['hora_entrada']) . ' – ' . hora12($x['hora_salida'])) : '—' ?></dd></div>
    <div><dt>Periodo autorizado</dt><dd><?= e(fecha_corta($x['fecha_inicio'])) ?> – <?= e(fecha_corta($x['fecha_fin_autorizada'])) ?></dd></div>
    <div><dt>Requisito</dt><dd><?= (int)$x['horas_requeridas'] ?> h y <?= $meses ?> meses</dd></div>
    <div><dt>Jornada prevista</dt><dd><?= (int)$x['jornada_prevista'] ?> h por día</dd></div>
    <div><dt>Feriados</dt><dd><?= (int)$x['feriados_acreditan'] === 1 ? 'Acreditan ' . (int)$x['horas_por_feriado'] . ' h' : 'No acreditan horas' ?></dd></div>
    <?php if ($x['observaciones']): ?><div class="ancho"><dt>Observaciones</dt><dd><?= nl2br(e($x['observaciones'])) ?></dd></div><?php endif; ?>
  </dl>

  <?php if ($abierto): ?>
  <details class="editar-datos"<?= $abrirForm ? ' open' : '' ?>>
    <summary>Editar datos y ajustes</summary>
    <?php if ($form['general']): ?><p class="aviso aviso-error" role="alert"><?= e($form['general']) ?></p><?php elseif ($errores): ?><p class="aviso aviso-error" role="alert">Revisa los campos marcados.</p><?php endif; ?>
    <form method="post" action="/expedientes/<?= (int)$x['id'] ?>/datos" class="ficha-form" novalidate>
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="version" value="<?= (int)$x['version'] ?>">
      <?php require __DIR__ . '/_campos.php'; ?>
      <div class="rejilla">
        <div class="campo ancho casilla"><input id="aplicar_feriados" name="aplicar_feriados" type="checkbox" value="1"<?= !empty($datos['aplicar_feriados']) ? ' checked' : '' ?>><label for="aplicar_feriados">Si cambia la regla de feriados, aplicarla también a los feriados del catálogo ya registrados</label></div>
        <p class="pista ancho">Por omisión el cambio solo vale para los feriados que se registren después (pendiente de definir por la coordinación).</p>
        <?php if (isset($errores['autorizar_fuera'])): ?>
        <div class="campo ancho casilla"><input id="autorizar_fuera" name="autorizar_fuera" type="checkbox" value="1"><label for="autorizar_fuera">Autorizo los registros que quedan fuera del nuevo periodo</label></div>
        <?= $error('autorizar_fuera') ?>
        <?php endif; ?>
        <div class="campo ancho"><label for="motivo">Motivo del cambio <span class="opcional">(queda en el historial)</span></label><input id="motivo" name="motivo" value="<?= $v('motivo') ?>" maxlength="255" required<?= $aria('motivo') ?>><?= $error('motivo') ?></div>
      </div>
      <div class="acciones"><button class="btn primario" type="submit">Guardar datos</button></div>
    </form>
  </details>
  <?php else: ?>
  <?php if ($form['general']): ?><p class="aviso aviso-error" role="alert"><?= e($form['general']) ?></p><?php endif; ?>
  <p class="nota">El expediente está <?= e($x['estado'] === 'baja' ? 'de baja' : 'concluido') ?>: sus datos no se modifican hasta reabrirlo.</p>
  <?php endif; ?>
</section>

<div id="zona-calendario" data-expediente="<?= (int)$x['id'] ?>">
<?= $zona ?>
</div>

<dialog id="dialogo-dia" class="dialogo" aria-labelledby="dialogo-titulo">
  <div id="dialogo-contenido"></div>
</dialog>
