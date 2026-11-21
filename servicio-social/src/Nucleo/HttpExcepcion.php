<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

/**
 * Error que se muestra al usuario con un código HTTP y un mensaje claro.
 */
final class HttpExcepcion extends \RuntimeException
{
    public function __construct(public readonly int $estado, string $mensaje, public readonly array $errores = [])
    {
        parent::__construct($mensaje);
    }
}
