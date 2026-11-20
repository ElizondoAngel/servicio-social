<?php
declare(strict_types=1);

/**
 * Arma la aplicación: dependencias y tabla de rutas.
 * Toda ruta es privada salvo que diga 'publico' => true.
 */

use ServicioSocial\Controladores\AuthControlador;
use ServicioSocial\Controladores\AlumnoControlador;
use ServicioSocial\Controladores\CatalogoControlador;
use ServicioSocial\Controladores\DashboardControlador;
use ServicioSocial\Controladores\ExpedienteControlador;
use ServicioSocial\Nucleo\Aplicacion;
use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Nucleo\Config;
use ServicioSocial\Nucleo\RelojSistema;
use ServicioSocial\Nucleo\Router;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Repositorios\AlumnoRepositorio;
use ServicioSocial\Repositorios\AsistenciaRepositorio;
use ServicioSocial\Repositorios\ConfiguracionRepositorio;
use ServicioSocial\Repositorios\DiaInhabilRepositorio;
use ServicioSocial\Repositorios\ExpedienteRepositorio;
use ServicioSocial\Repositorios\FirmaRepositorio;
use ServicioSocial\Repositorios\HistorialRepositorio;
use ServicioSocial\Repositorios\IntentoLoginRepositorio;
use ServicioSocial\Repositorios\TokenResetRepositorio;
use ServicioSocial\Repositorios\UsuarioRepositorio;
use ServicioSocial\Seguridad\Csrf;
use ServicioSocial\Seguridad\LimiteIntentos;
use ServicioSocial\Seguridad\Sesion;
use ServicioSocial\Servicios\AlumnoServicio;
use ServicioSocial\Servicios\AsistenciaServicio;
use ServicioSocial\Servicios\CatalogoServicio;
use ServicioSocial\Servicios\GestionExpedienteServicio;
use ServicioSocial\Servicios\ReglasExpediente;
use ServicioSocial\Servicios\AuthServicio;
use ServicioSocial\Servicios\ExpedienteServicio;
use ServicioSocial\Servicios\Correo\CorreoArchivo;
use ServicioSocial\Servicios\Correo\CorreoSmtp;
use ServicioSocial\Servicios\RecuperacionServicio;

