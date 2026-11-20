<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

/**
 * Tokens de recuperación. Solo se guarda el SHA-256; las fechas se
 * comparan en MariaDB para no depender del reloj de PHP.
 */
final class TokenResetRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Invalida los tokens anteriores del usuario y crea uno nuevo. */
    public function crear(int $usuarioId, string $tokenHash, int $minutos, string $ip): void
    {
        $this->pdo->prepare('UPDATE tokens_reset SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL')
            ->execute([$usuarioId]);
        $this->pdo->prepare('INSERT INTO tokens_reset (usuario_id, token_hash, expira_en, ip) VALUES (?, ?, NOW() + INTERVAL ? MINUTE, ?)')
            ->execute([$usuarioId, $tokenHash, $minutos, $ip]);
    }

    public function recientesPorUsuario(int $usuarioId, int $minutos): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM tokens_reset WHERE usuario_id = ? AND creado_en > NOW() - INTERVAL ? MINUTE');
        $q->execute([$usuarioId, $minutos]);
        return (int)$q->fetchColumn();
    }

    public function recientesPorIp(string $ip, int $minutos): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM tokens_reset WHERE ip = ? AND creado_en > NOW() - INTERVAL ? MINUTE');
        $q->execute([$ip, $minutos]);
        return (int)$q->fetchColumn();
    }

    /** Token sin usar, sin vencer y de un usuario activo. */
    public function vigente(string $tokenHash): ?array
    {
        $q = $this->pdo->prepare(
            'SELECT t.id, t.usuario_id, u.correo, u.nombre
               FROM tokens_reset t JOIN usuarios u ON u.id = t.usuario_id
              WHERE t.token_hash = ? AND t.usado_en IS NULL AND t.expira_en > NOW() AND u.activo = 1');
        $q->execute([$tokenHash]);
        return $q->fetch() ?: null;
    }

    /** Marca el token como usado solo si sigue sin usar (evita doble uso). */
    public function marcarUsado(int $id): bool
    {
        $q = $this->pdo->prepare('UPDATE tokens_reset SET usado_en = NOW() WHERE id = ? AND usado_en IS NULL');
        $q->execute([$id]);
        return $q->rowCount() === 1;
    }
}
