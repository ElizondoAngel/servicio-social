<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

final class AsistenciaRepositorio
{
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
}
