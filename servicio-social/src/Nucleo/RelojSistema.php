<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

final class RelojSistema implements Reloj
{
    public function ahora(): int
    {
        return time();
    }
}
