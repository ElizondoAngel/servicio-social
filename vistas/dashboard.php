<?php
/** @var array $estadisticas @var array $filas @var string $busqueda @var string $filtro @var array $filtros */
$tonos = ['ok' => 'pill-ok', 'info' => 'pill-info', 'aviso' => 'pill-aviso', 'mal' => 'pill-mal', 'neutro' => 'pill-neutro'];
$enlace = fn(string $f, ?string $o = null) => '/?' . http_build_query(array_filter([
    'q' => $busqueda, 'filtro' => $f === 'todos' ? '' : $f, 'orden' => ($o ?? $orden) === 'nombre' ? '' : ($o ?? $orden)]));
?>
<div class="encabezado">
  <div>
    <div class="eyebrow">Hoy, <?= e(fecha_corta(new DateTimeImmutable('today'))) ?></div>
    <h1>Expedientes</h1>
  </div>
  <div class="acciones">
    <a class="btn" href="<?= e('/expedientes/imprimir' . rtrim('?' . http_build_query(array_filter(['q' => $busqueda, 'filtro' => $filtro === 'todos' ? '' : $filtro, 'orden' => $orden === 'nombre' ? '' : $orden])), '?')) ?>"<?= count($filas) === 0 ? ' aria-disabled="true"' : '' ?>>Imprimir <?= $filtro !== 'todos' || $busqueda !== '' ? 'los mostrados' : 'todos' ?> (<?= count($filas) ?>)</a>
    <a class="btn primario" href="/alumnos/nuevo">Registrar alumno</a>
  </div>
</div>

<?php if (!empty($aviso)): ?><p class="aviso aviso-exito" role="status"><?= e($aviso) ?></p><?php endif; ?>

<?php
// Las tarjetas también sirven de filtro rápido: un toque muestra solo ese grupo (otro toque lo quita).
$tarjeta = fn(string $f) => e($enlace($filtro === $f ? 'todos' : $f));
$activa = fn(string $f) => $filtro === $f ? ' activa" aria-current="true' : '';
?>
<section class="estadisticas" aria-label="Resumen (toca una tarjeta para filtrar)">
  <a class="estadistica enlace" href="<?= e($enlace('todos')) ?>"><span class="eyebrow">Alumnos registrados</span><span class="cifra"><?= (int)$estadisticas['alumnos'] ?></span><span class="pista">Ver todos</span></a>
  <a class="estadistica enlace<?= $activa('activos') ?>" href="<?= $tarjeta('activos') ?>"><span class="eyebrow">Activos</span><span class="cifra"><?= (int)$estadisticas['activos'] ?></span><span class="pista">En servicio</span></a>
  <a class="estadistica enlace destacada<?= $activa('proximos') ?>" href="<?= $tarjeta('proximos') ?>"><span class="eyebrow">Próximos a concluir</span><span class="cifra"><?= (int)$estadisticas['proximos'] ?></span><span class="pista">≤ <?= (int)$estadisticas['umbral'] ?> h restantes</span></a>
  <a class="estadistica enlace<?= $activa('concluidos') ?>" href="<?= $tarjeta('concluidos') ?>"><span class="eyebrow">Concluyeron</span><span class="cifra"><?= (int)$estadisticas['concluidos'] ?></span><span class="pista">Servicio terminado</span></a>
  <div class="estadistica"><span class="eyebrow">Horas acreditadas</span><span class="cifra"><?= number_format((int)$estadisticas['horas']) ?> <small>h</small></span><span class="pista">entre todos los alumnos</span></div>
</section>

