<?php
/**
 * Parte de la página que cambia al capturar: resumen, calendario, estado e historial.
 * @var array $expediente @var \ServicioSocial\Dominio\Resumen $resumen @var array $hojas
 * @var array $historial @var bool $abierto @var DateTimeImmutable $hoy
 */
use ServicioSocial\Servicios\GestionExpedienteServicio;

$x = $expediente;
$r = $resumen;
$id = (int)$x['id'];
$tonos = ['ok' => 'pill-ok', 'info' => 'pill-info', 'aviso' => 'pill-aviso', 'mal' => 'pill-mal', 'neutro' => 'pill-neutro'];
$editable = $x['estado'] === 'activo';
$diaMes = fn(DateTimeInterface $d) => $d->format('j') . ' ' . ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][(int)$d->format('n') - 1];
$textoAccion = function (array $h): string {
    $antes = $h['antes'] ? json_decode($h['antes'], true) : [];
    $despues = $h['despues'] ? json_decode($h['despues'], true) : [];
    $fila = $despues ?: $antes;
    $dia = fn() => isset($fila['fecha']) ? ' del ' . fecha_corta($fila['fecha']) : '';
    return match ($h['entidad']) {
        'asistencias' => match ($h['accion']) {
            'crear'      => 'Registró ' . ($fila['estado'] ?? 'día') . (($fila['estado'] ?? '') !== 'falta' ? ' (' . (int)($fila['horas_acreditadas'] ?? 0) . ' h)' : '') . $dia(),
            'actualizar' => 'Corrigió el día' . substr($dia(), 4) . ': ' . ($antes['estado'] ?? '') . ' ' . (int)($antes['horas_acreditadas'] ?? 0) . ' h → '
                            . ($despues['estado'] ?? '') . ' ' . (int)($despues['horas_acreditadas'] ?? 0) . ' h',
            'quitar'     => 'Quitó el registro' . $dia() . ' (' . ($fila['estado'] ?? '') . ')',
            default      => $h['accion'],
        },
        'firmas_semana' => ($h['accion'] === 'crear' ? 'Firmó' : 'Quitó la firma de') . ' la semana del ' . fecha_corta($fila['semana_inicio'] ?? null),
        'expedientes' => match ($h['accion']) {
            'crear'          => 'Abrió el expediente',
            'concluir'       => 'Concluyó el expediente',
            'reabrir'        => 'Reabrió el expediente',
            'cambiar_estado' => 'Cambió el estado: ' . ($antes['estado'] ?? '') . ' → ' . ($despues['estado'] ?? ''),
            default          => 'Actualizó los datos del expediente',
        },
        default => $h['accion'] . ' ' . $h['entidad'],
    };
};
?>
<section class="estadisticas seis" aria-label="Avance">
  <div class="estadistica ancha">
    <span class="eyebrow">Horas acreditadas</span>
    <span class="cifra" id="horas-acreditadas"><?= (int)$r->acreditadas ?> <small>/ <?= (int)$r->horasRequeridas ?> h</small></span>
    <progress class="barra-mini grande" value="<?= e($r->avance) ?>" max="100"><?= e($r->avance) ?> %</progress>
    <span class="pista"><?= e(number_format($r->avance, 1)) ?> % · <?= (int)$r->trabajadas ?> h trabajadas</span>
  </div>
  <div class="estadistica"><span class="eyebrow">Restantes</span><span class="cifra"><?= (int)$r->restantes ?> <small>h</small></span><span class="pista"><?= (int)$r->diasHabilesRestantes ?> días de <?= (int)$x['jornada_prevista'] ?> h</span></div>
  <div class="estadistica"><span class="eyebrow">Fecha estimada</span><span class="cifra chica"><?= $r->restantes === 0 ? e(fecha_corta($r->fechaHorasCumplidas)) : e(fecha_corta($r->fechaEstimada)) ?></span><span class="pista"><?= $r->restantes === 0 ? 'Horas completas' : 'Yendo de lunes a viernes' ?></span></div>
  <div class="estadistica"><span class="eyebrow"><?= (int)$r->mesesRequeridos ?> meses</span><span class="cifra chica"><?= e(fecha_corta($r->fechaPeriodo)) ?></span><span class="pista"><?= $r->periodoCumplido ? 'Periodo cumplido' : 'Periodo en curso' ?></span></div>
  <div class="estadistica"><span class="eyebrow">Faltas</span><span class="cifra"><?= (int)$r->faltas ?></span><span class="pill <?= e($tonos[$r->tono()]) ?>"><?= e($r->etiqueta()) ?></span></div>
</section>

