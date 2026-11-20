<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use DateTimeImmutable;
use ServicioSocial\Nucleo\HttpExcepcion;
use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Repositorios\AlumnoRepositorio;
use ServicioSocial\Repositorios\ExpedienteRepositorio;
use ServicioSocial\Repositorios\HistorialRepositorio;
use ServicioSocial\Repositorios\UsuarioRepositorio;
use ServicioSocial\Seguridad\Sesion;
use ServicioSocial\Servicios\AsistenciaServicio;
use ServicioSocial\Servicios\ExpedienteServicio;
use ServicioSocial\Servicios\GestionExpedienteServicio;
use ServicioSocial\Servicios\ReglasExpediente;

/**
 * Página del expediente: datos y ajustes, resumen, calendario de
 * asistencia, estado e historial. Las acciones del calendario responden
 * JSON cuando las pide app.js y redirigen cuando no hay JavaScript.
 */
final class ExpedienteControlador
{
    public function __construct(
        private readonly Vista $vista,
        private readonly Sesion $sesion,
        private readonly ExpedienteRepositorio $expedientes,
        private readonly AlumnoRepositorio $alumnos,
        private readonly UsuarioRepositorio $usuarios,
        private readonly HistorialRepositorio $historial,
        private readonly ExpedienteServicio $calculos,
        private readonly AsistenciaServicio $asistencia,
        private readonly GestionExpedienteServicio $gestion,
        private readonly ReglasExpediente $reglas,
    ) {
    }

    public function ver(Peticion $p, array $params): Respuesta
    {
        $exp = $this->buscar($params['id']);
        if ($p->consultaTexto('parcial') === 'zona') {
            return Respuesta::html($this->zona($exp));
        }
        return $this->pagina($exp);
    }

    // ----- Día del calendario ----------------------------------------------

    public function dia(Peticion $p, array $params): Respuesta
    {
        $exp = $this->buscar($params['id']);
        $fecha = $this->fechaValida($p->consultaTexto('fecha'));
        return $this->formularioDia($exp, $fecha, null, [], null, $p->consultaTexto('parcial') === '1');
    }

    public function guardarDia(Peticion $p, array $params): Respuesta
    {
        $exp = $this->buscar($params['id']);
        $r = $this->asistencia->guardar($exp, $p->cuerpo, $this->usuarioId(), $this->calculos->hoy());
        $fecha = $this->fechaValida($p->texto('fecha'), false);
        if ($r['ok']) {
            return $this->exito($p, $exp, $r['aviso'], $fecha);
        }
        if ($fecha === null) {
            return $this->fracaso($p, $exp, $r);
        }
        // En conflicto se muestra el día como quedó; en error, lo que se escribió.
        $datos = $r['estado'] === 409 ? null : $p->cuerpo;
        $resp = $this->formularioDia($exp, $fecha, $datos, $r['errores'], $r['general'], $p->esperaJson(), $r['estado']);
        return $p->esperaJson() ? Respuesta::json(['ok' => false, 'error' => $r['general'] ?? 'Revisa los datos marcados.', 'html' => $resp->cuerpo], $r['estado']) : $resp;
    }

    public function firma(Peticion $p, array $params): Respuesta
    {
        $exp = $this->buscar($params['id']);
        $semana = $p->texto('semana');
        $r = $this->asistencia->alternarFirma($exp, $semana, $this->usuarioId(), $this->calculos->hoy());
        return $r['ok'] ? $this->exito($p, $exp, $r['aviso'], $semana) : $this->fracaso($p, $exp, $r, $semana);
    }

    public function confirmarFeriados(Peticion $p, array $params): Respuesta
    {
        $exp = $this->buscar($params['id']);
        $pendientes = $this->calculos->resumir($exp)->feriadosPorConfirmar;
        $r = $this->asistencia->confirmarFeriados($exp, $pendientes, $this->usuarioId());
        return $r['ok'] ? $this->exito($p, $exp, $r['aviso']) : $this->fracaso($p, $exp, $r);
    }

    // ----- Expediente --------------------------------------------------------

    public function actualizarDatos(Peticion $p, array $params): Respuesta
    {
        $exp = $this->buscar($params['id']);
        $r = $this->gestion->actualizarDatos($exp, $p->cuerpo, $this->usuarioId());
        if ($r['ok']) {
            $this->sesion->set('aviso', $r['aviso']);
            return Respuesta::redirigir('/expedientes/' . $exp['id']);
        }
        // En conflicto se recargan los datos actuales; si no, se conserva lo escrito.
        $actual = $this->buscar((int)$exp['id']);
        return $this->pagina($actual, [
            'datos'   => $r['estado'] === 409 ? null : ($r['datos'] ?? $p->cuerpo),
            'errores' => $r['errores'] ?? [],
            'general' => $r['general'] ?? null,
        ], $r['estado']);
    }

    public function cambiarEstado(Peticion $p, array $params): Respuesta
    {
        $exp = $this->buscar($params['id']);
        $r = $this->gestion->cambiarEstado($exp, $p->cuerpo, $this->calculos->resumir($exp), $this->calculos->hoy());
        return $r['ok'] ? $this->exito($p, $exp, $r['aviso']) : $this->fracaso($p, $exp, $r);
    }

    public function nuevoPeriodo(Peticion $p, array $params): Respuesta
    {
        $alumno = $this->alumnos->porId($params['id']) ?? throw new HttpExcepcion(404, 'No existe ese alumno.');
        $abierto = $this->expedientes->abiertoDeAlumno((int)$alumno['id']);
        $ultimo = $this->expedientes->deAlumno((int)$alumno['id'])[0] ?? null;
        $usuario = $this->usuarios->porId($this->usuarioId());
        return $this->formularioPeriodo($alumno, $abierto, $this->gestion->valoresNuevoPeriodo($ultimo, (string)($usuario['nombre'] ?? '')), [], null);
    }

