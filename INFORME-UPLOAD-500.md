# Informe: Error 500 en POST api/pdf/upload

Este documento describe cómo funciona la subida de PDFs y cómo depurar el error **500 (Internal Server Error)** en `api/pdf/upload` para que otra herramienta (p. ej. Gemini) pueda ayudar a resolverlo.

---

## 1. Arquitectura general

- **Frontend:** Vue 3 + Vite, en `C:\laragon\www\labhematologia-frontend`
- **Backend:** Laravel (API), en `C:\laragon\www\labhematologia-backend`
- **Petición:** El frontend hace `POST` a la API; en desarrollo Vite hace proxy de `/api` al backend.

---

## 2. Flujo de la subida (paso a paso)

### 2.1 Frontend (Vue)

1. **Vista:** `src/views/CargaExamenesView.vue`
   - El usuario elige fecha (año, mes, día) y arrastra/selecciona uno o más PDF.
   - Al pulsar "Subir exámenes" se llama a `subirArchivos()`.

2. **Composable:** `src/composables/useApi.js`
   - Función `uploadPdfs(files, opts)`:
     - Construye un `FormData`.
     - Un archivo: `form.append('file', files[0])`.
     - Varios: `form.append('files[]', f)` por cada uno.
     - Opcional: `form.append('year', opts.year)`, `month`, `day`.
     - Llama a `useApi('pdf/upload', 'POST', form)`.

3. **Base URL en desarrollo:**
   - `BASE = '/api'` (Vite proxy redirige al backend).
   - Petición real: `POST /api/pdf/upload` (relativo al origen del frontend).

4. **Proxy Vite** (`vite.config.js`):
   - `/api` → `target: 'http://labhematologia-backend.test'`
   - La petición que llega al backend es: `POST http://labhematologia-backend.test/api/pdf/upload`.

### 2.2 Backend (Laravel)

1. **Ruta:** `routes/api.php`
   - `Route::post('upload', [PdfUploadController::class, '__invoke'])->name('pdf.upload')->middleware('throttle:pdf-upload');`
   - URL completa: `POST /api/pdf/upload`.

2. **Controlador:** `App\Http\Controllers\PdfUploadController`
   - `__invoke(Request $request)` envuelve todo en try/catch y ante cualquier `\Throwable` devuelve **500** con:
     - `{ "ok": false, "message": "Error al subir. " . $e->getMessage() }`
   - Por tanto, **el cuerpo de la respuesta 500 incluye el mensaje de la excepción** (útil para depurar).

3. **Lógica interna (`doUpload`):**
   - **Validación:** `file` o `files[]`, `mimes:pdf`, `max:25*1024` KB. Opcional: `year`, `month`, `day` (integer en rangos lógicos).
   - **Duplicados:** Si ya existe un `PdfDocument` con mismo `year`, `month`, `day` y `name` (nombre original del archivo), responde **422** (no 500).
   - **Destino del archivo:**
     - Si existe y es directorio `config('filesystems.disks.pdf_remote.root')` (p. ej. `//labhematologia/LABHEMATOLOGIA`): intenta guardar ahí en `SubidosApp/YYYY/MM/DD/` con PHP nativo (`mkdir` + `copy`). Si falla, hace fallback a disco local.
     - Si no hay carpeta de red: guarda en disco `pdf_uploads` (Laravel Storage), raíz `storage_path('app/pdf_uploads')`, estructura `YYYY/MM/DD/`.
   - **Base de datos:** `PdfDocument::updateOrCreate(['path' => $path], ['name' => ..., 'year' => ..., 'month' => ..., 'day' => ..., 'source' => 'local'])`.
   - **Respuesta éxito:** 201 con `ok`, `message`, `count`, `data` (incluye `url_proxy` firmada).

---

## 3. Configuración relevante (backend)

### 3.1 Discos de archivos (`config/filesystems.php`)

- **pdf_remote:** raíz `env('PDF_REMOTE_ROOT', storage_path('app/pdfs'))`. Se usa para carpeta compartida (red) o, por defecto, carpeta local.
- **pdf_uploads:** raíz `storage_path('app/pdf_uploads')` (subidas directas de la app).

### 3.2 Variables de entorno (`.env`)

- `PDF_REMOTE_ROOT`: ruta de la carpeta compartida (ej.: `//labhematologia/LABHEMATOLOGIA`). Si no existe o no es directorio, se usa solo `pdf_uploads`.
- `APP_URL`: usada para URLs firmadas (p. ej. `http://labhematologia-backend.test`).

