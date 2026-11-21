<h1>Iniciar sesión</h1>
<?php if (!empty($aviso)): ?>
  <p class="aviso aviso-<?= e($aviso[0]) ?>" role="<?= $aviso[0] === 'error' ? 'alert' : 'status' ?>"><?= e($aviso[1]) ?>
    <?php if (!empty($buzonDev) && str_contains($aviso[1], 'te enviamos un enlace')): ?><br><a class="enlace-buzon" href="/dev/correos">Desarrollo: abrir el buzón de prueba</a><?php endif; ?></p>
<?php endif; ?>
<form method="post" action="/login" class="formulario" novalidate>
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <label for="correo">Correo electrónico</label>
  <input id="correo" name="correo" type="email" autocomplete="username" required value="<?= e($correo) ?>" autofocus>
  <label for="password">Contraseña</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>
  <button class="btn primario" type="submit">Entrar</button>
</form>
<p class="pie"><a href="/recuperar">Olvidé mi contraseña</a></p>
