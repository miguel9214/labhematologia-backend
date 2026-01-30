# Auditoría: Cron de Actualización de Exámenes (Importación de PDFs)

## 1. Comando localizado

- **Archivo:** `app/Console/Commands/ImportPdfs.php`
- **Comando Artisan:** `pdfs:import`
- **Descripción:** Indexa PDFs desde el disco `pdf_remote` (carpeta compartida o local). No existe `ImportExams`, `SyncExams` ni `UpdateExams`; en esta app los "exámenes" son **archivos PDF** listados en el filesystem y registrados en la tabla `pdf_documents`.

**Registro en Kernel:** `app/Console/Kernel.php` programa:
- Cada 15 min: `pdfs:import --since=60`
- Diario a las 02:00: `pdfs:import` (sin opciones)

---

## 2. Origen de los datos (no es SQL)

La fuente **no es una consulta Eloquent/SQL**. Es un **escaneo del disco** `pdf_remote`:

- **Si Flysystem funciona:** `Storage::disk('pdf_remote')->allFiles()` (líneas 57–58).
- **Si falla (p. ej. rutas UNC en Windows):** `$this->allFilesNative($root, $cutoff, $windowDays)` (líneas 62–64), que recorre el árbol de carpetas con PHP nativo.

La “lista de exámenes” es la lista de **archivos .pdf** encontrados en ese árbol. No hay filtros por estado ni por tabla de exámenes; solo por **ruta en disco** y, cuando aplica, por **fecha de modificación** o **ventana de fechas en el nombre de carpetas**.

---

## 3. Filtros que restringen qué se importa

### 3.1 Filtros de fecha / ventana de tiempo

| Ubicación | Qué hace | Efecto |
|-----------|----------|--------|
| **Líneas 29–37** | `--today` → `$cutoff = Carbon::today()->startOfDay()`; `--since=N` → `$cutoff = Carbon::now()->subMinutes($sinceMin)` | Si hay `$cutoff`, luego se usa para podar por tiempo o por ventana de días. |
| **Líneas 40–47** | Con `$cutoff` se construye `$windowDays`: días desde `$cutoff` hasta hoy. | Solo se usan para **poda por ruta** cuando se lista con `allFilesNative` (ver más abajo). |
| **Líneas 62–64** | Si se usa listado nativo **y** hay `$cutoff`, se llama `allFilesNative($root, $cutoff, $windowDays)`. | Con `$windowDays` no vacío, **solo se recorren carpetas cuyo nombre indica una fecha dentro de esa ventana** (p. ej. “ENERO 2026”, “29 ENERO”). Carpetas de otros años/meses/días **no se escanean**. |
| **Líneas 84–95** | Con `$cutoff` y sin “skip” por muchos archivos: por cada archivo se obtiene `lastModified` / `filemtime()`. | Si `mtime < $cutoff` → se **omite** el archivo (no se indexa). |

Conclusión: **cualquier uso de `--since=N` o `--today` restringe**:
- O bien a carpetas “recientes” (por nombre),  
- O bien a archivos modificados recientemente (por `mtime`).

### 3.2 Filtro por año (opcional)

| Ubicación | Qué hace |
|-----------|----------|
| **Línea 174** | `if ($yearFilter && (int) $year !== $yearFilter) { $skipped++; continue; }` |

Si se invoca con `--year=2025`, solo se indexan PDFs cuya ruta se interpreta como año 2025. El resto se omiten.

### 3.3 Sin filtros de estado

No hay `where('status', 'VALIDADO')` ni nada similar. No se usa ninguna columna de estado en esta importación.

### 3.4 Límites y paginación

No hay `limit()`, `take()` ni paginación. El límite real es **qué archivos entran en la lista** por:
- Cómo se recorre el disco (con o sin poda por `$windowDays`),
- Y, si aplica, el filtro por `mtime` y por `$yearFilter`.

### 3.5 Otras restricciones (por ruta)

- **Solo .pdf:** archivos que no terminen en `.pdf` se omiten (líneas 79–80).
- **Ruta con al menos 2 segmentos:** si la ruta tiene menos de 2 partes, se considera inválida (líneas 99–104).
- **Fecha inferible:** si con la ruta no se puede inferir un (año, mes, día) válido (p. ej. formato `YYYY/MM/DD` o “MES YYYY” + “DD MES”), el archivo se marca como error y no se indexa (líneas 159–170).
- **Rango de fechas:** año entre 1990 y año actual+1, mes 1–12, día 1–31 (líneas 160–166).

---

## 4. Flujo del Cron: qué se crea o actualiza

1. Se calculan `$cutoff` y `$windowDays` según opciones (`--since`, `--today`, etc.).
2. Se obtiene la lista de archivos del disco (Flysystem o `allFilesNative`).
3. Para cada archivo:
   - Se omite si no es `.pdf`.
   - Si hay `$cutoff` y no se aplica “skip” por muchos archivos, se omite si `mtime < $cutoff`.
   - Se parsea la ruta para obtener `year`, `month`, `day`; si no se puede o están fuera de rango, se omite.
   - Si hay `--year`, se omite cuando el año inferido no coincide.
