# Servicio Social UTN

Sistema privado para administrar los expedientes y las horas de servicio social. PHP 8 + MariaDB, sin frameworks.

El diseño técnico completo (tablas, reglas, cálculos, seguridad y respaldos) está en el documento **Servicio Social UTN · Diseño técnico (Etapa 2)**.

## Estado

| Etapa | Estado |
| --- | --- |
| 1. Análisis y arquitectura | Aprobada |
| 2. Base de datos | SQL listo y verificado en MariaDB 10.11 (63 pruebas). Falta confirmar la versión del servidor con TI |
| 3. Autenticación | Lista: inicio y cierre de sesión, inactividad, protección de rutas, CSRF, límite de intentos y recuperación por correo (54 pruebas) |
| 4. Dashboard y alumnos | Listo: tablero con estadísticas, búsqueda y filtros; alta de alumno con su expediente; edición; ficha del alumno y resumen del expediente con la Calculadora (48 pruebas) |
| 5. Expediente y calendario | Listo: datos y ajustes arriba; calendario por hojas, meses y semanas; captura de asistencia, falta, feriado o pendiente con correcciones (motivo), control de versión, autorizaciones, firmas semanales, feriados del catálogo, estados (suspender, baja, concluir, reabrir), nuevo periodo, catálogo de días inhábiles e historial del expediente (72 pruebas) |
| 6. Cálculos y proyecciones | Listo: conclusión probable en el tablero, orden por nombre, avance o conclusión, filtros en una sola barra y tarjetas que filtran, configuración operativa (las reglas pendientes de autorización quedan de solo lectura). El panel de proyección del expediente se quitó a petición (ruido visual); `Dominio/Proyeccion` conserva escenarios, ritmo y avance por mes, probados, por si se necesitan después |
| 7. Historial, respaldos y recuperación | Listo: historial general con filtros y detalle antes/después, sellado en cadena con `ss_sello` y su verificación, respaldos JSON firmados con retención, descarga con aviso, restauración que solo agrega lo que falta (vista previa, contraseña, respaldo previo, candado, una transacción), volcado nocturno, copia cifrada con age y restauración completa desde el servidor (53 pruebas) |
| 8. Pruebas, seguridad y despliegue | Listo para TI: pruebas de seguridad de todas las rutas, verificador de instalación, guía de despliegue (`docs/despliegue.md`). Falta lo que depende de TI |

## Instalación y operación

Todo lo del servidor está en **[docs/despliegue.md](docs/despliegue.md)**:
- requisitos;
- migraciones `000` a `006` y usuarios de MariaDB;
- configuración;
- Apache;
- cron;
- respaldos y restauración;
- actualizaciones;
- resumen de seguridad.

Después de instalar o actualizar: `php bin/verificar-instalacion.php` (debe terminar con 0 fallas).

| Script (`bin/`) | Para qué |
| --- | --- |
| `crear-admin.php` | Crear un administrador (pide la contraseña sin mostrarla) |
| `verificar-instalacion.php` | Revisar PHP, configuración, carpetas, base, permisos, respaldos y sellado |
| `respaldo-nocturno.php` | Cron: respaldo JSON, volcado, copia externa cifrada y limpieza |
| `sellar-historial.php` | Cron: sellar y verificar la cadena del historial (`--verificar` solo verifica) |
| `restaurar.php` | `--json=` agrega lo que falta; `--volcado=` reemplaza toda la base (desastre) |

## Pruebas

Todo de una vez, en una MariaDB **local de desarrollo** (el script borra y reinstala la base):

```bash
export PW_ADMIN=... PW_APP=... PW_RESPALDO=... PW_SELLO=...
tools/probar_todo.sh
```

| Grupo | Archivo | Qué revisa |
| --- | --- | --- |
| Base de datos | `tests/bd/verificar_bd.php` | Reglas de captura, conclusión, triggers, permisos, historial y la regla de los 6 meses en PHP y MariaDB |
| Unidad | `tests/unidad/seguridad.php` | Vigencia de sesión, CSRF, permisos, contraseñas, IP real, redirecciones seguras |
| Unidad | `tests/unidad/calculadora.php` | Horas acreditadas y trabajadas, feriados, cierres, 6 meses, proyección y situación (casos A–I y límites) |
| Punta a punta | `tests/e2e/autenticacion.php` | Un servidor real con `php -S`: login, bloqueo por intentos, cierre por inactividad, recuperación de contraseña, HTTPS |
| Punta a punta | `tests/e2e/alumnos.php` | Tablero, alta con validaciones, matrícula repetida, edición con motivo, búsqueda, filtros, escapado de HTML |
| Unidad | `tests/unidad/calendario.php` | Hojas de 12 semanas, estados y marcas de cada día, totales, firmas y días proyectados |
| Punta a punta | `tests/e2e/expediente.php` | Captura y corrección de días, duplicados y conflictos de versión, autorizaciones, firmas, catálogo, datos del expediente, estados, nuevo periodo, concluir y reabrir, hojas desplegables |
| Unidad | `tests/unidad/proyeccion.php` | Conclusión probable, escenarios por jornada, ritmo real y avance por mes |
| Punta a punta | `tests/e2e/proyeccion.php` | Panel de proyección, orden y filtros del tablero, configuración (rangos, motivo, reglas pendientes intocables) |
| Unidad | `tests/unidad/respaldos.php` | Formato firmado (archivos alterados, otra llave, truncados), validación de registros, cadena de sellos, retención de volcados |
| Punta a punta | `tests/e2e/seguridad.php` | Todas las rutas: sesión obligatoria y CSRF en cada POST; cabeceras, CSP sin código en línea, archivos privados, entradas maliciosas |
| Punta a punta | `tests/e2e/respaldos.php` | Historial, sellado, respaldos, descarga, retención, restauración, candado, mantenimiento, scripts del servidor y restauración completa |

`probar_todo.sh` también falla si PHP registra cualquier advertencia durante las pruebas.

Solo usan datos ficticios. Las pruebas no necesitan Composer.

Para ver el tablero con información en tu Mac: `php tools/datos_demo.php` carga 6 alumnos ficticios (matrículas `DEMO-001` a `DEMO-006`). Se niega a correr si el entorno es producción.

## Estructura

```
bin/               scripts de consola (crear-admin.php)
config/            config.ejemplo.php (config.local.php solo en el servidor)
database/          migraciones SQL numeradas
docs/              ejemplo de VirtualHost de Apache
public/            única carpeta visible: index.php y assets/
src/Nucleo/        arranque, router, peticiones, respuestas, vistas, base de datos
src/Seguridad/     sesión, CSRF, permisos, límite de intentos, contraseñas
src/Repositorios/  únicas clases que escriben SQL
src/Servicios/     reglas de negocio con transacciones (autenticación, alumnos, asistencia, expediente, catálogo)
src/Controladores/ reciben la petición, validan y responden
src/Dominio/       reglas sin base de datos (Periodo, Calculadora, Calendario)
storage/           respaldos, sesiones y logs (fuera de git)
tests/             pruebas
tools/             utilidades de desarrollo
vistas/            plantillas PHP
```
