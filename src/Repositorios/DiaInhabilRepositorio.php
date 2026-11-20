<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

final class DiaInhabilRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, string> fecha => tipo */
    public function mapa(): array
    {
        return $this->pdo->query('SELECT fecha, tipo FROM dias_inhabiles ORDER BY fecha')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Con cuántos registros de asistencia los usan. */
    public function todos(): array
    {
        return $this->pdo->query('SELECT d.*, (SELECT COUNT(*) FROM asistencias a WHERE a.dia_inhabil_id = d.id) AS usos
                                    FROM dias_inhabiles d ORDER BY d.fecha DESC')->fetchAll();
    }

    public function porId(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM dias_inhabiles WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public function porFecha(string $fecha): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM dias_inhabiles WHERE fecha = ?');
        $q->execute([$fecha]);
        return $q->fetch() ?: null;
    }

    public function crear(string $fecha, string $tipo, string $descripcion): int
    {
        $this->pdo->prepare('INSERT INTO dias_inhabiles (fecha, tipo, descripcion) VALUES (?, ?, ?)')->execute([$fecha, $tipo, $descripcion]);
        return (int)$this->pdo->lastInsertId();
    }

    public function quitar(int $id): bool
    {
        $q = $this->pdo->prepare('DELETE FROM dias_inhabiles WHERE id = ?');
        $q->execute([$id]);
        return $q->rowCount() === 1;
    }
}
