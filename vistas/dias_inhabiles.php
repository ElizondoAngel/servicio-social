<?php
/** @var array $grupos @var array $datos @var array $errores */
use ServicioSocial\Servicios\CatalogoServicio;

$v = fn(string $c) => e($datos[$c] ?? '');
$error = function (string $c) use ($errores): string {
    return isset($errores[$c]) ? '<p class="campo-error" id="error-' . e($c) . '">' . e($errores[$c]) . '</p>' : '';
};
$aria = fn(string $c) => isset($errores[$c]) ? ' aria-invalid="true" aria-describedby="error-' . e($c) . '"' : '';
?>
<p class="miga"><a href="/">Expedientes</a> / Días inhábiles</p>
<h1>Días inhábiles</h1>
<p class="nota">Catálogo general para todos los expedientes. Los <b>festivos</b> se registran como feriado (y acreditan horas según la regla de cada expediente). En los <b>cierres</b> la oficina no abre: no se proyectan y registrar ese día pide autorización. Los <b>periodos vacacionales</b> se registran como cierre, un día por cada día de lunes a viernes.</p>
<?php if (!empty($aviso)): ?><p class="aviso aviso-exito" role="status"><?= e($aviso) ?></p><?php endif; ?>
<?php if (!empty($falla)): ?><p class="aviso aviso-error" role="alert"><?= e($falla) ?></p><?php endif; ?>

<form method="post" action="/dias-inhabiles" class="panel ficha-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <h2>Agregar un día o un periodo</h2>
  <div class="campo casilla"><input id="periodo" class="js-periodo" type="checkbox" name="periodo" value="1"<?= ($datos['periodo'] ?? '') === '1' ? ' checked' : '' ?>><label for="periodo">Periodo vacacional (varios días seguidos, por ejemplo del 18 de diciembre al 5 de enero)</label></div>
  <div class="rejilla">
    <div class="campo"><label for="fecha"><?php $esPeriodo = ($datos['periodo'] ?? '') === '1'; ?><span class="js-etq-uno"<?= $esPeriodo ? ' hidden' : '' ?>>Fecha</span><span class="js-etq-periodo"<?= $esPeriodo ? '' : ' hidden' ?>>Fecha de inicio</span></label><input id="fecha" name="fecha" type="date" value="<?= $v('fecha') ?>" required<?= $aria('fecha') ?>><?= $error('fecha') ?></div>
    <div class="campo js-solo-periodo"><label for="fecha_fin">Fecha final <span class="pista">(solo para periodos)</span></label><input id="fecha_fin" name="fecha_fin" type="date" value="<?= $v('fecha_fin') ?>"<?= $aria('fecha_fin') ?>><?= $error('fecha_fin') ?></div>
    <div class="campo"><label for="tipo">Tipo</label>
      <select id="tipo" name="tipo"<?= $aria('tipo') ?>>
        <?php foreach (CatalogoServicio::TIPOS as $clave => $texto): ?><option value="<?= e($clave) ?>"<?= ($datos['tipo'] ?? '') === $clave ? ' selected' : '' ?>><?= e($texto) ?></option><?php endforeach; ?>
      </select><?= $error('tipo') ?></div>
    <div class="campo ancho"><label for="descripcion">Descripción</label><input id="descripcion" name="descripcion" value="<?= $v('descripcion') ?>" maxlength="150" required placeholder="Por ejemplo: Día de la Revolución o Vacaciones de invierno"<?= $aria('descripcion') ?>><?= $error('descripcion') ?></div>
  </div>
  <div class="acciones"><button class="btn primario" type="submit">Agregar</button></div>
</form>

<section class="panel">
  <h2>Catálogo</h2>
  <?php if (!$grupos): ?>
    <p class="nota">Todavía no hay días en el catálogo.</p>
  <?php else: ?>
  <div class="tabla-contenedor">
    <table class="tabla">
      <thead><tr><th scope="col">Fecha</th><th scope="col">Tipo</th><th scope="col">Descripción</th><th scope="col" class="num">Usos</th><th scope="col"><span class="oculto">Acciones</span></th></tr></thead>
      <tbody>
      <?php foreach ($grupos as $g): $varios = count($g['ids']) > 1; ?>
        <tr>
          <td><?= e(fecha_corta($g['desde'])) ?><?php if ($varios): ?> – <?= e(fecha_corta($g['hasta'])) ?><br><span class="pista"><?= count($g['ids']) ?> días hábiles</span><?php endif; ?></td>
          <td><span class="pill <?= $g['tipo'] === 'festivo' ? 'pill-info' : 'pill-neutro' ?>"><?= e($varios && $g['tipo'] === 'cierre' ? 'Periodo de cierre' : (CatalogoServicio::TIPOS[$g['tipo']] ?? $g['tipo'])) ?></span></td>
          <td class="texto"><?= e($g['descripcion']) ?></td>
          <td class="num"><?= (int)$g['usos'] ?></td>
          <td>
            <?php if ($g['usos'] === 0): ?>
            <form method="post" action="<?= $varios ? '/dias-inhabiles/periodo/quitar' : '/dias-inhabiles/' . (int)$g['ids'][0] . '/quitar' ?>">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <?php if ($varios): foreach ($g['ids'] as $idDia): ?><input type="hidden" name="ids[]" value="<?= (int)$idDia ?>"><?php endforeach; endif; ?>
              <button class="btn chico" type="submit"><?= $varios ? 'Quitar periodo' : 'Quitar' ?></button>
            </form>
            <?php else: ?><span class="pista">En uso</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
