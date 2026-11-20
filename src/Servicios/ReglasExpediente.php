<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use DateTimeImmutable;
use ServicioSocial\Dominio\Periodo;
use ServicioSocial\Repositorios\ConfiguracionRepositorio;
use ServicioSocial\Validacion\Validador;

/**
 * Validación de los datos de un expediente (bitácora), compartida por el
 * alta del alumno, la apertura de un nuevo periodo y la edición.
 */
final class ReglasExpediente
{
    public function __construct(private readonly ConfiguracionRepositorio $config)
    {
    }

    public function validar(Validador $v): array
    {
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
        if ($d['fecha_inicio'] !== null) {
            $d['fecha_fin_autorizada'] ??= Periodo::fechaCumplimiento(new DateTimeImmutable($d['fecha_inicio']),
                $this->config->entero('meses_requeridos', 6))->format('Y-m-d');
            if ($d['fecha_fin_autorizada'] <= $d['fecha_inicio']) {
                $v->agregarError('fecha_fin_autorizada', 'La fecha de término debe ser posterior a la fecha de inicio.');
            }
        }
        return $d;
    }

    /** @return int[] jornadas permitidas (4 a 6 h) según la configuración. */
    public function jornadasPermitidas(): array
    {
        $lista = array_map('intval', explode(',', $this->config->texto('jornadas_permitidas', '4,5,6')));
        return array_values(array_filter($lista, fn($h) => $h >= 4 && $h <= 6)) ?: [4, 5, 6];
    }
}
