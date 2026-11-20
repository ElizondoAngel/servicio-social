<?php
declare(strict_types=1);

namespace ServicioSocial\Seguridad;

use ServicioSocial\Repositorios\ConfiguracionRepositorio;
use ServicioSocial\Repositorios\IntentoLoginRepositorio;

/**
 * Bloquea el inicio de sesión tras varios intentos fallidos en una
 * ventana de tiempo, por correo y por IP (valores en configuracion).
 */
final class LimiteIntentos
{
    public function __construct(
        private readonly IntentoLoginRepositorio $intentos,
        private readonly ConfiguracionRepositorio $config,
    ) {
    }

    public function ventanaMinutos(): int
    {
        return $this->config->entero('intentos_ventana_minutos', 15);
    }

    public function bloqueado(string $correo, string $ip): bool
    {
        $ventana = $this->ventanaMinutos();
        return $this->intentos->fallosPorCorreo($correo, $ventana) >= $this->config->entero('intentos_max_correo', 5)
            || $this->intentos->fallosPorIp($ip, $ventana) >= $this->config->entero('intentos_max_ip', 20);
    }

    public function registrar(string $correo, string $ip, bool $exito): void
    {
        $this->intentos->registrar($correo, $ip, $exito);
    }
}
