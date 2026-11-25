<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titulo ?? 'Impresión') ?> · Servicio Social UTN</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">
  <link rel="stylesheet" href="/assets/impresion.css">
</head>
<body class="impresion" data-base="<?= e(\ServicioSocial\Nucleo\RutaBase::base()) ?>">
  <?= $contenido ?>
  <script src="/assets/app.js"></script>
</body>
</html>
