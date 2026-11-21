<?php
declare(strict_types=1);

namespace ServicioSocial\Dominio;

use DateTimeImmutable;

/**
 * Única fuente de los números de un expediente: horas, faltas, fecha de
 * los meses requeridos, proyección y situación. No toca la base de datos:
 * recibe los datos y la fecha de hoy, así que se puede probar sola.
 */
final class Calculadora
{
    private const MAX_DIAS_PROYECCION = 1100;

    /**
     * @param array $expediente  fila de expedientes (fecha_inicio, fecha_fin_autorizada, horas_requeridas,
     *                           meses_requeridos, jornada_prevista, feriados_acreditan, horas_por_feriado, estado)
     * @param array $registros   filas de asistencias: [fecha, estado, horas_trabajadas, horas_acreditadas]
     * @param array $inhabiles   ['Y-m-d' => 'festivo'|'cierre'] del catálogo
     * @param array $minimos     ['horas' => 480, 'meses' => 6] generales
     */
    public static function calcular(
        array $expediente,
        array $registros,
        array $inhabiles,
        DateTimeImmutable $hoy,
        array $minimos = ['horas' => 480, 'meses' => 6],
        int $umbralProximo = 40,
    ): Resumen {
        $hoy = $hoy->setTime(0, 0);
        $inicio = new DateTimeImmutable((string)$expediente['fecha_inicio']);
        $finAutorizada = new DateTimeImmutable((string)$expediente['fecha_fin_autorizada']);
        $requeridas = max((int)$expediente['horas_requeridas'], (int)($minimos['horas'] ?? 480));
        $meses = max((int)$expediente['meses_requeridos'], (int)($minimos['meses'] ?? 6));
        $jornada = (int)$expediente['jornada_prevista'];
        $acreditaFeriado = (int)$expediente['feriados_acreditan'] === 1;
        $horasFeriado = (int)$expediente['horas_por_feriado'];
        $estado = (string)$expediente['estado'];

        // --- Totales y fecha en que se alcanzaron las horas -----------------
        usort($registros, fn($a, $b) => strcmp((string)$a['fecha'], (string)$b['fecha']));
        $acreditadas = $trabajadas = $faltas = 0;
        $fechaHorasCumplidas = null;
        $conRegistro = [];
        foreach ($registros as $r) {
            $conRegistro[(string)$r['fecha']] = true;
            $acreditadas += (int)$r['horas_acreditadas'];
            $trabajadas += (int)$r['horas_trabajadas'];
            if ($r['estado'] === 'falta') {
                $faltas++;
            }
            if ($fechaHorasCumplidas === null && $acreditadas >= $requeridas) {
                $fechaHorasCumplidas = new DateTimeImmutable((string)$r['fecha']);
            }
        }
        $restantes = max(0, $requeridas - $acreditadas);
        $avance = min(100.0, $requeridas > 0 ? $acreditadas * 100 / $requeridas : 0.0);

        // --- Meses requeridos ------------------------------------------------
        $fechaPeriodo = Periodo::fechaCumplimiento($inicio, $meses);
        $periodoCumplido = $hoy >= $fechaPeriodo;

        // --- Feriados del catálogo ya pasados y sin capturar ----------------
        $porConfirmar = [];
        $limite = min($hoy->modify('-1 day'), $finAutorizada);
        foreach ($inhabiles as $fecha => $tipo) {
            $d = new DateTimeImmutable($fecha);
            if ($tipo === 'festivo' && $d >= $inicio && $d <= $limite && (int)$d->format('N') <= 5 && !isset($conRegistro[$fecha])) {
                $porConfirmar[] = $fecha;
            }
        }
        sort($porConfirmar);

        // --- Proyección ------------------------------------------------------
        $fechaEstimada = $fechaHorasCumplidas;
        $diasRestantes = 0;
        $proyectados = [];
        $excedente = 0;
        if ($restantes > 0 && !in_array($estado, ['suspendido', 'concluido', 'baja'], true)) {
            $fechaEstimada = null;
            $acumulado = 0;
            $d = $hoy > $inicio ? $hoy : $inicio;
            for ($i = 0; $i < self::MAX_DIAS_PROYECCION; $i++, $d = $d->modify('+1 day')) {
                $clave = $d->format('Y-m-d');
                if ((int)$d->format('N') > 5 || isset($conRegistro[$clave])) {
                    continue;
                }
                $tipo = $inhabiles[$clave] ?? null;
                if ($tipo === 'cierre') {
                    continue;
                }
                if ($tipo === 'festivo') {
                    if ($acreditaFeriado && $horasFeriado > 0) {
                        $acumulado += $horasFeriado;
                        $proyectados[] = $clave;
                    }
                } else {
                    $acumulado += $jornada;
                    $diasRestantes++;
                    $proyectados[] = $clave;
                }
                if ($acumulado >= $restantes) {
                    $fechaEstimada = $d;
                    $excedente = $acumulado - $restantes;
                    break;
                }
            }
        }

        // --- Situación (gana la primera que se cumple) ----------------------
        $situacion = match (true) {
            in_array($estado, ['concluido', 'baja', 'suspendido'], true) => $estado,
            $restantes === 0 && $periodoCumplido                        => Resumen::LISTO,
            $restantes === 0                                             => Resumen::HORAS_CUMPLIDAS,
            $periodoCumplido                                             => Resumen::PERIODO_VENCIDO,
            $hoy < $inicio                                               => Resumen::POR_INICIAR,
            $restantes <= $umbralProximo                                 => Resumen::PROXIMO,
            default                                                      => Resumen::EN_CURSO,
        };

        $motivo = null;
        if ($estado !== 'activo' && $estado !== 'suspendido') {
            $motivo = 'El expediente no está abierto.';
        } elseif ($restantes > 0) {
            $motivo = "Faltan $restantes h para las $requeridas h requeridas.";
        } elseif (!$periodoCumplido) {
            $motivo = 'El periodo de ' . $meses . ' meses se cumple el ' . $fechaPeriodo->format('d/m/Y') . '.';
        }

        return new Resumen(
            horasRequeridas: $requeridas,
            mesesRequeridos: $meses,
            acreditadas: $acreditadas,
            trabajadas: $trabajadas,
            restantes: $restantes,
            avance: round($avance, 1),
            faltas: $faltas,
            fechaPeriodo: $fechaPeriodo,
            periodoCumplido: $periodoCumplido,
            fechaHorasCumplidas: $fechaHorasCumplidas,
            fechaEstimada: $fechaEstimada,
            diasHabilesRestantes: $diasRestantes,
            excedente: $excedente,
            rebasaAutorizada: $fechaEstimada !== null && $restantes > 0 && $fechaEstimada > $finAutorizada,
            feriadosPorConfirmar: $porConfirmar,
            diasProyectados: $proyectados,
            situacion: $situacion,
            puedeConcluir: $motivo === null,
            motivoNoConcluir: $motivo,
        );
    }
}
