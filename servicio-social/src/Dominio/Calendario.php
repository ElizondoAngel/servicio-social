<?php
declare(strict_types=1);

namespace ServicioSocial\Dominio;

use DateTimeImmutable;

/**
 * Arma la cuadrícula de la bitácora en papel: hojas de 12 semanas, cada
 * hoja con 3 "meses" de 4 semanas, cada semana de lunes a viernes. No toca
 * la base de datos.
 */
final class Calendario
{
    public const SEMANAS_POR_HOJA = 12;
    public const SEMANAS_POR_MES = 4;
    private const MAX_SEMANAS = 156;
    private const DIAS = ['L', 'M', 'M', 'J', 'V'];
    private const NOMBRES = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes'];

    /**
     * @param array $expediente  fila de expedientes
     * @param array $registros   fecha => fila de asistencias
     * @param array $inhabiles   fecha => 'festivo'|'cierre'
     * @param array $proyectados fechas que cuenta la proyección
     * @param array $firmas      lunes => true
     * @param ?DateTimeImmutable $enfocar  fecha cuya hoja debe mostrarse abierta (por omisión, la de hoy)
     */
    public static function construir(array $expediente, array $registros, array $inhabiles, array $proyectados,
                                      array $firmas, DateTimeImmutable $hoy, ?DateTimeImmutable $fechaEstimada = null,
                                      ?DateTimeImmutable $enfocar = null): array
    {
        $hoy = $hoy->setTime(0, 0);
        $inicio = new DateTimeImmutable((string)$expediente['fecha_inicio']);
        $fin = new DateTimeImmutable((string)$expediente['fecha_fin_autorizada']);
        $jornada = (int)$expediente['jornada_prevista'];
        $proyectados = array_fill_keys($proyectados, true);

        $fechas = array_keys($registros);
        sort($fechas);
        $desde = $inicio;
        if ($fechas !== [] && $fechas[0] < $desde->format('Y-m-d')) {
            $desde = new DateTimeImmutable($fechas[0]);
        }
        $hasta = max($fin, $fechaEstimada ?? $fin);
        if ($fechas !== [] && end($fechas) > $hasta->format('Y-m-d')) {
            $hasta = new DateTimeImmutable(end($fechas));
        }

        $primerLunes = self::lunes($desde);
        $semanas = intdiv((int)$primerLunes->diff($hasta)->days, 7) + 1;
        $semanas = min(self::MAX_SEMANAS, (int)ceil($semanas / self::SEMANAS_POR_HOJA) * self::SEMANAS_POR_HOJA);
        $hoyTexto = $hoy->format('Y-m-d');

        $hojas = [];
        for ($h = 0; $h * self::SEMANAS_POR_HOJA < $semanas; $h++) {
            $hoja = ['numero' => $h + 1, 'horas' => 0, 'meses' => []];
            for ($m = 0; $m < self::SEMANAS_POR_HOJA / self::SEMANAS_POR_MES; $m++) {
                $mes = ['numero' => $m + 1, 'horas' => 0, 'semanas' => []];
                for ($s = 0; $s < self::SEMANAS_POR_MES; $s++) {
                    $indice = $h * self::SEMANAS_POR_HOJA + $m * self::SEMANAS_POR_MES + $s;
                    $lunes = $primerLunes->modify("+$indice week");
                    $viernes = $lunes->modify('+4 day');
                    $semana = [
                        'numero'  => $indice + 1,
                        'lunes'   => $lunes,
                        'viernes' => $viernes,
                        'horas'   => 0,
                        'firmada' => isset($firmas[$lunes->format('Y-m-d')]),
                        'actual'  => $hoy >= $lunes && $hoy <= $viernes->modify('+2 day'),
                        'firmable'=> $lunes <= $hoy,
                        'dias'    => [],
                    ];
                    for ($d = 0; $d < 5; $d++) {
                        $fecha = $lunes->modify("+$d day");
                        $clave = $fecha->format('Y-m-d');
                        $dia = self::dia($fecha, $clave, $d, $registros[$clave] ?? null, $inhabiles[$clave] ?? null,
                            isset($proyectados[$clave]), $clave === $hoyTexto, $fecha < $inicio || $fecha > $fin, $jornada);
                        $semana['horas'] += $dia['acreditadas'];
                        $semana['dias'][] = $dia;
                    }
                    $mes['horas'] += $semana['horas'];
                    $mes['semanas'][] = $semana;
                }
                $hoja['horas'] += $mes['horas'];
                $hoja['meses'][] = $mes;
            }
            $hoja['actual'] = $hoy >= $hoja['meses'][0]['semanas'][0]['lunes']
                && $hoy <= $hoja['meses'][2]['semanas'][3]['viernes']->modify('+2 day');
            $hoja['desde'] = $hoja['meses'][0]['semanas'][0]['lunes'];
            $hoja['hasta'] = $hoja['meses'][count($hoja['meses']) - 1]['semanas'][self::SEMANAS_POR_MES - 1]['viernes'];
            $hojas[] = $hoja;
        }
        // Se abre la hoja de hoy; si hoy queda fuera, la primera (aún no empieza) o la última con horas.
        $abierta = null;
        foreach ($hojas as $i => $h) {
            if ($h['actual'] && $enfocar === null) {
                $abierta = $i;
            }
        }
        if ($enfocar !== null) {
            foreach ($hojas as $i => $h) {
                if ($enfocar >= $h['desde'] && $enfocar <= $h['hasta']->modify('+2 day')) {
                    $abierta = $i;
                }
            }
        }
        if ($abierta === null && $hojas !== []) {
            $abierta = 0;
            if ($hoy > $hojas[0]['desde']) {
                foreach ($hojas as $i => $h) {
                    if ($h['horas'] > 0) {
                        $abierta = $i;
                    }
                }
            }
        }
        foreach ($hojas as $i => &$h) {
            $h['abierta'] = $i === $abierta;
        }
        unset($h);
        return $hojas;
    }

