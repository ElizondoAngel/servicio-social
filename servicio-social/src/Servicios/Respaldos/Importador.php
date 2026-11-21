<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios\Respaldos;

use DateTimeImmutable;
use ServicioSocial\Repositorios\RespaldoDatosRepositorio;

/**
 * Restauración "agregar lo que falta" a partir de los datos de un respaldo:
 *  - nunca borra ni sobrescribe nada existente;
 *  - reconoce alumnos por matrícula, expedientes por matrícula + fecha de
 *    inicio, asistencias por expediente + fecha y firmas por expediente + semana;
 *  - los ids del archivo nunca se reutilizan;
 *  - los usuarios se relacionan por correo; si alguno no existe se crea
 *    desactivado y sin contraseña;
 *  - el historial y la configuración del archivo no se importan.
 * Lo que no se puede agregar se informa con su motivo.
 *
 * analizar() no escribe nada (vista previa). agregar() debe correr dentro
 * de una transacción: quien llama la abre (y la deshace si algo falla).
 */
final class Importador
{
    private const ABIERTOS = ['activo', 'suspendido'];

    public function __construct(private readonly RespaldoDatosRepositorio $repo)
    {
    }

    /**
     * Revisa que cada registro tenga lo mínimo y sea coherente con el resto del
     * archivo. La base vuelve a validar todo (CHECK y triggers) al insertar.
     * @return list<string> errores (vacío si está bien)
     */
    public static function validar(array $datos, int $max = 20): array
    {
        $err = [];
        $agregar = function (string $m) use (&$err, $max): void {
            if (count($err) < $max) {
                $err[] = $m;
            }
        };
        $fecha = fn($v) => is_string($v) && ($d = DateTimeImmutable::createFromFormat('!Y-m-d', $v)) !== false && $d->format('Y-m-d') === $v;
        $ent = fn($v, int $min = 0, int $maxv = PHP_INT_MAX) => (is_int($v) || (is_string($v) && ctype_digit($v))) && (int)$v >= $min && (int)$v <= $maxv;
        $texto = fn($v) => is_string($v) && trim($v) !== '';

        $ids = [];
        foreach (RespaldoDatosRepositorio::COLUMNAS as $tabla => $cols) {
            $ids[$tabla] = [];
            foreach ($datos[$tabla] ?? [] as $i => $fila) {
                if (!is_array($fila)) {
                    $agregar("$tabla, registro $i: no es un registro.");
                    continue;
                }
                foreach ($cols as $c) {
                    if (!array_key_exists($c, $fila)) {
                        $agregar("$tabla, registro $i: falta «{$c}».");
                        continue 2;
                    }
                }
                if ($tabla !== 'configuracion') {
                    if (!$ent($fila['id'], 1)) {
                        $agregar("$tabla, registro $i: id no válido.");
                        continue;
                    }
                    $ids[$tabla][(int)$fila['id']] = $fila;
                }
            }
        }
        $usuario = fn($v) => $v === null || isset($ids['usuarios'][(int)$v]);

        foreach ($ids['usuarios'] as $id => $u) {
            if (!$texto($u['nombre']) || !is_string($u['correo']) || filter_var($u['correo'], FILTER_VALIDATE_EMAIL) === false) {
                $agregar("Usuario $id: nombre o correo no válido.");
            }
        }
        foreach ($ids['dias_inhabiles'] as $id => $d) {
            if (!$fecha($d['fecha']) || !in_array($d['tipo'], ['festivo', 'cierre'], true) || !$texto($d['descripcion'])) {
                $agregar("Día inhábil $id: fecha, tipo o descripción no válidos.");
            }
        }
        $matriculas = [];
        foreach ($ids['alumnos'] as $id => $a) {
            if (!$texto($a['matricula']) || !$texto($a['nombre']) || !$texto($a['apellido_paterno']) || !$texto($a['carrera'])) {
                $agregar("Alumno $id: faltan matrícula, nombre, apellido o carrera.");
            } elseif (isset($matriculas[strtoupper($a['matricula'])])) {
                $agregar("Alumno $id: matrícula repetida en el archivo.");
            }
            $matriculas[strtoupper((string)$a['matricula'])] = true;
        }
        foreach ($ids['expedientes'] as $id => $e) {
            if (!isset($ids['alumnos'][(int)$e['alumno_id']])) {
                $agregar("Expediente $id: su alumno no está en el archivo.");
            }
            if (!$fecha($e['fecha_inicio']) || !$fecha($e['fecha_fin_autorizada']) || $e['fecha_fin_autorizada'] <= $e['fecha_inicio']) {
                $agregar("Expediente $id: fechas no válidas.");
            }
            if (!in_array($e['estado'], ['activo', 'suspendido', 'concluido', 'baja'], true)
                || ($e['estado'] === 'concluido') !== $fecha($e['fecha_conclusion'])) {
                $agregar("Expediente $id: estado o fecha de conclusión no válidos.");
            }
            if (!$ent($e['jornada_prevista'], 4, 6) || !$ent($e['horas_por_feriado'], 0, 6) || !$ent($e['horas_requeridas'], 1) || !$ent($e['meses_requeridos'], 1)) {
                $agregar("Expediente $id: horas o jornada fuera de rango.");
            }
            if (!$usuario($e['creado_por']) || $e['creado_por'] === null) {
                $agregar("Expediente $id: su creador no está en el archivo.");
            }
        }
        $diasExp = [];
        foreach ($ids['asistencias'] as $id => $s) {
            if (!isset($ids['expedientes'][(int)$s['expediente_id']])) {
                $agregar("Asistencia $id: su expediente no está en el archivo.");
                continue;
            }
            $llave = $s['expediente_id'] . '|' . $s['fecha'];
            if (isset($diasExp[$llave])) {
                $agregar("Asistencia $id: día repetido en el archivo.");
            }
            $diasExp[$llave] = true;
            $ok = $fecha($s['fecha']) && in_array($s['estado'], ['asistencia', 'falta', 'feriado'], true)
                && $ent($s['horas_trabajadas'], 0, 6) && $ent($s['horas_acreditadas'], 0, 6)
                && ($s['estado'] !== 'asistencia' || (int)$s['horas_trabajadas'] >= 4)
                && ($s['estado'] !== 'falta' || ((int)$s['horas_trabajadas'] === 0 && (int)$s['horas_acreditadas'] === 0))
                && ($s['estado'] !== 'feriado' || (int)$s['horas_trabajadas'] === 0);
            if (!$ok) {
                $agregar("Asistencia $id: estado u horas no válidos.");
            }
            if (!$usuario($s['registrado_por']) || $s['registrado_por'] === null || !$usuario($s['actualizado_por']) || !$usuario($s['autorizado_por'])) {
                $agregar("Asistencia $id: un usuario relacionado no está en el archivo.");
            }
            if ($s['dia_inhabil_id'] !== null && !isset($ids['dias_inhabiles'][(int)$s['dia_inhabil_id']])) {
                $agregar("Asistencia $id: su día inhábil no está en el archivo.");
            }
        }
        foreach ($ids['firmas_semana'] as $id => $f) {
            if (!isset($ids['expedientes'][(int)$f['expediente_id']]) || !$fecha($f['semana_inicio'])
                || (new DateTimeImmutable((string)$f['semana_inicio']))->format('N') !== '1' || !$usuario($f['registrado_por'])) {
                $agregar("Firma $id: expediente, semana o usuario no válidos.");
            }
        }
        return $err;
    }

