<?php
declare(strict_types=1);

namespace ServicioSocial\Seguridad;

/**
 * Qué puede hacer cada rol. Hoy solo existe `admin`, con todo. Agregar
 * un rol (por ejemplo de solo consulta) es agregar una entrada aquí y en
 * el CHECK chk_usuarios_rol.
 */
final class Permisos
{
    public const MAPA = [
        'admin' => ['*'],
    ];

    public static function puede(?string $rol, string $permiso): bool
    {
        if ($rol === null || !isset(self::MAPA[$rol])) {
            return false;
        }
        $permisos = self::MAPA[$rol];
        if (in_array('*', $permisos, true) || in_array($permiso, $permisos, true)) {
            return true;
        }
        $grupo = strstr($permiso, '.', true);
        return $grupo !== false && in_array($grupo . '.*', $permisos, true);
    }
}
