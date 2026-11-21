<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios\Correo;

interface Correo
{
    /** Envía un correo de texto plano. Lanza una excepción si falla. */
    public function enviar(string $para, string $asunto, string $texto): void;
}
