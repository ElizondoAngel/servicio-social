<?php
/** @var array $h @var array $antes @var array $despues @var array $campos */
use ServicioSocial\Controladores\HistorialControlador;

$mostrar = function ($v): string {
    if ($v === null) { return '—'; }
    if (is_array($v)) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    if (is_bool($v)) { return $v ? 'sí' : 'no'; }
    return (string)$v;
};
?>
<p class="miga"><a href="/">Expedientes</a> / <a href="/historial">Historial</a> / Movimiento <?= (int)$h['id'] ?></p>
<h1>Movimiento <?= (int)$h['id'] ?></h1>
<section class="panel">
  <dl class="datos">
    <div><dt>Fecha y hora</dt><dd><?= e(fecha_corta(substr($h['creado_en'], 0, 10)) . ' ' . hora12(substr($h['creado_en'], 11, 5)) . substr($h['creado_en'], 16, 3)) ?></dd></div>
    <div><dt>Quién</dt><dd><?= e($h['usuario'] ? $h['usuario'] . ' (' . $h['usuario_correo'] . ')' : 'Sin usuario de la aplicación') ?></dd></div>
    <div><dt>Usuario de la base</dt><dd><?= e($h['usuario_bd'] ?? '—') ?></dd></div>
    <div><dt>Acción</dt><dd><?= e(str_replace('_', ' ', $h['accion'])) ?> · <?= e(HistorialControlador::ENTIDADES[$h['entidad']] ?? $h['entidad']) ?><?= $h['entidad_id'] ? ' ' . (int)$h['entidad_id'] : '' ?></dd></div>
    <div><dt>Origen</dt><dd><?= $h['origen'] === 'trigger' ? 'Cambio de datos (registrado por la base)' : 'Evento de la aplicación' ?></dd></div>
    <?php if ($h['expediente_id']): ?><div><dt>Expediente</dt><dd><a href="/expedientes/<?= (int)$h['expediente_id'] ?>">Ver expediente <?= (int)$h['expediente_id'] ?></a></dd></div><?php endif; ?>
    <div class="ancho"><dt>Motivo</dt><dd><?= e($h['motivo'] ?? '—') ?></dd></div>
    <div><dt>IP</dt><dd><?= e($h['ip'] ?? '—') ?></dd></div>
    <div class="ancho"><dt>Navegador</dt><dd><?= e($h['agente'] ?? '—') ?></dd></div>
    <div class="ancho"><dt>Sello</dt><dd><?= $h['hash'] ? '<code class="hash">' . e($h['hash']) . '</code> · ' . e(fecha_corta(substr($h['sellado_en'], 0, 10))) : 'Pendiente de sellar' ?></dd></div>
  </dl>
</section>
<?php if ($campos): ?>
<section class="panel">
  <h2>Datos</h2>
  <div class="tabla-contenedor">
    <table class="tabla comparacion">
      <thead><tr><th scope="col">Campo</th><?php if ($antes): ?><th scope="col">Antes</th><?php endif; ?><?php if ($despues): ?><th scope="col">Después</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($campos as $c): $cambio = $antes && $despues && ($antes[$c] ?? null) !== ($despues[$c] ?? null); ?>
        <tr<?= $cambio ? ' class="cambio"' : '' ?>>
          <th scope="row"><?= e(str_replace('_', ' ', $c)) ?></th>
          <?php if ($antes): ?><td class="texto"><?= e($mostrar($antes[$c] ?? null)) ?></td><?php endif; ?>
          <?php if ($despues): ?><td class="texto"><?= e($mostrar($despues[$c] ?? null)) ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($antes && $despues): ?><p class="pista">Las filas resaltadas cambiaron.</p><?php endif; ?>
</section>
<?php endif; ?>