### 3.3 Base de datos

- Tabla `pdf_documents`: `id`, `name`, `path`, `year`, `month`, `day`, `source` (`remote` | `local`), `timestamps`.
- La migración `2026_01_29_000000_add_source_to_pdf_documents_table` añade `source` (por defecto `remote`). Si esta migración no se ha ejecutado, guardar `source` puede provocar error (columna inexistente).

### 3.4 Throttle

- Ruta `pdf/upload` usa `throttle:pdf-upload`: 60 req/min en local, 30 en producción. Si se supera, Laravel responde **429**, no 500.

---

## 4. Posibles causas del 500

1. **Columna `source` inexistente:** migración no ejecutada → fallo en `PdfDocument::updateOrCreate(..., ['source' => PdfDocument::SOURCE_LOCAL])`. Solución: `php artisan migrate`.
2. **Permisos en `storage/app/pdf_uploads`:** el usuario del servidor web (PHP) no puede crear directorios o escribir. Solución: permisos (p. ej. 775) y propietario correcto.
3. **Carpeta de red (`PDF_REMOTE_ROOT`):** si está configurada pero no accesible (red caída, credenciales, etc.), el código hace fallback a local; si algo en ese camino lanza (p. ej. en `is_dir()` o al construir rutas), podría salir 500. Revisar que el mensaje de la excepción en el 500 indique el archivo y línea.
4. **Ruta firmada (`URL::temporarySignedRoute('pdf.view', ...)`):** si `APP_URL` está mal o la ruta `pdf.view` no existe, podría lanzar. Comprobar `php artisan route:list --name=pdf`.
5. **Validación oculta:** a veces `$request->validate()` o el manejo de `file`/`files` con FormData puede lanzar en lugar de devolver 422; si eso ocurre dentro del try/catch del controlador, se devuelve 500 con el mensaje de la excepción.
6. **Memoria o tamaño de archivo:** archivos muy grandes o muchos a la vez pueden provocar límites de PHP (`upload_max_filesize`, `post_max_size`) o tiempo; PHP puede fallar con una excepción/error que Laravel convierte en 500.

---

## 5. Cómo obtener el error exacto (para depurar con Gemini u otra herramienta)

### 5.1 Cuerpo de la respuesta 500

El controlador devuelve en JSON:

```json
{
  "ok": false,
  "message": "Error al subir. <mensaje de la excepción>"
}
```

- En el navegador: pestaña Red (Network), solicitud `pdf/upload`, ver respuesta (Response) o “Preview”.
- Con eso ya se puede ver el texto de la excepción (p. ej. “SQLSTATE...”, “Column not found: source”, “Permission denied”).

### 5.2 Log de Laravel

- Archivo: `storage/logs/laravel.log`.
- El controlador registra cada 500 con `Log::error('[pdf/upload] Error 500: ...')` incluyendo mensaje, clase de excepción, archivo, línea y trace. Buscar líneas recientes con `[pdf/upload]` o la hora de la petición fallida.

### 5.3 Comandos útiles

```bash
cd C:\laragon\www\labhematologia-backend
php artisan migrate
php artisan route:list --name=pdf
```

- Comprobar que exista la ruta con nombre `pdf.view` y que `pdf.upload` esté registrada.
- Comprobar permisos de `storage` y `storage/app/pdf_uploads` (y que existan).

---

## 6. Resumen para pegar a Gemini (o similar)

- **Error:** `POST api/pdf/upload` devuelve **500 Internal Server Error**.
- **Stack:** Frontend Vue 3 (Vite) → proxy `/api` → Laravel `POST /api/pdf/upload` → `PdfUploadController::__invoke`.
- **Payload:** `FormData` con `file` (o `files[]`) y opcionalmente `year`, `month`, `day`.
- **Backend:** Valida PDF, evita duplicados por (year, month, day, name), guarda en `pdf_remote` (red) o en `pdf_uploads` (local), y crea/actualiza fila en `pdf_documents` con `source = 'local'`. Cualquier excepción se captura y se devuelve en `message` del JSON 500.
- **Qué necesito:** El **texto completo de `message`** en la respuesta 500 (o el stack en `storage/logs/laravel.log`) para identificar la causa (BD, permisos, disco, ruta firmada, etc.).

Si puedes pegar aquí el contenido de `message` de la respuesta 500 o el fragmento relevante del log, se puede acotar la causa y proponer el cambio exacto (migración, permisos, config o código).
