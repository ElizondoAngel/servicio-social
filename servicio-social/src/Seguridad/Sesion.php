<?php
declare(strict_types=1);

namespace ServicioSocial\Seguridad;

use ServicioSocial\Nucleo\Config;
use ServicioSocial\Nucleo\Reloj;

/**
 * Sesión del administrador.
 *
 * - Carpeta de sesiones propia (no la compartida con RH).
 * - Cookie HttpOnly, SameSite=Lax y Secure en producción.
 * - Cierre por inactividad y duración máxima.
 * - Guarda la versión de sesión del usuario; si cambia su contraseña,
 *   la versión sube y esta sesión deja de valer.
 */
final class Sesion
{
    public const VALIDA = 'valida';
    public const ANONIMA = 'anonima';
    public const INACTIVA = 'inactiva';
    public const VENCIDA = 'vencida';

    public function __construct(private readonly Config $config, private readonly Reloj $reloj)
    {
    }

    public function iniciar(bool $https): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $ruta = (string)$this->config->get('sesion.ruta_archivos', __DIR__ . '/../../storage/sesiones');
        if (!is_dir($ruta)) {
            mkdir($ruta, 0700, true);
        }
        session_save_path($ruta);
        session_name((string)$this->config->get('sesion.nombre_cookie', 'ss_sesion'));
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        if (PHP_VERSION_ID < 80400) {
            // En PHP 8.4 estas opciones están en desuso; su valor por defecto ya es seguro.
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '6');
        }
        ini_set('session.gc_maxlifetime', (string)(12 * 3600));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https || $this->config->esProduccion(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Revisa la sesión contra los límites de tiempo. Función pura para
     * poder probarla sin una sesión real.
     */
    public static function evaluar(array $datos, int $ahora, int $inactividadSeg, int $maximaSeg): string
    {
        if (empty($datos['usuario_id'])) {
            return self::ANONIMA;
        }
        if ($ahora - (int)($datos['inicio'] ?? 0) >= $maximaSeg) {
            return self::VENCIDA;
        }
        if ($ahora - (int)($datos['ultimo_mov'] ?? 0) >= $inactividadSeg) {
            return self::INACTIVA;
        }
        return self::VALIDA;
    }

    public function estado(int $inactividadMin, int $maximaHoras): string
    {
        return self::evaluar($_SESSION, $this->reloj->ahora(), $inactividadMin * 60, $maximaHoras * 3600);
    }

    public function abrir(int $usuarioId, string $rol, int $versionSesion): void
    {
        session_regenerate_id(true);
        $destino = $_SESSION['destino'] ?? null;
        $_SESSION = [
            'usuario_id'     => $usuarioId,
            'rol'            => $rol,
            'sesion_version' => $versionSesion,
            'inicio'         => $this->reloj->ahora(),
            'ultimo_mov'     => $this->reloj->ahora(),
        ];
        if (is_string($destino)) {
            $_SESSION['destino'] = $destino;
        }
    }

    public function tocar(): void
    {
        $_SESSION['ultimo_mov'] = $this->reloj->ahora();
    }

    public function cerrar(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => 1, 'path' => $p['path'], 'secure' => $p['secure'],
                'httponly' => true, 'samesite' => $p['samesite'] ?: 'Lax',
            ]);
            session_destroy();
        }
    }

    /** Cambia el id de sesión sin perder los datos (p. ej. al validar un enlace). */
    public function regenerar(): void
    {
        session_regenerate_id(true);
    }

    public function usuarioId(): ?int
    {
        return isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;
    }

    public function rol(): ?string
    {
        return $_SESSION['rol'] ?? null;
    }

    public function versionSesion(): int
    {
        return (int)($_SESSION['sesion_version'] ?? 0);
    }

    public function get(string $clave): mixed
    {
        return $_SESSION[$clave] ?? null;
    }

    public function set(string $clave, mixed $valor): void
    {
        $_SESSION[$clave] = $valor;
    }

    public function quitar(string $clave): void
    {
        unset($_SESSION[$clave]);
    }

    /** Lee un valor y lo borra (mensajes de una sola vez). */
    public function tomar(string $clave): mixed
    {
        $valor = $_SESSION[$clave] ?? null;
        unset($_SESSION[$clave]);
        return $valor;
    }
}