4. **Inserción/actualización:**  
   `PdfDocument::updateOrCreate(['path' => $relative], ['name' => $filename, 'year' => $year, 'month' => $month, 'day' => $day, 'source' => PdfDocument::SOURCE_REMOTE])` (líneas 183–186).

**Criterio de “ya existe”:** la clave única es `path`. Si ya existe un registro con esa misma `path`, se actualiza; si no, se crea. No se usa status ni otro campo para decidir si un examen “existe”.

---

## 5. Dónde se restringe la consulta (y por qué no se muestran todos)

La “consulta” es el **conjunto de archivos que se escanean y que pasan los filtros**. Las restricciones importantes son:

1. **Uso de `--since=60` en el Cron cada 15 min** (`Kernel.php`, línea 23)  
   - Con listado nativo: solo se recorren carpetas cuyas fechas (por nombre) caen en la ventana “últimos 60 minutos” (en la práctica suele ser hoy y tal vez ayer).  
   - Con listado Flysystem: se listan todos los archivos, pero se indexan solo los que tienen `mtime` en la última hora.  
   **Efecto:** el cron corto **no** vuelve a indexar todo el histórico; solo lo “reciente”.

2. **Ventana de días (`$windowDays`)** cuando hay `$cutoff` (líneas 41–47, 62–64, 334–336)  
   - Solo se entran en carpetas que “parecen” fechas dentro de esa ventana.  
   **Efecto:** carpetas de meses/años antiguos no se escanean en ejecuciones con `--since` o `--today`.

3. **Filtro por `mtime`** (líneas 84–95)  
   - Con `$cutoff` y sin “skip”, los archivos con fecha de modificación antigua se omiten.  
   **Efecto:** mismo que antes: solo se indexan archivos “recientes” en tiempo de modificación.

4. **Importación completa solo a las 02:00** (`Kernel.php`, línea 28)  
   - `pdfs:import` sin `--since` ni `--today`: `$cutoff = null`, `$windowDays = []`, y se recorre **todo** el árbol (líneas 338–348).  
   **Efecto:** si esta tarea no se ejecuta (cron no configurado, servidor apagado, error), no se vuelve a importar todo el histórico.

---

## 6. Cómo ampliar el rango o traer todo el histórico

### Opción A: Ejecutar importación completa a mano (recomendado para comprobar)

```bash
php artisan pdfs:import
```

Sin `--since`, `--today` ni `--year` se escanea todo el disco y se indexan todos los PDFs con ruta y fecha válidas.

### Opción B: Cron cada 15 min con importación completa (más lento)

En `app/Console/Kernel.php`, línea 23, cambiar:

```php
$schedule->command('pdfs:import --since=60')
```

por:

```php
$schedule->command('pdfs:import')
```

Así cada 15 minutos se reindexa todo. Puede ser pesado si hay muchos archivos o disco en red.

### Opción C: Mantener cron corto + asegurar el diario

- Dejar el cron cada 15 min con `--since=60` para actualizaciones rápidas.
- Asegurar que el cron del sistema ejecute `schedule:run` cada minuto y que el servidor esté encendido a las 02:00 para que `pdfs:import` (sin opciones) corra y traiga **todo** el histórico al menos una vez al día.

### Opción D: Desde la app (API)

El endpoint de importación (p. ej. “Actualizar índice”) puede llamar con `scope=all` para ejecutar `pdfs:import` sin `--since`, es decir, importación completa, sin restricción de fecha.

---

## 7. Resumen

| Pregunta | Respuesta |
|----------|-----------|
| **¿Dónde está el comando?** | `app/Console/Commands/ImportPdfs.php` → `pdfs:import`. |
| **¿Consulta SQL/Eloquent?** | No. Origen = listado de archivos en disco `pdf_remote`. |
| **¿Filtros de fecha?** | Sí: `$cutoff` (--since / --today) y `$windowDays` restringen qué carpetas/archivos se consideran. |
| **¿Filtros de estado?** | No. |
| **¿Límites / paginación?** | No. |
| **¿Cómo se evita duplicar?** | `updateOrCreate(['path' => $relative], [...])` → por `path`. |
| **¿Qué línea restringe?** | Sobre todo el **uso de `--since=60`** en el cron (Kernel línea 23) y la construcción/uso de **`$windowDays`** y **`$cutoff`** en `ImportPdfs.php` (líneas 29–37, 41–47, 62–64, 84–95). |
| **¿Cómo traer todo?** | Ejecutar `php artisan pdfs:import` sin opciones y/o asegurar que el cron diario a las 02:00 se ejecute; opcionalmente cambiar el cron de 15 min a `pdfs:import` sin `--since` si se acepta el coste. |