<?php if ($r->feriadosPorConfirmar): ?>
  <div class="aviso aviso-aviso fila-entre">
    <p>Hay <?= count($r->feriadosPorConfirmar) ?> festivo(s) del catálogo sin registrar: <?= e(implode(', ', array_map('fecha_corta', $r->feriadosPorConfirmar))) ?>.</p>
    <?php if ($editable): ?>
    <form method="post" action="/expedientes/<?= $id ?>/feriados" class="js-accion">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button class="btn chico" type="submit">Registrar como feriados</button>
    </form>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($r->rebasaAutorizada): ?>
  <p class="aviso aviso-aviso">Con la jornada prevista, las horas se completarían después del término autorizado (<?= e(fecha_corta($x['fecha_fin_autorizada'])) ?>).</p>
<?php endif; ?>
<?php if ($x['estado'] === 'suspendido'): ?>
  <p class="aviso aviso-aviso">El expediente está suspendido: no se proyectan días ni se capturan asistencias hasta reactivarlo.</p>
<?php endif; ?>

<?php
$py = $proyeccion;
$conc = $py['conclusion'];
$ritmo = $py['ritmo'];
$mesesNombre = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
?>
<section class="panel proyeccion" aria-labelledby="titulo-proyeccion">
  <h2 id="titulo-proyeccion">Proyección</h2>
  <div class="proyeccion-claves">
    <div>
      <span class="eyebrow">Conclusión probable</span>
      <?php if ($x['estado'] === 'concluido'): ?>
        <span class="cifra chica">Concluido</span><span class="pista">el <?= e(fecha_corta($x['fecha_conclusion'])) ?></span>
      <?php elseif ($r->situacion === 'listo'): ?>
        <span class="cifra chica">Ya se puede concluir</span><span class="pista">Horas y periodo cumplidos</span>
      <?php elseif ($conc['fecha'] === null): ?>
        <span class="cifra chica">—</span><span class="pista">Sin proyección mientras el expediente no esté activo.</span>
      <?php else: ?>
        <span class="cifra chica" id="conclusion-probable"><?= e(fecha_corta($conc['fecha'])) ?></span>
        <span class="pista"><?= e(match ($conc['limitante']) {
            'horas'   => 'La marcan las horas; los ' . $r->mesesRequeridos . ' meses se cumplen antes (' . fecha_corta($r->fechaPeriodo) . ').',
            'periodo' => 'La marca el periodo de ' . $r->mesesRequeridos . ' meses; las horas se completarían antes (' . fecha_corta($r->restantes === 0 ? $r->fechaHorasCumplidas : $r->fechaEstimada) . ').',
            default   => 'Horas y periodo se cumplen el mismo día.',
        }) ?></span>
      <?php endif; ?>
    </div>
    <div>
      <span class="eyebrow">Ritmo real</span>
      <?php if ($ritmo['semanas'] === 0): ?>
        <span class="cifra chica">—</span><span class="pista">Aún no hay semanas completas para medirlo.</span>
      <?php else: ?>
        <span class="cifra chica"><?= e(number_format($ritmo['por_semana'], 1)) ?> <small>h por semana</small></span>
        <span class="pista">Últimas <?= (int)$ritmo['semanas'] ?> semanas completas (<?= (int)$ritmo['horas'] ?> h); lo previsto son <?= (int)$x['jornada_prevista'] * 5 ?> h por semana.<?php if ($ritmo['fecha'] && $x['estado'] === 'activo'): ?> A este ritmo, las horas se completarían alrededor del <?= e(fecha_corta($ritmo['fecha'])) ?>.<?php endif; ?></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($py['escenarios']): ?>
  <h3>Si cambia la jornada</h3>
  <div class="tabla-contenedor">
    <table class="tabla escenarios">
      <thead><tr><th scope="col">Jornada</th><th scope="col">Completa las horas</th><th scope="col" class="num">Días hábiles</th><th scope="col">Término autorizado</th><th scope="col">Conclusión probable</th></tr></thead>
      <tbody>
      <?php foreach ($py['escenarios'] as $esc): ?>
        <tr<?= $esc['actual'] ? ' class="actual"' : '' ?>>
          <td><?= (int)$esc['jornada'] ?> h por día<?= $esc['actual'] ? ' <span class="pill pill-neutro">actual</span>' : '' ?></td>
          <td><?= e(fecha_corta($esc['fecha_horas'])) ?></td>
          <td class="num"><?= (int)$esc['dias'] ?></td>
          <td><?= $esc['dentro_autorizada'] ? '<span class="pill pill-ok">Alcanza</span>' : '<span class="pill pill-aviso">Se pasa</span>' ?></td>
          <td><?= e(fecha_corta($esc['conclusion'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="pista">Supone asistencia de lunes a viernes desde hoy, sin faltas, saltando cierres y contando los festivos del catálogo según la regla del expediente. No cambia la jornada registrada.</p>
  <?php endif; ?>

  <?php if ($py['por_mes']): ?>
  <details class="por-mes">
    <summary>Avance por mes natural</summary>
    <div class="tabla-contenedor">
      <table class="tabla">
        <thead><tr><th scope="col">Mes</th><th scope="col" class="num">Asistencias</th><th scope="col" class="num">Faltas</th><th scope="col" class="num">Feriados</th><th scope="col" class="num">Horas acreditadas</th></tr></thead>
        <tbody>
        <?php foreach ($py['por_mes'] as $ym => $m): ?>
          <tr><td><?= e($mesesNombre[(int)substr($ym, 5, 2) - 1] . ' ' . substr($ym, 0, 4)) ?></td><td class="num"><?= (int)$m['asistencias'] ?></td><td class="num"><?= (int)$m['faltas'] ?></td><td class="num"><?= (int)$m['feriados'] ?></td><td class="num"><?= (int)$m['horas'] ?> h</td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th scope="row">Total</th><td class="num"><?= array_sum(array_column($py['por_mes'], 'asistencias')) ?></td><td class="num"><?= array_sum(array_column($py['por_mes'], 'faltas')) ?></td><td class="num"><?= array_sum(array_column($py['por_mes'], 'feriados')) ?></td><td class="num"><?= array_sum(array_column($py['por_mes'], 'horas')) ?> h</td></tr></tfoot>
      </table>
    </div>
  </details>
  <?php endif; ?>
</section>

<section class="calendario" aria-labelledby="titulo-calendario">
  <div class="fila-entre">
    <h2 id="titulo-calendario">Calendario de asistencia</h2>
    <div class="leyenda" aria-label="Leyenda">
      <span><i class="muestra m-a"></i>Asistencia</span>
      <span><i class="muestra m-f"></i>Falta</span>
      <span><i class="muestra m-h"></i>Feriado</span>
      <span><i class="muestra m-p"></i>Pendiente</span>
      <span><i class="muestra m-proy"></i>Proyectado</span>
      <span><i class="muestra m-fest"></i>Festivo del catálogo</span>
      <span><i class="muestra m-off"></i>Cierre o fuera del periodo</span>
    </div>
  </div>
  <div class="fila-entre">
  <p class="nota"><?= $editable ? 'Toca un día para registrar asistencia, falta o feriado, o para corregirlo.' : 'El calendario es de solo consulta mientras el expediente no esté activo.' ?> Toca el título de una hoja para mostrarla u ocultarla.</p>
  <div class="acciones"><button type="button" class="btn chico js-hojas" data-abrir="1" hidden>Mostrar todas</button><button type="button" class="btn chico js-hojas" data-abrir="0" hidden>Ocultar todas</button></div>
  </div>

  <?php foreach ($hojas as $hoja): ?>
  <details class="hoja" id="hoja-<?= (int)$hoja['numero'] ?>"<?= $hoja['abierta'] ? ' open' : '' ?>>
    <summary class="hoja-h">
      <span class="hoja-titulo"><h3>Hoja <?= (int)$hoja['numero'] ?></h3><?php if ($hoja['actual']): ?><span class="pill pill-ok">Semana actual</span><?php endif; ?></span>
      <span class="nota"><?= e(fecha_corta($hoja['desde'])) ?> – <?= e(fecha_corta($hoja['hasta'])) ?> · <b><?= (int)$hoja['horas'] ?> h</b></span>
    </summary>
    <div class="hoja-cuerpo">
    <?php foreach ($hoja['meses'] as $mes): ?>
    <div class="mes">
      <div class="mes-h"><span>Mes <?= (int)$mes['numero'] ?> de la hoja</span><span>Total de horas: <b><?= (int)$mes['horas'] ?></b></span></div>
      <div class="semanas">
        <?php foreach ($mes['semanas'] as $sem): $lunes = $sem['lunes']->format('Y-m-d'); ?>
        <div class="semana<?= $sem['actual'] ? ' actual' : '' ?>" id="semana-<?= e($lunes) ?>">
          <div class="semana-h">Semana <?= (int)$sem['numero'] ?><span><?= e($sem['lunes']->format('n') === $sem['viernes']->format('n') ? $sem['lunes']->format('j') . ' – ' . $diaMes($sem['viernes']) : $diaMes($sem['lunes']) . ' – ' . $diaMes($sem['viernes'])) ?></span></div>
          <div class="dias">
            <?php foreach ($sem['dias'] as $d): ?>
              <?php $clases = 'dia ' . implode(' ', $d['clases']); ?>
              <?php if ($editable): ?>
              <a class="<?= e($clases) ?>" data-s="<?= e($d['codigo']) ?>" href="/expedientes/<?= $id ?>/dia?fecha=<?= e($d['fecha']) ?>" aria-label="<?= e($d['etiqueta']) ?>" title="<?= e($d['etiqueta']) ?>"><span class="l"><?= e($d['letra']) ?></span><span class="n"><?= (int)$d['numero'] ?></span><span class="m"><?= e($d['marca']) ?></span></a>
              <?php else: ?>
              <span class="<?= e($clases) ?> solo-lectura" data-s="<?= e($d['codigo']) ?>" role="img" aria-label="<?= e($d['etiqueta']) ?>" title="<?= e($d['etiqueta']) ?>"><span class="l"><?= e($d['letra']) ?></span><span class="n"><?= (int)$d['numero'] ?></span><span class="m"><?= e($d['marca']) ?></span></span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <div class="semana-f">
            <span><b><?= (int)$sem['horas'] ?></b> h</span>
            <?php if ($abierto && $sem['firmable']): ?>
            <form method="post" action="/expedientes/<?= $id ?>/firmas" class="js-accion">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="semana" value="<?= e($lunes) ?>">
              <button type="submit" class="firma" aria-pressed="<?= $sem['firmada'] ? 'true' : 'false' ?>"><?= $sem['firmada'] ? 'Firmada ✓' : 'Sin firma' ?></button>
            </form>
            <?php else: ?>
            <span class="firma" aria-pressed="<?= $sem['firmada'] ? 'true' : 'false' ?>"><?= $sem['firmada'] ? 'Firmada ✓' : 'Sin firma' ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </details>
  <?php endforeach; ?>
</section>

<section class="panel" aria-labelledby="titulo-estado">
  <h2 id="titulo-estado">Estado del expediente</h2>
  <p>Estado actual: <span class="pill <?= e($tonos[$r->tono()]) ?>"><?= e($r->etiqueta()) ?></span>
    <?php if ($x['estado'] === 'concluido'): ?> · concluido el <?= e(fecha_corta($x['fecha_conclusion'])) ?><?php endif; ?></p>
  <?php if (!$r->puedeConcluir && $r->motivoNoConcluir && $abierto): ?>
    <p class="nota">Todavía no se puede concluir: <?= e($r->motivoNoConcluir) ?></p>
  <?php endif; ?>
  <div class="acciones-estado">
    <?php foreach (GestionExpedienteServicio::TRANSICIONES as $accion => [$origenes, , $motivoObligatorio]): ?>
      <?php if (!in_array($x['estado'], $origenes, true) || ($accion === 'concluir' && !$r->puedeConcluir)) { continue; } ?>
      <form method="post" action="/expedientes/<?= $id ?>/estado" class="form-estado">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="version" value="<?= (int)$x['version'] ?>">
        <input type="hidden" name="accion" value="<?= e($accion) ?>">
        <label class="oculto" for="motivo-<?= e($accion) ?>">Motivo</label>
        <input id="motivo-<?= e($accion) ?>" name="motivo" maxlength="255" placeholder="Motivo<?= $motivoObligatorio ? '' : ' (opcional)' ?>"<?= $motivoObligatorio ? ' required' : '' ?>>
        <button class="btn<?= $accion === 'concluir' ? ' primario' : ($accion === 'baja' ? ' peligro' : '') ?>" type="submit"><?= e(['suspender' => 'Suspender', 'reactivar' => 'Reactivar', 'baja' => 'Dar de baja', 'concluir' => 'Concluir servicio', 'reabrir' => 'Reabrir'][$accion]) ?></button>
      </form>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel" aria-labelledby="titulo-historial">
  <div class="fila-entre"><h2 id="titulo-historial">Historial del expediente</h2><a class="btn chico" href="/historial?expediente_id=<?= $id ?>">Ver completo</a></div>
  <?php if (!$historial): ?>
    <p class="nota">Sin movimientos.</p>
  <?php else: ?>
  <div class="tabla-contenedor">
    <table class="tabla historial">
      <thead><tr><th scope="col">Fecha y hora</th><th scope="col">Quién</th><th scope="col">Qué</th><th scope="col">Motivo</th></tr></thead>
      <tbody>
      <?php foreach ($historial as $h): ?>
        <tr>
          <td><?= e(fecha_corta(substr($h['creado_en'], 0, 10)) . ' ' . hora12(substr($h['creado_en'], 11, 5))) ?></td>
          <td><?= e($h['usuario'] ?? ('Base de datos (' . ($h['usuario_bd'] ?? '?') . ')')) ?></td>
          <td class="texto"><?= e($textoAccion($h)) ?></td>
          <td class="texto"><?= e($h['motivo'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
