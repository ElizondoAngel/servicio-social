<?php
declare(strict_types=1);

namespace ServicioSocial\Dominio;

use DateTimeImmutable;

/**
 * Resultado de la Calculadora para un expediente.
 */
final class Resumen
{
    public const LISTO = 'listo';
    public const HORAS_CUMPLIDAS = 'horas_cumplidas';
    public const PERIODO_VENCIDO = 'periodo_vencido';
    public const POR_INICIAR = 'por_iniciar';
    public const PROXIMO = 'proximo';
    public const EN_CURSO = 'en_curso';

    /** Texto y tono (para el color de la etiqueta) de cada situación. */
    public const ETIQUETAS = [
        'listo'           => ['Listo para concluir', 'ok'],
        'horas_cumplidas' => ['Horas cumplidas', 'info'],
        'periodo_vencido' => ['Periodo vencido', 'mal'],
        'por_iniciar'     => ['Por iniciar', 'neutro'],
        'proximo'         => ['Próximo a concluir', 'aviso'],
        'en_curso'        => ['En curso', 'neutro'],
        'suspendido'      => ['Suspendido', 'aviso'],
        'concluido'       => ['Concluido', 'ok'],
        'baja'            => ['Baja', 'mal'],
    ];

    public function __construct(
        public readonly int $horasRequeridas,
        public readonly int $mesesRequeridos,
        public readonly int $acreditadas,
        public readonly int $trabajadas,
        public readonly int $restantes,
        public readonly float $avance,
        public readonly int $faltas,
        public readonly DateTimeImmutable $fechaPeriodo,
        public readonly bool $periodoCumplido,
        public readonly ?DateTimeImmutable $fechaHorasCumplidas,
        public readonly ?DateTimeImmutable $fechaEstimada,
        public readonly int $diasHabilesRestantes,
        public readonly int $excedente,
        public readonly bool $rebasaAutorizada,
        public readonly array $feriadosPorConfirmar,
        public readonly string $situacion,
        public readonly bool $puedeConcluir,
        public readonly ?string $motivoNoConcluir,
    ) {
    }

    public function etiqueta(): string
    {
        return self::ETIQUETAS[$this->situacion][0] ?? $this->situacion;
    }

    public function tono(): string
    {
        return self::ETIQUETAS[$this->situacion][1] ?? 'neutro';
    }
}
