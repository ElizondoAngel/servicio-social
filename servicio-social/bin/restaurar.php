<?php
declare(strict_types=1);

/**
 * Restauración desde el servidor.
 *
 *   php bin/restaurar.php --volcado=/ruta/volcado-AAAAMMDD-HHMMSS.sql.gz
 *       Recuperación de desastre: REEMPLAZA toda la base con un volcado
 *       (nivel 2), incluido el historial con sus sellos. Usa ss_admin.
 *       Pone la aplicación en mantenimiento, hace y verifica un volcado
 *       previo y exige escribir RESTAURAR.
 *
 *   php bin/restaurar.php --json=/ruta/respaldo-....json.gz
 *       Igual que la pantalla Respaldos: valida la firma, muestra la vista
 *       previa y solo AGREGA lo que falta (útil si la web no está disponible).
 *
 * La contraseña de ss_admin se pide sin mostrarla (o en la variable de
 * entorno SS_ADMIN_PASSWORD). Para pruebas automáticas: --confirmar=RESTAURAR.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$config = require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/aplicacion.php';

use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Servicios\Respaldos\RespaldoInvalido;
use ServicioSocial\Servicios\Respaldos\Volcado;

$opciones = getopt('', ['volcado:', 'json:', 'confirmar:', 'motivo:']);
$preguntar = function (string $texto, bool $oculto = false): string {
    echo $texto;
    if ($oculto && stream_isatty(STDIN)) {
        shell_exec('stty -echo');
        $r = trim((string)fgets(STDIN));
        shell_exec('stty echo');
        echo "\n";
        return $r;
    }
    return trim((string)fgets(STDIN));
};
$confirmar = function () use ($opciones, $preguntar): void {
    $texto = $opciones['confirmar'] ?? $preguntar('Escribe RESTAURAR para continuar: ');
    if ($texto !== 'RESTAURAR') {
        fwrite(STDERR, "Cancelado: no se escribió RESTAURAR.\n");
        exit(1);
    }
};
$bandera = (string)$config->get('rutas.mantenimiento', __DIR__ . '/../storage/mantenimiento');

if (isset($opciones['volcado'])) {
    $archivo = (string)$opciones['volcado'];
    try {
        Volcado::verificar($archivo);
    } catch (Throwable $e) {
        fwrite(STDERR, 'El volcado no sirve: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $password = getenv('SS_ADMIN_PASSWORD') ?: $preguntar('Contraseña de ss_admin: ', true);
    $admin = new Volcado((string)$config->get('bd.host', 'localhost'), (int)$config->get('bd.puerto', 3306),
        (string)$config->get('bd.nombre', 'servicio_social'), 'ss_admin', $password);

    echo "Se va a REEMPLAZAR toda la base con:\n  $archivo\nLo que se haya capturado después de ese volcado se perderá (queda en el volcado previo).\n";
    $confirmar();

    file_put_contents($bandera, 'Restauración completa desde ' . basename($archivo) . ' · ' . date('Y-m-d H:i:s'));
    echo "Aplicación en mantenimiento.\n";
    try {
        $previo = $admin->crear((string)$config->get('respaldos.volcado.ruta', __DIR__ . '/../storage/volcados'), 'previo-restauracion');
        echo 'Volcado previo creado y verificado: ' . $previo . "\n";
    } catch (Throwable $e) {
        unlink($bandera);
        fwrite(STDERR, 'No se pudo hacer el volcado previo; no se cambió nada: ' . $e->getMessage() . "\n");
        exit(1);
    }
    try {
        $admin->importar($archivo);
    } catch (Throwable $e) {
        fwrite(STDERR, "FALLÓ la importación: " . $e->getMessage() . "\n"
            . "La aplicación sigue en mantenimiento. Para volver al estado anterior:\n"
            . "  php bin/restaurar.php --volcado=$previo\n");
        exit(1);
    }
    unlink($bandera);
    echo "Restauración completa terminada. La aplicación ya está disponible.\n"
        . "Siguiente paso recomendado: php bin/sellar-historial.php --verificar\n";
    exit(0);
}

if (isset($opciones['json'])) {
    $bd = new BaseDatos($config);
    $bd->fijarContexto(null, null, 'restaurar.php');
    $servicio = crearServicioRespaldos($config, $bd);
    try {
        $leido = $servicio->leerContenido((string)@file_get_contents((string)$opciones['json']));
    } catch (RespaldoInvalido $e) {
        fwrite(STDERR, 'El respaldo no sirve: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $plan = $servicio->vistaPrevia($leido['datos']);
    echo 'Respaldo del ' . $leido['cabecera']['creado_en'] . ". Se agregarían:\n";
    foreach ($plan['nuevos'] as $t => $n) {
        echo "  $t: $n (ya existen {$plan['existentes'][$t]})\n";
    }
    foreach ($plan['omitidos'] as $o) {
        echo "  NO se puede agregar — {$o['que']}: {$o['porque']}\n";
    }
    if (array_sum($plan['nuevos']) === 0) {
        echo "No hay nada que agregar.\n";
        exit(0);
    }
    $motivo = $opciones['motivo'] ?? $preguntar('Motivo de la restauración: ');
    if ($motivo === '') {
        fwrite(STDERR, "Cancelado: falta el motivo.\n");
        exit(1);
    }
    $confirmar();
    try {
        $r = $servicio->restaurarAgregando($leido, basename((string)$opciones['json']), $motivo, null, null, 'restaurar.php');
    } catch (RespaldoInvalido $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    echo 'Listo. Respaldo previo: ' . $r['previo']['archivo'] . "\n";
    exit(0);
}

fwrite(STDERR, "Uso:\n  php bin/restaurar.php --volcado=ARCHIVO.sql.gz   (reemplaza toda la base)\n"
    . "  php bin/restaurar.php --json=ARCHIVO.json.gz      (agrega lo que falta)\n");
exit(2);
