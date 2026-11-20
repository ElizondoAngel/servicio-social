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

    /** Últimos movimientos de un expediente con el nombre de quien los hizo. */
    public function deExpediente(int $expedienteId, int $limite = 60): array
    {
        $q = $this->pdo->prepare('SELECT h.id, h.accion, h.entidad, h.entidad_id, h.antes, h.despues, h.motivo, h.creado_en,
                                         h.usuario_bd, u.nombre AS usuario
                                    FROM historial h LEFT JOIN usuarios u ON u.id = h.usuario_id
                                   WHERE h.expediente_id = ? ORDER BY h.id DESC LIMIT ' . max(1, min(500, $limite)));
        $q->execute([$expedienteId]);
        return $q->fetchAll();
    }
}
