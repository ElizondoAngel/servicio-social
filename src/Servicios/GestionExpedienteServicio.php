<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use DateTimeImmutable;
use PDOException;
use ServicioSocial\Dominio\Resumen;
use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Repositorios\AsistenciaRepositorio;
use ServicioSocial\Repositorios\ConfiguracionRepositorio;
use ServicioSocial\Repositorios\ExpedienteRepositorio;
use ServicioSocial\Validacion\Validador;

/**
 * Cambios al expediente como un todo: sus datos y ajustes, su estado
 * (suspender, reactivar, baja, concluir, reabrir) y abrir un nuevo periodo.
 * Todo cambio lleva motivo y queda en el historial (triggers).
 */
final class GestionExpedienteServicio
{
    /** acción => [estados de origen, estado destino, motivo obligatorio, texto] */
    public const TRANSICIONES = [
        'suspender' => [['activo'], 'suspendido', true, 'Expediente suspendido.'],
        'reactivar' => [['suspendido'], 'activo', true, 'Expediente reactivado.'],
        'baja'      => [['activo', 'suspendido'], 'baja', true, 'Expediente dado de baja.'],
        'concluir'  => [['activo', 'suspendido'], 'concluido', false, 'Expediente concluido.'],
        'reabrir'   => [['concluido', 'baja'], 'activo', true, 'Expediente reabierto.'],
    ];

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly ExpedienteRepositorio $expedientes,
        private readonly AsistenciaRepositorio $asistencias,
        private readonly ConfiguracionRepositorio $config,
        private readonly ReglasExpediente $reglas,
    ) {
    }

    /**
     * @return array{ok: bool, estado?: int, errores?: array, general?: string, datos?: array, fuera?: array, aviso?: string}
     */
    public function actualizarDatos(array $exp, array $entrada, int $usuarioId): array
    {
        if (!in_array($exp['estado'], ['activo', 'suspendido'], true)) {
            return ['ok' => false, 'estado' => 422, 'errores' => [], 'general' => 'El expediente está cerrado; reábrelo para cambiar sus datos.', 'datos' => $entrada];
        }
        $v = (new Validador($entrada))->texto('motivo', 'el motivo del cambio', 255)->casilla('autorizar_fuera')->casilla('aplicar_feriados');
        $d = $this->reglas->validar($v);
        $version = is_string($entrada['version'] ?? null) && ctype_digit($entrada['version']) ? (int)$entrada['version'] : -1;
        if ($version !== (int)$exp['version']) {
            return ['ok' => false, 'estado' => 409, 'errores' => [], 'datos' => $d,
                'general' => 'El expediente cambió mientras lo editabas. Revisa los datos actuales y vuelve a intentarlo.'];
        }
        if (!$v->valido()) {
            return ['ok' => false, 'estado' => 422, 'errores' => $v->errores(), 'datos' => $d];
        }

        $sinCambios = true;
        foreach (ExpedienteRepositorio::CAMPOS_EDITABLES as $campo) {
            $antes = $exp[$campo];
            if (in_array($campo, ['hora_entrada', 'hora_salida'], true) && $antes !== null) {
                $antes = substr((string)$antes, 0, 5);
            }
            if ((string)$antes !== (string)$d[$campo]) {
                $sinCambios = false;
            }
        }
        if ($sinCambios) {
            return ['ok' => true, 'aviso' => 'Sin cambios.'];
        }

        // Registros que quedarían fuera de las nuevas fechas sin autorización.
        $fuera = $this->asistencias->fueraDeVentana((int)$exp['id'], $d['fecha_inicio'], $d['fecha_fin_autorizada']);
        if ($fuera !== [] && $d['autorizar_fuera'] !== 1) {
            $v->agregarError('autorizar_fuera', 'Con estas fechas, ' . count($fuera) . ' registro(s) quedarían fuera del periodo autorizado: '
                . implode(', ', array_map(fn($r) => fecha_corta($r['fecha']), array_slice($fuera, 0, 8)))
                . (count($fuera) > 8 ? '…' : '') . '. Marca la casilla para autorizarlos con el motivo de este cambio.');
            return ['ok' => false, 'estado' => 422, 'errores' => $v->errores(), 'datos' => $d, 'fuera' => $fuera];
        }

        $reglaFeriadoCambia = (int)$d['feriados_acreditan'] !== (int)$exp['feriados_acreditan']
            || (int)$d['horas_por_feriado'] !== (int)$exp['horas_por_feriado'];
        try {
            $this->bd->transaccion(function () use ($exp, $version, $d, $fuera, $usuarioId, $reglaFeriadoCambia) {
                if (!$this->expedientes->actualizarDatos((int)$exp['id'], $version, $d)) {
                    throw new ConflictoVersion();
                }
                foreach ($fuera as $r) {
                    $this->asistencias->autorizar((int)$r['id'], $usuarioId, mb_substr('Cambio de fechas: ' . $d['motivo'], 0, 255));
                }
                // Pendiente de autorización: por omisión el cambio de regla NO recalcula feriados ya registrados.
                if ($reglaFeriadoCambia && $d['aplicar_feriados'] === 1) {
                    $credito = (int)$d['feriados_acreditan'] === 1 ? (int)$d['horas_por_feriado'] : 0;
                    foreach ($this->asistencias->feriadosDeCatalogo((int)$exp['id']) as $r) {
                        if ((int)$r['horas_acreditadas'] !== $credito) {
                            $this->asistencias->fijarAcreditadas((int)$r['id'], $credito, $usuarioId);
                        }
                    }
                }
            }, $d['motivo']);
        } catch (ConflictoVersion) {
            return ['ok' => false, 'estado' => 409, 'errores' => [], 'datos' => $d,
                'general' => 'El expediente cambió mientras lo editabas. Revisa los datos actuales y vuelve a intentarlo.'];
        } catch (PDOException $e) {
            $mensaje = ErroresBd::mensaje($e) ?? throw $e;
            return ['ok' => false, 'estado' => 422, 'errores' => [], 'general' => $mensaje, 'datos' => $d];
        }
        return ['ok' => true, 'aviso' => 'Datos del expediente actualizados.'];
    }

    /**
     * @return array{ok: bool, estado?: int, general?: string, aviso?: string}
     */
    public function cambiarEstado(array $exp, array $entrada, Resumen $resumen, DateTimeImmutable $hoy): array
    {
        $accion = is_string($entrada['accion'] ?? null) ? $entrada['accion'] : '';
        if (!isset(self::TRANSICIONES[$accion])) {
            return $this->falla(422, 'Acción no válida.');
        }
        [$origenes, $destino, $motivoObligatorio, $aviso] = self::TRANSICIONES[$accion];
        $v = (new Validador($entrada))->texto('motivo', 'el motivo', 255, $motivoObligatorio);
        $motivo = $v->valor('motivo');
        $version = is_string($entrada['version'] ?? null) && ctype_digit($entrada['version']) ? (int)$entrada['version'] : -1;

        if ($version !== (int)$exp['version']) {
            return $this->falla(409, 'El expediente cambió mientras lo revisabas. Revisa cómo quedó y vuelve a intentarlo.');
        }
        if (!in_array($exp['estado'], $origenes, true)) {
            return $this->falla(422, 'Esa acción no aplica a un expediente ' . $exp['estado'] . '.');
        }
        if (!$v->valido()) {
            return $this->falla(422, 'Escribe el motivo para ' . ($accion === 'baja' ? 'dar de baja' : $accion) . ' el expediente.');
        }
        if ($accion === 'reabrir' || $accion === 'reactivar') {
            $abierto = $this->expedientes->abiertoDeAlumno((int)$exp['alumno_id']);
            if ($abierto !== null && (int)$abierto['id'] !== (int)$exp['id']) {
                return $this->falla(422, 'El alumno ya tiene otro expediente abierto; concluye o da de baja ese primero.');
            }
        }

        $fechaConclusion = null;
        $resumenJson = null;
        if ($accion === 'concluir') {
            if (!$resumen->puedeConcluir) {
                return $this->falla(422, 'Todavía no se puede concluir: ' . $resumen->motivoNoConcluir);
            }
            $fechaConclusion = $hoy->format('Y-m-d');
            $resumenJson = [
                'horas_requeridas'      => $resumen->horasRequeridas,
                'meses_requeridos'      => $resumen->mesesRequeridos,
                'horas_acreditadas'     => $resumen->acreditadas,
                'horas_trabajadas'      => $resumen->trabajadas,
                'faltas'                => $resumen->faltas,
                'fecha_horas_cumplidas' => $resumen->fechaHorasCumplidas?->format('Y-m-d'),
                'fecha_periodo'         => $resumen->fechaPeriodo->format('Y-m-d'),
                'calculado_en'          => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];
        }

        try {
            $this->bd->transaccion(function () use ($exp, $version, $destino, $fechaConclusion, $resumenJson) {
                if (!$this->expedientes->cambiarEstado((int)$exp['id'], $version, $destino, $fechaConclusion, $resumenJson)) {
                    throw new ConflictoVersion();
                }
            }, $motivo ?? ($accion === 'concluir' ? 'Conclusión del servicio social' : null));
        } catch (ConflictoVersion) {
            return $this->falla(409, 'El expediente cambió mientras lo revisabas. Revisa cómo quedó y vuelve a intentarlo.');
        } catch (PDOException $e) {
            return $this->falla(422, ErroresBd::mensaje($e) ?? throw $e);
        }
        return ['ok' => true, 'aviso' => $aviso];
    }

    /** Valores para abrir un nuevo periodo: los del último expediente, con fechas en blanco. */
    public function valoresNuevoPeriodo(?array $ultimo, string $responsable): array
    {
        $base = [
            'responsable'        => $responsable,
            'jornada_prevista'   => $this->config->entero('jornada_prevista', 4),
            'feriados_acreditan' => $this->config->entero('feriados_acreditan', 1) === 1 ? 1 : 0,
            'horas_por_feriado'  => $this->config->entero('horas_por_feriado', 4),
        ];
        if ($ultimo === null) {
            return $base;
        }
        return [
            'unidad_administrativa' => $ultimo['unidad_administrativa'],
            'responsable'           => $ultimo['responsable'],
            'hora_entrada'          => $ultimo['hora_entrada'] ? substr($ultimo['hora_entrada'], 0, 5) : null,
            'hora_salida'           => $ultimo['hora_salida'] ? substr($ultimo['hora_salida'], 0, 5) : null,
        ] + $base;
    }

    /**
     * @return array{ok: bool, errores?: array, general?: string, datos?: array, expediente_id?: int}
     */
    public function abrirPeriodo(int $alumnoId, array $entrada, int $usuarioId): array
    {
        $v = new Validador($entrada);
        $d = $this->reglas->validar($v);
        if ($this->expedientes->abiertoDeAlumno($alumnoId) !== null) {
            return ['ok' => false, 'errores' => [], 'datos' => $d, 'general' => 'El alumno ya tiene un expediente abierto; concluye o da de baja ese primero.'];
        }
        if (!$v->valido()) {
            return ['ok' => false, 'errores' => $v->errores(), 'datos' => $d];
        }
        try {
            $id = $this->bd->transaccion(fn() => $this->expedientes->crear($d + [
                'alumno_id'        => $alumnoId,
                'horas_requeridas' => $this->config->entero('horas_requeridas', 480),
                'meses_requeridos' => $this->config->entero('meses_requeridos', 6),
                'creado_por'       => $usuarioId,
            ]), 'Apertura de un nuevo periodo');
        } catch (PDOException $e) {
            return ['ok' => false, 'errores' => [], 'datos' => $d, 'general' => ErroresBd::mensaje($e) ?? throw $e];
        }
        return ['ok' => true, 'expediente_id' => $id];
    }

    private function falla(int $estado, string $mensaje): array
    {
        return ['ok' => false, 'estado' => $estado, 'general' => $mensaje];
    }
}
