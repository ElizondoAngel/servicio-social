<?php
/** @var array $filtros @var array $filas @var int $total @var int $pagina @var int $paginas @var array $opciones @var array $sello @var ?array $verificacion */
use ServicioSocial\Controladores\HistorialControlador;
use ServicioSocial\Dominio\SelloHistorial;

$entidades = HistorialControlador::ENTIDADES;
$pendientes = (int)$sello['total'] - (int)$sello['selladas'];
$consulta = fn(array $cambios) => '/historial?' . http_build_query(array_filter($cambios + $filtros, fn($v) => $v !== null && $v !== ''));
?>
<p class="miga"><a href="/">Expedientes</a> / Historial</p>
<div class="encabezado">
  <div><div class="eyebrow">Todo cambio y evento del sistema</div><h1>Historial</h1></div>
</div>

<section class="panel sello" aria-labelledby="titulo-sello">
  <div class="fila-entre">
    <h2 id="titulo-sello">Sellado del historial</h2>
    <form method="post" action="/historial/verificar"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn" type="submit">Verificar la cadena ahora</button></form>
  </div>
  <p class="nota"><?= (int)$sello['selladas'] ?> de <?= (int)$sello['total'] ?> movimientos sellados.
    <?= $sello['ultimo_sellado'] ? 'Último sellado: ' . e(fecha_corta(substr($sello['ultimo_sellado'], 0, 10)) . ' ' . hora12(substr($sello['ultimo_sellado'], 11, 5))) . '.' : 'Todavía no se ha sellado.' ?>
    <?= $pendientes > 0 ? $pendientes . ' pendientes de sellar (el sellado corre cada noche).' : '' ?></p>
  <?php if ($verificacion): ?>
    <?php if ($verificacion['ok']): ?>
      <p class="aviso aviso-exito" role="status">Cadena íntegra: se revisaron <?= (int)$verificacion['revisadas'] ?> movimientos (<?= (int)$verificacion['selladas'] ?> sellados) y todos los sellos coinciden.</p>
    <?php else: ?>
      <div class="aviso aviso-error" role="alert">
        <p><b>La cadena tiene problemas.</b> Alguien modificó el historial fuera de la aplicación. Avisa a TI y conserva los respaldos.</p>
        <ul><?php foreach ($verificacion['problemas'] as $pr): ?><li><a href="/historial/<?= (int)$pr['id'] ?>">Movimiento <?= (int)$pr['id'] ?></a>: <?= e(SelloHistorial::describir($pr['tipo'])) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="panel">
  <form class="filtros-historial" method="get" action="/historial">
    <div class="campo"><label for="f-entidad">Qué</label><select id="f-entidad" name="entidad"><option value="">Todo</option>
      <?php foreach ($opciones['entidades'] as $en): ?><option value="<?= e($en) ?>"<?= $filtros['entidad'] === $en ? ' selected' : '' ?>><?= e($entidades[$en] ?? $en) ?></option><?php endforeach; ?></select></div>
    <div class="campo"><label for="f-accion">Acción</label><select id="f-accion" name="accion"><option value="">Todas</option>
      <?php foreach ($opciones['acciones'] as $ac): ?><option value="<?= e($ac) ?>"<?= $filtros['accion'] === $ac ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $ac)) ?></option><?php endforeach; ?></select></div>
    <div class="campo"><label for="f-usuario">Quién</label><select id="f-usuario" name="usuario_id"><option value="">Cualquiera</option>
      <?php foreach ($opciones['usuarios'] as $uid => $nom): ?><option value="<?= (int)$uid ?>"<?= $filtros['usuario_id'] === (int)$uid ? ' selected' : '' ?>><?= e($nom) ?></option><?php endforeach; ?></select></div>
    <div class="campo"><label for="f-desde">Desde</label><input id="f-desde" type="date" name="desde" value="<?= e($filtros['desde'] ?? '') ?>"></div>
    <div class="campo"><label for="f-hasta">Hasta</label><input id="f-hasta" type="date" name="hasta" value="<?= e($filtros['hasta'] ?? '') ?>"></div>
    <div class="campo"><label for="f-texto">Motivo contiene</label><input id="f-texto" name="texto" value="<?= e($filtros['texto']) ?>" maxlength="80"></div>
    <?php if ($filtros['expediente_id']): ?><input type="hidden" name="expediente_id" value="<?= (int)$filtros['expediente_id'] ?>"><?php endif; ?>
    <div class="acciones"><button class="btn primario" type="submit">Filtrar</button><a class="btn" href="/historial">Limpiar</a></div>
  </form>
  <?php if ($filtros['expediente_id']): ?><p class="nota">Solo el expediente <?= (int)$filtros['expediente_id'] ?> · <a href="<?= e($consulta(['expediente_id' => null, 'pagina' => null])) ?>">ver todo</a></p><?php endif; ?>

  <p class="nota"><?= number_format($total) ?> movimiento(s).</p>
  <?php if ($filas): ?>
  <div class="tabla-contenedor">
    <table class="tabla">
      <thead><tr><th scope="col">#</th><th scope="col">Fecha y hora</th><th scope="col">Quién</th><th scope="col">Acción</th><th scope="col">Qué</th><th scope="col">Alumno</th><th scope="col">Motivo</th><th scope="col"><span class="oculto">Sello</span></th></tr></thead>
      <tbody>
      <?php foreach ($filas as $h): ?>
        <tr>
          <td><a href="/historial/<?= (int)$h['id'] ?>"><?= (int)$h['id'] ?></a></td>
          <td><?= e(fecha_corta(substr($h['creado_en'], 0, 10)) . ' ' . hora12(substr($h['creado_en'], 11, 5))) ?></td>
          <td><?= e($h['usuario'] ?? ('BD: ' . ($h['usuario_bd'] ?? '?'))) ?></td>
          <td><?= e(str_replace('_', ' ', $h['accion'])) ?></td>
          <td><?= e($entidades[$h['entidad']] ?? $h['entidad']) ?><?= $h['entidad_id'] ? ' ' . (int)$h['entidad_id'] : '' ?></td>
          <td><?php if ($h['expediente_id'] && $h['matricula']): ?><a href="/expedientes/<?= (int)$h['expediente_id'] ?>"><?= e($h['matricula']) ?></a><?php else: ?>—<?php endif; ?></td>
          <td class="texto"><?= e($h['motivo'] ?? '—') ?></td>
          <td><?= $h['sellada'] ? '<span class="pill pill-ok" title="Sellado">Sellado</span>' : '<span class="pill pill-neutro" title="Pendiente de sellar">Pendiente</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($paginas > 1): ?>
  <nav class="paginacion" aria-label="Páginas">
    <?php if ($pagina > 1): ?><a class="btn chico" href="<?= e($consulta(['pagina' => $pagina - 1])) ?>" rel="prev">← Más recientes</a><?php endif; ?>
    <span class="nota">Página <?= (int)$pagina ?> de <?= (int)$paginas ?></span>
    <?php if ($pagina < $paginas): ?><a class="btn chico" href="<?= e($consulta(['pagina' => $pagina + 1])) ?>" rel="next">Más antiguos →</a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>
