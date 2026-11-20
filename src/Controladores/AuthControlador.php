<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Seguridad\Csrf;
use ServicioSocial\Seguridad\Sesion;
use ServicioSocial\Servicios\AuthServicio;
use ServicioSocial\Servicios\RecuperacionServicio;

/**
 * Inicio de sesión, cierre y recuperación de contraseña.
 */
final class AuthControlador
{
    /** Mensajes fijos para el parámetro ?m= (nunca se imprime texto de la URL). */
    private const AVISOS = [
        'salida'       => ['info', 'Cerraste tu sesión.'],
        'expirada'     => ['aviso', 'Tu sesión se cerró por inactividad. Vuelve a iniciar sesión.'],
        'enviado'      => ['info', 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña. Revisa tu bandeja de entrada.'],
        'restablecida' => ['exito', 'Tu contraseña se cambió. Ya puedes iniciar sesión.'],
    ];

    public function __construct(
        private readonly Vista $vista,
        private readonly Sesion $sesion,
        private readonly Csrf $csrf,
        private readonly AuthServicio $auth,
        private readonly RecuperacionServicio $recuperacion,
    ) {
    }

    public function mostrarLogin(Peticion $p): Respuesta
    {
        if ($this->sesion->usuarioId() !== null) {
            return Respuesta::redirigir('/');
        }
        return $this->pantallaLogin('', self::AVISOS[$p->consultaTexto('m')] ?? null);
    }

    public function procesarLogin(Peticion $p): Respuesta
    {
        $correo = mb_strtolower($p->texto('correo'));
        $password = $p->textoCrudo('password');
        if ($correo === '' || $password === '') {
            return $this->pantallaLogin($correo, ['error', 'Escribe tu correo y tu contraseña.'], 422);
        }

        $r = $this->auth->iniciarSesion($correo, $password, $p->ip(), $p->agente());
        if ($r['resultado'] === AuthServicio::BLOQUEADO) {
            return $this->pantallaLogin($correo, ['error', 'Demasiados intentos fallidos. Espera unos minutos e inténtalo de nuevo.'], 429);
        }
        if ($r['resultado'] !== AuthServicio::OK) {
            return $this->pantallaLogin($correo, ['error', 'Correo o contraseña incorrectos.'], 422);
        }

        $u = $r['usuario'];
        $this->sesion->abrir((int)$u['id'], (string)$u['rol'], (int)$u['sesion_version']);
        $destino = $this->sesion->tomar('destino');
        return Respuesta::redirigir(is_string($destino) ? $destino : '/');
    }

    public function cerrarSesion(Peticion $p): Respuesta
    {
        $id = $this->sesion->usuarioId();
        if ($id !== null) {
            $this->auth->registrarCierre($id, 'manual', $p->ip(), $p->agente());
        }
        $this->sesion->cerrar();
        return Respuesta::redirigir('/login?m=salida');
    }

    /** Mantiene viva la sesión cuando el administrador elige seguir trabajando. */
    public function renovar(Peticion $p): Respuesta
    {
        return Respuesta::vacia();
    }

    public function mostrarOlvide(Peticion $p): Respuesta
    {
        return Respuesta::html($this->vista->render('auth/olvide', [
            'titulo' => 'Recuperar contraseña', 'csrf' => $this->csrf->token(), 'aviso' => null, 'correo' => '',
        ], 'layout_acceso'));
    }

    public function procesarOlvide(Peticion $p): Respuesta
    {
        $correo = mb_strtolower($p->texto('correo'));
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            return Respuesta::html($this->vista->render('auth/olvide', [
                'titulo' => 'Recuperar contraseña', 'csrf' => $this->csrf->token(),
                'aviso' => ['error', 'Escribe un correo válido.'], 'correo' => $correo,
            ], 'layout_acceso'), 422);
        }
        $this->recuperacion->solicitar($correo, $p->ip(), $p->agente());
        return Respuesta::redirigir('/login?m=enviado');
    }

    /**
     * GET /restablecer?t=... valida el enlace, guarda el token en la sesión
     * y redirige a /restablecer sin el token en la URL.
     */
    public function mostrarRestablecer(Peticion $p): Respuesta
    {
        $token = $p->consultaTexto('t');
        if ($token !== '') {
            $hash = RecuperacionServicio::hashToken($token);
            if ($this->recuperacion->vigente($hash) === null) {
                return $this->pantallaEnlaceInvalido();
            }
            $this->sesion->regenerar();
            $this->sesion->set('reset_token', $hash);
            return Respuesta::redirigir('/restablecer')->conCabecera('Referrer-Policy', 'no-referrer');
        }
        $hash = (string)$this->sesion->get('reset_token');
        if ($this->recuperacion->vigente($hash) === null) {
            $this->sesion->quitar('reset_token');
            return $this->pantallaEnlaceInvalido();
        }
        return $this->pantallaRestablecer([]);
    }

    public function procesarRestablecer(Peticion $p): Respuesta
    {
        $hash = (string)$this->sesion->get('reset_token');
        $errores = $this->recuperacion->restablecer($hash, $p->textoCrudo('password'), $p->textoCrudo('confirmacion'),
            $p->ip(), $p->agente());
        if ($errores) {
            if ($this->recuperacion->vigente($hash) === null) {
                $this->sesion->quitar('reset_token');
                return $this->pantallaEnlaceInvalido();
            }
            return $this->pantallaRestablecer($errores, 422);
        }
        $this->sesion->cerrar();
        return Respuesta::redirigir('/login?m=restablecida');
    }

    private function pantallaLogin(string $correo, ?array $aviso, int $estado = 200): Respuesta
    {
        return Respuesta::html($this->vista->render('auth/login', [
            'titulo' => 'Iniciar sesión', 'csrf' => $this->csrf->token(), 'aviso' => $aviso, 'correo' => $correo,
        ], 'layout_acceso'), $estado);
    }

    private function pantallaRestablecer(array $errores, int $estado = 200): Respuesta
    {
        return Respuesta::html($this->vista->render('auth/restablecer', [
            'titulo' => 'Nueva contraseña', 'csrf' => $this->csrf->token(), 'errores' => $errores,
        ], 'layout_acceso'), $estado)->conCabecera('Referrer-Policy', 'no-referrer');
    }

    private function pantallaEnlaceInvalido(): Respuesta
    {
        return Respuesta::html($this->vista->render('auth/enlace_invalido', ['titulo' => 'Enlace no válido'], 'layout_acceso'), 410)
            ->conCabecera('Referrer-Policy', 'no-referrer');
    }
}
