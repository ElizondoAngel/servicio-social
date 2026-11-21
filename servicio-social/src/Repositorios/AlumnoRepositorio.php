<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

final class AlumnoRepositorio
{
    private const CAMPOS = ['matricula', 'nombre', 'apellido_paterno', 'apellido_materno', 'carrera', 'institucion', 'correo', 'telefono'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function porId(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM alumnos WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public function idPorMatricula(string $matricula): ?int
    {
        $q = $this->pdo->prepare('SELECT id FROM alumnos WHERE matricula = ?');
        $q->execute([$matricula]);
        $id = $q->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function crear(array $d): int
    {
        $this->pdo->prepare('INSERT INTO alumnos (' . implode(', ', self::CAMPOS) . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_map(fn($c) => $d[$c] ?? null, self::CAMPOS));
        return (int)$this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $d): void
    {
        $sets = implode(', ', array_map(fn($c) => "$c = ?", self::CAMPOS));
        $valores = array_map(fn($c) => $d[$c] ?? null, self::CAMPOS);
        $valores[] = $id;
        $this->pdo->prepare("UPDATE alumnos SET $sets WHERE id = ?")->execute($valores);
    }
}
