<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titulo ?? 'Servicio Social') ?> · Servicio Social UTN</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="acceso">
  <main class="acceso-caja">
    <div class="marca">
      <span class="marca-sello" aria-hidden="true">SS</span>
      <div><div class="eyebrow">UTN Nezahualcóyotl</div><div class="marca-nombre">Servicio Social</div></div>
    </div>
    <?= $contenido ?>
  </main>
</body>
</html>
