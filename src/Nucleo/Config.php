<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

/**
 * Configuración del archivo config.local.php, leída con claves con punto:
 * $config->get('bd.host').
 */
final class Config
{
    public function __construct(private readonly array $valores)
    {
    }

    public function get(string $clave, mixed $porDefecto = null): mixed
    {
        $actual = $this->valores;
        foreach (explode('.', $clave) as $parte) {
            if (!is_array($actual) || !array_key_exists($parte, $actual)) {
                return $porDefecto;
            }
            $actual = $actual[$parte];
        }
        return $actual;
    }

    public function esProduccion(): bool
    {
        return $this->get('app.entorno') === 'produccion';
    }
}
