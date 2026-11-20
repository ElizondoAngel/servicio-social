<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

/**
 * Respuesta HTTP. Todas llevan las cabeceras de seguridad.
 */
final class Respuesta
{
    private array $cabeceras = [];

    public function __construct(
        public readonly int $estado = 200,
        public readonly string $cuerpo = '',
        array $cabeceras = [],
    ) {
        $this->cabeceras = $cabeceras;
    }

    public static function html(string $html, int $estado = 200): self
    {
        return new self($estado, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(array $datos, int $estado = 200): self
    {
        return new self($estado, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /** Redirección 303: después de un POST el navegador hace GET. */
    public static function redirigir(string $ruta): self
    {
        if (!str_starts_with($ruta, '/') || str_starts_with($ruta, '//')) {
            $ruta = '/';
        }
        return new self(303, '', ['Location' => $ruta]);
    }

    public static function vacia(int $estado = 204): self
    {
        return new self($estado);
    }

    public function conCabecera(string $nombre, string $valor): self
    {
        $copia = clone $this;
        $copia->cabeceras[$nombre] = $valor;
        return $copia;
    }

    public function cabeceras(): array
    {
        return $this->cabeceras;
    }

    public function enviar(bool $https): void
    {
        header_remove('X-Powered-By');
        http_response_code($this->estado);
        $seguridad = [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; "
                . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; "
                . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
            'X-Frame-Options'        => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'same-origin',
            'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
            'Cache-Control'          => 'no-store',
        ];
        if ($https) {
            $seguridad['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach (array_merge($seguridad, $this->cabeceras) as $nombre => $valor) {
            header($nombre . ': ' . $valor);
        }
        echo $this->cuerpo;
    }
}
