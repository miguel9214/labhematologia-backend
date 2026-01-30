# Programación automática (cron) – índice de PDFs

El proyecto ya programa la actualización del índice de documentos en las carpetas.

## Qué hace el scheduler

1. **Cada 15 minutos**  
   Ejecuta `php artisan pdfs:import --since=60`: indexa solo los PDFs modificados en la última hora. Así los archivos nuevos que se guarden en la carpeta compartida aparecen en la app en poco tiempo.

2. **Una vez al día (2:00)**  
   Ejecuta `php artisan pdfs:import` (importación completa, todos los años y carpetas). Sirve para corregir lo que no se haya detectado en las ejecuciones incrementales.

## Cómo activar el cron en tu equipo

Para que estas tareas se ejecuten solas, el sistema debe llamar cada minuto al scheduler de Laravel.

### Windows (Laragon / tu PC)

1. Abre **Programador de tareas** (Task Scheduler).
2. Crear tarea básica:
   - **Nombre:** Laravel Scheduler – Lab Hematología  
   - **Desencadenador:** Diariamente (o “Al iniciar sesión” si prefieres que solo corra con tu usuario).  
     Para que sea cada minuto: repetir cada **1 minuto** durante **1 día** (o “Indefinidamente” si tu versión lo permite).
3. **Acción:** Iniciar un programa  
   - **Programa:** `C:\laragon\bin\php\php-8.x\php.exe` (ajusta la versión de PHP de Laragon).  
   - **Argumentos:** `C:\laragon\www\labhematologia-backend\artisan schedule:run`  
   - **Iniciar en:** `C:\laragon\www\labhematologia-backend`
4. Opcional: marcar “Ejecutar con los privilegios más altos” si hay problemas de permisos con la carpeta de red.

Si prefieres un **archivo .bat** para probar o ejecutar a mano:

```bat
@echo off
cd /d C:\laragon\www\labhematologia-backend
C:\laragon\bin\php\php-8.2.20-Win32-vs16-x64\php.exe artisan schedule:run
```

(Ajusta la ruta de `php.exe` a la de tu Laragon.)

### Linux / servidor

Añade en crontab (`crontab -e`):

```cron
* * * * * cd /var/www/labhematologia-backend && php artisan schedule:run >> /dev/null 2>&1
```

## Comprobar que está programado

En la carpeta del proyecto:

```bash
php artisan schedule:list
```

Ahí verás las dos tareas (cada 15 min y diaria a las 2:00).

## Ejecutar la importación a mano

- **Solo archivos recientes (última hora):**  
  `php artisan pdfs:import --since=60`

- **Importación completa:**  
  `php artisan pdfs:import`

- **Solo un año:**  
  `php artisan pdfs:import --year=2025`

- **Simular (no guarda en BD):**  
  `php artisan pdfs:import --dry`
