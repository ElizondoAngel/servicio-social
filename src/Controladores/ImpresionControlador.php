<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use ServicioSocial\Nucleo\HttpExcepcion;
use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Repositorios\AlumnoRepositorio;
use ServicioSocial\Repositorios\ExpedienteRepositorio;
use ServicioSocial\Servicios\ExpedienteServicio;

/**
 * Versión para imprimir (o guardar como PDF desde el navegador) de un
 * expediente o de los expedientes que muestra el tablero, con la misma
 * plantilla: datos del alumno y del periodo, resumen de horas, bitácora
 * por hojas de 12 semanas, notas de los registros y espacio para firmas.
 */
final class ImpresionControlador
{
    /** Más que esto no se arma en una sola página. */
    public const MAXIMO = 150;

    public function __construct(
        private readonly Vista $vista,
        private readonly ExpedienteRepositorio $expedientes,
        private readonly AlumnoRepositorio $alumnos,
        private readonly ExpedienteServicio $calculos,
    ) {
    }

    public function uno(Peticion $p, array $params): Respuesta
    {
        $exp = $this->expedientes->porId($params['id']) ?? throw new HttpExcepcion(404, 'No existe ese expediente.');
        $alumno = $this->alumnos->porId((int)$exp['alumno_id']);
        return $this->pagina([$this->armar($exp)], 'Expediente de ' . nombre_completo($alumno), '/expedientes/' . (int)$exp['id']);
    }

    /** Los mismos expedientes que muestra el tablero con esa búsqueda, filtro y orden. */
    public function varios(Peticion $p): Respuesta
    {
        $busqueda = mb_substr($p->consultaTexto('q'), 0, 80);
        $filtro = $p->consultaTexto('filtro');
        $filtro = isset(ExpedienteServicio::FILTROS[$filtro]) ? $filtro : 'todos';
        $orden = $p->consultaTexto('orden');
        $orden = isset(ExpedienteServicio::ORDENES[$orden]) ? $orden : 'nombre';
        $filas = $this->calculos->tablero($busqueda, $filtro, $orden)['filas'];
        $total = count($filas);
        $expedientes = [];
        foreach (array_slice($filas, 0, self::MAXIMO) as $f) {
            $exp = $this->expedientes->porId((int)$f['expediente_id']);
            if ($exp !== null) {
                $expedientes[] = $this->armar($exp);
            }
        }
        $volver = '/?' . http_build_query(array_filter(['q' => $busqueda, 'filtro' => $filtro === 'todos' ? '' : $filtro,
            'orden' => $orden === 'nombre' ? '' : $orden]));
        return $this->pagina($expedientes, 'Expedientes de servicio social', rtrim($volver, '?'), $total);
    }

    private function armar(array $exp): array
    {
        return $this->calculos->detalle($exp) + [
            'expediente' => $exp,
            'alumno'     => $this->alumnos->porId((int)$exp['alumno_id']),
        ];
    }

    private function pagina(array $expedientes, string $titulo, string $volver, ?int $total = null): Respuesta
    {
        return Respuesta::html($this->vista->render('impresion/expedientes', [
            'titulo'      => $titulo,
            'expedientes' => $expedientes,
            'total'       => $total ?? count($expedientes),
            'volver'      => $volver,
            'impreso'     => $this->calculos->hoy(),
        ], 'layout_impresion'));
    }
}
