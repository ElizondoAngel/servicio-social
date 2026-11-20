<h1><?= (int)$estado === 404 ? 'Página no encontrada' : ((int)$estado === 403 ? 'Acceso denegado' : 'Algo salió mal') ?></h1>
<p class="aviso aviso-error" role="alert"><?= e($mensaje) ?></p>
<p class="pie"><a href="/">Ir al inicio</a></p>
