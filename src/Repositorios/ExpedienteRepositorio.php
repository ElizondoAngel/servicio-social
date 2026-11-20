<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

final class ExpedienteRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function porId(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM expedientes WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** Todos los expedientes de un alumno, el más reciente primero. */
    public function deAlumno(int $alumnoId): array
    {
        $q = $this->pdo->prepare('SELECT * FROM expedientes WHERE alumno_id = ? ORDER BY fecha_inicio DESC');
        $q->execute([$alumnoId]);
        return $q->fetchAll();
    }

    public function crear(array $d): int
    {
        $campos = ['alumno_id', 'unidad_administrativa', 'responsable', 'hora_entrada', 'hora_salida', 'fecha_inicio',
            'fecha_fin_autorizada', 'horas_requeridas', 'meses_requeridos', 'jornada_prevista', 'feriados_acreditan',
            'horas_por_feriado', 'observaciones', 'creado_por'];
        $this->pdo->prepare('INSERT INTO expedientes (' . implode(', ', $campos) . ') VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ')')
            ->execute(array_map(fn($c) => $d[$c] ?? null, $campos));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Un expediente por alumno no archivado para el tablero: el abierto
     * (activo o suspendido) o, si no hay, el más reciente.
     * $busqueda filtra por matrícula o nombre.
     */
    public function vigentes(string $busqueda = ''): array
    {
        $sql = "SELECT a.id AS alumno_id, a.matricula, a.nombre, a.apellido_paterno, a.apellido_materno, a.carrera,
                       e.id AS expediente_id, e.fecha_inicio, e.fecha_fin_autorizada, e.horas_requeridas, e.meses_requeridos,
                       e.jornada_prevista, e.feriados_acreditan, e.horas_por_feriado, e.estado
                  FROM alumnos a
                  JOIN expedientes e ON e.id = (
                        SELECT x.id FROM expedientes x WHERE x.alumno_id = a.id
                         ORDER BY x.estado IN ('activo', 'suspendido') DESC, x.fecha_inicio DESC LIMIT 1)
                 WHERE a.archivado = 0";
        $params = [];
        if ($busqueda !== '') {
            $like = '%' . addcslashes($busqueda, '%_\\') . '%';
            $sql .= " AND (a.matricula LIKE ? OR CONCAT_WS(' ', a.nombre, a.apellido_paterno, a.apellido_materno) LIKE ?
                           OR CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) LIKE ?)";
            $params = [$like, $like, $like];
        }
        $sql .= ' ORDER BY a.apellido_paterno, a.apellido_materno, a.nombre';
        $q = $this->pdo->prepare($sql);
        $q->execute($params);
        return $q->fetchAll();
    }

    /** Números generales del tablero (no dependen de la búsqueda). */
    public function conteos(): array
    {
        return $this->pdo->query(
            "SELECT (SELECT COUNT(*) FROM alumnos WHERE archivado = 0) AS alumnos,
                    (SELECT COUNT(DISTINCT e.alumno_id) FROM expedientes e JOIN alumnos a ON a.id = e.alumno_id
                      WHERE a.archivado = 0 AND e.estado = 'activo') AS activos,
                    (SELECT COUNT(DISTINCT e.alumno_id) FROM expedientes e JOIN alumnos a ON a.id = e.alumno_id
                      WHERE a.archivado = 0 AND e.estado = 'concluido') AS concluidos,
                    (SELECT COALESCE(SUM(s.horas_acreditadas), 0) FROM asistencias s
                       JOIN expedientes e ON e.id = s.expediente_id JOIN alumnos a ON a.id = e.alumno_id
                      WHERE a.archivado = 0) AS horas")->fetch();
    }
}
