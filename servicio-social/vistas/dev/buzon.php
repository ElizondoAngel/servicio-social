<?php /** @var array $correos */ ?>
<h1>Buzón de prueba</h1>
<p class="nota">Solo en desarrollo: aquí aparecen los correos que el sistema habría enviado. En el servidor real llegan al buzón de cada persona y esta página no existe.</p>
<?php if (!$correos): ?>
  <p class="aviso aviso-info">Todavía no hay correos. Pide un restablecimiento en <a href="/recuperar">Recuperar contraseña</a>.</p>
<?php endif; ?>
<?php foreach ($correos as $i => $c): ?>
  <article class="correo-dev<?= $i === 0 ? ' reciente' : '' ?>">
    <p class="pista"><?= e(fecha_corta(substr($c['fecha'], 0, 10)) . ' · ' . hora12(substr($c['fecha'], 11, 5))) ?> · para <?= e($c['para']) ?><?= $i === 0 ? ' · <b>el más reciente</b>' : '' ?></p>
    <h2><?= e($c['asunto']) ?></h2>
    <?php foreach ($c['enlaces'] as $enlace): ?>
      <p><a class="btn primario" href="<?= e($enlace) ?>">Abrir el enlace del correo</a></p>
    <?php endforeach; ?>
    <details><summary>Ver el texto completo</summary><pre class="texto-correo"><?= e($c['texto']) ?></pre></details>
  </article>
<?php endforeach; ?>
<p class="pie"><a href="/login">Volver a iniciar sesión</a></p>