<section class="panel">
  <form class="barra-tablero js-auto" method="get" action="/" role="search">
    <div class="campo buscar">
      <label for="q">Buscar</label>
      <input id="q" name="q" type="search" value="<?= e($busqueda) ?>" placeholder="Nombre o matrícula" maxlength="80">
    </div>
    <div class="campo">
      <label for="filtro">Mostrar</label>
      <select id="filtro" name="filtro">
        <?php foreach ($filtros as $clave => $nombre): ?><option value="<?= e($clave) ?>"<?= $clave === $filtro ? ' selected' : '' ?>><?= e($nombre) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="campo">
      <label for="orden">Ordenar por</label>
      <select id="orden" name="orden">
        <?php foreach ($ordenes as $clave => $nombre): ?><option value="<?= e($clave) ?>"<?= $clave === $orden ? ' selected' : '' ?>><?= e($nombre) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="campo boton"><button class="btn" type="submit">Aplicar</button></div>
  </form>
  <?php if ($filtro !== 'todos' || $busqueda !== ''): ?>
  <p class="filtro-actual" role="status">
    <?= count($filas) ?> resultado(s)<?= $filtro !== 'todos' ? ' · <b>' . e($filtros[$filtro]) . '</b>' : '' ?><?= $busqueda !== '' ? ' · «' . e($busqueda) . '»' : '' ?>
    <a class="btn-texto" href="<?= e('/?' . http_build_query(array_filter(['orden' => $orden === 'nombre' ? '' : $orden]))) ?>">Quitar filtros</a>
  </p>
  <?php endif; ?>

  <?php if ($filas === []): ?>
    <div class="vacio">
      <?php if ((int)$estadisticas['alumnos'] === 0): ?>
        <p><strong>Todavía no hay alumnos registrados.</strong></p>
        <p>Registra al primero para abrir su expediente y empezar su bitácora.</p>
        <div class="acciones">
    <a class="btn" href="<?= e('/expedientes/imprimir' . rtrim('?' . http_build_query(array_filter(['q' => $busqueda, 'filtro' => $filtro === 'todos' ? '' : $filtro, 'orden' => $orden === 'nombre' ? '' : $orden])), '?')) ?>"<?= count($filas) === 0 ? ' aria-disabled="true"' : '' ?>>Imprimir <?= $filtro !== 'todos' || $busqueda !== '' ? 'los mostrados' : 'todos' ?> (<?= count($filas) ?>)</a>
    <a class="btn primario" href="/alumnos/nuevo">Registrar alumno</a>
  </div>
      <?php else: ?>
        <p>No hay expedientes que coincidan<?= $busqueda !== '' ? ' con «' . e($busqueda) . '»' : '' ?>.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="tabla-contenedor">
      <table class="tabla">
        <thead>
          <tr><th>Alumno</th><th>Carrera</th><th>Inicio</th><th class="num">Acreditadas</th><th class="num">Restantes</th><th>Avance</th><th>Conclusión probable</th><th>Situación</th></tr>
        </thead>
        <tbody>
        <?php foreach ($filas as $f): $r = $f['resumen']; ?>
          <tr>
            <td><a class="alumno" href="/expedientes/<?= (int)$f['expediente_id'] ?>"><?= e(nombre_completo($f)) ?></a><span class="sub"><?= e($f['matricula']) ?></span></td>
            <td class="carrera"><?= e($f['carrera']) ?></td>
            <td><?= e(fecha_corta($f['fecha_inicio'])) ?></td>
            <td class="num"><?= (int)$r->acreditadas ?> h</td>
            <td class="num"><?= (int)$r->restantes ?> h</td>
            <td><div class="avance"><progress class="barra-mini" value="<?= e($r->avance) ?>" max="100"><?= e($r->avance) ?> %</progress><span><?= e(number_format($r->avance, 0)) ?> %</span></div></td>
            <td><?php if ($f['estado'] === 'concluido'): ?>Concluido<?php elseif ($r->situacion === 'listo'): ?>Ya se puede concluir<span class="sub">desde <?= e(fecha_corta($f['conclusion'])) ?></span><?php elseif ($f['conclusion']): ?><?= e(fecha_corta($f['conclusion'])) ?><span class="sub"><?= $r->restantes === 0 ? 'horas completas' : 'horas: ' . e(fecha_corta($r->fechaEstimada)) ?></span><?php else: ?>—<?php endif; ?></td>
            <td><span class="pill <?= e($tonos[$r->tono()]) ?>"><?= e($r->etiqueta()) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
