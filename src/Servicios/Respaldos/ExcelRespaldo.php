<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios\Respaldos;

use ServicioSocial\Nucleo\LibroExcel;
use ServicioSocial\Repositorios\RespaldoDatosRepositorio;

/**
 * Convierte un respaldo ya validado (firma y huella correctas) en un libro
 * de Excel para consultarlo: una hoja por tabla, con encabezados legibles.
 * Es solo para consulta: para restaurar se usa el archivo .json.gz, que
 * es el que lleva la firma.
 */
final class ExcelRespaldo
{
    /** Hoja => [tabla, columnas a mostrar]. El orden es el de las hojas. */
    private const HOJAS = [
        'Alumnos'          => 'alumnos',
        'Expedientes'      => 'expedientes',
        'Asistencias'      => 'asistencias',
        'Firmas semanales' => 'firmas_semana',
        'Días inhábiles'   => 'dias_inhabiles',
        'Usuarios'         => 'usuarios',
        'Configuración'    => 'configuracion',
        'Historial'        => 'historial',
    ];

    private const NOMBRES = [
        'id' => 'ID', 'alumno_id' => 'ID alumno', 'expediente_id' => 'ID expediente', 'dia_inhabil_id' => 'ID día inhábil',
        'usuario_id' => 'ID usuario', 'entidad_id' => 'ID registro', 'alumno' => 'Alumno',
        'matricula' => 'Matrícula', 'apellido_paterno' => 'Apellido paterno', 'apellido_materno' => 'Apellido materno',
        'institucion' => 'Institución', 'telefono' => 'Teléfono', 'creado_en' => 'Creado el', 'creado_por' => 'Creado por (ID)',
        'unidad_administrativa' => 'Unidad administrativa', 'hora_entrada' => 'Hora de entrada', 'hora_salida' => 'Hora de salida',
        'fecha_inicio' => 'Fecha de inicio', 'fecha_fin_autorizada' => 'Fin autorizado', 'horas_requeridas' => 'Horas requeridas',
        'meses_requeridos' => 'Meses requeridos', 'jornada_prevista' => 'Jornada prevista', 'feriados_acreditan' => 'Feriados acreditan',
        'horas_por_feriado' => 'Horas por feriado', 'fecha_conclusion' => 'Fecha de conclusión', 'resumen_conclusion' => 'Resumen de conclusión',
        'horas_trabajadas' => 'Horas trabajadas', 'horas_acreditadas' => 'Horas acreditadas', 'justificacion' => 'Justificación',
        'autorizado_por' => 'Autorizado por (ID)', 'motivo_autorizacion' => 'Motivo de autorización',
        'registrado_por' => 'Registrado por (ID)', 'actualizado_por' => 'Actualizado por (ID)',
        'semana_inicio' => 'Semana (lunes)', 'firmado_en' => 'Firmado el', 'descripcion' => 'Descripción',
        'usuario_bd' => 'Usuario BD', 'accion' => 'Acción', 'sellado_en' => 'Sellado el',
    ];

    private const NUMERICAS = ['id', 'alumno_id', 'expediente_id', 'dia_inhabil_id', 'usuario_id', 'entidad_id', 'creado_por',
        'autorizado_por', 'registrado_por', 'actualizado_por', 'horas_requeridas', 'meses_requeridos', 'jornada_prevista', 'horas_por_feriado',
        'horas_trabajadas', 'horas_acreditadas'];

    private const SI_NO = ['activo', 'archivado', 'feriados_acreditan'];

    /** @param array{cabecera: array, datos: array} $leido @return string bytes del .xlsx */
    public static function generar(array $leido, string $archivoOrigen): string
    {
        $cab = $leido['cabecera'];
        $datos = $leido['datos'];

        $alumnos = [];
        foreach ($datos['alumnos'] ?? [] as $a) {
            $alumnos[(int)$a['id']] = trim($a['nombre'] . ' ' . $a['apellido_paterno'] . ' ' . ($a['apellido_materno'] ?? ''));
        }
        $alumnoDeExpediente = [];
        foreach ($datos['expedientes'] ?? [] as $x) {
            $alumnoDeExpediente[(int)$x['id']] = $alumnos[(int)$x['alumno_id']] ?? '';
        }

        $libro = new LibroExcel();
        $tipos = ['manual' => 'Manual', 'automatico' => 'Automático', 'previo_restauracion' => 'Antes de restaurar'];
        $resumen = [
            ['Archivo de origen', $archivoOrigen],
            ['Creado el', (string)($cab['creado_en'] ?? '')],
            ['Tipo', $tipos[$cab['tipo'] ?? ''] ?? (string)($cab['tipo'] ?? '')],
            ['Huella SHA-256', (string)($cab['sha256'] ?? '')],
            ['Uso', 'Solo para consulta. Para restaurar se usa el archivo .json.gz, que lleva la firma.'],
        ];
        foreach (self::HOJAS as $hoja => $tabla) {
            $resumen[] = ['Registros en ' . $hoja, count($datos[$tabla] ?? [])];
        }
        $libro->agregarHoja('Resumen', ['Dato', 'Valor'], $resumen, [1]);

        foreach (self::HOJAS as $hoja => $tabla) {
            $filas = $datos[$tabla] ?? [];
            $columnas = $filas !== [] ? array_keys($filas[0]) : (RespaldoDatosRepositorio::COLUMNAS[$tabla] ?? []);
            // Nombre del alumno junto a la columna que lo identifica.
            $tras = ['expedientes' => 'alumno_id', 'asistencias' => 'expediente_id', 'firmas_semana' => 'expediente_id'][$tabla] ?? null;
            if ($tras !== null && $columnas !== []) {
                array_splice($columnas, array_search($tras, $columnas, true) + 1, 0, ['alumno']);
            }
            $salida = [];
            foreach ($filas as $f) {
                if ($tras === 'alumno_id') {
                    $f['alumno'] = $alumnos[(int)$f['alumno_id']] ?? '';
                } elseif ($tras === 'expediente_id') {
                    $f['alumno'] = $alumnoDeExpediente[(int)$f['expediente_id']] ?? '';
                }
                $salida[] = array_map(function (string $c) use ($f) {
                    $v = $f[$c] ?? null;
                    if (in_array($c, self::SI_NO, true) && $v !== null) {
                        return (int)$v === 1 ? 'Sí' : 'No';
                    }
                    return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
                }, $columnas);
            }
            $libro->agregarHoja(
                $hoja,
                array_map(fn($c) => self::NOMBRES[$c] ?? ucfirst(str_replace('_', ' ', $c)), $columnas),
                $salida,
                array_keys(array_filter($columnas, fn($c) => in_array($c, self::NUMERICAS, true))),
            );
        }
        return $libro->generar();
    }
}
