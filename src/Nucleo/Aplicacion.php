<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

use ServicioSocial\Repositorios\ConfiguracionRepositorio;
use ServicioSocial\Repositorios\UsuarioRepositorio;
use ServicioSocial\Seguridad\Csrf;
use ServicioSocial\Seguridad\Permisos;
use ServicioSocial\Seguridad\Sesion;
use ServicioSocial\Servicios\AuthServicio;

/**
 * Recorre cada petición en el mismo orden:
 * HTTPS → sesión → ruta → vigencia de la sesión → acceso → permiso → CSRF → acción.
 */
final class Aplicacion
{
    public function __construct(
        private readonly Config $config,
        private readonly Router $router,
        private readonly BaseDatos $bd,
        private readonly Sesion $sesion,
        private readonly Csrf $csrf,
        private readonly Vista $vista,
        private readonly UsuarioRepositorio $usuarios,
        private readonly ConfiguracionRepositorio $configuracion,
        private readonly AuthServicio $auth,
    ) {
    }

    public function manejar(Peticion $p): Respuesta
    {
        try {
            return $this->procesar($p);
        } catch (HttpExcepcion $e) {
            return $this->error($p, $e->estado, $e->getMessage());
        } catch (\Throwable $e) {
            error_log(sprintf('[%s] %s en %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
            $mensaje = $this->config->esProduccion() ? 'Ocurrió un error inesperado. Intenta de nuevo.' : $e->getMessage();
            return $this->error($p, 500, $mensaje);
        }
    }

    private function procesar(Peticion $p): Respuesta
    {
        // 1. HTTPS obligatorio en producción. La URL sale de la configuración,
        //    nunca de la cabecera Host.
        if ($this->config->get('app.forzar_https', $this->config->esProduccion()) && !$p->esHttps()) {
            $base = rtrim((string)$this->config->get('app.url_base'), '/');
            return new Respuesta(301, '', ['Location' => $base . $p->ruta]);
        }

        // 2. Sesión
        $this->sesion->iniciar($p->esHttps());
        $this->bd->fijarContexto(null, $p->ip(), $p->agente());

        // 3. Ruta
        $ruta = $this->router->buscar($p->metodo, $p->ruta);
        if ($ruta === null) {
            $estado = $this->router->existeRuta($p->ruta) ? 405 : 404;
            return $this->error($p, $estado, $estado === 405 ? 'Método no permitido.' : 'La página no existe.');
        }

        // 4. Vigencia de la sesión y del usuario
        $usuario = null;
        if ($this->sesion->usuarioId() !== null) {
            $usuario = $this->validarSesion($p);
            if ($usuario === null && !$ruta['publico']) {
                return $p->esperaJson()
                    ? Respuesta::json(['error' => 'sesion_expirada'], 401)
                    : Respuesta::redirigir('/login?m=expirada');
            }
        }

        // 5. Acceso a rutas privadas
        if (!$ruta['publico'] && $usuario === null) {
            if ($p->metodo === 'GET' && !$p->esperaJson()) {
                $this->sesion->set('destino', $p->ruta);
                return Respuesta::redirigir('/login');
            }
            return Respuesta::json(['error' => 'no_autenticado'], 401);
        }

        // 6. Permiso
        if ($ruta['permiso'] !== null && !Permisos::puede($this->sesion->rol(), $ruta['permiso'])) {
            return $this->error($p, 403, 'No tienes permiso para esta acción.');
        }

        // 7. CSRF en todo lo que no sea GET
        if (!in_array($p->metodo, ['GET', 'HEAD'], true)) {
            $token = $p->cuerpo['_csrf'] ?? $p->cabecera('X-CSRF-Token');
            if (!$this->csrf->valido(is_string($token) ? $token : null)) {
                return $this->error($p, 403, 'El formulario venció. Recarga la página e inténtalo de nuevo.');
            }
        }

        // 8. Acción
        $this->vista->compartir([
            'usuario'          => $usuario,
            'csrf'             => $this->csrf->token(),
            'inactividad_min'  => $this->configuracion->entero('inactividad_minutos', 30),
        ]);
        return ($ruta['accion'])($p);
    }

    /**
     * Devuelve el usuario si la sesión sigue valiendo; si no, la cierra.
     */
    private function validarSesion(Peticion $p): ?array
    {
        $id = (int)$this->sesion->usuarioId();
        $estado = $this->sesion->estado(
            $this->configuracion->entero('inactividad_minutos', 30),
            $this->configuracion->entero('sesion_max_horas', 10));

        $usuario = $estado === Sesion::VALIDA ? $this->usuarios->porId($id) : null;
        $vigente = $usuario !== null
            && (int)$usuario['activo'] === 1
            && (int)$usuario['sesion_version'] === $this->sesion->versionSesion();

        if (!$vigente) {
            $motivo = match (true) {
                $estado === Sesion::INACTIVA => 'inactividad',
                $estado === Sesion::VENCIDA  => 'duracion_maxima',
                default                      => 'sesion_invalidada',
            };
            try {
                $this->auth->registrarCierre($id, $motivo, $p->ip(), $p->agente());
            } catch (\Throwable $e) {
                error_log('No se pudo registrar el cierre de sesión: ' . $e->getMessage());
            }
            $this->sesion->cerrar();
            $this->sesion->iniciar($p->esHttps());
            return null;
        }

        $this->sesion->tocar();
        $this->bd->fijarContexto($id, $p->ip(), $p->agente());
        unset($usuario['password_hash']);
        return $usuario;
    }

    private function error(Peticion $p, int $estado, string $mensaje): Respuesta
    {
        if ($p->esperaJson()) {
            return Respuesta::json(['error' => $mensaje], $estado);
        }
        try {
            return Respuesta::html($this->vista->render('error', ['titulo' => 'Error', 'estado' => $estado, 'mensaje' => $mensaje], 'layout_acceso'), $estado);
        } catch (\Throwable) {
            return new Respuesta($estado, htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'), ['Content-Type' => 'text/html; charset=utf-8']);
        }
    }
}
