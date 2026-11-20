<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use DateTimeImmutable;
use PDOException;
use ServicioSocial\Dominio\Periodo;
use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Repositorios\AlumnoRepositorio;
use ServicioSocial\Repositorios\ConfiguracionRepositorio;
use ServicioSocial\Repositorios\ExpedienteRepositorio;
use ServicioSocial\Validacion\Validador;

/**
 * Alta y edición de alumnos. Al dar de alta un alumno se abre su primer
 * expediente (su bitácora) en la misma transacción.
 */
final class AlumnoServicio
{
    private const PATRON_MATRICULA = '/^[A-Za-z0-9-]{4,20}$/';
    private const PATRON_NOMBRE = "/^[\\p{L}][\\p{L} .'-]*$/u";

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly AlumnoRepositorio $alumnos,
        private readonly ExpedienteRepositorio $expedientes,
        private readonly ConfiguracionRepositorio $config,
    ) {
    }

    /** Valores iniciales del formulario de alta. */
    public function valoresIniciales(string $responsable): array
    {
        return [
            'institucion'        => 'Universidad Tecnológica de Nezahualcóyotl',
            'responsable'        => $responsable,
            'jornada_prevista'   => $this->config->entero('jornada_prevista', 4),
            'feriados_acreditan' => $this->config->entero('feriados_acreditan', 1) === 1 ? 1 : 0,
            'horas_por_feriado'  => $this->config->entero('horas_por_feriado', 4),
        ];
    }

    /**
     * @return array{ok: bool, errores?: array, datos?: array, alumno_id?: int, expediente_id?: int}
     */
    public function registrar(array $entrada, int $usuarioId): array
    {
        $v = $this->validarAlumno($entrada);
        $v->texto('unidad_administrativa', 'la unidad administrativa', 150)
          ->texto('responsable', 'el nombre del responsable', 150)
          ->hora('hora_entrada', 'la hora de entrada')
          ->hora('hora_salida', 'la hora de salida')
          ->fecha('fecha_inicio', 'la fecha de inicio')
          ->fecha('fecha_fin_autorizada', 'la fecha de término autorizada', false)
          ->opcionEntera('jornada_prevista', 'la jornada prevista', $this->jornadasPermitidas(), $this->config->entero('jornada_prevista', 4))
          ->casilla('feriados_acreditan')
          ->opcionEntera('horas_por_feriado', 'las horas por feriado', [0, 1, 2, 3, 4, 5, 6], $this->config->entero('horas_por_feriado', 4))
          ->texto('observaciones', 'las observaciones', 1000, false);

        $d = $v->limpios();
        if (($d['hora_entrada'] === null) !== ($d['hora_salida'] === null)) {
            $v->agregarError('hora_salida', 'Escribe la hora de entrada y la de salida, o deja las dos vacías.');
        } elseif ($d['hora_entrada'] !== null && $d['hora_salida'] <= $d['hora_entrada']) {
            $v->agregarError('hora_salida', 'La hora de salida debe ser después de la de entrada.');
        }

        $meses = $this->config->entero('meses_requeridos', 6);
        if ($d['fecha_inicio'] !== null) {
            $d['fecha_fin_autorizada'] ??= Periodo::fechaCumplimiento(new DateTimeImmutable($d['fecha_inicio']), $meses)->format('Y-m-d');
            if ($d['fecha_fin_autorizada'] <= $d['fecha_inicio']) {
                $v->agregarError('fecha_fin_autorizada', 'La fecha de término debe ser posterior a la fecha de inicio.');
            }
        }
        if ($d['matricula'] !== null && $this->alumnos->idPorMatricula($d['matricula']) !== null) {
            $v->agregarError('matricula', 'Ya existe un alumno con esa matrícula.');
        }
        if (!$v->valido()) {
            return ['ok' => false, 'errores' => $v->errores(), 'datos' => $d];
        }

        try {
            [$alumnoId, $expedienteId] = $this->bd->transaccion(function () use ($d, $meses, $usuarioId) {
                $alumnoId = $this->alumnos->crear($d);
                $expedienteId = $this->expedientes->crear($d + [
                    'alumno_id'        => $alumnoId,
                    'horas_requeridas' => $this->config->entero('horas_requeridas', 480),
                    'meses_requeridos' => $meses,
                    'creado_por'       => $usuarioId,
                ]);
                return [$alumnoId, $expedienteId];
            }, 'Alta de alumno y apertura de su expediente');
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_alumnos_matricula')) {
                return ['ok' => false, 'errores' => ['matricula' => 'Ya existe un alumno con esa matrícula.'], 'datos' => $d];
            }
            throw $e;
        }
        return ['ok' => true, 'alumno_id' => $alumnoId, 'expediente_id' => $expedienteId];
    }

    /**
     * @return array{ok: bool, errores?: array, datos?: array}
     */
    public function actualizar(int $id, array $entrada): array
    {
        $v = $this->validarAlumno($entrada)->texto('motivo', 'el motivo', 255, false);
        $d = $v->limpios();
        $otro = $d['matricula'] !== null ? $this->alumnos->idPorMatricula($d['matricula']) : null;
        if ($otro !== null && $otro !== $id) {
            $v->agregarError('matricula', 'Ya existe otro alumno con esa matrícula.');
        }
        if (!$v->valido()) {
            return ['ok' => false, 'errores' => $v->errores(), 'datos' => $d];
        }
        try {
            $this->bd->transaccion(fn() => $this->alumnos->actualizar($id, $d), $d['motivo'] ?? 'Corrección de datos del alumno');
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_alumnos_matricula')) {
                return ['ok' => false, 'errores' => ['matricula' => 'Ya existe otro alumno con esa matrícula.'], 'datos' => $d];
            }
            throw $e;
        }
        return ['ok' => true];
    }

    private function validarAlumno(array $entrada): Validador
    {
        $entrada['matricula'] = strtoupper(trim((string)($entrada['matricula'] ?? '')));
        return (new Validador($entrada))
            ->texto('matricula', 'la matrícula', 20, true, self::PATRON_MATRICULA, 'La matrícula solo lleva letras, números o guiones (4 a 20 caracteres).')
            ->texto('nombre', 'el nombre', 80, true, self::PATRON_NOMBRE, 'El nombre solo lleva letras, espacios, puntos o guiones.')
            ->texto('apellido_paterno', 'el apellido paterno', 80, true, self::PATRON_NOMBRE, 'El apellido solo lleva letras, espacios, puntos o guiones.')
            ->texto('apellido_materno', 'el apellido materno', 80, false, self::PATRON_NOMBRE, 'El apellido solo lleva letras, espacios, puntos o guiones.')
            ->texto('carrera', 'la carrera', 150)
            ->texto('institucion', 'la institución', 150)
            ->correo('correo')
            ->telefono('telefono');
    }

    private function jornadasPermitidas(): array
    {
        $lista = array_map('intval', explode(',', $this->config->texto('jornadas_permitidas', '4,5,6')));
        return array_values(array_filter($lista, fn($h) => $h >= 4 && $h <= 6)) ?: [4, 5, 6];
    }
}
