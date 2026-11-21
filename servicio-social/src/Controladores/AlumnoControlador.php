<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use ServicioSocial\Nucleo\HttpExcepcion;
use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Repositorios\AlumnoRepositorio;
use ServicioSocial\Repositorios\ExpedienteRepositorio;
use ServicioSocial\Repositorios\UsuarioRepositorio;
use ServicioSocial\Seguridad\Sesion;
use ServicioSocial\Servicios\AlumnoServicio;
use ServicioSocial\Servicios\ExpedienteServicio;

final class AlumnoControlador
{
    public function __construct(
        private readonly Vista $vista,
        private readonly Sesion $sesion,
        private readonly AlumnoServicio $servicio,
        private readonly AlumnoRepositorio $alumnos,
        private readonly ExpedienteRepositorio $expedientes,
        private readonly ExpedienteServicio $calculos,
        private readonly UsuarioRepositorio $usuarios,
    ) {
    }

    public function nuevo(Peticion $p): Respuesta
    {
        $usuario = $this->usuarios->porId((int)$this->sesion->usuarioId());
        return $this->formularioAlta($this->servicio->valoresIniciales((string)($usuario['nombre'] ?? '')), []);
    }

    public function crear(Peticion $p): Respuesta
    {
        $r = $this->servicio->registrar($p->cuerpo, (int)$this->sesion->usuarioId());
        if (!$r['ok']) {
            return $this->formularioAlta($r['datos'], $r['errores'], 422);
        }
        $this->sesion->set('aviso', 'Alumno registrado y expediente abierto.');
        return Respuesta::redirigir('/alumnos/' . $r['alumno_id']);
    }

    public function ver(Peticion $p, array $params): Respuesta
    {
        $alumno = $this->buscar($params['id']);
        $expedientes = array_map(fn(array $e) => $e + ['resumen' => $this->calculos->resumir($e)], $this->expedientes->deAlumno((int)$alumno['id']));
        return Respuesta::html($this->vista->render('alumnos/ver', [
            'titulo' => $alumno['nombre'] . ' ' . $alumno['apellido_paterno'],
            'alumno' => $alumno, 'expedientes' => $expedientes, 'aviso' => $this->sesion->tomar('aviso'),
        ]));
    }

    public function editar(Peticion $p, array $params): Respuesta
    {
        $alumno = $this->buscar($params['id']);
        return $this->formularioEdicion($alumno, $alumno, []);
    }

    public function actualizar(Peticion $p, array $params): Respuesta
    {
        $alumno = $this->buscar($params['id']);
        $r = $this->servicio->actualizar((int)$alumno['id'], $p->cuerpo);
        if (!$r['ok']) {
            return $this->formularioEdicion($alumno, $r['datos'], $r['errores'], 422);
        }
        $this->sesion->set('aviso', 'Datos del alumno actualizados.');
        return Respuesta::redirigir('/alumnos/' . $alumno['id']);
    }

    private function buscar(int $id): array
    {
        return $this->alumnos->porId($id) ?? throw new HttpExcepcion(404, 'No existe ese alumno.');
    }

    private function formularioAlta(array $datos, array $errores, int $estado = 200): Respuesta
    {
        return Respuesta::html($this->vista->render('alumnos/formulario', [
            'titulo' => 'Registrar alumno', 'modo' => 'alta', 'accion' => '/alumnos/nuevo',
            'datos' => $datos, 'errores' => $errores, 'alumno' => null,
        ]), $estado);
    }

    private function formularioEdicion(array $alumno, array $datos, array $errores, int $estado = 200): Respuesta
    {
        return Respuesta::html($this->vista->render('alumnos/formulario', [
            'titulo' => 'Editar alumno', 'modo' => 'edicion', 'accion' => '/alumnos/' . $alumno['id'] . '/editar',
            'datos' => $datos, 'errores' => $errores, 'alumno' => $alumno,
        ]), $estado);
    }
}
