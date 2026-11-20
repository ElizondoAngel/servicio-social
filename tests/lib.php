<?php
declare(strict_types=1);

/**
 * Mini marco de pruebas sin dependencias (el servidor no tiene acceso a
 * Packagist). Las funciones siguen la forma de PHPUnit para migrar fácil.
 */

$GLOBALS['__ok'] = 0;
$GLOBALS['__fallas'] = [];

function seccion(string $titulo): void
{
    echo "\n$titulo\n";
}

function prueba(string $nombre, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__ok']++;
        echo "  ok    $nombre\n";
    } catch (Throwable $e) {
        $GLOBALS['__fallas'][] = $nombre;
        echo "  FALLA $nombre -> {$e->getMessage()}\n";
    }
}

function igual(mixed $esperado, mixed $real, string $que = 'valor'): void
{
    if ($esperado !== $real) {
        throw new RuntimeException("$que: se esperaba " . var_export($esperado, true) . ', llegó ' . var_export($real, true));
    }
}

function verdadero(bool $condicion, string $que): void
{
    if (!$condicion) {
        throw new RuntimeException("no se cumplió: $que");
    }
}

function contiene(string $aguja, string $pajar, string $que = 'texto'): void
{
    if (!str_contains($pajar, $aguja)) {
        throw new RuntimeException("$que no contiene «{$aguja}»");
    }
}

function noContiene(string $aguja, string $pajar, string $que = 'texto'): void
{
    if (str_contains($pajar, $aguja)) {
        throw new RuntimeException("$que no debía contener «{$aguja}»");
    }
}

function resumen(): never
{
    $fallas = count($GLOBALS['__fallas']);
    echo "\n" . ($GLOBALS['__ok'] + $fallas) . " pruebas: {$GLOBALS['__ok']} correctas, $fallas fallidas\n";
    exit($fallas ? 1 : 0);
}
