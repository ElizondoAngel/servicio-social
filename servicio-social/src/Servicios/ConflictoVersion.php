<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

/** Alguien cambió el registro entre que se leyó y se guardó: no se pisa. */
final class ConflictoVersion extends \RuntimeException
{
}
