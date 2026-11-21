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

    /** Entero no negativo; si el valor guardado no es un número, el valor por omisión. */
    public function entero(string $clave, int $porDefecto): int
    {
        $valor = $this->texto($clave, (string)$porDefecto);
        return ctype_digit($valor) ? (int)$valor : $porDefecto;
    }

    /** Todas las claves con su descripción y quién las cambió por última vez. */
    public function todas(): array
    {
        return $this->pdo->query('SELECT c.*, u.nombre AS actualizado_por_nombre FROM configuracion c
                                    LEFT JOIN usuarios u ON u.id = c.actualizado_por ORDER BY c.clave')->fetchAll();
    }

    public function actualizar(string $clave, string $valor, int $usuarioId): void
    {
        $this->pdo->prepare('UPDATE configuracion SET valor = ?, actualizado_por = ? WHERE clave = ?')->execute([$valor, $usuarioId, $clave]);
        $this->cache = null;
    }
}
