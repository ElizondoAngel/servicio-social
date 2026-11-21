<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

use PDO;

/**
 * Una conexión PDO por petición, con modo estricto y la zona horaria de
 * México. También fija las variables que leen los triggers de historial
 * (@usuario_app, @ip_app, @agente_app, @motivo_app).
 */
final class BaseDatos
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config->get('bd.host', 'localhost'),
                (int)$this->config->get('bd.puerto', 3306),
                $this->config->get('bd.nombre', 'servicio_social'));
            $this->pdo = new PDO($dsn, (string)$this->config->get('bd.usuario'), (string)$this->config->get('bd.password'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $this->pdo->exec("SET time_zone = '" . (new \DateTimeImmutable())->format('P') . "'");
        }
        return $this->pdo;
    }

    /** Quién hace los cambios de esta petición (lo leen los triggers). */
    public function fijarContexto(?int $usuarioId, ?string $ip, ?string $agente): void
    {
        $this->pdo()->prepare('SET @usuario_app = ?, @ip_app = ?, @agente_app = ?, @motivo_app = NULL')
            ->execute([$usuarioId, $ip, $agente === null ? null : mb_substr($agente, 0, 255)]);
    }

    /**
     * Ejecuta $fn en una transacción con un motivo para el historial.
     * Si algo falla, no se guarda nada.
     */
    public function transaccion(callable $fn, ?string $motivo = null): mixed
    {
        $pdo = $this->pdo();
        // Mientras otra conexión restaura un respaldo, no se captura nada.
        $candado = $pdo->query("SELECT IS_USED_LOCK('ss_restauracion') AS dueno, CONNECTION_ID() AS yo")->fetch();
        if ($candado['dueno'] !== null && (int)$candado['dueno'] !== (int)$candado['yo']) {
            throw new HttpExcepcion(423, 'Hay una restauración de respaldo en curso. Espera un momento y vuelve a intentarlo.');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SET @motivo_app = ?')->execute([$motivo]);
            $resultado = $fn($pdo);
            $pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $pdo->exec('SET @motivo_app = NULL');
        }
    }
}
