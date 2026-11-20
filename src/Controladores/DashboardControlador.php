<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Seguridad\Sesion;
use ServicioSocial\Servicios\ExpedienteServicio;

final class DashboardControlador
{
    public function __construct(
        private readonly Vista $vista,
        private readonly Sesion $sesion,
        private readonly ExpedienteServicio $expedientes,
    ) {
    }

    public function inicio(Peticion $p): Respuesta
    {
        $busqueda = mb_substr($p->consultaTexto('q'), 0, 80);
        $filtro = $p->consultaTexto('filtro');
        if (!isset(ExpedienteServicio::FILTROS[$filtro])) {
            $filtro = 'todos';
        }
        $tablero = $this->expedientes->tablero($busqueda, $filtro);
        return Respuesta::html($this->vista->render('dashboard', $tablero + [
            'titulo'   => 'Expedientes',
            'busqueda' => $busqueda,
            'filtro'   => $filtro,
            'filtros'  => ExpedienteServicio::FILTROS,
            'aviso'    => $this->sesion->tomar('aviso'),
        ]));
    }
}
