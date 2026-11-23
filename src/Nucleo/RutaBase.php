<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

/**
 * Permite servir el sistema en una subcarpeta del dominio, por ejemplo
 * https://grasc.utn.edu.mx/servicio-social. La ruta base sale de
 * app.url_base; si url_base no tiene ruta, todo funciona en la raíz.
 *
 * - Peticion quita la base (y un /public opcional) antes de enrutar.
 * - Respuesta::redirigir y las vistas la agregan a los enlaces internos.
 */
final class RutaBase
{
    private static string $base = '';

    public static function fijar(string $urlBase): void
    {
        $ruta = rtrim((string)(parse_url($urlBase, PHP_URL_PATH) ?? ''), '/');
        self::$base = preg_match('#^(/[A-Za-z0-9._-]+)*$#', $ruta) ? $ruta : '';
    }

    /** '' en la raíz, o '/servicio-social'. */
    public static function base(): string
    {
        return self::$base;
    }

    /** Ruta interna (/expedientes) → ruta pública (/servicio-social/expedientes). */
    public static function url(string $ruta): string
    {
        return self::$base . $ruta;
    }

    /** Ruta pública → ruta interna que entiende el enrutador. */
    public static function quitar(string $ruta): string
    {
        if (self::$base === '') {
            return $ruta;
        }
        foreach ([self::$base, '/public'] as $prefijo) {
            if ($ruta === $prefijo) {
                $ruta = '/';
            } elseif (str_starts_with($ruta, $prefijo . '/')) {
                $ruta = substr($ruta, strlen($prefijo));
            }
        }
        return $ruta;
    }

    /** Agrega la base a href, action, src y formaction internos (una sola vez). */
    public static function enHtml(string $html): string
    {
        if (self::$base === '') {
            return $html;
        }
        $ya = preg_quote(ltrim(self::$base, '/'), '#');
        return (string)preg_replace(
            '#\b(href|action|src|formaction)="/(?!/)(?!' . $ya . '(?:[/"?\#]))#',
            '$1="' . self::$base . '/',
            $html
        );
    }
}
