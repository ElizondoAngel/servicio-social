<?php
declare(strict_types=1);

/**
 * Crea un usuario administrador desde la consola del servidor.
 *
 *   php bin/crear-admin.php
 *
 * Pide nombre, correo y contraseña (oculta, dos veces). La contraseña
 * se guarda solo como hash Argon2id; nunca aparece en el código, en el
 * SQL ni en la terminal. El alta queda en el historial por el trigger
 * de la tabla usuarios.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const MIN_PASSWORD = 12;

function salir(string $mensaje, int $codigo = 1): never
{
    fwrite(STDERR, $mensaje . PHP_EOL);
    exit($codigo);
}

function preguntar(string $texto): string
{
    fwrite(STDOUT, $texto);
    $linea = fgets(STDIN);
    if ($linea === false) {
        salir('Entrada cancelada.');
    }
    return trim($linea);
}

function preguntarOculto(string $texto): string
{
    $esTerminal = function_exists('posix_isatty') ? posix_isatty(STDIN) : stream_isatty(STDIN);
    if ($esTerminal) {
        shell_exec('stty -echo');
    }
    try {
        $valor = preguntar($texto);
    } finally {
        if ($esTerminal) {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }
    return $valor;
}

// --- Configuración --------------------------------------------------------
$rutaConfig = getenv('SS_CONFIG') ?: __DIR__ . '/../config/config.local.php';
if (!is_file($rutaConfig)) {
    salir("No encuentro la configuración en $rutaConfig. Copia config/config.ejemplo.php como config.local.php.");
}
$config = require $rutaConfig;
$bd = $config['bd'] ?? null;
if (!is_array($bd) || empty($bd['usuario'])) {
    salir('La sección "bd" de la configuración está incompleta.');
}

// --- Datos del administrador ------------------------------------------------
$nombre = preguntar('Nombre completo: ');
if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 120) {
    salir('El nombre debe tener entre 3 y 120 caracteres.');
}

$correo = mb_strtolower(preguntar('Correo electrónico: '));
if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false || mb_strlen($correo) > 190) {
    salir('El correo no es válido.');
}

$password = preguntarOculto('Contraseña (mínimo ' . MIN_PASSWORD . ' caracteres): ');
if (mb_strlen($password) < MIN_PASSWORD) {
    salir('La contraseña debe tener al menos ' . MIN_PASSWORD . ' caracteres.');
}
if (preguntarOculto('Confirma la contraseña: ') !== $password) {
    salir('Las contraseñas no coinciden.');
}

$algoritmo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$hash = password_hash($password, $algoritmo);
unset($password);

// --- Alta -------------------------------------------------------------------
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $bd['host'] ?? 'localhost', (int)($bd['puerto'] ?? 3306), $bd['nombre'] ?? 'servicio_social'),
        $bd['usuario'],
        $bd['password'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("SET time_zone = '-06:00'");

    $pdo->beginTransaction();
    $pdo->exec("SET @usuario_app = NULL, @motivo_app = 'Alta de administrador desde la consola', @ip_app = 'consola', @agente_app = 'bin/crear-admin.php'");

    $existe = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE correo = ?');
    $existe->execute([$correo]);
    if ((int)$existe->fetchColumn() > 0) {
        $pdo->rollBack();
        salir('Ya existe un usuario con ese correo.');
    }

    $alta = $pdo->prepare("INSERT INTO usuarios (nombre, correo, password_hash, rol, activo) VALUES (?, ?, ?, 'admin', 1)");
    $alta->execute([$nombre, $correo, $hash]);
    $id = (int)$pdo->lastInsertId();

    $pdo->commit();
    $pdo->exec('SET @usuario_app = NULL, @motivo_app = NULL, @ip_app = NULL, @agente_app = NULL');
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    salir('No se pudo crear el administrador: ' . $e->getMessage());
}

fwrite(STDOUT, "Administrador creado con id $id ($correo)." . PHP_EOL);
