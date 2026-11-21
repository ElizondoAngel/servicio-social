<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titulo ?? 'Inicio') ?> · Servicio Social UTN</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body data-inactividad="<?= (int)($inactividad_min ?? 30) ?>" data-csrf="<?= e($csrf ?? '') ?>">
  <header class="barra">
    <div class="barra-izq">
      <a class="marca" href="/">
        <span class="marca-sello" aria-hidden="true">SS</span>
        <span class="marca-nombre">Servicio Social</span>
      </a>
      <nav class="nav" aria-label="Principal">
        <a href="/">Expedientes</a>
        <a href="/alumnos/nuevo">Registrar alumno</a>
        <a href="/dias-inhabiles">Días inhábiles</a>
        <a href="/historial">Historial</a>
        <a href="/respaldos">Respaldos</a>
        <a href="/configuracion">Configuración</a>
      </nav>
    </div>
    <div class="barra-usuario">
      <span class="barra-nombre"><?= e($usuario['nombre'] ?? '') ?></span>
      <form method="post" action="/logout">
        <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
        <button class="btn" type="submit">Cerrar sesión</button>
      </form>
    </div>
  </header>
  <div id="aviso-inactividad" class="aviso-flotante" role="alertdialog" aria-live="assertive" hidden>
    <p>Tu sesión se cerrará en 1 minuto por inactividad.</p>
    <button class="btn primario" type="button" id="seguir-trabajando">Seguir trabajando</button>
  </div>
  <main class="contenido">
    <?= $contenido ?>
  </main>
  <script src="/assets/app.js"></script>
</body>
</html>
