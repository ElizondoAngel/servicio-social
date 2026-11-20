<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios\Respaldos;

/**
 * Formato del respaldo JSON (versión 1), comprimido con gzip:
 *
 *   línea 1: cabecera JSON {formato, version_formato, creado_en, tipo, totales, sha256, firma_hmac}
 *   línea 2: los datos JSON, exactamente los bytes sobre los que se calculó el sha256
 *
 * - sha256 detecta un archivo dañado.
 * - firma_hmac (HMAC-SHA256 de la cabecera sin la firma, con la llave de
 *   config.local.php) detecta un archivo alterado: sin la llave no se
 *   puede volver a firmar.
 */
final class FormatoRespaldo
{
    public const FORMATO = 'servicio-social-respaldo';
    public const VERSION = '1';
    public const TABLAS = ['usuarios', 'dias_inhabiles', 'configuracion', 'alumnos', 'expedientes', 'asistencias', 'firmas_semana', 'historial'];
    private const MAX_DESCOMPRIMIDO = 512 * 1024 * 1024;

    public static function llaveValida(string $llaveHex): bool
    {
        return (bool)preg_match('/^[0-9a-f]{64,}$/i', $llaveHex);
    }

    /** @return string contenido comprimido listo para escribir */
    public static function escribir(array $datos, string $tipo, string $creadoEn, string $llaveHex): string
    {
        self::exigirLlave($llaveHex);
        $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $cabecera = [
            'formato'         => self::FORMATO,
            'version_formato' => self::VERSION,
            'creado_en'       => $creadoEn,
            'tipo'            => $tipo,
            'totales'         => array_map('count', array_intersect_key($datos, array_flip(self::TABLAS))),
            'sha256'          => hash('sha256', $cuerpo),
        ];
        $cabecera['firma_hmac'] = self::firmar($cabecera, $llaveHex);
        $texto = json_encode($cabecera, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n" . $cuerpo;
        return gzencode($texto, 6);
    }

    /**
     * Valida el archivo completo y devuelve cabecera y datos.
     * @return array{cabecera: array, datos: array}
     * @throws RespaldoInvalido
     */
    public static function leer(string $comprimido, string $llaveHex): array
    {
        self::exigirLlave($llaveHex);
        $texto = @gzdecode($comprimido, self::MAX_DESCOMPRIMIDO);
        if ($texto === false) {
            throw new RespaldoInvalido('El archivo no es un respaldo comprimido válido (o está dañado).');
        }
        $salto = strpos($texto, "\n");
        if ($salto === false) {
            throw new RespaldoInvalido('El archivo no tiene el formato de un respaldo.');
        }
        $cabecera = json_decode(substr($texto, 0, $salto), true);
        $cuerpo = substr($texto, $salto + 1);
        if (!is_array($cabecera) || ($cabecera['formato'] ?? null) !== self::FORMATO) {
            throw new RespaldoInvalido('El archivo no es un respaldo de este sistema.');
        }
        if (($cabecera['version_formato'] ?? null) !== self::VERSION) {
            throw new RespaldoInvalido('Versión de formato desconocida: ' . (string)($cabecera['version_formato'] ?? '?') . '.');
        }
        $firma = (string)($cabecera['firma_hmac'] ?? '');
        $sinFirma = $cabecera;
        unset($sinFirma['firma_hmac']);
        if (!hash_equals(self::firmar($sinFirma, $llaveHex), $firma)) {
            throw new RespaldoInvalido('La firma no coincide: el archivo fue alterado o se generó con otra llave.');
        }
        if (!hash_equals((string)($cabecera['sha256'] ?? ''), hash('sha256', $cuerpo))) {
            throw new RespaldoInvalido('El contenido no coincide con su huella (SHA-256): el archivo está dañado o alterado.');
        }
        $datos = json_decode($cuerpo, true);
        if (!is_array($datos)) {
            throw new RespaldoInvalido('Los datos del respaldo no son JSON válido.');
        }
        foreach (self::TABLAS as $t) {
            if (!isset($datos[$t]) || !is_array($datos[$t]) || count($datos[$t]) !== (int)($cabecera['totales'][$t] ?? -1)) {
                throw new RespaldoInvalido("Los totales de «{$t}» no coinciden con la cabecera.");
            }
        }
        return ['cabecera' => $cabecera, 'datos' => $datos];
    }

    private static function firmar(array $cabecera, string $llaveHex): string
    {
        return hash_hmac('sha256', json_encode($cabecera, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), hex2bin($llaveHex));
    }

    private static function exigirLlave(string $llaveHex): void
    {
        if (!self::llaveValida($llaveHex)) {
            throw new RespaldoInvalido("Falta la llave de firma de respaldos ('respaldos.llave_hmac', 64 caracteres hexadecimales) en la configuración.");
        }
    }
}
