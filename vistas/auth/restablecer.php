<h1>Nueva contraseña</h1>
<p class="nota">Usa al menos 12 caracteres. Al guardarla se cerrarán todas tus sesiones abiertas.</p>
<?php if (!empty($errores)): ?>
  <div class="aviso aviso-error" role="alert">
    <?php foreach ($errores as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
  </div>
<?php endif; ?>
<form method="post" action="/restablecer" class="formulario" novalidate>
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <label for="password">Nueva contraseña</label>
  <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required autofocus>
  <label for="confirmacion">Confirma la contraseña</label>
  <input id="confirmacion" name="confirmacion" type="password" autocomplete="new-password" minlength="12" required>
  <button class="btn primario" type="submit">Guardar contraseña</button>
</form>
