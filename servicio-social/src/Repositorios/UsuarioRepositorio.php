<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

final class UsuarioRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function porCorreo(string $correo): ?array
    {
        $q = $this->pdo->prepare('SELECT id, nombre, correo, password_hash, rol, activo, sesion_version FROM usuarios WHERE correo = ?');
        $q->execute([mb_strtolower(trim($correo))]);
        return $q->fetch() ?: null;
    }

    public function porId(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT id, nombre, correo, password_hash, rol, activo, sesion_version FROM usuarios WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public function registrarAcceso(int $id): void
    {
        $this->pdo->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?')->execute([$id]);
    }

    /** Cambia el hash sin cerrar sesiones (actualización de algoritmo). */
    public function actualizarHash(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    /** Cambia la contraseña y sube la versión: todas las sesiones abiertas dejan de valer. */
    public function cambiarPassword(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE usuarios SET password_hash = ?, sesion_version = sesion_version + 1 WHERE id = ?')
            ->execute([$hash, $id]);
    }
}
