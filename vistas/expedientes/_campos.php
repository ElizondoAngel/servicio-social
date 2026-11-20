<?php
/**
 * Campos del expediente (bitácora). Se incluye desde formularios que ya
 * definen $datos, $v, $error y $aria.
 */
?>
    <div class="rejilla">
      <div class="campo ancho"><label for="unidad_administrativa">Unidad administrativa</label><input id="unidad_administrativa" name="unidad_administrativa" value="<?= $v('unidad_administrativa') ?>" maxlength="150" required<?= $aria('unidad_administrativa') ?>><?= $error('unidad_administrativa') ?></div>
      <div class="campo ancho"><label for="responsable">Responsable</label><input id="responsable" name="responsable" value="<?= $v('responsable') ?>" maxlength="150" required<?= $aria('responsable') ?>><?= $error('responsable') ?></div>
      <div class="campo"><label for="hora_entrada">Hora de entrada <span class="opcional">(opcional)</span></label><input id="hora_entrada" name="hora_entrada" type="time" value="<?= $v('hora_entrada') ?>"<?= $aria('hora_entrada') ?>><?= $error('hora_entrada') ?></div>
      <div class="campo"><label for="hora_salida">Hora de salida <span class="opcional">(opcional)</span></label><input id="hora_salida" name="hora_salida" type="time" value="<?= $v('hora_salida') ?>"<?= $aria('hora_salida') ?>><?= $error('hora_salida') ?></div>
      <div class="campo"><label for="fecha_inicio">Fecha de inicio</label><input id="fecha_inicio" name="fecha_inicio" type="date" value="<?= $v('fecha_inicio') ?>" required<?= $aria('fecha_inicio') ?>><?= $error('fecha_inicio') ?></div>
      <div class="campo"><label for="fecha_fin_autorizada">Término autorizado <span class="opcional">(opcional)</span></label><input id="fecha_fin_autorizada" name="fecha_fin_autorizada" type="date" value="<?= $v('fecha_fin_autorizada') ?>"<?= $aria('fecha_fin_autorizada') ?>><p class="pista">Si la dejas vacía: inicio + <?= (int)($meses ?? 6) ?> meses.</p><?= $error('fecha_fin_autorizada') ?></div>
      <div class="campo"><label for="jornada_prevista">Jornada prevista</label>
        <select id="jornada_prevista" name="jornada_prevista"<?= $aria('jornada_prevista') ?>>
          <?php foreach ($jornadas ?? [4, 5, 6] as $h): ?><option value="<?= $h ?>"<?= (int)($datos['jornada_prevista'] ?? 4) === $h ? ' selected' : '' ?>><?= $h ?> horas</option><?php endforeach; ?>
        </select><p class="pista">Se usa para proyectar los días que faltan.</p><?= $error('jornada_prevista') ?></div>
      <div class="campo"><label for="horas_por_feriado">Horas por feriado</label>
        <select id="horas_por_feriado" name="horas_por_feriado"<?= $aria('horas_por_feriado') ?>>
          <?php foreach (range(0, 6) as $h): ?><option value="<?= $h ?>"<?= (int)($datos['horas_por_feriado'] ?? 4) === $h ? ' selected' : '' ?>><?= $h ?> horas</option><?php endforeach; ?>
        </select><?= $error('horas_por_feriado') ?></div>
      <div class="campo ancho casilla"><input id="feriados_acreditan" name="feriados_acreditan" type="checkbox" value="1"<?= !empty($datos['feriados_acreditan']) ? ' checked' : '' ?>><label for="feriados_acreditan">Los feriados acreditan horas en este expediente</label></div>
      <div class="campo ancho"><label for="observaciones">Observaciones <span class="opcional">(opcional)</span></label><textarea id="observaciones" name="observaciones" rows="3" maxlength="1000"><?= $v('observaciones') ?></textarea><?= $error('observaciones') ?></div>
    </div>
