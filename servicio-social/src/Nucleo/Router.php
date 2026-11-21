<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

/**
 * Tabla de rutas. Cada ruta declara si es pública y qué permiso exige;
 * por defecto TODA ruta es privada, así que olvidar la opción nunca deja
 * una página expuesta.
 */
final class Router
{
    /** @var array<string, array{0: callable, 1: array}> */
    private array $rutas = [];

    public function get(string $ruta, callable $accion, array $opciones = []): void
    {
        $this->rutas['GET ' . $ruta] = [$accion, $opciones];
    }

    public function post(string $ruta, callable $accion, array $opciones = []): void
    {
        $this->rutas['POST ' . $ruta] = [$accion, $opciones];
    }

    /**
     * Busca la ruta. Los segmentos {id} aceptan solo números enteros
     * positivos y llegan a la acción en $parametros.
     *
     * @return array{accion: callable, publico: bool, permiso: ?string, parametros: array}|null
     */
    public function buscar(string $metodo, string $ruta): ?array
    {
        $metodo = $metodo === 'HEAD' ? 'GET' : $metodo;
        foreach ($this->rutas as $clave => [$accion, $opciones]) {
            [$m, $patron] = explode(' ', $clave, 2);
            if ($m !== $metodo) {
                continue;
            }
            $parametros = self::coincide($patron, $ruta);
            if ($parametros !== null) {
                return [
                    'accion'     => $accion,
                    'publico'    => (bool)($opciones['publico'] ?? false),
                    'permiso'    => $opciones['permiso'] ?? null,
                    'parametros' => $parametros,
                ];
            }
        }
        return null;
    }

    public function existeRuta(string $ruta): bool
    {
        foreach (array_keys($this->rutas) as $clave) {
            if (self::coincide(explode(' ', $clave, 2)[1], $ruta) !== null) {
                return true;
            }
        }
        return false;
    }

    private static function coincide(string $patron, string $ruta): ?array
    {
        if (!str_contains($patron, '{')) {
            return $patron === $ruta ? [] : null;
        }
        $regex = '#^' . preg_replace('#\\\{([a-z_]+)\\\}#', '(?P<$1>[1-9][0-9]{0,9})', preg_quote($patron, '#')) . '$#';
        if (!preg_match($regex, $ruta, $m)) {
            return null;
        }
        return array_map('intval', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
    }
}
