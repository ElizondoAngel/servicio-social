<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios\Correo;

/**
 * Solo para desarrollo y pruebas: escribe los correos en un archivo en
 * lugar de enviarlos. Nunca se usa en producción.
 */
final class CorreoArchivo implements Correo
{
    public function __construct(private readonly string $archivo)
    {
    }

    public function enviar(string $para, string $asunto, string $texto): void
    {
        $linea = json_encode(['fecha' => date('c'), 'para' => $para, 'asunto' => $asunto, 'texto' => $texto],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->archivo, $linea . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir el correo de desarrollo.');
        }
    }
}
