<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios\Respaldos;

/**
 * Volcados de la base con mysqldump (nivel 2), su verificación, su
 * retención y la copia cifrada con age (nivel 3). La contraseña de
 * MariaDB nunca va en la línea de comandos: se pasa en un archivo
 * temporal con permisos 600 que se borra al terminar.
 */
final class Volcado
{
    public function __construct(
        private readonly string $host,
        private readonly int $puerto,
        private readonly string $base,
        private readonly string $usuario,
        private readonly string $password,
    ) {
    }

    /** Crea el volcado comprimido y lo verifica. @return string ruta del archivo */
    public function crear(string $carpeta, string $prefijo = 'volcado'): string
    {
        if (!is_dir($carpeta) && !mkdir($carpeta, 0750, true) && !is_dir($carpeta)) {
            throw new \RuntimeException("No se pudo crear la carpeta $carpeta.");
        }
        $destino = sprintf('%s/%s-%s.sql.gz', rtrim($carpeta, '/'), $prefijo, date('Ymd-His'));
        $temporal = $destino . '.tmp';
        $this->conCredenciales(function (string $cred) use ($temporal) {
            $cmd = 'set -o pipefail; mysqldump --defaults-extra-file=' . escapeshellarg($cred)
                . ' --single-transaction --triggers --routines --no-tablespaces --hex-blob --default-character-set=utf8mb4 '
                . escapeshellarg($this->base) . ' | gzip -c > ' . escapeshellarg($temporal);
            self::ejecutar($cmd, 'mysqldump');
        });
        chmod($temporal, 0640);
        rename($temporal, $destino);
        self::verificar($destino);
        return $destino;
    }

    /** Importa un volcado completo (reemplaza las tablas). */
    public function importar(string $archivo): void
    {
        self::verificar($archivo);
        $this->conCredenciales(function (string $cred) use ($archivo) {
            $cmd = 'set -o pipefail; gzip -dc ' . escapeshellarg($archivo) . ' | mariadb --defaults-extra-file=' . escapeshellarg($cred)
                . ' --default-character-set=utf8mb4 ' . escapeshellarg($this->base);
            self::ejecutar($cmd, 'mariadb');
        });
    }

    /**
     * Comprueba que el archivo sea un gzip completo con el esquema y el
     * cierre de mysqldump.
     */
    public static function verificar(string $archivo): void
    {
        $gz = @gzopen($archivo, 'rb');
        if ($gz === false || filesize($archivo) === 0) {
            throw new \RuntimeException("El volcado $archivo no se puede abrir.");
        }
        $tieneHistorial = false;
        $final = '';
        while (!gzeof($gz)) {
            $bloque = gzread($gz, 1 << 20);
            if ($bloque === false) {
                gzclose($gz);
                throw new \RuntimeException("El volcado $archivo está dañado.");
            }
            $tieneHistorial = $tieneHistorial || str_contains($final . $bloque, 'CREATE TABLE `historial`');
            $final = substr($final . $bloque, -200);
        }
        gzclose($gz);
        if (!$tieneHistorial || !str_contains($final, '-- Dump completed')) {
            throw new \RuntimeException("El volcado $archivo está incompleto.");
        }
    }

    /**
     * Conserva los $diarios más recientes y, de los demás, los $semanales
     * más recientes hechos en domingo. @return list<string> archivos borrados
     */
    public static function retencion(string $carpeta, int $diarios, int $semanales, string $prefijo = 'volcado'): array
    {
        $archivos = glob(rtrim($carpeta, '/') . "/$prefijo-*.sql.gz") ?: [];
        rsort($archivos); // el nombre lleva la fecha: orden descendente = más reciente primero
        $borrados = [];
        $semanalesGuardados = 0;
        foreach ($archivos as $i => $a) {
            if ($i < $diarios) {
                continue;
            }
            if (preg_match('/-(\d{8})-\d{6}\.sql\.gz$/', $a, $m) && date('N', strtotime($m[1])) === '7' && $semanalesGuardados < $semanales) {
                $semanalesGuardados++;
                continue;
            }
            unlink($a);
            $borrados[] = basename($a);
        }
        return $borrados;
    }

    /** Cifra con la llave pública de age y deja la copia en $destino. @return string ruta cifrada */
    public static function cifrar(string $archivo, string $destinatario, string $destino): string
    {
        if (!preg_match('/^age1[0-9a-z]{20,}$/', $destinatario)) {
            throw new \RuntimeException('El destinatario de age no es válido (debe empezar con age1).');
        }
        if (!is_dir($destino) || !is_writable($destino)) {
            throw new \RuntimeException("La carpeta externa $destino no existe o no se puede escribir.");
        }
        $salida = rtrim($destino, '/') . '/' . basename($archivo) . '.age';
        self::ejecutar('age -r ' . escapeshellarg($destinatario) . ' -o ' . escapeshellarg($salida . '.tmp') . ' ' . escapeshellarg($archivo), 'age');
        rename($salida . '.tmp', $salida);
        if (filesize($salida) === 0) {
            throw new \RuntimeException('La copia cifrada quedó vacía.');
        }
        return $salida;
    }

    private function conCredenciales(callable $fn): void
    {
        $cred = tempnam(sys_get_temp_dir(), 'ss-cred-');
        chmod($cred, 0600);
        file_put_contents($cred, sprintf("[client]\nuser=%s\npassword=\"%s\"\nhost=%s\nport=%d\n",
            $this->usuario, addcslashes($this->password, "\"\\"), $this->host, $this->puerto));
        try {
            $fn($cred);
        } finally {
            @unlink($cred);
        }
    }

    private static function ejecutar(string $cmd, string $que): void
    {
        $proceso = proc_open(['bash', '-c', $cmd], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
        if (!is_resource($proceso)) {
            throw new \RuntimeException("No se pudo ejecutar $que.");
        }
        $salida = stream_get_contents($tubos[1]);
        $error = stream_get_contents($tubos[2]);
        fclose($tubos[1]);
        fclose($tubos[2]);
        $codigo = proc_close($proceso);
        if ($codigo !== 0) {
            throw new \RuntimeException("$que falló (código $codigo): " . trim((string)$error ?: (string)$salida));
        }
    }
}
