<?php
/** @var bool $configurado @var array $respaldos @var ?array $ultimo @var bool $viejo @var ?array $resultado */
$tipos = ['manual' => 'Manual', 'automatico' => 'Automático', 'previo_restauracion' => 'Antes de restaurar'];
$tablas = ['usuarios' => 'usuarios', 'dias_inhabiles' => 'días inhábiles', 'alumnos' => 'alumnos', 'expedientes' => 'expedientes',
    'asistencias' => 'días de asistencia', 'firmas_semana' => 'firmas'];
$tamano = fn(int $b) => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format(max(1, $b / 1024), 0) . ' KB';
$fechaHora = fn(string $f) => fecha_corta(substr($f, 0, 10)) . ' ' . hora12(substr($f, 11, 5));
$conArchivo = array_values(array_filter($respaldos, fn($r) => $r['archivo_eliminado_en'] === null));
?>
<p class="miga"><a href="/">Expedientes</a> / Respaldos</p>
<div class="encabezado">
  <div><div class="eyebrow">Copias firmadas de toda la información</div><h1>Respaldos</h1></div>
  <?php if ($configurado): ?>
  <form method="post" action="/respaldos"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn primario" type="submit">Crear respaldo ahora</button></form>
  <?php endif; ?>
</div>
<?php if (!empty($aviso)): ?><p class="aviso aviso-exito" role="status"><?= e($aviso) ?></p><?php endif; ?>
<?php if (!empty($falla)): ?><p class="aviso aviso-error" role="alert"><?= e($falla) ?></p><?php endif; ?>

<?php if (!$configurado): ?>
  <p class="aviso aviso-error" role="alert">Los respaldos no están configurados: falta la llave de firma (<code>respaldos.llave_hmac</code>) en <code>config.local.php</code>. Pide a TI que la agregue.</p>
<?php elseif ($viejo): ?>
  <p class="aviso aviso-aviso"><?= $ultimo ? 'El último respaldo tiene más de 48 horas (' . e($fechaHora($ultimo['creado_en'])) . ').' : 'Todavía no hay respaldos.' ?> Revisa que el respaldo nocturno esté programado o crea uno ahora.</p>
<?php else: ?>
  <p class="aviso aviso-info">Último respaldo: <?= e($fechaHora($ultimo['creado_en'])) ?> (<?= e($tipos[$ultimo['tipo']] ?? $ultimo['tipo']) ?>).</p>
<?php endif; ?>

<?php if ($resultado): ?>
<section class="panel aviso-exito resultado" role="status">
  <h2>Restauración terminada</h2>
  <p>Se agregaron: <?= e(implode(', ', array_filter(array_map(fn($t, $n) => $n ? "$n {$tablas[$t]}" : null, array_keys($resultado['nuevos']), $resultado['nuevos'])))) ?: 'nada (todo ya existía)' ?>.
    Antes de restaurar se guardó el respaldo <code><?= e($resultado['previo']) ?></code>.</p>
  <?php if ($resultado['omitidos']): ?>
    <p><b>No se pudo agregar:</b></p>
    <ul><?php foreach ($resultado['omitidos'] as $o): ?><li><?= e($o['que']) ?>: <?= e($o['porque']) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
  <h2>Versiones guardadas</h2>
  <p class="nota">Se conservan los más recientes según la configuración; de los anteriores queda el registro sin el archivo. Los archivos están fuera de la carpeta pública y solo se descargan desde aquí. <b>Excel</b> sirve para consultar la información (una hoja por tabla); para restaurar se usa el <b>respaldo .json.gz</b>, que lleva la firma.</p>
  <?php if (!$respaldos): ?>
    <p class="nota">Todavía no hay respaldos.</p>
  <?php else: ?>
  <div class="tabla-contenedor">
    <table class="tabla">
      <thead><tr><th scope="col">Fecha</th><th scope="col">Tipo</th><th scope="col" class="num">Alumnos</th><th scope="col" class="num">Expedientes</th><th scope="col" class="num">Días</th><th scope="col" class="num">Tamaño</th><th scope="col">Verificado</th><th scope="col"><span class="oculto">Acciones</span></th></tr></thead>
      <tbody>
      <?php foreach ($respaldos as $r): $hay = $r['archivo_eliminado_en'] === null; ?>
        <tr<?= $hay ? '' : ' class="eliminado"' ?>>
          <td><?= e($fechaHora($r['creado_en'])) ?><span class="sub"><?= e($r['creado_por_nombre'] ?? 'Proceso nocturno') ?></span></td>
          <td><?= e($tipos[$r['tipo']] ?? $r['tipo']) ?></td>
          <td class="num"><?= (int)$r['total_alumnos'] ?></td>
          <td class="num"><?= (int)$r['total_expedientes'] ?></td>
          <td class="num"><?= (int)$r['total_asistencias'] ?></td>
          <td class="num"><?= e($tamano((int)$r['tamano_bytes'])) ?></td>
          <td><?= $hay ? e($r['verificado_en'] ? $fechaHora($r['verificado_en']) : '—') : '<span class="pista">Archivo eliminado</span>' ?></td>
          <td class="acciones-fila">
            <?php if ($hay): ?>
            <form method="post" action="/respaldos/<?= (int)$r['id'] ?>/verificar"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn chico" type="submit">Verificar</button></form>
            <form method="post" action="/respaldos/<?= (int)$r['id'] ?>/descargar" class="form-descarga">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <label class="casilla-chica"><input type="checkbox" name="entiendo" value="1" required> Contiene datos personales</label>
              <button class="btn chico" type="submit" formaction="/respaldos/<?= (int)$r['id'] ?>/excel">Excel</button>
              <button class="btn chico" type="submit" title="Archivo firmado; es el que sirve para restaurar">Archivo .json.gz</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($configurado): ?>
<section class="panel">
  <h2>Restaurar</h2>
  <p class="nota">Desde aquí solo se <b>agrega lo que falta</b>: nunca se borra ni se sobrescribe nada. Primero verás qué se agregaría y luego confirmarás con tu contraseña. Antes de restaurar se crea un respaldo automático. Para reemplazar toda la base (recuperación de desastre) se usa <code>bin/restaurar.php</code> en el servidor.</p>
  <form method="post" action="/respaldos/restaurar" enctype="multipart/form-data" class="ficha-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="rejilla">
      <div class="campo"><label for="respaldo_id">Un respaldo guardado</label>
        <select id="respaldo_id" name="respaldo_id"><option value="">—</option>
          <?php foreach ($conArchivo as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($fechaHora($r['creado_en']) . ' · ' . ($tipos[$r['tipo']] ?? $r['tipo'])) ?></option><?php endforeach; ?>
        </select></div>
      <div class="campo"><label for="archivo">o un archivo descargado <span class="opcional">(.json.gz)</span></label><input id="archivo" name="archivo" type="file" accept=".gz,application/gzip"></div>
    </div>
    <div class="acciones"><button class="btn" type="submit">Revisar qué se agregaría</button></div>
  </form>
</section>
<?php endif; ?>
