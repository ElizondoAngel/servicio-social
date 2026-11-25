<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use DateTimeImmutable;
use PDOException;
use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Repositorios\DiaInhabilRepositorio;
use ServicioSocial\Validacion\Validador;

/**
 * Catálogo general de días inhábiles: festivos (pueden acreditar horas
 * según la regla de cada expediente) y cierres (la oficina no abre).
 */
final class CatalogoServicio
{
    public const TIPOS = ['festivo' => 'Festivo', 'cierre' => 'Cierre de oficina'];

    public function __construct(private readonly BaseDatos $bd, private readonly DiaInhabilRepositorio $dias)
    {
    }

    /** Un periodo vacacional abarca a lo más este número de días naturales. */
    public const MAX_DIAS_PERIODO = 93;

    /**
     * Agrega un día o, con «periodo» marcado, todos los días hábiles (lunes
     * a viernes) entre la fecha y la fecha final. Las fechas que ya están en
     * el catálogo se conservan como están y se informan.
     */
    public function agregar(array $entrada): array
    {
        $periodo = ($entrada['periodo'] ?? '') === '1';
        $v = (new Validador($entrada))->fecha('fecha', $periodo ? 'la fecha de inicio' : 'la fecha')
            ->texto('descripcion', 'la descripción', 150);
        if ($periodo) {
            $v->fecha('fecha_fin', 'la fecha final');
        }
        $tipo = is_string($entrada['tipo'] ?? null) ? $entrada['tipo'] : '';
        if (!isset(self::TIPOS[$tipo])) {
            $v->agregarError('tipo', 'Elige si es festivo o cierre.');
        }
        $d = $v->limpios() + ['tipo' => $tipo, 'periodo' => $periodo ? '1' : ''];
        $fechas = [];
        $omitidas = [];
        if ($d['fecha'] !== null && !$periodo) {
            if ($this->dias->porFecha($d['fecha']) !== null) {
                $v->agregarError('fecha', 'Esa fecha ya está en el catálogo.');
            }
            $fechas = [$d['fecha']];
        }
        if ($periodo && $d['fecha'] !== null && ($d['fecha_fin'] ?? null) !== null) {
            $inicio = new DateTimeImmutable($d['fecha']);
            $fin = new DateTimeImmutable($d['fecha_fin']);
            if ($fin < $inicio) {
                $v->agregarError('fecha_fin', 'La fecha final debe ser igual o posterior a la de inicio.');
            } elseif ((int)$inicio->diff($fin)->days + 1 > self::MAX_DIAS_PERIODO) {
                $v->agregarError('fecha_fin', 'Un periodo puede abarcar como máximo ' . self::MAX_DIAS_PERIODO . ' días.');
            } else {
                $existentes = $this->dias->mapa();
                for ($f = $inicio; $f <= $fin; $f = $f->modify('+1 day')) {
                    if ((int)$f->format('N') > 5) {
                        continue;
                    }
                    $clave = $f->format('Y-m-d');
                    isset($existentes[$clave]) ? $omitidas[] = $clave : $fechas[] = $clave;
                }
                if ($fechas === []) {
                    $v->agregarError('fecha_fin', $omitidas === []
                        ? 'El periodo no tiene días de lunes a viernes.'
                        : 'Todos los días hábiles de ese periodo ya están en el catálogo.');
                }
            }
        }
        if (!$v->valido()) {
            return ['ok' => false, 'errores' => $v->errores(), 'datos' => $d];
        }
        try {
            $this->bd->transaccion(function () use ($fechas, $tipo, $d) {
                foreach ($fechas as $fecha) {
                    $this->dias->crear($fecha, $tipo, $d['descripcion']);
                }
            }, $periodo ? 'Alta de un periodo en el catálogo de días inhábiles' : 'Alta en el catálogo de días inhábiles');
        } catch (PDOException $e) {
            return ['ok' => false, 'errores' => ['fecha' => ErroresBd::mensaje($e) ?? throw $e], 'datos' => $d];
        }
        return ['ok' => true, 'agregados' => count($fechas), 'omitidas' => $omitidas, 'periodo' => $periodo];
    }

    /**
     * Quita varios días a la vez (un periodo completo). Si alguno ya se usó
     * en una asistencia, no se quita ninguno.
     * @param list<int> $ids
     */
    public function quitarVarios(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if ($ids === [] || count($ids) > self::MAX_DIAS_PERIODO) {
            return ['ok' => false, 'general' => 'No se indicó qué días quitar.'];
        }
        try {
            $this->bd->transaccion(function () use ($ids) {
                foreach ($ids as $id) {
                    if (!$this->dias->quitar($id)) {
                        throw new \RuntimeException('falta');
                    }
                }
            }, 'Baja de un periodo del catálogo de días inhábiles');
        } catch (PDOException $e) {
            return ['ok' => false, 'general' => ErroresBd::mensaje($e) ?? throw $e];
        } catch (\RuntimeException) {
            return ['ok' => false, 'general' => 'Algún día de ese periodo ya no estaba en el catálogo; no se quitó ninguno.'];
        }
        return ['ok' => true, 'quitados' => count($ids)];
    }

    /**
     * Agrupa el catálogo para mostrarlo: días seguidos (sin contar fines de
     * semana) con el mismo tipo y la misma descripción forman un periodo.
     * @return list<array{desde: string, hasta: string, tipo: string, descripcion: string, ids: list<int>, usos: int}>
     */
    public static function agrupar(array $dias): array
    {
        usort($dias, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
        $grupos = [];
        foreach ($dias as $d) {
            $n = count($grupos) - 1;
            if ($n >= 0) {
                $g = $grupos[$n];
                $siguiente = (new DateTimeImmutable($g['hasta']))->modify('+1 weekday')->format('Y-m-d');
                if ($g['tipo'] === $d['tipo'] && $g['descripcion'] === $d['descripcion'] && $d['fecha'] === $siguiente) {
                    $grupos[$n]['hasta'] = $d['fecha'];
                    $grupos[$n]['ids'][] = (int)$d['id'];
                    $grupos[$n]['usos'] += (int)$d['usos'];
                    continue;
                }
            }
            $grupos[] = ['desde' => $d['fecha'], 'hasta' => $d['fecha'], 'tipo' => $d['tipo'], 'descripcion' => $d['descripcion'],
                         'ids' => [(int)$d['id']], 'usos' => (int)$d['usos']];
        }
        return array_reverse($grupos);
    }

    public function quitar(int $id): array
    {
        $dia = $this->dias->porId($id);
        if ($dia === null) {
            return ['ok' => false, 'general' => 'Ese día ya no está en el catálogo.'];
        }
        try {
            $this->bd->transaccion(fn() => $this->dias->quitar($id), 'Baja del catálogo de días inhábiles');
        } catch (PDOException $e) {
            return ['ok' => false, 'general' => ErroresBd::mensaje($e) ?? throw $e];
        }
        return ['ok' => true];
    }
}
