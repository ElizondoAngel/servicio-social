<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

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

    public function agregar(array $entrada): array
    {
        $v = (new Validador($entrada))->fecha('fecha', 'la fecha')->texto('descripcion', 'la descripción', 150);
        $tipo = is_string($entrada['tipo'] ?? null) ? $entrada['tipo'] : '';
        if (!isset(self::TIPOS[$tipo])) {
            $v->agregarError('tipo', 'Elige si es festivo o cierre.');
        }
        $d = $v->limpios() + ['tipo' => $tipo];
        if ($d['fecha'] !== null && $this->dias->porFecha($d['fecha']) !== null) {
            $v->agregarError('fecha', 'Esa fecha ya está en el catálogo.');
        }
        if (!$v->valido()) {
            return ['ok' => false, 'errores' => $v->errores(), 'datos' => $d];
        }
        try {
            $this->bd->transaccion(fn() => $this->dias->crear($d['fecha'], $tipo, $d['descripcion']), 'Alta en el catálogo de días inhábiles');
        } catch (PDOException $e) {
            return ['ok' => false, 'errores' => ['fecha' => ErroresBd::mensaje($e) ?? throw $e], 'datos' => $d];
        }
        return ['ok' => true];
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
