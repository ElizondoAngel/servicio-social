<?php
declare(strict_types=1);

/**
 * Carga alumnos FICTICIOS para ver el tablero en desarrollo.
 * Matrículas DEMO-xxx. Se niega a correr si el entorno es producción.
 *
 *   php tools/datos_demo.php
 */

$config = require __DIR__ . '/../src/bootstrap.php';
if ($config->esProduccion()) {
    fwrite(STDERR, "Este script solo corre en desarrollo.\n");
    exit(1);
}

use ServicioSocial\Nucleo\BaseDatos;

$bd = new BaseDatos($config);
$pdo = $bd->pdo();
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1 ORDER BY id LIMIT 1")->fetchColumn();
if ($admin === 0) {
    fwrite(STDERR, "Primero crea un administrador con php bin/crear-admin.php\n");
    exit(1);
}
if ((int)$pdo->query("SELECT COUNT(*) FROM alumnos WHERE matricula LIKE 'DEMO-%'")->fetchColumn() > 0) {
    fwrite(STDERR, "Los datos de demostración ya están cargados.\n");
    exit(0);
}
$bd->fijarContexto($admin, 'consola', 'tools/datos_demo.php');

$hoy = new DateTimeImmutable('today');
$alumnos = [
    // matrícula, nombre, paterno, materno, carrera, inicio (días atrás), días asistidos, horas, faltas, estado final
    ['DEMO-001', 'Mariana', 'López', 'Hernández', 'Ing. en Desarrollo de Software', 150, 100, 4, 3, 'activo'],
    ['DEMO-002', 'Jorge', 'Ramírez', 'Cruz', 'Ing. en Desarrollo de Software', 175, 114, 4, 1, 'activo'],
    ['DEMO-003', 'Daniela', 'Torres', null, 'Lic. en Contaduría', 60, 38, 5, 2, 'activo'],
    ['DEMO-004', 'Luis', 'Mendoza', 'Ortega', 'Ing. en Mecatrónica', 90, 40, 4, 5, 'suspendido'],
    ['DEMO-005', 'Fernanda', 'García', 'Ruiz', 'Lic. en Administración', 260, 120, 4, 0, 'concluido'],
    ['DEMO-006', 'Ricardo', 'Silva', 'Navarro', 'Ing. en Desarrollo de Software', -7, 0, 4, 0, 'activo'],
];

$pdo->beginTransaction();
$pdo->exec("SET @motivo_app = 'Datos de demostración (desarrollo)'");
foreach ($alumnos as [$mat, $nom, $pat, $mat2, $carrera, $haceDias, $diasAsistidos, $horas, $faltas, $estado]) {
    $pdo->prepare('INSERT INTO alumnos (matricula, nombre, apellido_paterno, apellido_materno, carrera) VALUES (?, ?, ?, ?, ?)')
        ->execute([$mat, $nom, $pat, $mat2, $carrera]);
    $alumnoId = (int)$pdo->lastInsertId();

    $inicio = $hoy->modify(($haceDias >= 0 ? '-' : '+') . abs($haceDias) . ' days');
    while ((int)$inicio->format('N') > 5) { $inicio = $inicio->modify('+1 day'); }
    $fin = \ServicioSocial\Dominio\Periodo::fechaCumplimiento($inicio, 6);
    $pdo->prepare("INSERT INTO expedientes (alumno_id, unidad_administrativa, responsable, hora_entrada, hora_salida, fecha_inicio,
                   fecha_fin_autorizada, jornada_prevista, creado_por) VALUES (?, 'Estadística', 'Responsable de demostración', '16:00', ?, ?, ?, ?, ?)")
        ->execute([$alumnoId, sprintf('%02d:00', 16 + $horas), $inicio->format('Y-m-d'), $fin->format('Y-m-d'), $horas, $admin]);
    $expId = (int)$pdo->lastInsertId();

    $d = $inicio; $hechos = 0; $faltasPuestas = 0;
    $ins = $pdo->prepare("INSERT INTO asistencias (expediente_id, fecha, estado, horas_trabajadas, horas_acreditadas, registrado_por) VALUES (?, ?, ?, ?, ?, ?)");
    while ($hechos < $diasAsistidos && $d < $hoy) {
        if ((int)$d->format('N') <= 5) {
            if ($faltasPuestas < $faltas && $hechos % 17 === 5) {
                $ins->execute([$expId, $d->format('Y-m-d'), 'falta', 0, 0, $admin]);
                $faltasPuestas++;
            } else {
                $ins->execute([$expId, $d->format('Y-m-d'), 'asistencia', $horas, $horas, $admin]);
                $hechos++;
            }
        }
        $d = $d->modify('+1 day');
    }

    if ($estado === 'suspendido') {
        $pdo->prepare("UPDATE expedientes SET estado = 'suspendido' WHERE id = ?")->execute([$expId]);
    } elseif ($estado === 'concluido') {
        $pdo->prepare("UPDATE expedientes SET estado = 'concluido', fecha_conclusion = ?, resumen_conclusion = ? WHERE id = ?")
            ->execute([$fin->format('Y-m-d'), json_encode(['demostracion' => true]), $expId]);
    }
}
$pdo->commit();
echo "Listo: " . count($alumnos) . " alumnos de demostración (DEMO-001 a DEMO-006).\n";
