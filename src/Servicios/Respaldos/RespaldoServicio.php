<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios\Respaldos;

use DateTimeImmutable;
use PDOException;
use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Repositorios\ConfiguracionRepositorio;
use ServicioSocial\Repositorios\HistorialRepositorio;
use ServicioSocial\Repositorios\RespaldoDatosRepositorio;
use ServicioSocial\Repositorios\RespaldoRepositorio;
use ServicioSocial\Servicios\ErroresBd;

/**
 * Respaldos JSON firmados (nivel 1): crear, verificar, conservar los
 * últimos N, leer para descargar y restaurar agregando lo que falta.
 */
final class RespaldoServicio
{
    public const CANDADO = 'ss_restauracion';

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RespaldoRepositorio $respaldos,
        private readonly RespaldoDatosRepositorio $datos,
        private readonly HistorialRepositorio $historial,
        private readonly ConfiguracionRepositorio $config,
        private readonly string $ruta,
        private readonly string $llave,
    ) {
    }

    public function configurado(): bool
    {
        return FormatoRespaldo::llaveValida($this->llave);
    }

    public function lista(): array
    {
        return $this->respaldos->todos();
    }

    public function ultimo(): ?array
    {
        return $this->respaldos->ultimo();
    }

    /**
     * Crea un respaldo, lo vuelve a leer para verificarlo y lo registra.
     * @return array fila de respaldos
     */
    public function crear(string $tipo, ?int $usuarioId, ?string $ip = null, ?string $agente = null): array
    {
        if (!$this->configurado()) {
            throw new RespaldoInvalido("Falta la llave de firma de respaldos ('respaldos.llave_hmac') en la configuración del servidor.");
        }
        if (!is_dir($this->ruta) && !mkdir($this->ruta, 0750, true) && !is_dir($this->ruta)) {
            throw new \RuntimeException('No se pudo crear la carpeta de respaldos.');
        }
        $ahora = new DateTimeImmutable();
        $datos = $this->datos->exportar();
        $contenido = FormatoRespaldo::escribir($datos, $tipo, $ahora->format('Y-m-d H:i:s'), $this->llave);
        $nombre = sprintf('respaldo-%s-%s-%s.json.gz', $ahora->format('Ymd-His'), $tipo, bin2hex(random_bytes(3)));
        $destino = $this->ruta . '/' . $nombre;
        $temporal = $destino . '.tmp';
        if (file_put_contents($temporal, $contenido, LOCK_EX) !== strlen($contenido)) {
            @unlink($temporal);
            throw new \RuntimeException('No se pudo escribir el respaldo (¿espacio en disco?).');
        }
        chmod($temporal, 0640);
        rename($temporal, $destino);

        // Se verifica leyendo lo que quedó en disco, no lo que hay en memoria.
        $leido = FormatoRespaldo::leer((string)file_get_contents($destino), $this->llave);
        $cab = $leido['cabecera'];
        $id = $this->respaldos->crear([
            'tipo' => $tipo, 'archivo' => $nombre, 'tamano_bytes' => filesize($destino), 'sha256' => $cab['sha256'],
            'firma_hmac' => $cab['firma_hmac'], 'verificado_en' => $ahora->format('Y-m-d H:i:s'), 'version_formato' => $cab['version_formato'],
            'total_alumnos' => $cab['totales']['alumnos'], 'total_expedientes' => $cab['totales']['expedientes'],
            'total_asistencias' => $cab['totales']['asistencias'], 'creado_por' => $usuarioId,
        ]);
        $this->historial->evento('respaldar', 'respaldos', $id, $usuarioId, ['archivo' => $nombre, 'tipo' => $tipo, 'totales' => $cab['totales']], $ip, $agente);
        if ($tipo !== 'previo_restauracion') {
            $this->aplicarRetencion();
        }
        return $this->respaldos->porId($id);
    }

    /** Conserva los últimos N archivos; de los demás borra el archivo y deja la fila como registro. */
    public function aplicarRetencion(): int
    {
        $retener = max(5, $this->config->entero('respaldos_retener', 30));
        $borrados = 0;
        foreach (array_slice($this->respaldos->conArchivo(), $retener) as $viejo) {
            $archivo = $this->archivo($viejo);
            if ($archivo !== null && is_file($archivo)) {
                unlink($archivo);
            }
            $this->respaldos->marcarEliminado((int)$viejo['id']);
            $borrados++;
        }
        return $borrados;
    }

    /** @return array{ok: bool, mensaje: string} */
    public function verificar(int $id, ?int $usuarioId, ?string $ip, ?string $agente): array
    {
        $fila = $this->buscar($id);
        try {
            $leido = $this->leer($fila);
            if (!hash_equals($fila['sha256'], $leido['cabecera']['sha256'])) {
                throw new RespaldoInvalido('El archivo no es el que se registró al crear el respaldo.');
            }
            $this->respaldos->marcarVerificado($id);
            $r = ['ok' => true, 'mensaje' => 'El respaldo está íntegro: firma y huella correctas.'];
        } catch (RespaldoInvalido $e) {
            $r = ['ok' => false, 'mensaje' => $e->getMessage()];
        }
        $this->historial->evento('verificar_respaldo', 'respaldos', $id, $usuarioId, ['ok' => $r['ok']], $ip, $agente);
        return $r;
    }

    /** Contenido para descargar; deja el evento en el historial. */
    public function contenidoParaDescargar(int $id, ?int $usuarioId, ?string $ip, ?string $agente): array
    {
        $fila = $this->buscar($id);
        $archivo = $this->archivo($fila) ?? throw new RespaldoInvalido('El archivo de este respaldo ya no existe (se eliminó por la retención).');
        $this->historial->evento('descargar_respaldo', 'respaldos', $id, $usuarioId, ['archivo' => $fila['archivo']], $ip, $agente);
        return ['nombre' => $fila['archivo'], 'contenido' => (string)file_get_contents($archivo)];
    }

    /** Bytes del archivo de un respaldo registrado (sin dejar evento: uso interno). */
    public function contenidoInterno(array $fila): string
    {
        $archivo = $this->archivo($fila) ?? throw new RespaldoInvalido('El archivo de este respaldo ya no existe.');
        return (string)file_get_contents($archivo);
    }

    /** Lee y valida un respaldo registrado. */
    public function leer(array $fila): array
    {
        $archivo = $this->archivo($fila) ?? throw new RespaldoInvalido('El archivo de este respaldo ya no existe.');
        return FormatoRespaldo::leer((string)file_get_contents($archivo), $this->llave);
    }

    /** Lee y valida un archivo subido (o de otra ruta). */
    public function leerContenido(string $comprimido): array
    {
        $leido = FormatoRespaldo::leer($comprimido, $this->llave);
        $errores = Importador::validar($leido['datos']);
        if ($errores !== []) {
            throw new RespaldoInvalido('Hay registros que no cumplen las reglas: ' . implode(' ', array_slice($errores, 0, 5))
                . (count($errores) > 5 ? ' …' : ''));
        }
        return $leido;
    }

    public function vistaPrevia(array $datos): array
    {
        return (new Importador($this->datos))->analizar($datos);
    }

    /**
     * Restaura agregando lo que falta:
     * candado → respaldo previo verificado → una sola transacción → registro.
     */
    public function restaurarAgregando(array $leido, string $origen, string $motivo, ?int $usuarioId, ?string $ip, ?string $agente): array
    {
        $pdo = $this->bd->pdo();
        if ((int)$pdo->query("SELECT GET_LOCK('" . self::CANDADO . "', 0)")->fetchColumn() !== 1) {
            throw new RespaldoInvalido('Ya hay una restauración en curso. Espera a que termine.');
        }
        try {
            $previo = $this->crear('previo_restauracion', $usuarioId, $ip, $agente);
            $this->leer($previo); // si no se puede releer y validar, se cancela
            $importador = new Importador($this->datos);
            try {
                $r = $this->bd->transaccion(fn() => $importador->agregar($leido['datos']),
                    mb_substr("Restauración desde $origen: $motivo", 0, 255));
            } catch (PDOException $e) {
                throw new RespaldoInvalido('La base rechazó un registro y no se restauró nada: ' . (ErroresBd::mensaje($e) ?? $e->getMessage()));
            }
            $this->historial->evento('restaurar', 'respaldos', (int)$previo['id'], $usuarioId, [
                'origen' => $origen, 'creado_en_respaldo' => $leido['cabecera']['creado_en'], 'agregados' => $r['nuevos'],
                'omitidos' => count($r['omitidos']), 'respaldo_previo' => $previo['archivo'],
            ], $ip, $agente, mb_substr($motivo, 0, 255));
            return $r + ['previo' => $previo];
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . self::CANDADO . "')");
        }
    }

    public function buscar(int $id): array
    {
        return $this->respaldos->porId($id) ?? throw new RespaldoInvalido('No existe ese respaldo.');
    }

    /** Ruta del archivo, solo si sigue existiendo y el nombre es seguro. */
    private function archivo(array $fila): ?string
    {
        if ($fila['archivo_eliminado_en'] !== null || !preg_match('/^respaldo-[0-9A-Za-z._-]+\.json\.gz$/', (string)$fila['archivo'])) {
            return null;
        }
        $ruta = $this->ruta . '/' . $fila['archivo'];
        return is_file($ruta) ? $ruta : null;
    }
}
