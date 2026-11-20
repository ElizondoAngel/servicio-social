<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Repositorios\HistorialRepositorio;
use ServicioSocial\Repositorios\TokenResetRepositorio;
use ServicioSocial\Repositorios\UsuarioRepositorio;
use ServicioSocial\Seguridad\Contrasenas;
use ServicioSocial\Servicios\Correo\Correo;

/**
 * Recuperación de contraseña por correo con un enlace de un solo uso.
 */
final class RecuperacionServicio
{
    public const VIGENCIA_MINUTOS = 30;
    private const MAX_POR_USUARIO_HORA = 3;
    private const MAX_POR_IP_HORA = 10;

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly UsuarioRepositorio $usuarios,
        private readonly TokenResetRepositorio $tokens,
        private readonly HistorialRepositorio $historial,
        private readonly Correo $correo,
        private readonly string $urlBase,
    ) {
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Si el correo es de un usuario activo y no pasó los límites, envía el
     * enlace. Quien llama siempre muestra el mismo mensaje.
     */
    public function solicitar(string $correo, string $ip, string $agente): void
    {
        $usuario = $this->usuarios->porCorreo($correo);
        if (!$usuario || (int)$usuario['activo'] !== 1) {
            return;
        }
        $id = (int)$usuario['id'];
        if ($this->tokens->recientesPorUsuario($id, 60) >= self::MAX_POR_USUARIO_HORA
            || $this->tokens->recientesPorIp($ip, 60) >= self::MAX_POR_IP_HORA) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $this->bd->transaccion(fn() => $this->tokens->crear($id, self::hashToken($token), self::VIGENCIA_MINUTOS, $ip));

        $enlace = rtrim($this->urlBase, '/') . '/restablecer?t=' . $token;
        try {
            $this->correo->enviar($usuario['correo'], 'Restablece tu contraseña',
                "Hola {$usuario['nombre']}:\n\n"
                . "Recibimos una solicitud para restablecer tu contraseña del sistema de Servicio Social.\n\n"
                . "Abre este enlace en los próximos " . self::VIGENCIA_MINUTOS . " minutos:\n$enlace\n\n"
                . "El enlace solo sirve una vez. Si no lo pediste, ignora este correo; tu contraseña no cambia.\n");
        } catch (\Throwable $e) {
            // La respuesta al visitante no cambia: no debe revelar si el correo existe.
            error_log('No se pudo enviar el correo de recuperación: ' . $e->getMessage());
            return;
        }
        $this->historial->evento('solicitar_restablecimiento', 'usuarios', $id, null, null, $ip, $agente);
    }

    /** Datos del token si sigue vigente. */
    public function vigente(string $tokenHash): ?array
    {
        return $tokenHash === '' ? null : $this->tokens->vigente($tokenHash);
    }

    /**
     * @return string[] errores; vacío si se cambió la contraseña
     */
    public function restablecer(string $tokenHash, string $password, string $confirmacion, string $ip, string $agente): array
    {
        $token = $this->vigente($tokenHash);
        if ($token === null) {
            return ['El enlace ya no es válido. Solicita uno nuevo.'];
        }
        $errores = Contrasenas::validar($password, $confirmacion, (string)$token['correo']);
        if ($errores) {
            return $errores;
        }

        $id = (int)$token['usuario_id'];
        $this->bd->fijarContexto($id, $ip, $agente);
        $usado = $this->bd->transaccion(function () use ($token, $id, $password) {
            if (!$this->tokens->marcarUsado((int)$token['id'])) {
                return false;
            }
            $this->usuarios->cambiarPassword($id, Contrasenas::hash($password));
            return true;
        }, 'Restablecimiento de contraseña por correo');

        if (!$usado) {
            return ['El enlace ya no es válido. Solicita uno nuevo.'];
        }

        try {
            $this->correo->enviar($token['correo'], 'Tu contraseña cambió',
                "Hola {$token['nombre']}:\n\nTu contraseña del sistema de Servicio Social se cambió el "
                . date('d/m/Y \a \l\a\s g:i a') . ". Se cerraron todas tus sesiones abiertas.\n\n"
                . "Si no fuiste tú, avisa de inmediato al administrador del sistema.\n");
        } catch (\Throwable $e) {
            error_log('No se pudo enviar el aviso de cambio de contraseña: ' . $e->getMessage());
        }
        return [];
    }
}
