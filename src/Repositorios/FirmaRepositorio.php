<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

/** Firma semanal de la bitácora en papel (una por lunes). */
final class FirmaRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, true> lunes => true */
    public function deExpediente(int $expedienteId): array
    {
        $q = $this->pdo->prepare('SELECT semana_inicio FROM firmas_semana WHERE expediente_id = ?');
        $q->execute([$expedienteId]);
        return array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN), true);
    }

    public function crear(int $expedienteId, string $lunes, int $usuarioId): void
    {
        $this->pdo->prepare('INSERT INTO firmas_semana (expediente_id, semana_inicio, registrado_por) VALUES (?, ?, ?)')
            ->execute([$expedienteId, $lunes, $usuarioId]);
    }

    public function quitar(int $expedienteId, string $lunes): bool
    {
        $q = $this->pdo->prepare('DELETE FROM firmas_semana WHERE expediente_id = ? AND semana_inicio = ?');
        $q->execute([$expedienteId, $lunes]);
        return $q->rowCount() === 1;
    }
}
