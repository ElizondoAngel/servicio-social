<?php
/** @var string $modo alta|edicion @var array $datos @var array $errores @var string $accion */
$v = fn(string $c) => e($datos[$c] ?? '');
$error = function (string $c) use ($errores): string {
    return isset($errores[$c]) ? '<p class="campo-error" id="error-' . e($c) . '">' . e($errores[$c]) . '</p>' : '';
};
$aria = fn(string $c) => isset($errores[$c]) ? ' aria-invalid="true" aria-describedby="error-' . e($c) . '"' : '';
?>
<p class="miga"><a href="/">Expedientes</a><?php if ($alumno): ?> / <a href="/alumnos/<?= (int)$alumno['id'] ?>"><?= e(nombre_completo($alumno)) ?></a><?php endif; ?></p>
<h1><?= $modo === 'alta' ? 'Registrar alumno' : 'Editar datos del alumno' ?></h1>
<?php if ($errores): ?><p class="aviso aviso-error" role="alert">Revisa los campos marcados.</p><?php endif; ?>

<form method="post" action="<?= e($accion) ?>" class="ficha-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

  <fieldset class="panel">
    <legend>Datos del alumno</legend>
    <div class="rejilla">
      <div class="campo"><label for="matricula">Matrícula</label><input id="matricula" name="matricula" value="<?= $v('matricula') ?>" maxlength="20" required autocomplete="off"<?= $aria('matricula') ?>><?= $error('matricula') ?></div>
      <div class="campo"><label for="nombre">Nombre(s)</label><input id="nombre" name="nombre" value="<?= $v('nombre') ?>" maxlength="80" required<?= $aria('nombre') ?>><?= $error('nombre') ?></div>
      <div class="campo"><label for="apellido_paterno">Apellido paterno</label><input id="apellido_paterno" name="apellido_paterno" value="<?= $v('apellido_paterno') ?>" maxlength="80" required<?= $aria('apellido_paterno') ?>><?= $error('apellido_paterno') ?></div>
      <div class="campo"><label for="apellido_materno">Apellido materno <span class="opcional">(opcional)</span></label><input id="apellido_materno" name="apellido_materno" value="<?= $v('apellido_materno') ?>" maxlength="80"<?= $aria('apellido_materno') ?>><?= $error('apellido_materno') ?></div>
      <div class="campo ancho"><label for="carrera">Carrera</label><input id="carrera" name="carrera" value="<?= $v('carrera') ?>" maxlength="150" required<?= $aria('carrera') ?>><?= $error('carrera') ?></div>
      <div class="campo ancho"><label for="institucion">Institución educativa</label><input id="institucion" name="institucion" value="<?= $v('institucion') ?>" maxlength="150" required<?= $aria('institucion') ?>><?= $error('institucion') ?></div>
      <div class="campo"><label for="correo">Correo <span class="opcional">(opcional)</span></label><input id="correo" name="correo" type="email" value="<?= $v('correo') ?>" maxlength="190"<?= $aria('correo') ?>><?= $error('correo') ?></div>
      <div class="campo"><label for="telefono">Teléfono <span class="opcional">(opcional)</span></label><input id="telefono" name="telefono" inputmode="tel" value="<?= $v('telefono') ?>" maxlength="15"<?= $aria('telefono') ?>><?= $error('telefono') ?></div>
    </div>
  </fieldset>

  <?php if ($modo === 'alta'): ?>
  <fieldset class="panel">
    <legend>Expediente de servicio social</legend>
    <p class="nota">Las horas requeridas (480) y el periodo mínimo (6 meses) se toman de la configuración general.</p>
    <div class="rejilla">
      <div class="campo ancho"><label for="unidad_administrativa">Unidad administrativa</label><input id="unidad_administrativa" name="unidad_administrativa" value="<?= $v('unidad_administrativa') ?>" maxlength="150" required<?= $aria('unidad_administrativa') ?>><?= $error('unidad_administrativa') ?></div>
      <div class="campo ancho"><label for="responsable">Responsable</label><input id="responsable" name="responsable" value="<?= $v('responsable') ?>" maxlength="150" required<?= $aria('responsable') ?>><?= $error('responsable') ?></div>
      <div class="campo"><label for="hora_entrada">Hora de entrada <span class="opcional">(opcional)</span></label><input id="hora_entrada" name="hora_entrada" type="time" value="<?= $v('hora_entrada') ?>"<?= $aria('hora_entrada') ?>><?= $error('hora_entrada') ?></div>
      <div class="campo"><label for="hora_salida">Hora de salida <span class="opcional">(opcional)</span></label><input id="hora_salida" name="hora_salida" type="time" value="<?= $v('hora_salida') ?>"<?= $aria('hora_salida') ?>><?= $error('hora_salida') ?></div>
      <div class="campo"><label for="fecha_inicio">Fecha de inicio</label><input id="fecha_inicio" name="fecha_inicio" type="date" value="<?= $v('fecha_inicio') ?>" required<?= $aria('fecha_inicio') ?>><?= $error('fecha_inicio') ?></div>
      <div class="campo"><label for="fecha_fin_autorizada">Término autorizado <span class="opcional">(opcional)</span></label><input id="fecha_fin_autorizada" name="fecha_fin_autorizada" type="date" value="<?= $v('fecha_fin_autorizada') ?>"<?= $aria('fecha_fin_autorizada') ?>><p class="pista">Si la dejas vacía: inicio + 6 meses.</p><?= $error('fecha_fin_autorizada') ?></div>
      <div class="campo"><label for="jornada_prevista">Jornada prevista</label>
        <select id="jornada_prevista" name="jornada_prevista"<?= $aria('jornada_prevista') ?>>
          <?php foreach ([4, 5, 6] as $h): ?><option value="<?= $h ?>"<?= (int)($datos['jornada_prevista'] ?? 4) === $h ? ' selected' : '' ?>><?= $h ?> horas</option><?php endforeach; ?>
        </select><p class="pista">Se usa para proyectar los días que faltan.</p><?= $error('jornada_prevista') ?></div>
      <div class="campo"><label for="horas_por_feriado">Horas por feriado</label>
        <select id="horas_por_feriado" name="horas_por_feriado"<?= $aria('horas_por_feriado') ?>>
          <?php foreach (range(0, 6) as $h): ?><option value="<?= $h ?>"<?= (int)($datos['horas_por_feriado'] ?? 4) === $h ? ' selected' : '' ?>><?= $h ?> horas</option><?php endforeach; ?>
        </select><?= $error('horas_por_feriado') ?></div>
      <div class="campo ancho casilla"><input id="feriados_acreditan" name="feriados_acreditan" type="checkbox" value="1"<?= !empty($datos['feriados_acreditan']) ? ' checked' : '' ?>><label for="feriados_acreditan">Los feriados acreditan horas en este expediente</label></div>
      <div class="campo ancho"><label for="observaciones">Observaciones <span class="opcional">(opcional)</span></label><textarea id="observaciones" name="observaciones" rows="3" maxlength="1000"><?= $v('observaciones') ?></textarea><?= $error('observaciones') ?></div>
    </div>
  </fieldset>
  <?php else: ?>
  <fieldset class="panel">
    <legend>Motivo del cambio</legend>
    <div class="campo"><label for="motivo">¿Por qué se corrigen los datos? <span class="opcional">(opcional, queda en el historial)</span></label><input id="motivo" name="motivo" value="<?= $v('motivo') ?>" maxlength="255"><?= $error('motivo') ?></div>
  </fieldset>
  <?php endif; ?>

  <div class="acciones">
    <button class="btn primario" type="submit"><?= $modo === 'alta' ? 'Registrar alumno y abrir expediente' : 'Guardar cambios' ?></button>
    <a class="btn" href="<?= $alumno ? '/alumnos/' . (int)$alumno['id'] : '/' ?>">Cancelar</a>
  </div>
</form>