    /** Vista previa: qué es nuevo, qué ya existe y qué no se podrá agregar. No escribe. */
    public function analizar(array $datos): array
    {
        return $this->recorrer($datos, false);
    }

    /** Agrega lo que falta. Debe llamarse dentro de una transacción. */
    public function agregar(array $datos): array
    {
        return $this->recorrer($datos, true);
    }

    private function recorrer(array $datos, bool $escribir): array
    {
        $r = [
            'nuevos'    => ['usuarios' => 0, 'dias_inhabiles' => 0, 'alumnos' => 0, 'expedientes' => 0, 'asistencias' => 0, 'firmas_semana' => 0],
            'existentes'=> ['usuarios' => 0, 'dias_inhabiles' => 0, 'alumnos' => 0, 'expedientes' => 0, 'asistencias' => 0, 'firmas_semana' => 0],
            'omitidos'  => [],
        ];
        $omitir = function (string $que, string $porque) use (&$r): void {
            $r['omitidos'][] = ['que' => $que, 'porque' => $porque];
        };
        $nuevoId = -1; // ids provisionales para la vista previa

        // Usuarios por correo
        $usuarios = [];
        $locales = $this->repo->usuariosPorCorreo();
        foreach ($datos['usuarios'] as $u) {
            $correo = strtolower((string)$u['correo']);
            if (isset($locales[$correo])) {
                $usuarios[(int)$u['id']] = $locales[$correo];
                $r['existentes']['usuarios']++;
            } else {
                $usuarios[(int)$u['id']] = $locales[$correo] = $escribir
                    ? $this->repo->usuarioInactivo((string)$u['nombre'], $correo, (string)$u['rol']) : $nuevoId--;
                $r['nuevos']['usuarios']++;
            }
        }
        $u = fn($id) => $id === null ? null : ($usuarios[(int)$id] ?? null);

        // Días inhábiles por fecha
        $dias = [];
        $diasLocales = $this->repo->diasPorFecha();
        foreach ($datos['dias_inhabiles'] as $d) {
            if (isset($diasLocales[$d['fecha']])) {
                $r['existentes']['dias_inhabiles']++;
            } else {
                $diasLocales[$d['fecha']] = $escribir ? $this->repo->insertar('dias_inhabiles', $d) : $nuevoId--;
                $r['nuevos']['dias_inhabiles']++;
            }
            $dias[(int)$d['id']] = $diasLocales[$d['fecha']];
        }

        // Alumnos por matrícula
        $alumnos = [];
        $matriculas = [];
        $alumnosLocales = $this->repo->alumnosPorMatricula();
        foreach ($datos['alumnos'] as $a) {
            $m = strtoupper((string)$a['matricula']);
            $matriculas[(int)$a['id']] = $m;
            if (isset($alumnosLocales[$m])) {
                $r['existentes']['alumnos']++;
            } else {
                $alumnosLocales[$m] = $escribir ? $this->repo->insertar('alumnos', ['matricula' => $m] + $a) : $nuevoId--;
                $r['nuevos']['alumnos']++;
            }
            $alumnos[(int)$a['id']] = $alumnosLocales[$m];
        }

        // Hijos de cada expediente
        $asisPorExp = [];
        foreach ($datos['asistencias'] as $s) {
            $asisPorExp[(int)$s['expediente_id']][] = $s;
        }
        $firmasPorExp = [];
        foreach ($datos['firmas_semana'] as $f) {
            $firmasPorExp[(int)$f['expediente_id']][] = $f;
        }
        $asisLocales = $this->repo->asistenciasExistentes();
        $firmasLocales = $this->repo->firmasExistentes();
        $expLocales = $this->repo->expedientesPorLlave();
        $conAbierto = $this->repo->alumnosConAbierto();

        foreach ($datos['expedientes'] as $e) {
            $alumnoLocal = $alumnos[(int)$e['alumno_id']];
            $etiqueta = 'Expediente de ' . $matriculas[(int)$e['alumno_id']] . ' desde ' . $e['fecha_inicio'];
            $llave = $alumnoLocal . '|' . $e['fecha_inicio'];
            $hijos = count($asisPorExp[(int)$e['id']] ?? []) + count($firmasPorExp[(int)$e['id']] ?? []);

            if (isset($expLocales[$llave])) {
                // Ya existe: solo se agregan días y firmas que falten, si está abierto.
                $r['existentes']['expedientes']++;
                $local = $expLocales[$llave];
                $this->agregarHijos($e, $local['id'], in_array($local['estado'], self::ABIERTOS, true), $etiqueta,
                    $asisPorExp, $firmasPorExp, $asisLocales, $firmasLocales, $u, $dias, $escribir, $r, $omitir);
                continue;
            }
            if (isset($conAbierto[$alumnoLocal])) {
                $omitir($etiqueta, 'el alumno ya tiene otro expediente abierto' . ($hijos ? " (con $hijos días y firmas)" : '')
                    . '; ciérralo primero o usa la restauración completa desde el servidor.');
                continue;
            }
            // Se crea abierto, se le agregan sus días y firmas, y después se cierra si así estaba.
            $idLocal = $escribir ? $this->repo->insertar('expedientes', [
                'alumno_id' => $alumnoLocal, 'estado' => 'activo', 'fecha_conclusion' => null, 'resumen_conclusion' => null,
                'creado_por' => $u($e['creado_por']),
            ] + $e) : $nuevoId--;
            $r['nuevos']['expedientes']++;
            $this->agregarHijos($e, $idLocal, true, $etiqueta, $asisPorExp, $firmasPorExp, $asisLocales, $firmasLocales,
                $u, $dias, $escribir, $r, $omitir);
            if ($e['estado'] !== 'activo' && $escribir) {
                $this->repo->cerrarExpediente($idLocal, (string)$e['estado'], $e['fecha_conclusion'],
                    is_string($e['resumen_conclusion']) ? $e['resumen_conclusion'] : ($e['resumen_conclusion'] === null ? null : json_encode($e['resumen_conclusion'])));
            }
            if (in_array($e['estado'], self::ABIERTOS, true)) {
                $conAbierto[$alumnoLocal] = true;
            }
            $expLocales[$llave] = ['id' => $idLocal, 'estado' => $e['estado']];
        }
        return $r;
    }

