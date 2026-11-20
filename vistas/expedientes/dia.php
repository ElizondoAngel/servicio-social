<?php
/**
 * Formulario de un día. Se muestra como página (sin JavaScript) o dentro
 * del diálogo del calendario ($parcial).
 * @var array $expediente @var array $alumno @var string $fecha @var ?array $registro @var ?array $inhabil
 * @var bool $futuro @var array $requiere @var int $credito @var array $jornadas
 * @var ?array $datos @var array $errores @var ?string $general @var bool $parcial
 */
use ServicioSocial\Servicios\AsistenciaServicio;

$x = $expediente;
$id = (int)$x['id'];
$reg = $registro;
if ($datos === null) {
    $datos = $reg === null
        ? ['estado' => ($inhabil['tipo'] ?? '') === 'festivo' ? 'feriado' : 'asistencia', 'horas_trabajadas' => (int)$x['jornada_prevista']]
        : [
            'estado'            => $reg['estado'],
            'horas_trabajadas'  => $reg['estado'] === 'asistencia' ? (int)$reg['horas_trabajadas'] : (int)$x['jornada_prevista'],
            'horas_acreditadas' => match (true) {
                $reg['estado'] === 'asistencia' && (int)$reg['horas_acreditadas'] === (int)$reg['horas_trabajadas'] => '',
                $reg['estado'] === 'feriado' && $reg['dia_inhabil_id'] !== null && (int)$reg['horas_acreditadas'] === $credito => '',
                $reg['estado'] === 'falta' => '',
                default => (string)$reg['horas_acreditadas'],
            },
            'justificacion'     => $reg['justificacion'],
            'hora_entrada'      => $reg['hora_entrada'] ? substr($reg['hora_entrada'], 0, 5) : '',
            'hora_salida'       => $reg['hora_salida'] ? substr($reg['hora_salida'], 0, 5) : '',
            'observaciones'     => $reg['observaciones'],
        ];
}
$v = fn(string $c) => e($datos[$c] ?? '');
$error = function (string $c) use ($errores): string {
    return isset($errores[$c]) ? '<p class="campo-error" id="error-dia-' . e($c) . '">' . e($errores[$c]) . '</p>' : '';
};
$aria = fn(string $c) => isset($errores[$c]) ? ' aria-invalid="true" aria-describedby="error-dia-' . e($c) . '"' : '';
$estado = $datos['estado'] ?? 'asistencia';
$dias = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
$titulo = $dias[(int)date('N', strtotime($fecha)) - 1] . ' ' . fecha_corta($fecha);
$editable = $x['estado'] === 'activo';
$necesitaAutorizacion = $requiere !== [] && ($reg['autorizado_por'] ?? null) === null;
?>
<?php if (!$parcial): ?>
<p class="miga"><a href="/">Expedientes</a> / <a href="/expedientes/<?= $id ?>"><?= e(nombre_completo($alumno)) ?></a> / Día</p>
<?php endif; ?>
<div class="form-dia" data-fecha="<?= e($fecha) ?>">
  <div class="fila-entre">
    <h2 id="dialogo-titulo"><?= e(ucfirst($titulo)) ?></h2>
    <?php if ($parcial): ?><button type="button" class="btn chico js-cerrar" aria-label="Cerrar">Cerrar</button><?php endif; ?>
  </div>

  <?php if ($inhabil): ?>
    <p class="aviso aviso-info"><?= $inhabil['tipo'] === 'festivo' ? 'Festivo del catálogo' : 'Cierre de oficina' ?>: <?= e($inhabil['descripcion']) ?>.
      <?php if ($inhabil['tipo'] === 'festivo'): ?>La regla de este expediente acredita <?= (int)$credito ?> h.<?php endif; ?></p>
  <?php endif; ?>
  <?php if ($reg): ?>
    <p class="nota">Registrado: <b><?= e(AsistenciaServicio::ESTADOS[$reg['estado']]) ?></b>, <?= (int)$reg['horas_acreditadas'] ?> h acreditadas
      <?= $reg['autorizado_por'] ? '· autorizado: ' . e($reg['motivo_autorizacion']) : '' ?>. Para corregirlo cambia los datos y escribe el motivo.</p>
  <?php endif; ?>
  <?php if ($general): ?><p class="aviso aviso-error" role="alert"><?= e($general) ?></p><?php elseif ($errores): ?><p class="aviso aviso-error" role="alert">Revisa los campos marcados.</p><?php endif; ?>

  <?php if (!$editable): ?>
    <p class="aviso aviso-aviso">El expediente no está activo; el día no se puede modificar.</p>
  <?php elseif ($futuro && !$reg): ?>
    <p class="aviso aviso-info">Este día todavía no llega. Se podrá registrar a partir de esa fecha.</p>
  <?php else: ?>
  <form method="post" action="/expedientes/<?= $id ?>/dia" class="ficha-form js-form-dia" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
    <?php if ($reg): ?><input type="hidden" name="version" value="<?= (int)$reg['version'] ?>"><?php endif; ?>

    <fieldset class="opciones-estado"<?= $aria('estado') ?>>
      <legend>¿Qué pasó este día?</legend>
      <?php foreach (AsistenciaServicio::ESTADOS as $clave => $texto): if ($futuro && $clave !== 'pendiente') { continue; } ?>
        <label class="opcion-estado op-<?= e($clave) ?>"><input type="radio" name="estado" value="<?= e($clave) ?>"<?= $estado === $clave ? ' checked' : '' ?>> <?= e($clave === 'pendiente' ? ($reg ? 'Pendiente (quitar registro)' : 'Pendiente') : $texto) ?></label>
      <?php endforeach; ?>
      <?= $error('estado') ?>
    </fieldset>

    <div class="rejilla">
      <div class="campo solo-asistencia"><label for="dia-horas_trabajadas">Horas trabajadas</label>
        <select id="dia-horas_trabajadas" name="horas_trabajadas"<?= $aria('horas_trabajadas') ?>>
          <?php foreach ($jornadas as $h): ?><option value="<?= $h ?>"<?= (int)($datos['horas_trabajadas'] ?? $x['jornada_prevista']) === $h ? ' selected' : '' ?>><?= $h ?> horas</option><?php endforeach; ?>
        </select><?= $error('horas_trabajadas') ?></div>
      <div class="campo con-horas"><label for="dia-horas_acreditadas">Horas acreditadas</label>
        <select id="dia-horas_acreditadas" name="horas_acreditadas"<?= $aria('horas_acreditadas') ?>>
          <option value="">Automático</option>
          <?php foreach (range(0, 6) as $h): ?><option value="<?= $h ?>"<?= ($datos['horas_acreditadas'] ?? '') !== '' && (int)$datos['horas_acreditadas'] === $h ? ' selected' : '' ?>><?= $h ?> horas</option><?php endforeach; ?>
        </select>
        <p class="pista">Automático: asistencia = horas trabajadas; feriado del catálogo = <?= (int)$credito ?> h. Otro valor pide justificación.</p>
        <?= $error('horas_acreditadas') ?></div>
      <div class="campo solo-asistencia"><label for="dia-hora_entrada">Entrada <span class="opcional">(opcional)</span></label><input id="dia-hora_entrada" name="hora_entrada" type="time" value="<?= $v('hora_entrada') ?>"<?= $aria('hora_entrada') ?>><?= $error('hora_entrada') ?></div>
      <div class="campo solo-asistencia"><label for="dia-hora_salida">Salida <span class="opcional">(opcional)</span></label><input id="dia-hora_salida" name="hora_salida" type="time" value="<?= $v('hora_salida') ?>"<?= $aria('hora_salida') ?>><?= $error('hora_salida') ?></div>
      <div class="campo ancho con-horas"><label for="dia-justificacion">Justificación <span class="opcional">(si acreditas horas distintas)</span></label><input id="dia-justificacion" name="justificacion" value="<?= $v('justificacion') ?>" maxlength="255"<?= $aria('justificacion') ?>><?= $error('justificacion') ?></div>
      <div class="campo ancho"><label for="dia-observaciones">Observaciones <span class="opcional">(opcional)</span></label><textarea id="dia-observaciones" name="observaciones" rows="2" maxlength="500"<?= $aria('observaciones') ?>><?= $v('observaciones') ?></textarea><?= $error('observaciones') ?></div>

      <?php if ($necesitaAutorizacion): ?>
      <div class="campo ancho autorizacion">
        <p class="aviso aviso-aviso">Este día <?= e(implode(' y ', $requiere)) ?>. Solo se registra con autorización.</p>
        <div class="campo casilla"><input id="dia-autorizar" name="autorizar" type="checkbox" value="1"<?= !empty($datos['autorizar']) ? ' checked' : '' ?><?= $aria('autorizar') ?>><label for="dia-autorizar">Autorizo este registro</label></div>
        <label for="dia-motivo_autorizacion">Motivo de la autorización</label>
        <input id="dia-motivo_autorizacion" name="motivo_autorizacion" value="<?= $v('motivo_autorizacion') ?>" maxlength="255">
        <?= $error('autorizar') ?>
      </div>
      <?php endif; ?>

      <?php if ($reg): ?>
      <div class="campo ancho"><label for="dia-motivo">Motivo de la corrección <span class="opcional">(queda en el historial)</span></label><input id="dia-motivo" name="motivo" value="<?= $v('motivo') ?>" maxlength="255" required<?= $aria('motivo') ?>><?= $error('motivo') ?></div>
      <?php endif; ?>
    </div>

    <div class="acciones">
      <button class="btn primario" type="submit">Guardar</button>
      <a class="btn js-cerrar" href="/expedientes/<?= $id ?>#semana-<?= e(date('Y-m-d', strtotime($fecha . ' -' . ((int)date('N', strtotime($fecha)) - 1) . ' day'))) ?>">Cancelar</a>
    </div>
  </form>
  <?php endif; ?>
</div>
