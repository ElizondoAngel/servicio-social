<?php
declare(strict_types=1);

namespace ServicioSocial\Seguridad;

/**
 * Política, hash y verificación de contraseñas.
 */
final class Contrasenas
{
    public const MINIMO = 12;
    public const MAXIMO = 200;

    /** Hash fijo para gastar el mismo tiempo cuando el correo no existe. */
    private const HASH_SENUELO = '$argon2id$v=19$m=65536,t=4,p=1$LlVRS21LTFZtUUtFVDRVMg$Qh/pf2bpETwrgYUedU4Eb4akbm4HKSR3Y1ZOtlB+7B4';

    public static function algoritmo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algoritmo());
    }

    public static function verificar(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            password_verify($password, self::HASH_SENUELO);
            return false;
        }
        return password_verify($password, $hash);
    }

    public static function necesitaRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algoritmo());
    }

    /**
     * @return string[] errores; vacío si cumple la política
     */
    public static function validar(string $password, string $confirmacion, string $correo = ''): array
    {
        $errores = [];
        $largo = mb_strlen($password);
        if ($largo < self::MINIMO) {
            $errores[] = 'La contraseña debe tener al menos ' . self::MINIMO . ' caracteres.';
        }
        if ($largo > self::MAXIMO) {
            $errores[] = 'La contraseña no puede tener más de ' . self::MAXIMO . ' caracteres.';
        }
        if ($correo !== '' && mb_strtolower($password) === mb_strtolower($correo)) {
            $errores[] = 'La contraseña no puede ser igual a tu correo.';
        }
        if ($password !== $confirmacion) {
            $errores[] = 'Las contraseñas no coinciden.';
        }
        return $errores;
    }
}