    private static function dia(DateTimeImmutable $fecha, string $clave, int $d, ?array $registro, ?string $inhabil,
                                bool $proyectado, bool $esHoy, bool $fuera, int $jornada): array
    {
        $estado = $registro['estado'] ?? '';
        $acreditadas = (int)($registro['horas_acreditadas'] ?? 0);
        $marca = match ($estado) {
            'asistencia' => $acreditadas === $jornada ? '✓' : $acreditadas . 'h',
            'falta'      => '✗',
            'feriado'    => 'F',
            default      => $proyectado ? '·' : ($inhabil === 'cierre' ? 'C' : ''),
        };
        $texto = match ($estado) {
            'asistencia' => "asistencia, $acreditadas h acreditadas",
            'falta'      => 'falta',
            'feriado'    => "feriado, $acreditadas h acreditadas",
            default      => 'pendiente',
        };
        if ($inhabil !== null) {
            $texto .= $inhabil === 'festivo' ? ' (festivo del catálogo)' : ' (cierre del catálogo)';
        }
        if ($fuera) {
            $texto .= ' (fuera del periodo autorizado)';
        }
        $clases = array_keys(array_filter([
            'hoy'        => $esHoy,
            'proyectado' => $proyectado && $estado === '',
            'fuera'      => $fuera,
            'festivo'    => $inhabil === 'festivo',
            'cierre'     => $inhabil === 'cierre',
            'con-nota'   => $registro !== null && (($registro['observaciones'] ?? '') !== '' || ($registro['justificacion'] ?? '') !== ''),
            'autorizado' => $registro !== null && ($registro['autorizado_por'] ?? null) !== null,
        ]));
        return [
            'fecha'       => $clave,
            'letra'       => self::DIAS[$d],
            'numero'      => (int)$fecha->format('j'),
            'estado'      => $estado,
            'codigo'      => ['asistencia' => 'a', 'falta' => 'f', 'feriado' => 'h'][$estado] ?? '',
            'acreditadas' => $acreditadas,
            'marca'       => $marca,
            'clases'      => $clases,
            'etiqueta'    => self::NOMBRES[$d] . ' ' . $fecha->format('j') . ' ' . self::mes($fecha) . ': ' . $texto,
        ];
    }

    public static function lunes(DateTimeImmutable $fecha): DateTimeImmutable
    {
        return $fecha->modify('-' . ((int)$fecha->format('N') - 1) . ' day')->setTime(0, 0);
    }

    private static function mes(DateTimeImmutable $d): string
    {
        return ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][(int)$d->format('n') - 1];
    }
}
