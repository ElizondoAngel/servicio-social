<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use PDOException;
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
        private readonly ReglasExpediente $reglas,
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
        $d = $this->reglas->validar($v);
        $meses = $this->config->entero('meses_requeridos', 6);
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
        // Solo texto: un arreglo (matricula[]=...) no debe convertirse en "ARRAY".
        $entrada['matricula'] = is_string($entrada['matricula'] ?? null) ? strtoupper(trim($entrada['matricula'])) : '';
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
}
