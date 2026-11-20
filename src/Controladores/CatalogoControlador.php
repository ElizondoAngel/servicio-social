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
        return $this->pagina(['tipo' => 'festivo'], []);
    }

    public function agregar(Peticion $p): Respuesta
    {
        $r = $this->servicio->agregar($p->cuerpo);
        if (!$r['ok']) {
            return $this->pagina($r['datos'], $r['errores'], 422);
        }
        $this->sesion->set('aviso', 'Día agregado al catálogo.');
        return Respuesta::redirigir('/dias-inhabiles');
    }

    public function quitar(Peticion $p, array $params): Respuesta
    {
        $r = $this->servicio->quitar($params['id']);
        $this->sesion->set($r['ok'] ? 'aviso' : 'error', $r['ok'] ? 'Día quitado del catálogo.' : $r['general']);
        return Respuesta::redirigir('/dias-inhabiles');
    }

    private function pagina(array $datos, array $errores, int $estado = 200): Respuesta
    {
        return Respuesta::html($this->vista->render('dias_inhabiles', [
            'titulo' => 'Días inhábiles', 'dias' => $this->dias->todos(), 'datos' => $datos, 'errores' => $errores,
            'aviso' => $this->sesion->tomar('aviso'), 'falla' => $this->sesion->tomar('error'),
        ]), $estado);
    }
}
