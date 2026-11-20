<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use ServicioSocial\Nucleo\HttpExcepcion;
use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Repositorios\AlumnoRepositorio;
use ServicioSocial\Repositorios\ExpedienteRepositorio;
use ServicioSocial\Servicios\ExpedienteServicio;

/**
 * Ficha del expediente. En la Etapa 5 se agrega el calendario de asistencia.
 */
final class ExpedienteControlador
{
    public function __construct(
        private readonly Vista $vista,
        private readonly ExpedienteRepositorio $expedientes,
        private readonly AlumnoRepositorio $alumnos,
        private readonly ExpedienteServicio $calculos,
    ) {
    }

    public function ver(Peticion $p, array $params): Respuesta
    {
        $exp = $this->expedientes->porId($params['id']) ?? throw new HttpExcepcion(404, 'No existe ese expediente.');
        $alumno = $this->alumnos->porId((int)$exp['alumno_id']);
        return Respuesta::html($this->vista->render('expedientes/ver', [
            'titulo' => 'Expediente', 'expediente' => $exp, 'alumno' => $alumno, 'resumen' => $this->calculos->resumir($exp),
        ]));
    }
}
