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
     * @return array{accion: callable, publico: bool, permiso: ?string}|null
     */
    public function buscar(string $metodo, string $ruta): ?array
    {
        $metodo = $metodo === 'HEAD' ? 'GET' : $metodo;
        $encontrada = $this->rutas[$metodo . ' ' . $ruta] ?? null;
        if ($encontrada === null) {
            return null;
        }
        [$accion, $opciones] = $encontrada;
        return [
            'accion'  => $accion,
            'publico' => (bool)($opciones['publico'] ?? false),
            'permiso' => $opciones['permiso'] ?? null,
        ];
    }

    public function existeRuta(string $ruta): bool
    {
        foreach (array_keys($this->rutas) as $clave) {
            if (substr($clave, strpos($clave, ' ') + 1) === $ruta) {
                return true;
            }
        }
        return false;
    }
}