    public function abrirPeriodo(Peticion $p, array $params): Respuesta
    {
        $alumno = $this->alumnos->porId($params['id']) ?? throw new HttpExcepcion(404, 'No existe ese alumno.');
        $r = $this->gestion->abrirPeriodo((int)$alumno['id'], $p->cuerpo, $this->usuarioId());
        if (!$r['ok']) {
            return $this->formularioPeriodo($alumno, $this->expedientes->abiertoDeAlumno((int)$alumno['id']), $r['datos'], $r['errores'], $r['general'] ?? null, 422);
        }
        $this->sesion->set('aviso', 'Nuevo periodo abierto.');
        return Respuesta::redirigir('/expedientes/' . $r['expediente_id']);
    }

    // ----- Apoyo -------------------------------------------------------------

    private function pagina(array $exp, array $formulario = [], int $estado = 200): Respuesta
    {
        $alumno = $this->alumnos->porId((int)$exp['alumno_id']);
        return Respuesta::html($this->vista->render('expedientes/ver', [
            'titulo'     => 'Expediente de ' . $alumno['nombre'] . ' ' . $alumno['apellido_paterno'],
            'expediente' => $exp,
            'alumno'     => $alumno,
            'zona'       => $this->zona($exp),
            'vecinos'    => $this->calculos->vecinos((int)$alumno['id']),
            'periodos'   => $this->expedientes->deAlumno((int)$alumno['id']),
            'jornadas'   => $this->reglas->jornadasPermitidas(),
            'aviso'      => $this->sesion->tomar('aviso'),
            'falla'      => $this->sesion->tomar('error'),
            'form'       => $formulario + ['datos' => null, 'errores' => [], 'general' => null],
        ]), $estado);
    }

    /** Resumen, calendario, estado e historial: lo que cambia al capturar. */
    private function zona(array $exp): string
    {
        $detalle = $this->calculos->detalle($exp);
        return $this->vista->render('expedientes/_zona', $detalle + [
            'expediente' => $exp,
            'hoy'        => $this->calculos->hoy(),
            'historial'  => $this->historial->deExpediente((int)$exp['id']),
            'abierto'    => in_array($exp['estado'], ['activo', 'suspendido'], true),
        ], null);
    }

    private function formularioDia(array $exp, string $fecha, ?array $datos, array $errores, ?string $general,
                                   bool $parcial, int $estado = 200): Respuesta
    {
        $contexto = $this->asistencia->contextoDia($exp, $fecha, $this->calculos->hoy());
        $alumno = $this->alumnos->porId((int)$exp['alumno_id']);
        $html = $this->vista->render('expedientes/dia', $contexto + [
            'titulo' => 'Día ' . fecha_corta($fecha), 'expediente' => $exp, 'alumno' => $alumno, 'fecha' => $fecha,
            'datos' => $datos, 'errores' => $errores, 'general' => $general, 'parcial' => $parcial,
        ], $parcial ? null : 'layout');
        return Respuesta::html($html, $estado);
    }

    private function formularioPeriodo(array $alumno, ?array $abierto, array $datos, array $errores, ?string $general, int $estado = 200): Respuesta
    {
        return Respuesta::html($this->vista->render('expedientes/nuevo', [
            'titulo' => 'Nuevo periodo', 'alumno' => $alumno, 'abierto' => $abierto, 'datos' => $datos,
            'errores' => $errores, 'general' => $general, 'jornadas' => $this->reglas->jornadasPermitidas(),
        ]), $estado);
    }

    private function exito(Peticion $p, array $exp, string $aviso, ?string $fecha = null): Respuesta
    {
        if ($p->esperaJson()) {
            return Respuesta::json(['ok' => true, 'aviso' => $aviso]);
        }
        $this->sesion->set('aviso', $aviso);
        return Respuesta::redirigir('/expedientes/' . $exp['id'] . $this->ancla($fecha));
    }

    private function fracaso(Peticion $p, array $exp, array $r, ?string $fecha = null): Respuesta
    {
        $mensaje = $r['general'] ?? (reset($r['errores']) ?: 'No se pudo guardar.');
        if ($p->esperaJson()) {
            return Respuesta::json(['ok' => false, 'error' => $mensaje], $r['estado'] ?? 422);
        }
        $this->sesion->set('error', $mensaje);
        return Respuesta::redirigir('/expedientes/' . $exp['id'] . $this->ancla($fecha));
    }

    /** Ancla a la semana del día para volver al mismo lugar del calendario. */
    private function ancla(?string $fecha): string
    {
        if ($fecha === null || DateTimeImmutable::createFromFormat('!Y-m-d', $fecha) === false) {
            return '';
        }
        $d = new DateTimeImmutable($fecha);
        return '#semana-' . $d->modify('-' . ((int)$d->format('N') - 1) . ' day')->format('Y-m-d');
    }

    private function fechaValida(string $fecha, bool $obligatoria = true): ?string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($d === false || $d->format('Y-m-d') !== $fecha || (int)$d->format('Y') < 2000 || (int)$d->format('Y') > 2100) {
            return $obligatoria ? throw new HttpExcepcion(404, 'Fecha no válida.') : null;
        }
        return $fecha;
    }

    private function buscar(int $id): array
    {
        return $this->expedientes->porId($id) ?? throw new HttpExcepcion(404, 'No existe ese expediente.');
    }

    private function usuarioId(): int
    {
        return (int)$this->sesion->usuarioId();
    }
}
