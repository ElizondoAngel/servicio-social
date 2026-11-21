<h1>Recuperar contraseña</h1>
<p class="nota">Escribe el correo con el que entras al sistema. Si está registrado, te enviaremos un enlace que sirve una sola vez durante 30 minutos.</p>
<?php if (!empty($aviso)): ?>
  <p class="aviso aviso-<?= e($aviso[0]) ?>" role="alert"><?= e($aviso[1]) ?></p>
<?php endif; ?>
<form method="post" action="/recuperar" class="formulario" novalidate>
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <label for="correo">Correo electrónico</label>
  <input id="correo" name="correo" type="email" autocomplete="email" required value="<?= e($correo) ?>" autofocus>
  <button class="btn primario" type="submit">Enviar enlace</button>
</form>
<p class="pie"><a href="/login">Volver a iniciar sesión</a><?php if (!empty($buzonDev)): ?> · <a href="/dev/correos">Buzón de prueba (desarrollo)</a><?php endif; ?></p>
