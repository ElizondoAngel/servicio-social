<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use ServicioSocial\Nucleo\Config;
use ServicioSocial\Nucleo\HttpExcepcion;
use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;

/**
 * Buzón de prueba (solo desarrollo): muestra los correos que el sistema
 * "envió" al archivo de desarrollo, con sus enlaces listos para abrir.
 *
 * Solo existe si TODO esto se cumple; si no, responde 404 como si no existiera:
 *  - el entorno no es producción;
 *  - el correo está en modo 'archivo' (no se envía nada de verdad);
 *  - la petición viene de la misma computadora (127.0.0.1 o ::1).
 */
final class BuzonDevControlador
{
    private const MOSTRAR = 20;

    public function __construct(private readonly Vista $vista, private readonly Config $config)
    {
    }

    public static function disponible(Config $config): bool
    {
        return !$config->esProduccion() && $config->get('correo.driver', 'smtp') === 'archivo';
    }

    public function ver(Peticion $p): Respuesta
    {
        if (!self::disponible($this->config) || !in_array($p->ip(), ['127.0.0.1', '::1'], true)) {
            throw new HttpExcepcion(404, 'Página no encontrada.');
        }
        $archivo = (string)$this->config->get('correo.archivo', '');
        $lineas = is_file($archivo) ? (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $correos = [];
        foreach (array_reverse(array_slice($lineas, -self::MOSTRAR)) as $linea) {
            $c = json_decode($linea, true);
            if (!is_array($c)) {
                continue;
            }
            preg_match_all('#https?://\S+#', (string)($c['texto'] ?? ''), $m);
            $correos[] = [
                'fecha'   => (string)($c['fecha'] ?? ''),
                'para'    => (string)($c['para'] ?? ''),
                'asunto'  => (string)($c['asunto'] ?? ''),
                'texto'   => (string)($c['texto'] ?? ''),
                'enlaces' => $m[0],
            ];
        }
        return Respuesta::html($this->vista->render('dev/buzon', ['titulo' => 'Buzón de prueba', 'correos' => $correos], 'layout_acceso'));
    }
}
