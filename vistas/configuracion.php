<?php
/** @var array $valores clave => fila @var ?array $datos @var array $errores */
use ServicioSocial\Servicios\ConfiguracionServicio;

$valor = fn(string $c) => e($datos[$c] ?? ($valores[$c]['valor'] ?? ''));
$error = function (string $c) use ($errores): string {
    return isset($errores[$c]) ? '<p class="campo-error" id="error-' . e($c) . '">' . e($errores[$c]) . '</p>' : '';
};
$aria = fn(string $c) => isset($errores[$c]) ? ' aria-invalid="true" aria-describedby="error-' . e($c) . '"' : '';
?>
<p class="miga"><a href="/">Expedientes</a> / Configuración</p>
<h1>Configuración</h1>
<?php if (!empty($aviso)): ?><p class="aviso aviso-exito" role="status"><?= e($aviso) ?></p><?php endif; ?>
<?php if ($errores): ?><p class="aviso aviso-error" role="alert">Revisa los campos marcados.</p><?php endif; ?>

<form method="post" action="/configuracion" class="ficha-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <fieldset class="panel">
    <legend>Operación y seguridad</legend>
    <div class="rejilla">
      <?php foreach (ConfiguracionServicio::EDITABLES as $clave => [$etiqueta, $unidad, $min, $max]): ?>
      <div class="campo"><label for="<?= e($clave) ?>"><?= e($etiqueta) ?> <span class="opcional">(<?= e($unidad) ?>, <?= (int)$min ?>–<?= (int)$max ?>)</span></label>
        <input id="<?= e($clave) ?>" name="<?= e($clave) ?>" type="number" inputmode="numeric" min="<?= (int)$min ?>" max="<?= (int)$max ?>" value="<?= $valor($clave) ?>" required<?= $aria($clave) ?>>
        <?php if (!empty($valores[$clave]['actualizado_por_nombre'])): ?><p class="pista">Último cambio: <?= e($valores[$clave]['actualizado_por_nombre']) ?>, <?= e(fecha_corta(substr($valores[$clave]['actualizado_en'], 0, 10))) ?></p><?php endif; ?>
        <?= $error($clave) ?></div>
      <?php endforeach; ?>
      <div class="campo ancho"><label for="motivo">Motivo del cambio <span class="opcional">(queda en el historial)</span></label><input id="motivo" name="motivo" value="<?= e($datos['motivo'] ?? '') ?>" maxlength="255" required<?= $aria('motivo') ?>><?= $error('motivo') ?></div>
    </div>
  </fieldset>
  <div class="acciones"><button class="btn primario" type="submit">Guardar configuración</button></div>
</form>

<section class="panel">
  <h2>Reglas del servicio social</h2>
  <p class="nota">Estas reglas están pendientes de autorización de la coordinación (Lic. César Leal Guerrero). Se muestran para consulta y no se cambian desde aquí.</p>
  <dl class="datos">
    <?php foreach (ConfiguracionServicio::PENDIENTES as $clave => $etiqueta): ?>
      <div><dt><?= e($etiqueta) ?></dt><dd><?= e($valores[$clave]['valor'] ?? '—') ?></dd></div>
    <?php endforeach; ?>
  </dl>
</section>
