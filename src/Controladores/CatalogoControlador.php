<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Repositorios\DiaInhabilRepositorio;
use ServicioSocial\Seguridad\Sesion;
use ServicioSocial\Servicios\CatalogoServicio;

/** Catálogo de días inhábiles (festivos y cierres). */
final class CatalogoControlador
{
    public function __construct(
        private readonly Vista $vista,
        private readonly Sesion $sesion,
        private readonly CatalogoServicio $servicio,
        private readonly DiaInhabilRepositorio $dias,
    ) {
    }

    public function index(Peticion $p): Respuesta
    {
        return $this->pagina(['tipo' => 'festivo', 'periodo' => ''], []);
    }

    public function agregar(Peticion $p): Respuesta
    {
        $r = $this->servicio->agregar($p->cuerpo);
        if (!$r['ok']) {
            return $this->pagina($r['datos'], $r['errores'], 422);
        }
        $aviso = $r['periodo']
            ? 'Periodo agregado: ' . $r['agregados'] . ($r['agregados'] === 1 ? ' día hábil.' : ' días hábiles.')
            : 'Día agregado al catálogo.';
        if ($r['omitidas'] !== []) {
            $aviso .= ' Se conservaron como estaban ' . count($r['omitidas']) . ' fecha(s) que ya estaban en el catálogo: '
                . implode(', ', array_map('fecha_corta', $r['omitidas'])) . '.';
        }
        $this->sesion->set('aviso', $aviso);
        return Respuesta::redirigir('/dias-inhabiles');
    }

    public function quitar(Peticion $p, array $params): Respuesta
    {
        $r = $this->servicio->quitar($params['id']);
        $this->sesion->set($r['ok'] ? 'aviso' : 'error', $r['ok'] ? 'Día quitado del catálogo.' : $r['general']);
        return Respuesta::redirigir('/dias-inhabiles');
    }

    /** Quita un periodo completo (los ids de sus días). */
    public function quitarPeriodo(Peticion $p): Respuesta
    {
        $ids = $p->cuerpo['ids'] ?? [];
        $r = $this->servicio->quitarVarios(is_array($ids) ? $ids : []);
        $this->sesion->set($r['ok'] ? 'aviso' : 'error', $r['ok'] ? 'Periodo quitado del catálogo (' . $r['quitados'] . ' días).' : $r['general']);
        return Respuesta::redirigir('/dias-inhabiles');
    }

    private function pagina(array $datos, array $errores, int $estado = 200): Respuesta
    {
        return Respuesta::html($this->vista->render('dias_inhabiles', [
            'titulo' => 'Días inhábiles', 'grupos' => CatalogoServicio::agrupar($this->dias->todos()), 'datos' => $datos, 'errores' => $errores,
            'aviso' => $this->sesion->tomar('aviso'), 'falla' => $this->sesion->tomar('error'),
        ]), $estado);
    }
}
