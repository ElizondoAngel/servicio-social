<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use PDO;
use ServicioSocial\Dominio\SelloHistorial;

/**
 * Sella el historial con el usuario ss_sello, que solo puede leer el
 * historial y escribir las columnas hash y sellado_en. Lo corre el cron
 * cada noche (bin/sellar-historial.php).
 *
 * Solo sella filas con al menos $antiguedadSeg segundos, para no sellar
 * antes de que termine una transacción con un id menor.
 */
final class SelladorHistorial
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{selladas: int, verificacion: array} */
    public function sellar(int $antiguedadSeg = 120): array
    {
        if ((int)$this->pdo->query("SELECT GET_LOCK('ss_sello', 0)")->fetchColumn() !== 1) {
            throw new \RuntimeException('Otro proceso está sellando el historial.');
        }
        try {
            $ultima = $this->pdo->query('SELECT id, hash FROM historial WHERE hash IS NOT NULL ORDER BY id DESC LIMIT 1')->fetch();
            $anterior = $ultima['hash'] ?? SelloHistorial::INICIO;
            $desde = (int)($ultima['id'] ?? 0);

            $limite = $this->pdo->query("SELECT NOW(3) - INTERVAL $antiguedadSeg SECOND")->fetchColumn();
            $leer = $this->pdo->prepare('SELECT * FROM historial WHERE id > ? AND hash IS NULL ORDER BY id LIMIT 1000');
            $escribir = $this->pdo->prepare('UPDATE historial SET hash = ?, sellado_en = NOW() WHERE id = ? AND hash IS NULL');
            $selladas = 0;
            do {
                $leer->execute([$desde]);
                $filas = $leer->fetchAll(PDO::FETCH_ASSOC);
                foreach ($filas as $f) {
                    if ((string)$f['creado_en'] >= (string)$limite) {
                        break 2; // muy reciente: se sella en la siguiente corrida
                    }
                    $hash = SelloHistorial::encadenar($anterior, $f);
                    $escribir->execute([$hash, $f['id']]);
                    if ($escribir->rowCount() !== 1) {
                        throw new \RuntimeException('No se pudo sellar la fila ' . $f['id'] . '.');
                    }
                    $anterior = $hash;
                    $desde = (int)$f['id'];
                    $selladas++;
                }
            } while (count($filas) === 1000);

            return ['selladas' => $selladas, 'verificacion' => $this->verificar()];
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('ss_sello')");
        }
    }

    public function verificar(): array
    {
        $filas = (function () {
            $ultimo = 0;
            $q = $this->pdo->prepare('SELECT * FROM historial WHERE id > ? ORDER BY id LIMIT 1000');
            do {
                $q->execute([$ultimo]);
                $bloque = $q->fetchAll(PDO::FETCH_ASSOC);
                foreach ($bloque as $f) {
                    $ultimo = (int)$f['id'];
                    yield $f;
                }
            } while (count($bloque) === 1000);
        })();
        return SelloHistorial::verificar($filas);
    }
}
