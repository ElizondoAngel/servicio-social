<?php
/** @var array $origen @var array $cabecera @var array $plan @var array $errores @var string $motivo */
$tablas = ['alumnos' => 'Alumnos', 'expedientes' => 'Expedientes', 'asistencias' => 'Días de asistencia', 'firmas_semana' => 'Firmas',
    'dias_inhabiles' => 'Días inhábiles', 'usuarios' => 'Usuarios'];
$nuevos = array_sum($plan['nuevos']);
$error = fn(string $c) => isset($errores[$c]) ? '<p class="campo-error" id="error-' . e($c) . '">' . e($errores[$c]) . '</p>' : '';
$aria = fn(string $c) => isset($errores[$c]) ? ' aria-invalid="true" aria-describedby="error-' . e($c) . '"' : '';
?>
<p class="miga"><a href="/">Expedientes</a> / <a href="/respaldos">Respaldos</a> / Restaurar</p>
<h1>Restaurar: vista previa</h1>
<p class="nota">Archivo <code><?= e($origen['nombre']) ?></code>, creado el <?= e(fecha_corta(substr($cabecera['creado_en'], 0, 10)) . ' ' . hora12(substr($cabecera['creado_en'], 11, 5))) ?>. Firma y huella verificadas. Todavía no se ha cambiado nada.</p>
<?php if ($errores): ?><p class="aviso aviso-error" role="alert">Revisa los campos marcados.</p><?php endif; ?>

<section class="panel">
  <h2>Qué pasaría</h2>
  <div class="tabla-contenedor">
    <table class="tabla">
      <thead><tr><th scope="col">Qué</th><th scope="col" class="num">Se agregarían</th><th scope="col" class="num">Ya existen</th></tr></thead>
      <tbody>
      <?php foreach ($tablas as $t => $nombre): ?>
        <tr<?= $plan['nuevos'][$t] ? ' class="cambio"' : '' ?>><th scope="row"><?= e($nombre) ?></th><td class="num"><?= (int)$plan['nuevos'][$t] ?></td><td class="num"><?= (int)$plan['existentes'][$t] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($plan['nuevos']['usuarios']): ?><p class="pista">Los usuarios que no existen se crean desactivados y sin contraseña, solo para conservar quién registró cada dato.</p><?php endif; ?>
  <?php if ($plan['omitidos']): ?>
    <div class="aviso aviso-aviso"><p><b>No se podrá agregar:</b></p><ul><?php foreach ($plan['omitidos'] as $o): ?><li><?= e($o['que']) ?>: <?= e($o['porque']) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
</section>

<?php if ($nuevos === 0): ?>
  <p class="aviso aviso-info">No hay nada que agregar: todo lo del archivo ya existe.</p>
  <p><a class="btn" href="/respaldos">Volver</a></p>
<?php else: ?>
<form method="post" action="/respaldos/restaurar/confirmar" class="panel ficha-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <h2>Confirmar</h2>
  <div class="rejilla">
    <div class="campo ancho"><label for="motivo">Motivo de la restauración <span class="opcional">(queda en el historial)</span></label><input id="motivo" name="motivo" value="<?= e($motivo) ?>" maxlength="200" required<?= $aria('motivo') ?>><?= $error('motivo') ?></div>
    <div class="campo"><label for="password">Tu contraseña</label><input id="password" name="password" type="password" autocomplete="current-password" required<?= $aria('password') ?>><?= $error('password') ?></div>
    <div class="campo ancho casilla"><input id="confirmo" name="confirmo" type="checkbox" value="1"<?= $aria('confirmo') ?>><label for="confirmo">Entiendo que se agregarán <?= (int)$nuevos ?> registros y que antes se guardará un respaldo automático</label></div>
    <?= $error('confirmo') ?>
  </div>
  <div class="acciones"><button class="btn primario" type="submit">Restaurar</button><a class="btn" href="/respaldos">Cancelar</a></div>
</form>
<?php endif; ?>
