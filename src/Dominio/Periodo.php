<?php
declare(strict_types=1);

namespace ServicioSocial\Dominio;

use DateTimeImmutable;

/**
 * Reglas de fechas del periodo de servicio social.
 */
final class Periodo
{
    /**
     * Fecha en que se cumplen $meses meses naturales desde $inicio.
     *
     * Conserva el mismo día del mes; si ese día no existe en el mes de
     * destino, usa el último día de ese mes (31-ago + 6 = 28-feb).
     * Es la misma regla que DATE_ADD(fecha, INTERVAL n MONTH) de MariaDB,
     * que usa el CHECK chk_exp_seis_meses. No usar modify('+6 months'):
     * PHP desborda al mes siguiente (31-ago + 6 = 3-mar).
     */
    public static function fechaCumplimiento(DateTimeImmutable $inicio, int $meses): DateTimeImmutable
    {
        if ($meses < 0) {
            throw new \InvalidArgumentException('Los meses no pueden ser negativos.');
        }
        $anio = (int)$inicio->format('Y');
        $mes  = (int)$inicio->format('n') + $meses;
        $anio += intdiv($mes - 1, 12);
        $mes   = ($mes - 1) % 12 + 1;
        $ultimoDia = self::diasDelMes($anio, $mes);
        $dia = min((int)$inicio->format('j'), $ultimoDia);

        return $inicio->setDate($anio, $mes, $dia)->setTime(0, 0);
    }

    private static function diasDelMes(int $anio, int $mes): int
    {
        return (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))->format('t');
    }
}
