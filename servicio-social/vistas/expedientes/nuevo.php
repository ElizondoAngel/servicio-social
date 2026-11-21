<?php
/** @var array $alumno @var ?array $abierto @var array $datos @var array $errores @var ?string $general @var array $jornadas */
$v = fn(string $c) => e($datos[$c] ?? '');
$error = function (string $c) use ($errores): string {
    return isset($errores[$c]) ? '<p class="campo-error" id="error-' . e($c) . '">' . e($errores[$c]) . '</p>' : '';
};
$aria = fn(string $c) => isset($errores[$c]) ? ' aria-invalid="true" aria-describedby="error-' . e($c) . '"' : '';
?>
<p class="miga"><a href="/">Expedientes</a> / <a href="/alumnos/<?= (int)$alumno['id'] ?>"><?= e(nombre_completo($alumno)) ?></a> / Nuevo periodo</p>
<h1>Abrir un nuevo periodo</h1>
<?php if ($abierto): ?>
  <p class="aviso aviso-aviso">El alumno ya tiene un expediente abierto (desde el <?= e(fecha_corta($abierto['fecha_inicio'])) ?>). Concluye o da de baja ese expediente antes de abrir otro.</p>
  <p><a class="btn" href="/expedientes/<?= (int)$abierto['id'] ?>">Ir al expediente abierto</a></p>
<?php else: ?>
  <?php if ($general): ?><p class="aviso aviso-error" role="alert"><?= e($general) ?></p><?php elseif ($errores): ?><p class="aviso aviso-error" role="alert">Revisa los campos marcados.</p><?php endif; ?>
  <form method="post" action="/alumnos/<?= (int)$alumno['id'] ?>/expedientes/nuevo" class="ficha-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <fieldset class="panel">
      <legend>Expediente de servicio social</legend>
      <p class="nota">Las horas requeridas y el periodo mínimo se toman de la configuración general. Los datos de la unidad se copian del periodo anterior.</p>
      <?php require __DIR__ . '/_campos.php'; ?>
    </fieldset>
    <div class="acciones">
      <button class="btn primario" type="submit">Abrir periodo</button>
      <a class="btn" href="/alumnos/<?= (int)$alumno['id'] ?>">Cancelar</a>
    </div>
  </form>
<?php endif; ?>
