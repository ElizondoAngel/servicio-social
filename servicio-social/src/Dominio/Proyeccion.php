<?php
declare(strict_types=1);

namespace ServicioSocial\Dominio;

use DateTimeImmutable;

/**
 * Lecturas adicionales de un expediente a partir de la Calculadora:
 *  - conclusión probable: el día en que se cumplen las DOS condiciones
 *    (horas y meses), que es cuando se puede concluir;
 *  - escenarios: qué pasaría con cada jornada permitida (4, 5 o 6 h);
 *  - ritmo real: horas acreditadas por semana en las últimas semanas
 *    completas y a qué fecha llevaría ese ritmo;
 *  - avance por mes natural, para cotejar con la bitácora en papel.
 * No toca la base de datos ni cambia ninguna regla.
 */
final class Proyeccion
{
    public const SEMANAS_RITMO = 4;

    /**
     * Fecha en que se podrá concluir: la más tardía entre la de las horas
     * (cumplidas o estimadas) y la del periodo. Null si no se puede estimar.
     * @return array{fecha: ?DateTimeImmutable, limitante: string} limitante: horas|periodo|ambos|ninguno
     */
    public static function conclusionProbable(Resumen $r): array
    {
        $horas = $r->restantes === 0 ? $r->fechaHorasCumplidas : $r->fechaEstimada;
        if ($horas === null) {
            return ['fecha' => null, 'limitante' => 'ninguno'];
        }
        return match ($horas <=> $r->fechaPeriodo) {
            1  => ['fecha' => $horas, 'limitante' => 'horas'],
            -1 => ['fecha' => $r->fechaPeriodo, 'limitante' => 'periodo'],
            default => ['fecha' => $horas, 'limitante' => 'ambos'],
        };
    }

    /**
     * Un resultado por jornada: fecha en que se completarían las horas,
     * días hábiles necesarios, si cabe en el término autorizado y la
     * conclusión probable.
     */
    public static function escenarios(array $expediente, array $registros, array $inhabiles, DateTimeImmutable $hoy,
                                      array $minimos, int $umbral, array $jornadas): array
    {
        $salida = [];
        foreach ($jornadas as $j) {
            $r = Calculadora::calcular(['jornada_prevista' => $j] + $expediente, $registros, $inhabiles, $hoy, $minimos, $umbral);
            $salida[] = [
                'jornada'            => (int)$j,
                'actual'             => (int)$j === (int)$expediente['jornada_prevista'],
                'fecha_horas'        => $r->fechaEstimada,
                'dias'               => $r->diasHabilesRestantes,
                'dentro_autorizada'  => !$r->rebasaAutorizada,
                'excedente'          => $r->excedente,
                'conclusion'         => self::conclusionProbable($r)['fecha'],
            ];
        }
        return $salida;
    }

    /**
     * Horas acreditadas por semana en las últimas semanas completas
     * (sin contar la semana en curso) y fecha aproximada a ese ritmo.
     * @return array{semanas: int, horas: int, por_semana: float, fecha: ?DateTimeImmutable}
     */
    public static function ritmo(array $registros, DateTimeImmutable $hoy, DateTimeImmutable $inicio, int $restantes,
                                 int $semanas = self::SEMANAS_RITMO): array
    {
        $lunesHoy = Calendario::lunes($hoy);
        $desde = $lunesHoy->modify("-$semanas week");
        // Si el servicio empezó hace menos, se cuentan solo las semanas completas transcurridas.
        $lunesInicio = Calendario::lunes($inicio);
        if ($lunesInicio >= $lunesHoy) {
            $semanas = 0; // empieza esta semana o después: no hay semanas completas
        } elseif ($lunesInicio > $desde) {
            $semanas = intdiv((int)$lunesInicio->diff($lunesHoy)->days, 7);
            $desde = $lunesInicio;
        }
        if ($semanas === 0) {
            return ['semanas' => 0, 'horas' => 0, 'por_semana' => 0.0, 'fecha' => null];
        }
        $horas = 0;
        foreach ($registros as $r) {
            $f = new DateTimeImmutable((string)$r['fecha']);
            if ($f >= $desde && $f < $lunesHoy) {
                $horas += (int)$r['horas_acreditadas'];
            }
        }
        $porSemana = $horas / $semanas;
        $fecha = null;
        if ($restantes > 0 && $porSemana > 0) {
            $fecha = $hoy->modify('+' . (int)ceil($restantes / $porSemana * 7) . ' day');
        }
        return ['semanas' => $semanas, 'horas' => $horas, 'por_semana' => round($porSemana, 1), 'fecha' => $fecha];
    }

    /**
     * Horas acreditadas, asistencias, faltas y feriados por mes natural.
     * @return array<string, array{asistencias: int, faltas: int, feriados: int, horas: int}> 'Y-m' => totales
     */
    public static function porMes(array $registros): array
    {
        $meses = [];
        foreach ($registros as $r) {
            $clave = substr((string)$r['fecha'], 0, 7);
            $meses[$clave] ??= ['asistencias' => 0, 'faltas' => 0, 'feriados' => 0, 'horas' => 0];
            $meses[$clave]['horas'] += (int)$r['horas_acreditadas'];
            match ($r['estado']) {
                'asistencia' => $meses[$clave]['asistencias']++,
                'falta'      => $meses[$clave]['faltas']++,
                'feriado'    => $meses[$clave]['feriados']++,
                default      => null,
            };
        }
        ksort($meses);
        return $meses;
    }
}
