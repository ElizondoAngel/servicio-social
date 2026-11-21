<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use PDOException;

/**
 * Traduce los rechazos de la base (triggers, CHECK, llaves únicas y
 * foráneas) a mensajes claros. La base es la última barrera: si la
 * aplicación dejó pasar algo, la base lo frena y aquí se explica.
 */
final class ErroresBd
{
    private const CODIGOS = [
        'EXPEDIENTE_CERRADO'  => 'El expediente está concluido o de baja; reábrelo para hacer cambios.',
        'HORAS_INSUFICIENTES' => 'No se puede concluir: faltan horas acreditadas.',
        'PERIODO_INCOMPLETO'  => 'No se puede concluir antes de cumplir los meses requeridos.',
        'MOTIVO_REQUERIDO'    => 'Para reabrir un expediente concluido escribe el motivo.',
        'MINIMO_HORAS'        => 'Las horas requeridas no pueden ser menores al mínimo general.',
        'MINIMO_MESES'        => 'Los meses requeridos no pueden ser menores al mínimo general.',
        'REGISTRO_INMOVIBLE'  => 'Un registro no cambia de fecha: quítalo y captúralo en la fecha correcta.',
        'FIRMA_INMUTABLE'     => 'Una firma no se edita: quítala y regístrala de nuevo.',
    ];

    private const LLAVES = [
        'uq_asistencias_expediente_fecha' => 'Ese día ya tiene un registro. Recarga la página para verlo.',
        'uq_firmas_expediente_semana'     => 'Esa semana ya estaba firmada.',
        'uq_expedientes_abierto'          => 'El alumno ya tiene otro expediente abierto; concluye o da de baja ese primero.',
        'uq_expedientes_alumno_inicio'    => 'El alumno ya tiene un expediente que inicia en esa fecha.',
        'uq_dias_inhabiles_fecha'         => 'Esa fecha ya está en el catálogo.',
        'fk_asis_dia_inhabil'             => 'Ese día del catálogo ya se usó en registros de asistencia; no se puede quitar.',
        'chk_firmas_lunes'                => 'La firma se registra por semana (lunes).',
        'chk_exp_seis_meses'              => 'No se puede concluir antes de cumplir los meses requeridos.',
        'chk_exp_fin_autorizada'          => 'La fecha de término debe ser posterior a la fecha de inicio.',
        'chk_asis_horario'                => 'El horario de entrada y salida no coincide con las horas trabajadas.',
        'chk_asis_justificacion'          => 'Escribe la justificación de las horas acreditadas.',
    ];

    public static function mensaje(PDOException $e): ?string
    {
        $texto = $e->getMessage();
        foreach (self::CODIGOS as $codigo => $mensaje) {
            if (str_contains($texto, $codigo . ':')) {
                return $mensaje;
            }
        }
        foreach (self::LLAVES as $llave => $mensaje) {
            if (str_contains($texto, $llave)) {
                return $mensaje;
            }
        }
        return null;
    }
}
