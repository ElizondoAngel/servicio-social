<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

/**
 * Hora actual. Se inyecta para poder probar vencimientos sin esperar.
 */
interface Reloj
{
    public function ahora(): int;
}
