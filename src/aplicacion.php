<?php
declare(strict_types=1);

/**
 * Arma la aplicación: dependencias y tabla de rutas.
 * Toda ruta es privada salvo que diga 'publico' => true.
 */

use ServicioSocial\Controladores\AuthControlador;
use ServicioSocial\Controladores\InicioControlador;
use ServicioSocial\Nucleo\Aplicacion;
use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Nucleo\Config;
use ServicioSocial\Nucleo\RelojSistema;
use ServicioSocial\Nucleo\Router;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Repositorios\ConfiguracionRepositorio;
use ServicioSocial\Repositorios\HistorialRepositorio;
use ServicioSocial\Repositorios\IntentoLoginRepositorio;
use ServicioSocial\Repositorios\TokenResetRepositorio;
use ServicioSocial\Repositorios\UsuarioRepositorio;
use ServicioSocial\Seguridad\Csrf;
use ServicioSocial\Seguridad\LimiteIntentos;
use ServicioSocial\Seguridad\Sesion;
use ServicioSocial\Servicios\AuthServicio;
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

    $authCtl = new AuthControlador($vista, $sesion, $csrf, $auth, $recuperacion);
    $inicioCtl = new InicioControlador($vista);

    $r = new Router();
    $r->get('/login', [$authCtl, 'mostrarLogin'], ['publico' => true]);
    $r->post('/login', [$authCtl, 'procesarLogin'], ['publico' => true]);
    $r->post('/logout', [$authCtl, 'cerrarSesion']);
    $r->post('/sesion/renovar', [$authCtl, 'renovar']);
    $r->get('/recuperar', [$authCtl, 'mostrarOlvide'], ['publico' => true]);
    $r->post('/recuperar', [$authCtl, 'procesarOlvide'], ['publico' => true]);
    $r->get('/restablecer', [$authCtl, 'mostrarRestablecer'], ['publico' => true]);
    $r->post('/restablecer', [$authCtl, 'procesarRestablecer'], ['publico' => true]);

    $r->get('/', [$inicioCtl, 'inicio'], ['permiso' => 'expedientes.ver']);

    return new Aplicacion($config, $r, $bd, $sesion, $csrf, $vista, $usuarios, $configuracion, $auth);
}
