<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios;

use ServicioSocial\Nucleo\BaseDatos;
use ServicioSocial\Repositorios\ConfiguracionRepositorio;
use ServicioSocial\Validacion\Validador;

/**
 * Configuración general. Solo se editan desde la aplicación los valores
 * operativos y de seguridad. Las reglas administrativas que aún debe
 * autorizar la coordinación (480 h, 6 meses, jornadas, feriados) se
 * muestran pero no se cambian aquí.
 */
final class ConfiguracionServicio
{
    /** clave => [etiqueta, unidad, mínimo, máximo] */
    public const EDITABLES = [
        'umbral_proximo_horas'     => ['Umbral de «próximo a concluir»', 'h restantes', 1, 480],
        'inactividad_minutos'      => ['Cierre de sesión por inactividad', 'minutos', 5, 120],
        'sesion_max_horas'         => ['Duración máxima de una sesión', 'horas', 1, 24],
        'respaldos_retener'        => ['Respaldos JSON que se conservan', 'respaldos', 5, 365],
        'intentos_max_correo'      => ['Intentos fallidos por correo antes de bloquear', 'intentos', 3, 20],
        'intentos_max_ip'          => ['Intentos fallidos por IP antes de bloquear', 'intentos', 5, 200],
        'intentos_ventana_minutos' => ['Ventana para contar intentos fallidos', 'minutos', 5, 120],
    ];

    /** Reglas pendientes de autorización: solo lectura. */
    public const PENDIENTES = [
        'horas_requeridas'    => 'Horas requeridas para concluir',
        'meses_requeridos'    => 'Meses naturales requeridos',
        'jornadas_permitidas' => 'Jornadas permitidas por día (h)',
        'jornada_prevista'    => 'Jornada prevista para expedientes nuevos (h)',
        'feriados_acreditan'  => 'Los feriados acreditan horas (1 = sí) en expedientes nuevos',
        'horas_por_feriado'   => 'Horas que acredita un feriado en expedientes nuevos',
    ];

    public function __construct(private readonly BaseDatos $bd, private readonly ConfiguracionRepositorio $config)
    {
    }

    public function valores(): array
    {
        return array_column($this->config->todas(), null, 'clave');
    }

    /**
     * @return array{ok: bool, errores?: array, datos?: array, aviso?: string}
     */
    public function guardar(array $entrada, int $usuarioId): array
    {
        $v = (new Validador($entrada))->texto('motivo', 'el motivo del cambio', 255);
        $actuales = $this->valores();
        $nuevos = [];
        foreach (self::EDITABLES as $clave => [$etiqueta, , $min, $max]) {
            $crudo = is_string($entrada[$clave] ?? null) ? trim($entrada[$clave]) : '';
            if (!ctype_digit($crudo) || (int)$crudo < $min || (int)$crudo > $max) {
                $v->agregarError($clave, "$etiqueta: escribe un número entre $min y $max.");
                continue;
            }
            $nuevos[$clave] = (string)(int)$crudo;
        }
        if (isset($nuevos['inactividad_minutos'], $nuevos['sesion_max_horas'])
            && (int)$nuevos['inactividad_minutos'] >= (int)$nuevos['sesion_max_horas'] * 60) {
            $v->agregarError('inactividad_minutos', 'La inactividad debe ser menor que la duración máxima de la sesión.');
        }
        $datos = $entrada;
        if (!$v->valido()) {
            return ['ok' => false, 'errores' => $v->errores(), 'datos' => $datos];
        }
        $cambios = array_filter($nuevos, fn($valor, $clave) => ($actuales[$clave]['valor'] ?? null) !== $valor, ARRAY_FILTER_USE_BOTH);
        if ($cambios === []) {
            return ['ok' => true, 'aviso' => 'Sin cambios.'];
        }
        $this->bd->transaccion(function () use ($cambios, $usuarioId) {
            foreach ($cambios as $clave => $valor) {
                $this->config->actualizar($clave, $valor, $usuarioId);
            }
        }, $v->valor('motivo'));
        return ['ok' => true, 'aviso' => count($cambios) === 1 ? 'Se guardó 1 cambio.' : 'Se guardaron ' . count($cambios) . ' cambios.'];
    }
}
