<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Seguridad\Sesion;
use ServicioSocial\Servicios\ConfiguracionServicio;

final class ConfiguracionControlador
{
    public function __construct(private readonly Vista $vista, private readonly Sesion $sesion, private readonly ConfiguracionServicio $servicio)
    {
    }

    public function ver(Peticion $p): Respuesta
    {
        return $this->pagina(null, []);
    }

    public function guardar(Peticion $p): Respuesta
    {
        $r = $this->servicio->guardar($p->cuerpo, (int)$this->sesion->usuarioId());
        if (!$r['ok']) {
            return $this->pagina($r['datos'], $r['errores'], 422);
        }
        $this->sesion->set('aviso', $r['aviso']);
        return Respuesta::redirigir('/configuracion');
    }

    private function pagina(?array $datos, array $errores, int $estado = 200): Respuesta
    {
        return Respuesta::html($this->vista->render('configuracion', [
            'titulo' => 'Configuración', 'valores' => $this->servicio->valores(), 'datos' => $datos, 'errores' => $errores,
            'aviso' => $this->sesion->tomar('aviso'),
        ]), $estado);
    }
}
