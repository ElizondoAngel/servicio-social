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
  <a class="btn primario" href="/alumnos/nuevo">Registrar alumno</a>
</div>

<?php if (!empty($aviso)): ?><p class="aviso aviso-exito" role="status"><?= e($aviso) ?></p><?php endif; ?>

<section class="estadisticas" aria-label="Resumen">
  <div class="estadistica"><span class="eyebrow">Alumnos registrados</span><span class="cifra"><?= (int)$estadisticas['alumnos'] ?></span></div>
  <div class="estadistica"><span class="eyebrow">Activos</span><span class="cifra"><?= (int)$estadisticas['activos'] ?></span></div>
  <div class="estadistica destacada"><span class="eyebrow">Próximos a concluir</span><span class="cifra"><?= (int)$estadisticas['proximos'] ?></span><span class="pista">≤ <?= (int)$estadisticas['umbral'] ?> h restantes</span></div>
  <div class="estadistica"><span class="eyebrow">Concluyeron</span><span class="cifra"><?= (int)$estadisticas['concluidos'] ?></span></div>
  <div class="estadistica"><span class="eyebrow">Horas acreditadas</span><span class="cifra"><?= number_format((int)$estadisticas['horas']) ?> <small>h</small></span><span class="pista">entre todos los alumnos</span></div>
</section>

<section class="panel">
  <form class="buscador" method="get" action="/" role="search">
    <label class="oculto" for="q">Buscar por nombre o matrícula</label>
    <input id="q" name="q" type="search" value="<?= e($busqueda) ?>" placeholder="Buscar por nombre o matrícula" maxlength="80">
    <?php if ($filtro !== 'todos'): ?><input type="hidden" name="filtro" value="<?= e($filtro) ?>"><?php endif; ?>
    <?php if ($orden !== 'nombre'): ?><input type="hidden" name="orden" value="<?= e($orden) ?>"><?php endif; ?>
    <button class="btn" type="submit">Buscar</button>
    <?php if ($busqueda !== ''): ?><a class="btn-texto" href="<?= e($enlace($filtro)) ?>&amp;q=">Limpiar</a><?php endif; ?>
  </form>
  <nav class="filtros" aria-label="Filtrar">
    <?php foreach ($filtros as $clave => $nombre): ?>
      <a class="filtro<?= $clave === $filtro ? ' activo' : '' ?>" href="<?= e($enlace($clave)) ?>"<?= $clave === $filtro ? ' aria-current="page"' : '' ?>><?= e($nombre) ?></a>
    <?php endforeach; ?>
  </nav>
  <nav class="filtros orden" aria-label="Ordenar">
    <span class="pista">Ordenar por:</span>
    <?php foreach ($ordenes as $clave => $nombre): ?>
      <a class="filtro<?= $clave === $orden ? ' activo' : '' ?>" href="<?= e($enlace($filtro, $clave)) ?>"<?= $clave === $orden ? ' aria-current="true"' : '' ?>><?= e($nombre) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($filas === []): ?>
    <div class="vacio">
      <?php if ((int)$estadisticas['alumnos'] === 0): ?>
        <p><strong>Todavía no hay alumnos registrados.</strong></p>
        <p>Registra al primero para abrir su expediente y empezar su bitácora.</p>
        <a class="btn primario" href="/alumnos/nuevo">Registrar alumno</a>
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
