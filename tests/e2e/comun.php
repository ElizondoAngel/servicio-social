<?php
declare(strict_types=1);

/**
 * Piezas comunes de las pruebas de punta a punta.
 */

/** Un navegador con sus propias cookies. */
final class Navegador
{
    private string $jar;
    public array $ultima = [];

    public function __construct(private readonly string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function get(string $ruta, array $cabeceras = []): array
    {
        return $this->pedir('GET', $ruta, null, $cabeceras);
    }

    public function post(string $ruta, array $datos, array $cabeceras = []): array
    {
        return $this->pedir('POST', $ruta, http_build_query($datos), $cabeceras);
    }

    /** Token CSRF de la página actual (o de /login si no hay). */
    public function csrf(string $desde = '/login'): string
    {
        $r = $this->get($desde);
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['cuerpo'], $m);
        if (!$m) {
            preg_match('/data-csrf="([a-f0-9]{64})"/', $r['cuerpo'], $m);
        }
        return $m[1] ?? '';
    }

    public function cookieSesion(): ?string
    {
        foreach (file($this->jar) ?: [] as $linea) {
            $c = explode("\t", trim($linea));
            if (count($c) === 7 && $c[5] === 'ss_sesion') {
                return rawurldecode($c[6]);
            }
        }
        return null;
    }

    private function pedir(string $metodo, string $ruta, ?string $cuerpo, array $cabeceras): array
    {
        $ch = curl_init($this->base . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_HTTPHEADER     => $cabeceras,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $raw = (string)curl_exec($ch);
        $tam = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $estado = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $h = [];
        foreach (explode("\r\n", substr($raw, 0, $tam)) as $l) {
            if (str_contains($l, ':')) {
                [$k, $v] = explode(':', $l, 2);
                $h[strtolower(trim($k))] = trim($v);
            }
        }
        return $this->ultima = ['estado' => $estado, 'cabeceras' => $h, 'cuerpo' => substr($raw, $tam)];
    }
}

function entrar(Navegador $n, string $correo, string $password): array
{
    return $n->post('/login', ['_csrf' => $n->csrf(), 'correo' => $correo, 'password' => $password]);
}
