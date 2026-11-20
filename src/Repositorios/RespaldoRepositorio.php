<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

final class RespaldoRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function crear(array $d): int
    {
        $campos = ['tipo', 'archivo', 'tamano_bytes', 'sha256', 'firma_hmac', 'verificado_en', 'version_formato',
            'total_alumnos', 'total_expedientes', 'total_asistencias', 'creado_por'];
        $this->pdo->prepare('INSERT INTO respaldos (' . implode(', ', $campos) . ') VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ')')
            ->execute(array_map(fn($c) => $d[$c] ?? null, $campos));
        return (int)$this->pdo->lastInsertId();
    }

    public function todos(int $limite = 100): array
    {
        return $this->pdo->query('SELECT r.*, u.nombre AS creado_por_nombre FROM respaldos r LEFT JOIN usuarios u ON u.id = r.creado_por
                                   ORDER BY r.creado_en DESC, r.id DESC LIMIT ' . max(1, $limite))->fetchAll();
    }

    public function porId(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM respaldos WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public function ultimo(): ?array
    {
        return $this->pdo->query('SELECT * FROM respaldos WHERE archivo_eliminado_en IS NULL ORDER BY creado_en DESC, id DESC LIMIT 1')->fetch() ?: null;
    }

    /** Respaldos con archivo, del más nuevo al más viejo. */
    public function conArchivo(): array
    {
        return $this->pdo->query('SELECT * FROM respaldos WHERE archivo_eliminado_en IS NULL ORDER BY creado_en DESC, id DESC')->fetchAll();
    }

    public function marcarVerificado(int $id): void
    {
        $this->pdo->prepare('UPDATE respaldos SET verificado_en = NOW() WHERE id = ?')->execute([$id]);
    }

    public function marcarEliminado(int $id): void
    {
        $this->pdo->prepare('UPDATE respaldos SET archivo_eliminado_en = NOW() WHERE id = ?')->execute([$id]);
    }
}
