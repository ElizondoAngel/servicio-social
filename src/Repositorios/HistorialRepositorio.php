<?php
declare(strict_types=1);

namespace ServicioSocial\Repositorios;

use PDO;

/**
 * Eventos de la aplicación que no cambian datos (inicios de sesión,
 * descargas, respaldos). Los cambios de datos los registran los triggers.
 */
final class HistorialRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function evento(string $accion, string $entidad, ?int $entidadId, ?int $usuarioId, ?array $detalle,
                           ?string $ip, ?string $agente, ?string $motivo = null): void
    {
        $this->pdo->prepare(
            "INSERT INTO historial (origen, usuario_id, usuario_bd, accion, entidad, entidad_id, despues, motivo, ip, agente)
             VALUES ('app', ?, USER(), ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$usuarioId, $accion, $entidad, $entidadId,
                $detalle === null ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $motivo, $ip, $agente === null ? null : mb_substr($agente, 0, 255)]);
    }
}
