<?php
declare(strict_types=1);

namespace ServicioSocial\Validacion;

use DateTimeImmutable;

/**
 * Valida y limpia los campos de un formulario. Junta un mensaje por
 * campo para mostrarlo junto al campo; nada inválido llega a la base.
 */
final class Validador
{
    private array $errores = [];
    private array $limpios = [];

    public function __construct(private readonly array $datos)
    {
    }

    public function texto(string $campo, string $etiqueta, int $max, bool $requerido = true, ?string $patron = null, string $mensajePatron = ''): self
    {
        $valor = $this->crudo($campo);
        $valor = preg_replace('/\s+/u', ' ', $valor) ?? '';
        if ($valor === '') {
            if ($requerido) {
                $this->errores[$campo] = "Escribe $etiqueta.";
            }
            $this->limpios[$campo] = null;
            return $this;
        }
        if (mb_strlen($valor) > $max) {
            $this->errores[$campo] = ucfirst($etiqueta) . " no puede tener más de $max caracteres.";
        } elseif ($patron !== null && !preg_match($patron, $valor)) {
            $this->errores[$campo] = $mensajePatron;
        }
        $this->limpios[$campo] = $valor;
        return $this;
    }

    public function correo(string $campo, bool $requerido = false): self
    {
        $valor = mb_strtolower($this->crudo($campo));
        if ($valor === '') {
            if ($requerido) {
                $this->errores[$campo] = 'Escribe el correo electrónico.';
            }
            $this->limpios[$campo] = null;
            return $this;
        }
        if (mb_strlen($valor) > 190 || filter_var($valor, FILTER_VALIDATE_EMAIL) === false) {
            $this->errores[$campo] = 'Escribe un correo válido, por ejemplo nombre@dominio.com.';
        }
        $this->limpios[$campo] = $valor;
        return $this;
    }

    public function telefono(string $campo): self
    {
        $valor = preg_replace('/[\s\-().]/', '', $this->crudo($campo)) ?? '';
        if ($valor !== '' && !preg_match('/^\d{10}$/', $valor)) {
            $this->errores[$campo] = 'El teléfono debe tener 10 dígitos.';
        }
        $this->limpios[$campo] = $valor === '' ? null : $valor;
        return $this;
    }

    public function fecha(string $campo, string $etiqueta, bool $requerido = true): self
    {
        $valor = $this->crudo($campo);
        if ($valor === '') {
            if ($requerido) {
                $this->errores[$campo] = "Escribe $etiqueta.";
            }
            $this->limpios[$campo] = null;
            return $this;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($d === false || $d->format('Y-m-d') !== $valor || (int)$d->format('Y') < 2000 || (int)$d->format('Y') > 2100) {
            $this->errores[$campo] = ucfirst($etiqueta) . ' no es una fecha válida.';
            $this->limpios[$campo] = null;
            return $this;
        }
        $this->limpios[$campo] = $valor;
        return $this;
    }

    public function hora(string $campo, string $etiqueta): self
    {
        $valor = $this->crudo($campo);
        if ($valor === '') {
            $this->limpios[$campo] = null;
            return $this;
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor)) {
            $this->errores[$campo] = ucfirst($etiqueta) . ' no es una hora válida.';
            $this->limpios[$campo] = null;
            return $this;
        }
        $this->limpios[$campo] = $valor;
        return $this;
    }

    /** Entero dentro de una lista de valores permitidos. */
    public function opcionEntera(string $campo, string $etiqueta, array $permitidos, int $porDefecto): self
    {
        $valor = $this->crudo($campo);
        if ($valor === '') {
            $this->limpios[$campo] = $porDefecto;
            return $this;
        }
        if (!ctype_digit($valor) || !in_array((int)$valor, $permitidos, true)) {
            $this->errores[$campo] = ucfirst($etiqueta) . ' debe ser ' . implode(', ', $permitidos) . '.';
        }
        $this->limpios[$campo] = (int)$valor;
        return $this;
    }

    public function casilla(string $campo): self
    {
        $this->limpios[$campo] = in_array($this->crudo($campo), ['1', 'on', 'si'], true) ? 1 : 0;
        return $this;
    }

    /** Error que depende de varios campos (lo agrega quien llama). */
    public function agregarError(string $campo, string $mensaje): self
    {
        $this->errores[$campo] ??= $mensaje;
        return $this;
    }

    public function errores(): array
    {
        return $this->errores;
    }

    public function valido(): bool
    {
        return $this->errores === [];
    }

    public function limpios(): array
    {
        return $this->limpios;
    }

    public function valor(string $campo): mixed
    {
        return $this->limpios[$campo] ?? null;
    }

    private function crudo(string $campo): string
    {
        $v = $this->datos[$campo] ?? '';
        return is_string($v) ? trim($v) : '';
    }
}
