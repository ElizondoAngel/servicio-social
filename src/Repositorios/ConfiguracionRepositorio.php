<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

/**
 * Lee la tabla configuracion una vez por petición.
 */
final class ConfiguracionRepositorio
{
    private ?array $cache = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function texto(string $clave, string $porDefecto = ''): string
    {
        if ($this->cache === null) {
            $this->cache = $this->pdo->query('SELECT clave, valor FROM configuracion')->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        return isset($this->cache[$clave]) ? (string)$this->cache[$clave] : $porDefecto;
    }

    public function entero(string $clave, int $porDefecto): int
    {
        $valor = $this->texto($clave, (string)$porDefecto);
        return ctype_digit($valor) && (int)$valor > 0 ? (int)$valor : $porDefecto;
    }
}
