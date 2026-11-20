<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use DateTimeImmutable;
use ServicioSocial\Dominio\Calculadora;
use ServicioSocial\Dominio\Calendario;
use ServicioSocial\Dominio\Proyeccion;
use ServicioSocial\Dominio\Resumen;
use ServicioSocial\Repositorios\AsistenciaRepositorio;
use ServicioSocial\Repositorios\ConfiguracionRepositorio;
use ServicioSocial\Repositorios\DiaInhabilRepositorio;
use ServicioSocial\Repositorios\ExpedienteRepositorio;
use ServicioSocial\Repositorios\FirmaRepositorio;

/**
 * Arma los resúmenes de expedientes con la Calculadora (tablero y fichas).
 */
final class ExpedienteServicio
{
    public const FILTROS = ['todos' => 'Todos', 'activos' => 'Activos', 'proximos' => 'Próximos a concluir',
        'listos' => 'Listos para concluir', 'vencidos' => 'Periodo vencido', 'concluidos' => 'Concluidos'];
    public const ORDENES = ['nombre' => 'Nombre', 'avance' => 'Avance', 'conclusion' => 'Conclusión probable'];

    private ?array $inhabiles = null;

    public function __construct(
        private readonly ExpedienteRepositorio $expedientes,
        private readonly AsistenciaRepositorio $asistencias,
        private readonly DiaInhabilRepositorio $dias,
        private readonly ConfiguracionRepositorio $config,
        private readonly FirmaRepositorio $firmas,
    ) {
    }

    public function hoy(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }

    public function resumir(array $expediente, ?array $registros = null): Resumen
    {
        $registros ??= $this->asistencias->paraCalculo([(int)$expediente['id']])[(int)$expediente['id']] ?? [];
        return Calculadora::calcular($expediente, $registros, $this->inhabiles ??= $this->dias->mapa(), $this->hoy(),
            $this->minimos(), $this->config->entero('umbral_proximo_horas', 40));
    }

    private function minimos(): array
    {
        return ['horas' => $this->config->entero('horas_requeridas', 480), 'meses' => $this->config->entero('meses_requeridos', 6)];
    }

    /** Conclusión probable, escenarios por jornada, ritmo real y avance por mes. */
    public function proyeccion(array $exp, array $registros, Resumen $resumen, array $jornadas): array
    {
        $registros = array_values($registros);
        $activo = $exp['estado'] === 'activo';
        return [
            'conclusion' => Proyeccion::conclusionProbable($resumen),
            'escenarios' => $activo && $resumen->restantes > 0
                ? Proyeccion::escenarios($exp, $registros, $this->inhabiles ??= $this->dias->mapa(), $this->hoy(),
                    $this->minimos(), $this->config->entero('umbral_proximo_horas', 40), $jornadas)
                : [],
            'ritmo'      => Proyeccion::ritmo($registros, $this->hoy(), new DateTimeImmutable((string)$exp['fecha_inicio']), $resumen->restantes),
            'por_mes'    => Proyeccion::porMes($registros),
        ];
    }

    /**
     * @return array{estadisticas: array, filas: array}
     */
    public function tablero(string $busqueda, string $filtro, string $orden = 'nombre'): array
    {
        $todas = $this->expedientes->vigentes();
        $registros = $this->asistencias->paraCalculo(array_column($todas, 'expediente_id'));
        $resumenes = [];
        foreach ($todas as $fila) {
            $id = (int)$fila['expediente_id'];
            $resumenes[$id] = $this->resumir($fila + ['id' => $id], $registros[$id] ?? []);
        }

        $conteos = $this->expedientes->conteos();
        $proximos = count(array_filter($resumenes, fn(Resumen $r) => $r->situacion === Resumen::PROXIMO));

        $filas = $busqueda === '' ? $todas : $this->expedientes->vigentes($busqueda);
        $filas = array_values(array_filter(array_map(function (array $f) use ($resumenes) {
            $f['resumen'] = $resumenes[(int)$f['expediente_id']] ?? null;
            return $f;
        }, $filas), fn(array $f) => match ($filtro) {
            'activos'    => $f['estado'] === 'activo',
            'proximos'   => $f['resumen']?->situacion === Resumen::PROXIMO,
            'listos'     => $f['resumen']?->situacion === Resumen::LISTO,
            'vencidos'   => $f['resumen']?->situacion === Resumen::PERIODO_VENCIDO,
            'concluidos' => $f['estado'] === 'concluido',
            default      => true,
        }));

        foreach ($filas as &$f) {
            $f['conclusion'] = $f['resumen'] && !in_array($f['estado'], ['concluido', 'baja', 'suspendido'], true)
                ? Proyeccion::conclusionProbable($f['resumen'])['fecha'] : null;
        }
        unset($f);
        if ($orden === 'avance') {
            usort($filas, fn($a, $b) => [$b['resumen']?->avance ?? 0, nombre_completo($a)] <=> [$a['resumen']?->avance ?? 0, nombre_completo($b)]);
        } elseif ($orden === 'conclusion') {
            // Sin fecha (concluidos, suspendidos, sin proyección) al final.
            usort($filas, fn($a, $b) => [$a['conclusion'] === null, $a['conclusion'], nombre_completo($a)]
                <=> [$b['conclusion'] === null, $b['conclusion'], nombre_completo($b)]);
        }

        return [
            'estadisticas' => [
                'alumnos'    => (int)$conteos['alumnos'],
                'activos'    => (int)$conteos['activos'],
                'concluidos' => (int)$conteos['concluidos'],
                'proximos'   => $proximos,
                'horas'      => (int)$conteos['horas'],
                'umbral'     => $this->config->entero('umbral_proximo_horas', 40),
            ],
            'filas' => $filas,
        ];
    }

    /**
     * Todo lo que necesita la página del expediente: resumen, registros
     * completos y la cuadrícula del calendario.
     */
    public function detalle(array $exp, ?DateTimeImmutable $enfocar = null): array
    {
        $registros = $this->asistencias->deExpediente((int)$exp['id']);
        $resumen = $this->resumir($exp, array_values($registros));
        $hojas = Calendario::construir($exp, $registros, $this->inhabiles ??= $this->dias->mapa(), $resumen->diasProyectados,
            $this->firmas->deExpediente((int)$exp['id']), $this->hoy(), $resumen->fechaEstimada, $enfocar);
        return ['resumen' => $resumen, 'registros' => $registros, 'hojas' => $hojas];
    }

    /** Expediente anterior y siguiente en el orden del tablero (para navegar). */
    public function vecinos(int $alumnoId): array
    {
        $lista = $this->expedientes->vigentes();
        $i = array_search($alumnoId, array_map('intval', array_column($lista, 'alumno_id')), true);
        if ($i === false) {
            return ['anterior' => null, 'siguiente' => null];
        }
        return ['anterior' => $lista[$i - 1] ?? null, 'siguiente' => $lista[$i + 1] ?? null];
    }
}
