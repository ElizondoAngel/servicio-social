<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use DateTimeImmutable;
use ServicioSocial\Dominio\SelloHistorial;
use ServicioSocial\Nucleo\HttpExcepcion;
use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Repositorios\HistorialRepositorio;
use ServicioSocial\Seguridad\Sesion;

/**
 * Historial general: búsqueda con filtros, detalle de cada movimiento
 * (antes y después) y verificación de la cadena de sellos.
 */
final class HistorialControlador
{
    public const POR_PAGINA = 50;
    public const ENTIDADES = [
        'alumnos' => 'Alumnos', 'expedientes' => 'Expedientes', 'asistencias' => 'Asistencias', 'firmas_semana' => 'Firmas',
        'dias_inhabiles' => 'Días inhábiles', 'configuracion' => 'Configuración', 'usuarios' => 'Usuarios',
        'sesion' => 'Sesiones', 'respaldos' => 'Respaldos', 'historial' => 'Historial',
    ];

    public function __construct(
        private readonly Vista $vista,
        private readonly Sesion $sesion,
        private readonly HistorialRepositorio $historial,
    ) {
    }

    public function index(Peticion $p): Respuesta
    {
        $f = $this->filtros($p);
        $pagina = max(1, (int)$p->consultaTexto('pagina'));
        $total = $this->historial->contar($f);
        return Respuesta::html($this->vista->render('historial/index', [
            'titulo'   => 'Historial',
            'filtros'  => $f,
            'filas'    => $this->historial->buscar($f, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA),
            'total'    => $total,
            'pagina'   => $pagina,
            'paginas'  => max(1, (int)ceil($total / self::POR_PAGINA)),
            'opciones' => $this->historial->opciones(),
            'sello'    => $this->historial->estadoSello(),
            'verificacion' => $this->sesion->tomar('verificacion'),
        ]));
    }

    public function ver(Peticion $p, array $params): Respuesta
    {
        $h = $this->historial->porId($params['id']) ?? throw new HttpExcepcion(404, 'No existe ese movimiento.');
        $antes = $h['antes'] ? (json_decode($h['antes'], true) ?: []) : [];
        $despues = $h['despues'] ? (json_decode($h['despues'], true) ?: []) : [];
        $campos = array_values(array_unique(array_merge(array_keys($antes), array_keys($despues))));
        return Respuesta::html($this->vista->render('historial/ver', [
            'titulo' => 'Movimiento ' . $h['id'], 'h' => $h, 'antes' => $antes, 'despues' => $despues, 'campos' => $campos,
        ]));
    }

    /** Recorre toda la cadena con la conexión de la aplicación (solo lectura). */
    public function verificar(Peticion $p): Respuesta
    {
        $v = SelloHistorial::verificar($this->historial->recorrer());
        $this->historial->evento('verificar_historial', 'historial', null, $this->sesion->usuarioId(),
            ['ok' => $v['ok'], 'revisadas' => $v['revisadas'], 'selladas' => $v['selladas'], 'problemas' => count($v['problemas'])],
            $p->ip(), $p->agente());
        $this->sesion->set('verificacion', $v + ['en' => (new DateTimeImmutable())->format('Y-m-d H:i:s')]);
        return Respuesta::redirigir('/historial');
    }

    private function filtros(Peticion $p): array
    {
        $fecha = function (string $c) use ($p): ?string {
            $v = $p->consultaTexto($c);
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
        };
        $entero = fn(string $c) => ctype_digit($p->consultaTexto($c)) && (int)$p->consultaTexto($c) > 0 ? (int)$p->consultaTexto($c) : null;
        $texto = fn(string $c, int $max) => mb_substr($p->consultaTexto($c), 0, $max);
        $entidad = $texto('entidad', 40);
        $accion = $texto('accion', 40);
        return [
            'entidad'       => preg_match('/^[a-z_]+$/', $entidad) ? $entidad : '',
            'accion'        => preg_match('/^[a-z_]+$/', $accion) ? $accion : '',
            'usuario_id'    => $entero('usuario_id'),
            'expediente_id' => $entero('expediente_id'),
            'desde'         => $fecha('desde'),
            'hasta'         => $fecha('hasta'),
            'texto'         => $texto('texto', 80),
        ];
    }
}