function crearAplicacion(Config $config): Aplicacion
{
    $bd = new BaseDatos($config);
    $pdo = $bd->pdo();
    $reloj = new RelojSistema();

    $vista = new Vista(__DIR__ . '/../vistas');
    $sesion = new Sesion($config, $reloj);
    $csrf = new Csrf();

    $usuarios = new UsuarioRepositorio($pdo);
    $configuracion = new ConfiguracionRepositorio($pdo);
    $historial = new HistorialRepositorio($pdo);
    $limite = new LimiteIntentos(new IntentoLoginRepositorio($pdo), $configuracion);

    $correo = match ($config->get('correo.driver', 'smtp')) {
        'archivo' => $config->esProduccion()
            ? throw new RuntimeException('El correo de archivo no se permite en producción.')
            : new CorreoArchivo((string)$config->get('correo.archivo')),
        default   => new CorreoSmtp($config),
    };

    $auth = new AuthServicio($bd, $usuarios, $limite, $historial);
    $recuperacion = new RecuperacionServicio($bd, $usuarios, new TokenResetRepositorio($pdo), $historial, $correo,
        (string)$config->get('app.url_base'));

    $alumnos = new AlumnoRepositorio($pdo);
    $expedientes = new ExpedienteRepositorio($pdo);
    $asistencias = new AsistenciaRepositorio($pdo);
    $dias = new DiaInhabilRepositorio($pdo);
    $firmas = new FirmaRepositorio($pdo);
    $reglas = new ReglasExpediente($configuracion);
    $calculos = new ExpedienteServicio($expedientes, $asistencias, $dias, $configuracion, $firmas);
    $alumnoServicio = new AlumnoServicio($bd, $alumnos, $expedientes, $configuracion, $reglas);
    $asistenciaServicio = new AsistenciaServicio($bd, $asistencias, $dias, $firmas, $reglas);
    $gestion = new GestionExpedienteServicio($bd, $expedientes, $asistencias, $configuracion, $reglas);

    $authCtl = new AuthControlador($vista, $sesion, $csrf, $auth, $recuperacion);
    $tableroCtl = new DashboardControlador($vista, $sesion, $calculos);
    $alumnoCtl = new AlumnoControlador($vista, $sesion, $alumnoServicio, $alumnos, $expedientes, $calculos, $usuarios);
    $expedienteCtl = new ExpedienteControlador($vista, $sesion, $expedientes, $alumnos, $usuarios, $historial, $calculos,
        $asistenciaServicio, $gestion, $reglas);
    $catalogoCtl = new CatalogoControlador($vista, $sesion, new CatalogoServicio($bd, $dias), $dias);

    $r = new Router();
    $r->get('/login', [$authCtl, 'mostrarLogin'], ['publico' => true]);
    $r->post('/login', [$authCtl, 'procesarLogin'], ['publico' => true]);
    $r->post('/logout', [$authCtl, 'cerrarSesion']);
    $r->post('/sesion/renovar', [$authCtl, 'renovar']);
    $r->get('/recuperar', [$authCtl, 'mostrarOlvide'], ['publico' => true]);
    $r->post('/recuperar', [$authCtl, 'procesarOlvide'], ['publico' => true]);
    $r->get('/restablecer', [$authCtl, 'mostrarRestablecer'], ['publico' => true]);
    $r->post('/restablecer', [$authCtl, 'procesarRestablecer'], ['publico' => true]);

    $r->get('/', [$tableroCtl, 'inicio'], ['permiso' => 'expedientes.ver']);
    $r->get('/alumnos/nuevo', [$alumnoCtl, 'nuevo'], ['permiso' => 'alumnos.crear']);
    $r->post('/alumnos/nuevo', [$alumnoCtl, 'crear'], ['permiso' => 'alumnos.crear']);
    $r->get('/alumnos/{id}', [$alumnoCtl, 'ver'], ['permiso' => 'alumnos.ver']);
    $r->get('/alumnos/{id}/editar', [$alumnoCtl, 'editar'], ['permiso' => 'alumnos.editar']);
    $r->post('/alumnos/{id}/editar', [$alumnoCtl, 'actualizar'], ['permiso' => 'alumnos.editar']);
    $r->get('/alumnos/{id}/expedientes/nuevo', [$expedienteCtl, 'nuevoPeriodo'], ['permiso' => 'expedientes.crear']);
    $r->post('/alumnos/{id}/expedientes/nuevo', [$expedienteCtl, 'abrirPeriodo'], ['permiso' => 'expedientes.crear']);
    $r->get('/expedientes/{id}', [$expedienteCtl, 'ver'], ['permiso' => 'expedientes.ver']);
    $r->get('/expedientes/{id}/dia', [$expedienteCtl, 'dia'], ['permiso' => 'asistencias.registrar']);
    $r->post('/expedientes/{id}/dia', [$expedienteCtl, 'guardarDia'], ['permiso' => 'asistencias.registrar']);
    $r->post('/expedientes/{id}/firmas', [$expedienteCtl, 'firma'], ['permiso' => 'asistencias.registrar']);
    $r->post('/expedientes/{id}/feriados', [$expedienteCtl, 'confirmarFeriados'], ['permiso' => 'asistencias.registrar']);
    $r->post('/expedientes/{id}/datos', [$expedienteCtl, 'actualizarDatos'], ['permiso' => 'expedientes.editar']);
    $r->post('/expedientes/{id}/estado', [$expedienteCtl, 'cambiarEstado'], ['permiso' => 'expedientes.estado']);
    $r->get('/dias-inhabiles', [$catalogoCtl, 'index'], ['permiso' => 'catalogo.ver']);
    $r->post('/dias-inhabiles', [$catalogoCtl, 'agregar'], ['permiso' => 'catalogo.editar']);
    $r->post('/dias-inhabiles/{id}/quitar', [$catalogoCtl, 'quitar'], ['permiso' => 'catalogo.editar']);

    return new Aplicacion($config, $r, $bd, $sesion, $csrf, $vista, $usuarios, $configuracion, $auth);
}
