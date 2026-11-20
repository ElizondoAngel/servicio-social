<?php
declare(strict_types=1);

/**
 * Sella y verifica la cadena del historial. Pensado para cron, cada noche:
 *   15 2 * * *  php /ruta/servicio-social/bin/sellar-historial.php >> /ruta/storage/logs/sello.log 2>&1
 *
 * Usa el usuario de MariaDB ss_sello (config: 'sello' => ['usuario', 'password']).
 * Sale con código 1 si la cadena tiene problemas: cron avisa por correo
 * al responsable cuando un comando falla o imprime algo.
 * Opción --verificar: solo verifica, no sella.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$config = require __DIR__ . '/../src/bootstrap.php';

use ServicioSocial\Dominio\SelloHistorial;
use ServicioSocial\Servicios\SelladorHistorial;

$usuario = (string)$config->get('sello.usuario', 'ss_sello');
$password = (string)$config->get('sello.password', '');
if ($password === '') {
    fwrite(STDERR, "Falta 'sello.password' en la configuración.\n");
    exit(2);
}
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->get('bd.host', 'localhost'),
    (int)$config->get('bd.puerto', 3306), $config->get('bd.nombre', 'servicio_social'));
$pdo = new PDO($dsn, $usuario, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec("SET time_zone = '" . (new DateTimeImmutable())->format('P') . "'");

$sellador = new SelladorHistorial($pdo);
$soloVerificar = in_array('--verificar', $argv, true);
$antiguedad = 120;
foreach ($argv as $a) {
    if (str_starts_with($a, '--antiguedad=')) {
        $antiguedad = max(0, (int)substr($a, 13));
    }
}
$r = $soloVerificar ? ['selladas' => 0, 'verificacion' => $sellador->verificar()] : $sellador->sellar($antiguedad);
$v = $r['verificacion'];
$fecha = (new DateTimeImmutable())->format('Y-m-d H:i:s');
echo "[$fecha] Selladas ahora: {$r['selladas']} · revisadas: {$v['revisadas']} · selladas: {$v['selladas']} · pendientes: {$v['sin_sellar']}\n";
if (!$v['ok']) {
    foreach ($v['problemas'] as $p) {
        echo "  PROBLEMA en la fila {$p['id']}: " . SelloHistorial::describir($p['tipo']) . "\n";
    }
    exit(1);
}
echo "  Cadena íntegra.\n";