    private function agregarHijos(array $e, int $idLocal, bool $abierto, string $etiqueta, array $asisPorExp, array $firmasPorExp,
                                  array &$asisLocales, array &$firmasLocales, callable $u, array $dias, bool $escribir,
                                  array &$r, callable $omitir): void
    {
        $faltanAsis = $faltanFirmas = 0;
        foreach ($asisPorExp[(int)$e['id']] ?? [] as $s) {
            $llave = $idLocal . '|' . $s['fecha'];
            if (isset($asisLocales[$llave])) {
                $r['existentes']['asistencias']++;
                continue;
            }
            if (!$abierto) {
                $faltanAsis++;
                continue;
            }
            if ($escribir) {
                $this->repo->insertar('asistencias', [
                    'expediente_id' => $idLocal, 'registrado_por' => $u($s['registrado_por']), 'actualizado_por' => $u($s['actualizado_por']),
                    'autorizado_por' => $u($s['autorizado_por']),
                    'dia_inhabil_id' => $s['dia_inhabil_id'] === null ? null : $dias[(int)$s['dia_inhabil_id']],
                ] + $s);
            }
            $asisLocales[$llave] = true;
            $r['nuevos']['asistencias']++;
        }
        foreach ($firmasPorExp[(int)$e['id']] ?? [] as $f) {
            $llave = $idLocal . '|' . $f['semana_inicio'];
            if (isset($firmasLocales[$llave])) {
                $r['existentes']['firmas_semana']++;
                continue;
            }
            if (!$abierto) {
                $faltanFirmas++;
                continue;
            }
            if ($escribir) {
                $this->repo->insertar('firmas_semana', ['expediente_id' => $idLocal, 'registrado_por' => $u($f['registrado_por'])] + $f);
            }
            $firmasLocales[$llave] = true;
            $r['nuevos']['firmas_semana']++;
        }
        if ($faltanAsis + $faltanFirmas > 0) {
            $omitir($etiqueta, "faltan $faltanAsis día(s) y $faltanFirmas firma(s), pero el expediente está concluido o de baja; reábrelo y vuelve a restaurar.");
        }
    }
}
