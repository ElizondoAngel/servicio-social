<?php
declare(strict_types=1);

namespace ServicioSocial\Seguridad;

/**
 * Token CSRF por sesión. Toda petición que no sea GET debe traerlo en el
 * campo _csrf del formulario o en la cabecera X-CSRF-Token.
 */
final class Csrf
{
    private const CLAVE = 'csrf';

    public function token(): string
    {
        if (empty($_SESSION[self::CLAVE]) || !is_string($_SESSION[self::CLAVE])) {
            $_SESSION[self::CLAVE] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CLAVE];
    }

    public function valido(?string $recibido): bool
    {
        $esperado = $_SESSION[self::CLAVE] ?? null;
        return is_string($esperado) && is_string($recibido) && $recibido !== '' && hash_equals($esperado, $recibido);
    }
}
