<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;

/**
 * Página de inicio protegida. En la Etapa 4 se convierte en el dashboard.
 */
final class InicioControlador
{
    public function __construct(private readonly Vista $vista)
    {
    }

    public function inicio(Peticion $p): Respuesta
    {
        return Respuesta::html($this->vista->render('inicio', ['titulo' => 'Inicio']));
    }
}
