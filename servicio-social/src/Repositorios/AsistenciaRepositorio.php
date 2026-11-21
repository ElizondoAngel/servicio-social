<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

final class AsistenciaRepositorio
{
    private const CAMPOS = ['estado', 'horas_trabajadas', 'horas_acreditadas', 'dia_inhabil_id', 'justificacion',
        'hora_entrada', 'hora_salida', 'observaciones', 'autorizado_por', 'motivo_autorizacion'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Registros mínimos para la Calculadora, agrupados por expediente.
     * @return array<int, array>
     */
    public function paraCalculo(array $expedienteIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $expedienteIds)));
        $grupos = array_fill_keys($ids, []);
        if ($ids === []) {
            return $grupos;
        }
        $q = $this->pdo->prepare('SELECT expediente_id, fecha, estado, horas_trabajadas, horas_acreditadas FROM asistencias
                                   WHERE expediente_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute($ids);
        foreach ($q as $fila) {
            $grupos[(int)$fila['expediente_id']][] = $fila;
        }
        return $grupos;
    }

    /** @return array<string, array> fecha => registro completo */
    public function deExpediente(int $expedienteId): array
    {
        $q = $this->pdo->prepare('SELECT * FROM asistencias WHERE expediente_id = ? ORDER BY fecha');
        $q->execute([$expedienteId]);
        $mapa = [];
        foreach ($q as $fila) {
            $mapa[$fila['fecha']] = $fila;
        }
        return $mapa;
    }

    public function porFecha(int $expedienteId, string $fecha): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM asistencias WHERE expediente_id = ? AND fecha = ?');
        $q->execute([$expedienteId, $fecha]);
        return $q->fetch() ?: null;
    }

    public function crear(int $expedienteId, string $fecha, array $d, int $usuarioId): int
    {
        $campos = array_merge(['expediente_id', 'fecha', 'registrado_por'], self::CAMPOS);
        $valores = array_merge([$expedienteId, $fecha, $usuarioId], array_map(fn($c) => $d[$c] ?? null, self::CAMPOS));
        $this->pdo->prepare('INSERT INTO asistencias (' . implode(', ', $campos) . ') VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ')')
            ->execute($valores);
        return (int)$this->pdo->lastInsertId();
    }

    /** Actualiza solo si nadie lo cambió desde que se leyó (versión). */
    public function actualizar(int $id, int $version, array $d, int $usuarioId): bool
    {
        $sets = implode(', ', array_map(fn($c) => "$c = ?", self::CAMPOS));
        $q = $this->pdo->prepare("UPDATE asistencias SET $sets, actualizado_por = ? WHERE id = ? AND version = ?");
        $q->execute(array_merge(array_map(fn($c) => $d[$c] ?? null, self::CAMPOS), [$usuarioId, $id, $version]));
        return $q->rowCount() === 1;
    }

    public function quitar(int $id, int $version): bool
    {
        $q = $this->pdo->prepare('DELETE FROM asistencias WHERE id = ? AND version = ?');
        $q->execute([$id, $version]);
        return $q->rowCount() === 1;
    }

    /** Registros sin autorización fuera de [inicio, fin]. */
    public function fueraDeVentana(int $expedienteId, string $inicio, string $fin): array
    {
        $q = $this->pdo->prepare('SELECT * FROM asistencias WHERE expediente_id = ? AND autorizado_por IS NULL
                                     AND (fecha < ? OR fecha > ?) ORDER BY fecha');
        $q->execute([$expedienteId, $inicio, $fin]);
        return $q->fetchAll();
    }

    public function autorizar(int $id, int $usuarioId, string $motivo): void
    {
        $this->pdo->prepare('UPDATE asistencias SET autorizado_por = ?, motivo_autorizacion = ?, actualizado_por = ? WHERE id = ?')
            ->execute([$usuarioId, $motivo, $usuarioId, $id]);
    }

    /** Feriados del catálogo sin justificación propia (siguen la regla del expediente). */
    public function feriadosDeCatalogo(int $expedienteId): array
    {
        $q = $this->pdo->prepare("SELECT * FROM asistencias WHERE expediente_id = ? AND estado = 'feriado'
                                     AND dia_inhabil_id IS NOT NULL AND justificacion IS NULL");
        $q->execute([$expedienteId]);
        return $q->fetchAll();
    }

    public function fijarAcreditadas(int $id, int $horas, int $usuarioId): void
    {
        $this->pdo->prepare('UPDATE asistencias SET horas_acreditadas = ?, actualizado_por = ? WHERE id = ?')
            ->execute([$horas, $usuarioId, $id]);
    }
}
