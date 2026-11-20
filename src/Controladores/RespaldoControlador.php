<?php
declare(strict_types=1);

namespace ServicioSocial\Controladores;

use DateTimeImmutable;
use ServicioSocial\Nucleo\Peticion;
use ServicioSocial\Nucleo\Respuesta;
use ServicioSocial\Nucleo\Vista;
use ServicioSocial\Repositorios\UsuarioRepositorio;
use ServicioSocial\Seguridad\Sesion;
use ServicioSocial\Servicios\Respaldos\RespaldoInvalido;
use ServicioSocial\Servicios\Respaldos\RespaldoServicio;

/**
 * Pantalla de respaldos: crear, verificar, descargar y restaurar
 * (solo agregando lo que falta). La descarga y la restauración pasan
 * siempre por la aplicación: los archivos no tienen URL pública.
 */
final class RespaldoControlador
{
    private const MAX_SUBIDA = 50 * 1024 * 1024;
    private const INTENTOS_PASSWORD = 3;

    public function __construct(
        private readonly Vista $vista,
        private readonly Sesion $sesion,
        private readonly RespaldoServicio $servicio,
        private readonly UsuarioRepositorio $usuarios,
        private readonly string $temporal,
    ) {
    }

    public function index(Peticion $p): Respuesta
    {
        $ultimo = $this->servicio->ultimo();
        $horas = $ultimo ? ((new DateTimeImmutable())->getTimestamp() - (new DateTimeImmutable($ultimo['creado_en']))->getTimestamp()) / 3600 : null;
        return Respuesta::html($this->vista->render('respaldos/index', [
            'titulo'      => 'Respaldos',
            'configurado' => $this->servicio->configurado(),
            'respaldos'   => $this->servicio->lista(),
            'ultimo'      => $ultimo,
            'viejo'       => $horas === null || $horas > 48,
            'aviso'       => $this->sesion->tomar('aviso'),
            'falla'       => $this->sesion->tomar('error'),
            'resultado'   => $this->sesion->tomar('resultado_restauracion'),
        ]));
    }

    public function crear(Peticion $p): Respuesta
    {
        try {
            $r = $this->servicio->crear('manual', $this->usuarioId(), $p->ip(), $p->agente());
            $this->sesion->set('aviso', 'Respaldo creado y verificado: ' . $r['archivo'] . '.');
        } catch (RespaldoInvalido $e) {
            $this->sesion->set('error', $e->getMessage());
        }
        return Respuesta::redirigir('/respaldos');
    }

    public function verificar(Peticion $p, array $params): Respuesta
    {
        try {
            $r = $this->servicio->verificar($params['id'], $this->usuarioId(), $p->ip(), $p->agente());
            $this->sesion->set($r['ok'] ? 'aviso' : 'error', $r['mensaje']);
        } catch (RespaldoInvalido $e) {
            $this->sesion->set('error', $e->getMessage());
        }
        return Respuesta::redirigir('/respaldos');
    }

    public function descargar(Peticion $p, array $params): Respuesta
    {
        if ($p->texto('entiendo') !== '1') {
            $this->sesion->set('error', 'Para descargar confirma que entiendes que el archivo contiene datos personales.');
            return Respuesta::redirigir('/respaldos');
        }
        try {
            $a = $this->servicio->contenidoParaDescargar($params['id'], $this->usuarioId(), $p->ip(), $p->agente());
        } catch (RespaldoInvalido $e) {
            $this->sesion->set('error', $e->getMessage());
            return Respuesta::redirigir('/respaldos');
        }
        return new Respuesta(200, $a['contenido'], [
            'Content-Type'        => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="' . $a['nombre'] . '"',
            'Content-Length'      => (string)strlen($a['contenido']),
        ]);
    }

