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
}
