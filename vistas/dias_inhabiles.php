<?php
/** @var array $dias @var array $datos @var array $errores */
use ServicioSocial\Servicios\CatalogoServicio;

$v = fn(string $c) => e($datos[$c] ?? '');
$error = function (string $c) use ($errores): string {
    return isset($errores[$c]) ? '<p class="campo-error" id="error-' . e($c) . '">' . e($errores[$c]) . '</p>' : '';
};
$aria = fn(string $c) => isset($errores[$c]) ? ' aria-invalid="true" aria-describedby="error-' . e($c) . '"' : '';
?>
<p class="miga"><a href="/">Expedientes</a> / Días inhábiles</p>
<h1>Días inhábiles</h1>
<p class="nota">Catálogo general para todos los expedientes. Los <b>festivos</b> se registran como feriado (y acreditan horas según la regla de cada expediente). En los <b>cierres</b> la oficina no abre: no se proyectan y registrar ese día pide autorización.</p>
<?php if (!empty($aviso)): ?><p class="aviso aviso-exito" role="status"><?= e($aviso) ?></p><?php endif; ?>
<?php if (!empty($falla)): ?><p class="aviso aviso-error" role="alert"><?= e($falla) ?></p><?php endif; ?>

<form method="post" action="/dias-inhabiles" class="panel ficha-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <h2>Agregar un día</h2>
  <div class="rejilla">
    <div class="campo"><label for="fecha">Fecha</label><input id="fecha" name="fecha" type="date" value="<?= $v('fecha') ?>" required<?= $aria('fecha') ?>><?= $error('fecha') ?></div>
    <div class="campo"><label for="tipo">Tipo</label>
      <select id="tipo" name="tipo"<?= $aria('tipo') ?>>
        <?php foreach (CatalogoServicio::TIPOS as $clave => $texto): ?><option value="<?= e($clave) ?>"<?= ($datos['tipo'] ?? '') === $clave ? ' selected' : '' ?>><?= e($texto) ?></option><?php endforeach; ?>
      </select><?= $error('tipo') ?></div>
    <div class="campo ancho"><label for="descripcion">Descripción</label><input id="descripcion" name="descripcion" value="<?= $v('descripcion') ?>" maxlength="150" required placeholder="Por ejemplo: Día de la Revolución"<?= $aria('descripcion') ?>><?= $error('descripcion') ?></div>
  </div>
  <div class="acciones"><button class="btn primario" type="submit">Agregar</button></div>
</form>

<section class="panel">
  <h2>Catálogo</h2>
  <?php if (!$dias): ?>
    <p class="nota">Todavía no hay días en el catálogo.</p>
  <?php else: ?>
  <div class="tabla-contenedor">
    <table class="tabla">
      <thead><tr><th scope="col">Fecha</th><th scope="col">Tipo</th><th scope="col">Descripción</th><th scope="col" class="num">Usos</th><th scope="col"><span class="oculto">Acciones</span></th></tr></thead>
      <tbody>
      <?php foreach ($dias as $d): ?>
        <tr>
          <td><?= e(fecha_corta($d['fecha'])) ?></td>
          <td><span class="pill <?= $d['tipo'] === 'festivo' ? 'pill-info' : 'pill-neutro' ?>"><?= e(CatalogoServicio::TIPOS[$d['tipo']] ?? $d['tipo']) ?></span></td>
          <td class="texto"><?= e($d['descripcion']) ?></td>
          <td class="num"><?= (int)$d['usos'] ?></td>
          <td>
            <?php if ((int)$d['usos'] === 0): ?>
            <form method="post" action="/dias-inhabiles/<?= (int)$d['id'] ?>/quitar">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <button class="btn chico" type="submit">Quitar</button>
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
