Carpeta de trabajo del servidor (logs, sesiones, respaldos, volcados y temporales). Su contenido no se sube a git.
En el servidor: `mkdir -p storage/{logs,sesiones,respaldos,volcados,tmp}` con permisos 750 y el usuario de Apache como dueño.
Ver docs/despliegue.md.
