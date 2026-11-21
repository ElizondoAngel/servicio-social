<?php
/**
 * Plantilla de configuración. Copia este archivo como config.local.php
 * SOLO en el servidor (permisos 640) y llena los valores reales.
 * config.local.php está en .gitignore y nunca se sube al repositorio.
 *
 * Se usa .php y no .env porque WinSCP no sube archivos que empiezan
 * con punto.
 */
return [
    'app' => [
        'entorno'      => 'produccion',          // produccion | desarrollo
        'url_base'     => 'https://serviciosocial.ejemplo.edu.mx',
        'zona_horaria' => 'America/Mexico_City',
        'forzar_https' => true,                  // en producción siempre true
        // IPs de proxies inversos de confianza (vacío si Apache recibe la conexión directa)
        'proxies_confianza' => [],
    ],

    'rutas' => [
        'logs'         => __DIR__ . '/../storage/logs',
        'temporal'     => __DIR__ . '/../storage/tmp',          // archivos subidos para restaurar
        // Si existe este archivo, la aplicación responde "en mantenimiento" (lo crea bin/restaurar.php)
        'mantenimiento' => __DIR__ . '/../storage/mantenimiento',
    ],

    'bd' => [
        'host'     => 'localhost',
        'puerto'   => 3306,
        'nombre'   => 'servicio_social',
        'usuario'  => 'ss_app',
        'password' => '',                         // contraseña de ss_app
    ],

    'sesion' => [
        'nombre_cookie' => 'ss_sesion',
        'ruta_archivos' => __DIR__ . '/../storage/sesiones',
    ],

    'correo' => [
        'driver'        => 'smtp',               // 'archivo' solo en desarrollo
        'archivo'       => __DIR__ . '/../storage/logs/correos-dev.log',
        'smtp_host'     => '',
        'smtp_puerto'   => 587,
        'smtp_usuario'  => '',
        'smtp_password' => '',
        'remitente'     => '',
        'nombre'        => 'Servicio Social UTN',
    ],

    'respaldos' => [
        // Nivel 1: respaldos JSON firmados (pantalla Respaldos y cada noche)
        'ruta'       => __DIR__ . '/../storage/respaldos',
        // 64 caracteres hexadecimales: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        // Guárdala también fuera del servidor: sin ella no se pueden verificar ni restaurar los respaldos.
        'llave_hmac' => '',

        // Nivel 2: volcado nocturno con mysqldump y el usuario ss_respaldo (bin/respaldo-nocturno.php)
        'volcado' => [
            'activo'   => false,
            'ruta'     => __DIR__ . '/../storage/volcados',
            'usuario'  => 'ss_respaldo',
            'password' => '',
            'diarios'  => 14,
            'semanales'=> 8,   // los del domingo
        ],

        // Nivel 3: copia externa cifrada (semanal). PENDIENTE: destino que autorice TI.
        // Se cifra con la llave PÚBLICA de age (la privada nunca vive en el servidor).
        'externo' => [
            'activo'          => false,
            'destino'         => '',        // carpeta montada o sincronizada que autorice TI
            'age_destinatario'=> '',        // age1...
        ],
    ],

    // Sellado nocturno del historial (bin/sellar-historial.php) con el usuario ss_sello
    'sello' => [
        'usuario'  => 'ss_sello',
        'password' => '',
    ],
];
