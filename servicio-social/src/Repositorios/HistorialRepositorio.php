<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

/**
 * Eventos de la aplicación que no cambian datos (inicios de sesión,
 * descargas, respaldos). Los cambios de datos los registran los triggers.
 */
final class HistorialRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function evento(string $accion, string $entidad, ?int $entidadId, ?int $usuarioId, ?array $detalle,
                           ?string $ip, ?string $agente, ?string $motivo = null): void
    {
        $this->pdo->prepare(
            "INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, despues, motivo, ip, agente)
             VALUES ('app', ?, USER(), ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$usuarioId, $accion, $entidad, $entidadId,
                $detalle === null ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $motivo, $ip, $agente === null ? null : mb_substr($agente, 0, 255)]);
    }

    /** Últimos movimientos de un expediente con el nombre de quien los hizo. */
    public function deExpediente(int $expedienteId, int $limite = 60): array
    {
        $q = $this->pdo->prepare('SELECT h.id, h.accion, h.entidad, h.entidad_id, h.antes, h.despues, h.motivo, h.creado_en,
                                         h.usuario_bd, u.nombre AS usuario
                                    FROM historial h LEFT JOIN usuarios u ON u.id = h.usuario_id
                                   WHERE h.expediente_id = ? ORDER BY h.id DESC LIMIT ' . max(1, min(500, $limite)));
        $q->execute([$expedienteId]);
        return $q->fetchAll();
    }

    /**
     * Búsqueda para la página de historial.
     * Filtros: entidad, accion, usuario_id, expediente_id, desde, hasta (Y-m-d), texto (en el motivo).
     */
    public function buscar(array $f, int $limite, int $desplazar): array
    {
        [$where, $params] = $this->condiciones($f);
        $q = $this->pdo->prepare("SELECT h.id, h.origen, h.accion, h.entidad, h.entidad_id, h.expediente_id, h.motivo, h.creado_en,
                                         h.usuario_bd, h.hash IS NOT NULL AS sellada, u.nombre AS usuario,
                                         a.matricula, a.nombre AS alumno_nombre, a.apellido_paterno
                                    FROM historial h
                                    LEFT JOIN usuarios u ON u.id = h.usuario_id
                                    LEFT JOIN expedientes e ON e.id = h.expediente_id
                                    LEFT JOIN alumnos a ON a.id = e.alumno_id
                                   $where ORDER BY h.id DESC LIMIT " . max(1, min(200, $limite)) . ' OFFSET ' . max(0, $desplazar));
        $q->execute($params);
        return $q->fetchAll();
    }

    public function contar(array $f): int
    {
        [$where, $params] = $this->condiciones($f);
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM historial h $where");
        $q->execute($params);
        return (int)$q->fetchColumn();
    }

    public function porId(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT h.*, u.nombre AS usuario, u.correo AS usuario_correo FROM historial h
                                    LEFT JOIN usuarios u ON u.id = h.usuario_id WHERE h.id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** Valores distintos para los filtros. */
    public function opciones(): array
    {
        return [
            'entidades' => $this->pdo->query('SELECT DISTINCT entidad FROM historial ORDER BY entidad')->fetchAll(PDO::FETCH_COLUMN),
            'acciones'  => $this->pdo->query('SELECT DISTINCT accion FROM historial ORDER BY accion')->fetchAll(PDO::FETCH_COLUMN),
            'usuarios'  => $this->pdo->query('SELECT id, nombre FROM usuarios ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR),
        ];
    }

    /** Resumen del sellado sin recorrer la cadena. */
    public function estadoSello(): array
    {
        return $this->pdo->query('SELECT COUNT(*) AS total, SUM(hash IS NOT NULL) AS selladas, MAX(sellado_en) AS ultimo_sellado,
                                         MIN(CASE WHEN hash IS NULL THEN creado_en END) AS pendiente_mas_antigua
                                    FROM historial')->fetch();
    }

    /**
     * Todas las filas en orden de id, en bloques, para verificar la cadena
     * sin cargar todo en memoria.
     * @return \Generator<array>
     */
    public function recorrer(int $bloque = 1000): \Generator
    {
        $ultimo = 0;
        $q = $this->pdo->prepare('SELECT * FROM historial WHERE id > ? ORDER BY id LIMIT ' . $bloque);
        do {
            $q->execute([$ultimo]);
            $filas = $q->fetchAll();
            foreach ($filas as $f) {
                $ultimo = (int)$f['id'];
                yield $f;
            }
        } while (count($filas) === $bloque);
    }

    private function condiciones(array $f): array
    {
        $w = [];
        $p = [];
        foreach (['entidad' => 'h.entidad = ?', 'accion' => 'h.accion = ?', 'usuario_id' => 'h.usuario_id = ?',
                  'expediente_id' => 'h.expediente_id = ?'] as $clave => $sql) {
            if (($f[$clave] ?? '') !== '' && $f[$clave] !== null) {
                $w[] = $sql;
                $p[] = $f[$clave];
            }
        }
        if (!empty($f['desde'])) {
            $w[] = 'h.creado_en >= ?';
            $p[] = $f['desde'] . ' 00:00:00';
        }
        if (!empty($f['hasta'])) {
            $w[] = 'h.creado_en < DATE_ADD(?, INTERVAL 1 DAY)';
            $p[] = $f['hasta'];
        }
        if (!empty($f['texto'])) {
            $w[] = 'h.motivo LIKE ?';
            $p[] = '%' . addcslashes((string)$f['texto'], '%_\\') . '%';
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }
}
