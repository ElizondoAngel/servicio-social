<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Repositorios\HistorialRepositorio;
use ServicioSocial\Repositorios\UsuarioRepositorio;
use ServicioSocial\Seguridad\Contrasenas;
use ServicioSocial\Seguridad\LimiteIntentos;

/**
 * Inicio de sesión. Nunca revela si un correo existe: un correo
 * inexistente, una contraseña incorrecta y un usuario desactivado reciben
 * el mismo mensaje y tardan lo mismo.
 */
final class AuthServicio
{
    public const OK = 'ok';
    public const INVALIDO = 'invalido';
    public const BLOQUEADO = 'bloqueado';

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly UsuarioRepositorio $usuarios,
        private readonly LimiteIntentos $limite,
        private readonly HistorialRepositorio $historial,
    ) {
    }

    /**
     * @return array{resultado: string, usuario?: array}
     */
    public function iniciarSesion(string $correo, string $password, string $ip, string $agente): array
    {
        $correo = mb_strtolower(trim($correo));

        if ($this->limite->bloqueado($correo, $ip)) {
            return ['resultado' => self::BLOQUEADO];
        }

        $usuario = $this->usuarios->porCorreo($correo);
        $valida = Contrasenas::verificar($password, $usuario['password_hash'] ?? null);

        if (!$valida || !$usuario || (int)$usuario['activo'] !== 1) {
            $this->limite->registrar($correo, $ip, false);
            if ($this->limite->bloqueado($correo, $ip)) {
                $this->historial->evento('bloqueo_por_intentos', 'usuarios', $usuario['id'] ?? null, null,
                    ['correo' => $correo], $ip, $agente);
            }
            return ['resultado' => self::INVALIDO];
        }

        $this->limite->registrar($correo, $ip, true);
        $id = (int)$usuario['id'];
        $this->bd->fijarContexto($id, $ip, $agente);

        if (Contrasenas::necesitaRehash((string)$usuario['password_hash'])) {
            $this->bd->transaccion(fn() => $this->usuarios->actualizarHash($id, Contrasenas::hash($password)),
                'Actualización automática del cifrado de la contraseña');
        }
        $this->usuarios->registrarAcceso($id);
        $this->historial->evento('iniciar_sesion', 'usuarios', $id, $id, null, $ip, $agente);

        return ['resultado' => self::OK, 'usuario' => $usuario];
    }

    public function registrarCierre(int $usuarioId, string $motivo, string $ip, string $agente): void
    {
        $this->historial->evento('cerrar_sesion', 'usuarios', $usuarioId, $usuarioId, ['motivo' => $motivo], $ip, $agente);
    }
}
