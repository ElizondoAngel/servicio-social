<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use DateTimeImmutable;
use PDOException;
use ServicioSocial\Dominio\Calendario;
use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Repositorios\AsistenciaRepositorio;
use ServicioSocial\Repositorios\DiaInhabilRepositorio;
use ServicioSocial\Repositorios\FirmaRepositorio;
use ServicioSocial\Validacion\Validador;

/**
 * Captura de un día de la bitácora: asistencia, falta, feriado o
 * pendiente (sin registro). Reglas:
 *  - un registro por día; corregir uno existente exige motivo;
 *  - si alguien más cambió el día mientras se editaba, no se pisa (409);
 *  - no se capturan días que no han llegado;
 *  - fuera del periodo autorizado, en fin de semana o en un día de cierre
 *    hace falta una autorización explícita con motivo;
 *  - acreditar horas distintas a las trabajadas exige justificación.
 */
final class AsistenciaServicio
{
    public const ESTADOS = ['asistencia' => 'Asistencia', 'falta' => 'Falta', 'feriado' => 'Feriado', 'pendiente' => 'Pendiente'];
    private const COMPARAR = ['estado', 'horas_trabajadas', 'horas_acreditadas', 'dia_inhabil_id', 'justificacion',
        'hora_entrada', 'hora_salida', 'observaciones', 'autorizado_por', 'motivo_autorizacion'];

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly AsistenciaRepositorio $asistencias,
        private readonly DiaInhabilRepositorio $dias,
        private readonly FirmaRepositorio $firmas,
        private readonly ReglasExpediente $reglas,
    ) {
    }

    /** Qué se necesita saber del día para mostrar su formulario. */
    public function contextoDia(array $exp, string $fecha, DateTimeImmutable $hoy): array
    {
        $d = new DateTimeImmutable($fecha);
        $inhabil = $this->dias->porFecha($fecha);
        return [
            'registro'     => $this->asistencias->porFecha((int)$exp['id'], $fecha),
            'inhabil'      => $inhabil,
            'futuro'       => $d > $hoy->setTime(0, 0),
            'requiere'     => $this->razonesAutorizacion($exp, $d, $inhabil),
            'credito'      => $this->creditoFeriado($exp),
            'jornadas'     => $this->reglas->jornadasPermitidas(),
        ];
    }

    /**
     * @return array{ok: bool, estado?: int, errores?: array, general?: string, aviso?: string}
     */
    public function guardar(array $exp, array $entrada, int $usuarioId, DateTimeImmutable $hoy): array
    {
        if ($exp['estado'] !== 'activo') {
            return $this->falla(422, [], $exp['estado'] === 'suspendido'
                ? 'El expediente está suspendido; reactívalo para registrar días.'
                : 'El expediente está ' . ($exp['estado'] === 'baja' ? 'de baja' : 'concluido') . '; reábrelo para hacer cambios.');
        }

        $v = (new Validador($entrada))
            ->fecha('fecha', 'la fecha')
            ->hora('hora_entrada', 'la hora de entrada')
            ->hora('hora_salida', 'la hora de salida')
            ->texto('justificacion', 'la justificación', 255, false)
            ->texto('observaciones', 'las observaciones', 500, false)
            ->texto('motivo', 'el motivo', 255, false)
            ->texto('motivo_autorizacion', 'el motivo de la autorización', 255, false)
            ->casilla('autorizar');
        $estado = is_string($entrada['estado'] ?? null) ? $entrada['estado'] : '';
        if (!isset(self::ESTADOS[$estado])) {
            $v->agregarError('estado', 'Elige qué pasó ese día.');
        }
        $version = is_string($entrada['version'] ?? null) && ctype_digit($entrada['version']) ? (int)$entrada['version'] : null;
        $c = $v->limpios();
        if ($c['fecha'] === null) {
            return $this->falla(422, $v->errores());
        }

        $fecha = new DateTimeImmutable($c['fecha']);
        $actual = $this->asistencias->porFecha((int)$exp['id'], $c['fecha']);
        if (($actual !== null && $version !== (int)$actual['version']) || ($actual === null && $version !== null)) {
            return $this->conflicto();
        }
        if ($actual !== null && $c['motivo'] === null) {
            $v->agregarError('motivo', $estado === 'pendiente' ? 'Escribe por qué quitas este registro.' : 'Escribe el motivo de la corrección.');
        }

        // Dejar pendiente = quitar el registro.
        if ($estado === 'pendiente') {
            if ($actual === null) {
                return ['ok' => true, 'aviso' => 'El día ya estaba pendiente.'];
            }
            if (!$v->valido()) {
                return $this->falla(422, $v->errores());
            }
            return $this->ejecutar(fn() => $this->asistencias->quitar((int)$actual['id'], (int)$actual['version']),
                $c['motivo'], 'Registro quitado; el día queda pendiente.');
        }

        if ($fecha > $hoy->setTime(0, 0)) {
            $v->agregarError('fecha', 'No se registra un día que todavía no llega.');
        }

        // Autorización para fechas fuera de lo normal.
        $inhabil = $this->dias->porFecha($c['fecha']);
        $razones = $this->razonesAutorizacion($exp, $fecha, $inhabil);
        $autorizadoPor = $actual['autorizado_por'] ?? null;
        $motivoAut = $actual['motivo_autorizacion'] ?? null;
        $autorizacionNueva = false;
        if ($razones !== [] && $autorizadoPor === null) {
            if ($c['autorizar'] !== 1 || $c['motivo_autorizacion'] === null) {
                $v->agregarError('autorizar', 'Este día ' . implode(' y ', $razones)
                    . '. Para registrarlo marca «Autorizo este registro» y escribe el motivo.');
            } else {
                $autorizadoPor = $usuarioId;
                $motivoAut = $c['motivo_autorizacion'];
                $autorizacionNueva = true;
            }
        }

        $d = [
            'estado' => $estado, 'horas_trabajadas' => 0, 'horas_acreditadas' => 0, 'dia_inhabil_id' => null,
            'justificacion' => $c['justificacion'], 'hora_entrada' => null, 'hora_salida' => null,
            'observaciones' => $c['observaciones'], 'autorizado_por' => $autorizadoPor, 'motivo_autorizacion' => $motivoAut,
        ];

        if ($estado === 'asistencia') {
            $v->opcionEntera('horas_trabajadas', 'las horas trabajadas', $this->reglas->jornadasPermitidas(), (int)$exp['jornada_prevista']);
            $trabajadas = (int)$v->valor('horas_trabajadas');
            $acreditadas = $this->horasOpcionales($v, $entrada, 'horas_acreditadas', $trabajadas);
            $d['horas_trabajadas'] = $trabajadas;
            $d['horas_acreditadas'] = $acreditadas;
            if ($acreditadas !== $trabajadas && $c['justificacion'] === null) {
                $v->agregarError('justificacion', "Acreditas $acreditadas h de $trabajadas h trabajadas: escribe la justificación.");
            }
            if (($c['hora_entrada'] === null) !== ($c['hora_salida'] === null)) {
                $v->agregarError('hora_salida', 'Escribe la hora de entrada y la de salida, o deja las dos vacías.');
            } elseif ($c['hora_entrada'] !== null) {
                $minutos = $this->minutos($c['hora_salida']) - $this->minutos($c['hora_entrada']);
                if ($minutos !== $trabajadas * 60) {
                    $v->agregarError('hora_salida', 'De ' . hora12($c['hora_entrada']) . ' a ' . hora12($c['hora_salida'])
                        . ' no son ' . $trabajadas . ' h: el horario debe coincidir con las horas trabajadas.');
                }
                $d['hora_entrada'] = $c['hora_entrada'];
                $d['hora_salida'] = $c['hora_salida'];
            }
        } elseif ($estado === 'feriado') {
            $credito = $this->creditoFeriado($exp);
            $deCatalogo = $inhabil !== null && $inhabil['tipo'] === 'festivo';
            $acreditadas = $this->horasOpcionales($v, $entrada, 'horas_acreditadas', $deCatalogo ? $credito : 0);
            $d['horas_acreditadas'] = $acreditadas;
            $d['dia_inhabil_id'] = $deCatalogo ? (int)$inhabil['id'] : null;
            if ($c['justificacion'] === null) {
                if ($deCatalogo && $acreditadas !== $credito) {
                    $v->agregarError('justificacion', "La regla del expediente acredita $credito h por feriado; justifica las $acreditadas h.");
                } elseif (!$deCatalogo && $acreditadas > 0) {
                    $v->agregarError('justificacion', 'Este día no está en el catálogo de festivos: justifica las horas acreditadas o agrégalo al catálogo.');
                }
            }
        }

        if (!$v->valido()) {
            return $this->falla(422, $v->errores());
        }
        if ($actual !== null && $this->sinCambios($actual, $d)) {
            return ['ok' => true, 'aviso' => 'Sin cambios.'];
        }

        if ($actual === null) {
            return $this->ejecutar(fn() => $this->asistencias->crear((int)$exp['id'], $c['fecha'], $d, $usuarioId) > 0,
                $autorizacionNueva ? 'Autorización: ' . $motivoAut : null, 'Día registrado.');
        }
        return $this->ejecutar(fn() => $this->asistencias->actualizar((int)$actual['id'], (int)$actual['version'], $d, $usuarioId),
            $c['motivo'], 'Corrección guardada.');
    }

    /** Registra de una vez los festivos del catálogo que ya pasaron y no tienen registro. */
    public function confirmarFeriados(array $exp, array $fechas, int $usuarioId): array
    {
        if ($exp['estado'] !== 'activo') {
            return $this->falla(422, [], 'El expediente no está activo.');
        }
        $credito = $this->creditoFeriado($exp);
        $hechos = 0;
        try {
            $this->bd->transaccion(function () use ($exp, $fechas, $credito, $usuarioId, &$hechos) {
                foreach ($fechas as $fecha) {
                    $dia = $this->dias->porFecha($fecha);
                    if ($dia === null || $dia['tipo'] !== 'festivo' || $this->asistencias->porFecha((int)$exp['id'], $fecha) !== null) {
                        continue;
                    }
                    $this->asistencias->crear((int)$exp['id'], $fecha, [
                        'estado' => 'feriado', 'horas_trabajadas' => 0, 'horas_acreditadas' => $credito,
                        'dia_inhabil_id' => (int)$dia['id'],
                    ], $usuarioId);
                    $hechos++;
                }
            }, 'Confirmación de feriados del catálogo');
        } catch (PDOException $e) {
            return $this->falla(409, [], ErroresBd::mensaje($e) ?? throw $e);
        }
        return ['ok' => true, 'aviso' => $hechos === 1 ? 'Se registró 1 feriado.' : "Se registraron $hechos feriados."];
    }

    /** Marca o quita la firma de la semana que empieza en $lunes. */
    public function alternarFirma(array $exp, string $lunes, int $usuarioId, DateTimeImmutable $hoy): array
    {
        if (!in_array($exp['estado'], ['activo', 'suspendido'], true)) {
            return $this->falla(422, [], 'El expediente está cerrado; reábrelo para cambiar firmas.');
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $lunes);
        if ($d === false || $d->format('Y-m-d') !== $lunes || $d->format('N') !== '1') {
            return $this->falla(422, [], 'La semana no es válida.');
        }
        $primerLunes = Calendario::lunes(new DateTimeImmutable((string)$exp['fecha_inicio']));
        if ($d < $primerLunes || $d > $hoy) {
            return $this->falla(422, [], 'Solo se firman semanas del expediente que ya empezaron.');
        }
        $firmada = isset($this->firmas->deExpediente((int)$exp['id'])[$lunes]);
        return $this->ejecutar(function () use ($exp, $lunes, $usuarioId, $firmada) {
            if ($firmada) {
                return $this->firmas->quitar((int)$exp['id'], $lunes);
            }
            $this->firmas->crear((int)$exp['id'], $lunes, $usuarioId);
            return true;
        }, $firmada ? 'Firma retirada' : null, $firmada ? 'Firma quitada.' : 'Semana firmada.');
    }

    /** Por qué un día necesita autorización (vacío si no la necesita). */
    private function razonesAutorizacion(array $exp, DateTimeImmutable $fecha, ?array $inhabil): array
    {
        $razones = [];
        if ($fecha->format('Y-m-d') < $exp['fecha_inicio'] || $fecha->format('Y-m-d') > $exp['fecha_fin_autorizada']) {
            $razones[] = 'está fuera del periodo autorizado (' . fecha_corta($exp['fecha_inicio']) . ' – ' . fecha_corta($exp['fecha_fin_autorizada']) . ')';
        }
        if ((int)$fecha->format('N') > 5) {
            $razones[] = 'es fin de semana';
        }
        if ($inhabil !== null && $inhabil['tipo'] === 'cierre') {
            $razones[] = 'es día de cierre del catálogo (' . $inhabil['descripcion'] . ')';
        }
        return $razones;
    }

    private function creditoFeriado(array $exp): int
    {
        return (int)$exp['feriados_acreditan'] === 1 ? (int)$exp['horas_por_feriado'] : 0;
    }

    private function horasOpcionales(Validador $v, array $entrada, string $campo, int $porDefecto): int
    {
        $v->opcionEntera($campo, 'las horas acreditadas', [0, 1, 2, 3, 4, 5, 6], $porDefecto);
        return (int)$v->valor($campo);
    }

    private function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));
        return $h * 60 + $m;
    }

    private function sinCambios(array $actual, array $d): bool
    {
        foreach (self::COMPARAR as $campo) {
            $antes = $actual[$campo];
            if (in_array($campo, ['hora_entrada', 'hora_salida'], true) && $antes !== null) {
                $antes = substr((string)$antes, 0, 5);
            }
            if ((string)$antes !== (string)$d[$campo]) {
                return false;
            }
        }
        return true;
    }

    private function ejecutar(callable $fn, ?string $motivo, string $aviso): array
    {
        try {
            $hecho = $this->bd->transaccion(fn() => $fn() ?: throw new ConflictoVersion(), $motivo);
        } catch (ConflictoVersion) {
            return $this->conflicto();
        } catch (PDOException $e) {
            $mensaje = ErroresBd::mensaje($e);
            if ($mensaje === null) {
                throw $e;
            }
            return $this->falla(str_contains($e->getMessage(), 'uq_') ? 409 : 422, [], $mensaje);
        }
        return ['ok' => true, 'aviso' => $aviso];
    }

    private function conflicto(): array
    {
        return $this->falla(409, [], 'Este día cambió mientras lo editabas (en otra pestaña o por otra persona). Revisa cómo quedó y vuelve a intentarlo.');
    }

    private function falla(int $estado, array $errores, ?string $general = null): array
    {
        return ['ok' => false, 'estado' => $estado, 'errores' => $errores, 'general' => $general];
    }
}
