<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

/**
 * Datos de la petición HTTP actual.
 */
final class Peticion
{
    public function __construct(
        public readonly string $metodo,
        public readonly string $ruta,
        public readonly array $consulta,
        public readonly array $cuerpo,
        public readonly array $servidor,
        private readonly array $proxiesConfianza = [],
        public readonly array $archivos = [],
    ) {
    }

    public static function desdeGlobales(array $proxiesConfianza = []): self
    {
        $ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $ruta = '/' . trim(rawurldecode($ruta), '/');
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $ruta, $_GET, $_POST, $_SERVER, $proxiesConfianza, $_FILES);
    }

    public function texto(string $campo): string
    {
        $valor = $this->cuerpo[$campo] ?? '';
        return is_string($valor) ? trim($valor) : '';
    }

    /** Texto sin recortar (contraseñas: los espacios cuentan). */
    public function textoCrudo(string $campo): string
    {
        $valor = $this->cuerpo[$campo] ?? '';
        return is_string($valor) ? $valor : '';
    }

    public function consultaTexto(string $campo): string
    {
        $valor = $this->consulta[$campo] ?? '';
        return is_string($valor) ? trim($valor) : '';
    }

    public function cabecera(string $nombre): ?string
    {
        $clave = 'HTTP_' . strtoupper(str_replace('-', '_', $nombre));
        $valor = $this->servidor[$clave] ?? null;
        return is_string($valor) ? $valor : null;
    }

    /**
     * IP real de la conexión. X-Forwarded-For solo se toma en cuenta si
     * la conexión viene de un proxy declarado de confianza.
     */
    public function ip(): string
    {
        $remota = (string)($this->servidor['REMOTE_ADDR'] ?? '0.0.0.0');
        if (in_array($remota, $this->proxiesConfianza, true)) {
            $reenviada = $this->cabecera('X-Forwarded-For');
            if ($reenviada !== null) {
                $primera = trim(explode(',', $reenviada)[0]);
                if (filter_var($primera, FILTER_VALIDATE_IP)) {
                    return $primera;
                }
            }
        }
        return $remota;
    }

    public function agente(): string
    {
        return mb_substr((string)($this->servidor['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function esHttps(): bool
    {
        $https = $this->servidor['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string)$https) !== 'off') {
            return true;
        }
        $remota = (string)($this->servidor['REMOTE_ADDR'] ?? '');
        return in_array($remota, $this->proxiesConfianza, true)
            && strtolower((string)$this->cabecera('X-Forwarded-Proto')) === 'https';
    }

    public function esperaJson(): bool
    {
        return str_contains((string)$this->cabecera('Accept'), 'application/json');
    }

    /**
     * Archivo subido sin errores, o null. Nunca confía en el nombre ni en el
     * tipo que manda el navegador.
     * @return array{ruta: string, nombre: string, tamano: int}|null
     */
    public function archivo(string $campo): ?array
    {
        $a = $this->archivos[$campo] ?? null;
        if (!is_array($a) || !is_string($a['tmp_name'] ?? null) || ($a['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if (!is_uploaded_file($a['tmp_name']) && PHP_SAPI !== 'cli') {
            return null;
        }
        return ['ruta' => $a['tmp_name'], 'nombre' => basename((string)($a['name'] ?? 'archivo')), 'tamano' => (int)($a['size'] ?? 0)];
    }
}
