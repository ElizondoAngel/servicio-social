<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

final class IntentoLoginRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrar(string $correo, string $ip, bool $exito): void
    {
        $this->pdo->prepare('INSERT INTO intentos_login (correo, ip, exito) VALUES (?, ?, ?)')
            ->execute([mb_substr(mb_strtolower($correo), 0, 190), $ip, $exito ? 1 : 0]);
    }

    public function fallosPorCorreo(string $correo, int $minutos): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM intentos_login WHERE correo = ? AND exito = 0 AND creado_en > NOW() - INTERVAL ? MINUTE');
        $q->execute([mb_strtolower($correo), $minutos]);
        return (int)$q->fetchColumn();
    }

    public function fallosPorIp(string $ip, int $minutos): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM intentos_login WHERE ip = ? AND exito = 0 AND creado_en > NOW() - INTERVAL ? MINUTE');
        $q->execute([$ip, $minutos]);
        return (int)$q->fetchColumn();
    }

    public function depurar(int $dias = 30): int
    {
        $q = $this->pdo->prepare('DELETE FROM intentos_login WHERE creado_en < NOW() - INTERVAL ? DAY');
        $q->execute([$dias]);
        return $q->rowCount();
    }
}
