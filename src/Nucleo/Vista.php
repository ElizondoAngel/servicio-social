<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

/**
 * Plantillas PHP en vistas/. Todo texto variable se imprime con e().
 */
final class Vista
{
    private array $compartidos = [];

    public function __construct(private readonly string $ruta)
    {
    }

    /** Datos disponibles en todas las vistas (usuario, token CSRF, etc.). */
    public function compartir(array $datos): void
    {
        $this->compartidos = $datos + $this->compartidos;
    }

    public function render(string $plantilla, array $datos = [], ?string $layout = 'layout'): string
    {
        $datos += $this->compartidos;
        $contenido = $this->incluir($plantilla, $datos);
        return RutaBase::enHtml($layout === null ? $contenido : $this->incluir($layout, $datos + ['contenido' => $contenido]));
    }

    private function incluir(string $plantilla, array $variables): string
    {
        $archivo = $this->ruta . '/' . $plantilla . '.php';
        if (!is_file($archivo)) {
            throw new \RuntimeException("No existe la vista $plantilla");
        }
        // La plantilla se ejecuta en un ámbito limpio: sus variables nunca
        // chocan con las de este método (p. ej. una variable $datos).
        $ejecutar = static function (string $__archivo, array $__variables): void {
            extract($__variables, EXTR_SKIP);
            require $__archivo;
        };
        ob_start();
        try {
            $ejecutar($archivo, $variables);
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