    /** Paso 1: elegir el archivo, validarlo y mostrar qué se agregaría. No escribe nada. */
    public function preparar(Peticion $p): Respuesta
    {
        try {
            $subido = $p->archivo('archivo');
            if ($subido !== null) {
                if ($subido['tamano'] > self::MAX_SUBIDA) {
                    throw new RespaldoInvalido('El archivo es demasiado grande.');
                }
                $contenido = (string)file_get_contents($subido['ruta']);
                $leido = $this->servicio->leerContenido($contenido);
                if (!is_dir($this->temporal) && !mkdir($this->temporal, 0750, true) && !is_dir($this->temporal)) {
                    throw new \RuntimeException('No se pudo crear la carpeta temporal.');
                }
                $token = bin2hex(random_bytes(16));
                file_put_contents($this->temporal . "/restaurar-$token.json.gz", $contenido, LOCK_EX);
                $origen = ['tipo' => 'subido', 'token' => $token, 'nombre' => $subido['nombre']];
            } elseif (ctype_digit($p->texto('respaldo_id'))) {
                $fila = $this->servicio->buscar((int)$p->texto('respaldo_id'));
                $leido = $this->servicio->leerContenido((string)$this->servicio->contenidoInterno($fila));
                $origen = ['tipo' => 'respaldo', 'id' => (int)$fila['id'], 'nombre' => $fila['archivo']];
            } else {
                throw new RespaldoInvalido('Elige un respaldo de la lista o sube un archivo.');
            }
        } catch (RespaldoInvalido $e) {
            $this->sesion->set('error', 'No se puede restaurar: ' . $e->getMessage());
            return Respuesta::redirigir('/respaldos');
        }
        $this->sesion->set('restauracion', $origen + ['intentos' => 0]);
        return Respuesta::html($this->vista->render('respaldos/vista_previa', [
            'titulo' => 'Restaurar respaldo', 'origen' => $origen, 'cabecera' => $leido['cabecera'],
            'plan' => $this->servicio->vistaPrevia($leido['datos']), 'errores' => [], 'motivo' => '',
        ]));
    }

    /** Paso 2: confirmar con contraseña y motivo; se restaura agregando lo que falta. */
    public function confirmar(Peticion $p): Respuesta
    {
        $origen = $this->sesion->get('restauracion');
        if (!is_array($origen)) {
            $this->sesion->set('error', 'La restauración venció o ya se hizo. Empieza de nuevo.');
            return Respuesta::redirigir('/respaldos');
        }
        try {
            $leido = $this->servicio->leerContenido($this->contenidoDe($origen));
        } catch (RespaldoInvalido $e) {
            $this->cancelar($origen);
            $this->sesion->set('error', 'No se puede restaurar: ' . $e->getMessage());
            return Respuesta::redirigir('/respaldos');
        }

        $errores = [];
        $motivo = mb_substr(preg_replace('/\s+/u', ' ', $p->texto('motivo')) ?? '', 0, 200);
        if ($motivo === '') {
            $errores['motivo'] = 'Escribe por qué se restaura.';
        }
        if ($p->texto('confirmo') !== '1') {
            $errores['confirmo'] = 'Marca la casilla para confirmar.';
        }
        $usuario = $this->usuarios->porId($this->usuarioId());
        if (!$usuario || !password_verify($p->textoCrudo('password'), (string)$usuario['password_hash'])) {
            $origen['intentos']++;
            if ($origen['intentos'] >= self::INTENTOS_PASSWORD) {
                $this->cancelar($origen);
                $this->sesion->set('error', 'Contraseña incorrecta demasiadas veces; se canceló la restauración.');
                return Respuesta::redirigir('/respaldos');
            }
            $this->sesion->set('restauracion', $origen);
            $errores['password'] = 'La contraseña no es correcta.';
        }
        if ($errores !== []) {
            return Respuesta::html($this->vista->render('respaldos/vista_previa', [
                'titulo' => 'Restaurar respaldo', 'origen' => $origen, 'cabecera' => $leido['cabecera'],
                'plan' => $this->servicio->vistaPrevia($leido['datos']), 'errores' => $errores, 'motivo' => $motivo,
            ]), 422);
        }

        try {
            $r = $this->servicio->restaurarAgregando($leido, $origen['nombre'], $motivo, $this->usuarioId(), $p->ip(), $p->agente());
            $this->sesion->set('resultado_restauracion', ['nuevos' => $r['nuevos'], 'omitidos' => $r['omitidos'], 'previo' => $r['previo']['archivo']]);
        } catch (RespaldoInvalido $e) {
            $this->sesion->set('error', $e->getMessage());
        }
        $this->cancelar($origen);
        return Respuesta::redirigir('/respaldos');
    }

    private function contenidoDe(array $origen): string
    {
        if ($origen['tipo'] === 'subido') {
            $ruta = $this->temporal . '/restaurar-' . preg_replace('/[^0-9a-f]/', '', (string)$origen['token']) . '.json.gz';
            return is_file($ruta) ? (string)file_get_contents($ruta) : throw new RespaldoInvalido('El archivo subido ya no está disponible.');
        }
        return $this->servicio->contenidoInterno($this->servicio->buscar((int)$origen['id']));
    }

    private function cancelar(array $origen): void
    {
        if ($origen['tipo'] === 'subido') {
            @unlink($this->temporal . '/restaurar-' . preg_replace('/[^0-9a-f]/', '', (string)$origen['token']) . '.json.gz');
        }
        $this->sesion->set('restauracion', null);
    }

    private function usuarioId(): int
    {
        return (int)$this->sesion->usuarioId();
    }
}
