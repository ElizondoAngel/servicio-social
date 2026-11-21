<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

/**
 * Lectura de todas las tablas para el respaldo JSON y escrituras que
 * usa la restauración. Nunca toca contraseñas, hashes ni tokens.
 */
final class RespaldoDatosRepositorio
{
    public const COLUMNAS = [
        'usuarios'       => ['id', 'nombre', 'correo', 'rol', 'activo'],
        'dias_inhabiles' => ['id', 'fecha', 'tipo', 'descripcion'],
        'configuracion'  => ['clave', 'valor', 'descripcion'],
        'alumnos'        => ['id', 'matricula', 'nombre', 'apellido_paterno', 'apellido_materno', 'carrera', 'institucion',
                             'correo', 'telefono', 'archivado', 'creado_en'],
        'expedientes'    => ['id', 'alumno_id', 'unidad_administrativa', 'responsable', 'hora_entrada', 'hora_salida', 'fecha_inicio',
                             'fecha_fin_autorizada', 'horas_requeridas', 'meses_requeridos', 'jornada_prevista', 'feriados_acreditan',
                             'horas_por_feriado', 'estado', 'fecha_conclusion', 'resumen_conclusion', 'observaciones', 'creado_por', 'creado_en'],
        'asistencias'    => ['id', 'expediente_id', 'fecha', 'estado', 'horas_trabajadas', 'horas_acreditadas', 'dia_inhabil_id',
                             'justificacion', 'hora_entrada', 'hora_salida', 'observaciones', 'autorizado_por', 'motivo_autorizacion',
                             'registrado_por', 'actualizado_por', 'creado_en'],
        'firmas_semana'  => ['id', 'expediente_id', 'semana_inicio', 'registrado_por', 'firmado_en'],
        'historial'      => ['id', 'origen', 'usuario_id', 'usuario_bd', 'accion', 'entidad', 'entidad_id', 'expediente_id',
                             'antes', 'despues', 'motivo', 'ip', 'agente', 'creado_en', 'hash', 'sellado_en'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Todas las tablas en una sola foto consistente (transacción de solo lectura). */
    public function exportar(): array
    {
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $datos = [];
            foreach (self::COLUMNAS as $tabla => $cols) {
                $orden = $tabla === 'configuracion' ? 'clave' : 'id';
                $datos[$tabla] = $this->pdo->query('SELECT ' . implode(', ', $cols) . " FROM $tabla ORDER BY $orden")->fetchAll(PDO::FETCH_ASSOC);
            }
            $this->pdo->commit();
            return $datos;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ----- Búsquedas por llave natural ---------------------------------------

    /** @return array<string, int> correo => id */
    public function usuariosPorCorreo(): array
    {
        return array_map('intval', $this->pdo->query('SELECT LOWER(correo), id FROM usuarios')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return array<string, int> fecha => id (solo festivos y cierres) */
    public function diasPorFecha(): array
    {
        return array_map('intval', $this->pdo->query('SELECT fecha, id FROM dias_inhabiles')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return array<string, int> MATRÍCULA => id */
    public function alumnosPorMatricula(): array
    {
        return array_map('intval', $this->pdo->query('SELECT UPPER(matricula), id FROM alumnos')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return array<string, array{id: int, estado: string}> "alumno_id|fecha_inicio" => expediente */
    public function expedientesPorLlave(): array
    {
        $mapa = [];
        foreach ($this->pdo->query('SELECT id, alumno_id, fecha_inicio, estado FROM expedientes') as $e) {
            $mapa[$e['alumno_id'] . '|' . $e['fecha_inicio']] = ['id' => (int)$e['id'], 'estado' => $e['estado']];
        }
        return $mapa;
    }

    /** @return array<int, true> alumno_id con un expediente abierto */
    public function alumnosConAbierto(): array
    {
        return array_fill_keys(array_map('intval', $this->pdo->query("SELECT alumno_id FROM expedientes WHERE estado IN ('activo', 'suspendido')")
            ->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** @return array<string, true> "expediente_id|fecha" */
    public function asistenciasExistentes(): array
    {
        return array_fill_keys($this->pdo->query("SELECT CONCAT(expediente_id, '|', fecha) FROM asistencias")->fetchAll(PDO::FETCH_COLUMN), true);
    }

    /** @return array<string, true> "expediente_id|semana_inicio" */
    public function firmasExistentes(): array
    {
        return array_fill_keys($this->pdo->query("SELECT CONCAT(expediente_id, '|', semana_inicio) FROM firmas_semana")->fetchAll(PDO::FETCH_COLUMN), true);
    }

    public function totalFilas(): int
    {
        return (int)$this->pdo->query('SELECT (SELECT COUNT(*) FROM alumnos) + (SELECT COUNT(*) FROM expedientes) + (SELECT COUNT(*) FROM asistencias)
                                        + (SELECT COUNT(*) FROM firmas_semana) + (SELECT COUNT(*) FROM dias_inhabiles)')->fetchColumn();
    }

    // ----- Escrituras ----------------------------------------------------------

    /** Inserta columnas dadas; con $conId conserva el id del respaldo (restauración completa). */
    public function insertar(string $tabla, array $fila, bool $conId = false): int
    {
        $cols = array_values(array_filter(self::COLUMNAS[$tabla], fn($c) => ($conId || $c !== 'id') && array_key_exists($c, $fila)));
        $this->pdo->prepare("INSERT INTO $tabla (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_map(fn($c) => $fila[$c], $cols));
        return $conId && isset($fila['id']) ? (int)$fila['id'] : (int)$this->pdo->lastInsertId();
    }

    public function usuarioInactivo(string $nombre, string $correo, string $rol): int
    {
        $this->pdo->prepare('INSERT INTO usuarios (nombre, correo, rol, activo, password_hash) VALUES (?, ?, ?, 0, NULL)')->execute([$nombre, $correo, $rol]);
        return (int)$this->pdo->lastInsertId();
    }

    public function cerrarExpediente(int $id, string $estado, ?string $fechaConclusion, ?string $resumen): void
    {
        $this->pdo->prepare('UPDATE expedientes SET estado = ?, fecha_conclusion = ?, resumen_conclusion = ? WHERE id = ?')
            ->execute([$estado, $fechaConclusion, $resumen, $id]);
    }

    public function actualizarConfiguracion(string $clave, string $valor): void
    {
        $this->pdo->prepare('UPDATE configuracion SET valor = ? WHERE clave = ?')->execute([$valor, $clave]);
    }

    public function vacia(): bool
    {
        return $this->totalFilas() === 0;
    }
}
