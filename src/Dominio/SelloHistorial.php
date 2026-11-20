<?php
declare(strict_types=1);

namespace ServicioSocial\Dominio;

/**
 * Cadena de sellos del historial: cada fila sellada guarda
 *   hash = SHA-256( hash de la fila sellada anterior + contenido de la fila )
 * Si alguien altera o borra una fila ya sellada (por ejemplo con root,
 * saltándose los triggers), el hash de esa fila o el de la siguiente deja
 * de coincidir y la verificación lo señala.
 */
final class SelloHistorial
{
    public const INICIO = '0000000000000000000000000000000000000000000000000000000000000000';
    public const CAMPOS = ['id', 'origen', 'usuario_id', 'usuario_bd', 'accion', 'entidad', 'entidad_id', 'expediente_id',
        'antes', 'despues', 'motivo', 'ip', 'agente', 'creado_en'];

    /** Texto canónico de una fila: los campos en orden fijo, nulos explícitos. */
    public static function contenido(array $fila): string
    {
        $valores = [];
        foreach (self::CAMPOS as $c) {
            $valores[] = $fila[$c] === null ? null : (string)$fila[$c];
        }
        return json_encode($valores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function encadenar(string $anterior, array $fila): string
    {
        return hash('sha256', $anterior . "\n" . self::contenido($fila));
    }

    /**
     * Recorre filas ordenadas por id y comprueba la cadena.
     * @param iterable<array> $filas
     * @return array{ok: bool, revisadas: int, selladas: int, sin_sellar: int, ultimo_hash: string,
     *               ultimo_sellado: ?int, problemas: list<array{id: int, tipo: string}>}
     */
    public static function verificar(iterable $filas, int $maxProblemas = 20): array
    {
        $anterior = self::INICIO;
        $revisadas = $selladas = $sinSellar = 0;
        $ultimoSellado = null;
        $problemas = [];
        $huecoAbierto = false; // ya apareció una fila sin sellar
        foreach ($filas as $f) {
            $revisadas++;
            if ($f['hash'] === null) {
                $sinSellar++;
                $huecoAbierto = true;
                continue;
            }
            $selladas++;
            if ($huecoAbierto && count($problemas) < $maxProblemas) {
                // Una fila sellada después de otra sin sellar: el orden de la cadena se rompió.
                $problemas[] = ['id' => (int)$f['id'], 'tipo' => 'sellada_despues_de_pendiente'];
            }
            if (!hash_equals(self::encadenar($anterior, $f), (string)$f['hash']) && count($problemas) < $maxProblemas) {
                $problemas[] = ['id' => (int)$f['id'], 'tipo' => 'no_coincide'];
            }
            $anterior = (string)$f['hash'];
            $ultimoSellado = (int)$f['id'];
        }
        return [
            'ok' => $problemas === [], 'revisadas' => $revisadas, 'selladas' => $selladas, 'sin_sellar' => $sinSellar,
            'ultimo_hash' => $anterior, 'ultimo_sellado' => $ultimoSellado, 'problemas' => $problemas,
        ];
    }

    public static function describir(string $tipo): string
    {
        return match ($tipo) {
            'no_coincide' => 'El sello no coincide: la fila se alteró o se borró la fila sellada anterior.',
            'sellada_despues_de_pendiente' => 'Hay una fila sellada después de otra sin sellar.',
            default => $tipo,
        };
    }
}
