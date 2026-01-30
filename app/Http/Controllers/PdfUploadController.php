<?php

namespace App\Http\Controllers;

use App\Models\PdfDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PdfUploadController extends Controller
{
    private const UPLOAD_SUBDIR = 'SubidosApp';
    private const MAX_SIZE_KB = 25600; // 25 MB

    /**
     * Maneja la subida de uno o varios PDFs.
     */
    public function __invoke(Request $request)
    {
        try {
            return $this->doUpload($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (Throwable $e) {
            DB::rollBack();
            $msg = $e->getMessage();
            if (str_contains($msg, 'Ya existe un examen con la misma fecha y nombre')) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }
            Log::error('[pdf/upload] Error 500: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            if (str_contains($msg, 'Path cannot be empty')) {
                $msg = 'Error de configuración: Una ruta de destino está vacía. Revise pdf_uploads.root y pdf_remote.root.';
            }
            return response()->json([
                'ok' => false,
                'message' => 'Error al subir. ' . $msg,
                'error_detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Lógica principal de subida. Incluye bloque de diagnóstico al inicio.
     */
    private function doUpload(Request $request)
    {
        DB::beginTransaction();
        $results = [];

        $request->validate([
            'files' => 'required_without:file|array',
            'files.*' => 'mimes:pdf|max:' . self::MAX_SIZE_KB,
            'file' => 'required_without:files|file|mimes:pdf|max:' . self::MAX_SIZE_KB,
            'year' => ['nullable', 'integer', 'between:1990,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'day' => ['nullable', 'integer', 'between:1,31'],
        ], [
            'file.required_without' => 'Debe enviar un archivo PDF (file o files).',
            'files.required_without' => 'Debe enviar un archivo PDF (file o files).',
            'file.mimes' => 'Solo se permiten archivos PDF.',
            'files.*.mimes' => 'Solo se permiten archivos PDF.',
            'file.max' => 'El archivo no debe superar 25 MB.',
            'files.*.max' => 'Cada archivo no debe superar 25 MB.',
        ]);

        $files = $request->hasFile('files')
            ? (array) $request->file('files')
            : [$request->file('file')];

        $year = $request->input('year');
        $month = $request->input('month');
        $day = $request->input('day');

        // ---------- DEBUG PROFUNDO (diagnóstico antes de cualquier guardado) ----------
        $firstFile = $files[0] ?? null;
        if ($firstFile) {
            if (!$firstFile->isValid()) {
                throw new RuntimeException('Subida inválida (php.ini / servidor): ' . $firstFile->getErrorMessage());
            }

            $debug_pdf_remote_root = config('filesystems.disks.pdf_remote.root');
            $debug_pdf_uploads_root = config('filesystems.disks.pdf_uploads.root');
            $debug_file_temp_path = $firstFile->getRealPath() ?: (method_exists($firstFile, 'path') ? $firstFile->path() : null);
            $debug_file_original_name = $firstFile->getClientOriginalName();

            Log::emergency('[DEBUG-PDF] Valores crudos antes de guardar', [
                'pdf_remote.root' => $debug_pdf_remote_root,
                'pdf_remote.root_type' => gettype($debug_pdf_remote_root),
                'pdf_uploads.root' => $debug_pdf_uploads_root,
                'pdf_uploads.root_type' => gettype($debug_pdf_uploads_root),
                'file_temp_path' => $debug_file_temp_path,
                'file_temp_path_type' => gettype($debug_file_temp_path),
                'file_original_name' => $debug_file_original_name,
            ]);

            if ($debug_pdf_remote_root === null || $debug_pdf_remote_root === '') {
                throw new RuntimeException('DEBUG: La config pdf_remote.root está vacía o es NULL.');
            }
            if (trim((string) $debug_pdf_remote_root) === '') {
                throw new RuntimeException('DEBUG: La config pdf_remote.root es una cadena vacía (solo espacios).');
            }
            if ($debug_pdf_uploads_root === null || $debug_pdf_uploads_root === '') {
                throw new RuntimeException('DEBUG: La config pdf_uploads.root está vacía o es NULL.');
            }
            if (trim((string) $debug_pdf_uploads_root) === '') {
                throw new RuntimeException('DEBUG: La config pdf_uploads.root es una cadena vacía (solo espacios).');
            }
            if ($debug_file_temp_path === false || $debug_file_temp_path === null || $debug_file_temp_path === '') {
                throw new RuntimeException('DEBUG: La ruta temporal del archivo (getRealPath/path) está vacía o no disponible.');
            }
            if (trim((string) $debug_file_original_name) === '') {
                throw new RuntimeException('DEBUG: El nombre original del archivo está vacío.');
            }
        }
        // ---------- Fin DEBUG PROFUNDO ----------

        foreach ($files as $file) {
            if ($file && $file->isValid()) {
                $results[] = $this->processOneFile($file, $year, $month, $day);
            }
        }

        if (empty($results)) {
            DB::rollBack();
            return response()->json([
                'ok' => false,
                'message' => 'No se recibió ningún archivo PDF válido.',
            ], 422);
        }

        DB::commit();

        \Illuminate\Support\Facades\Cache::increment('pdfs:list:version');

        $where = collect($results)->contains('source', PdfDocument::SOURCE_REMOTE) ? 'carpeta de red' : 'servidor (local)';
        return response()->json([
            'ok' => true,
            'message' => count($results) === 1
                ? "Examen subido correctamente ({$where})."
                : count($results) . " exámenes subidos correctamente ({$where}).",
            'count' => count($results),
            'data' => $results,
        ], 201);
    }

    /**
     * Procesa un solo archivo: remoto (si aplica) o local, y persiste en BD.
     */
    private function processOneFile($file, $year, $month, $day): array
    {
        $originalName = $file->getClientOriginalName();

        $y = (int) ($year ?? date('Y'));
        $m = (int) ($month ?? date('n'));
        $d = (int) ($day ?? date('j'));

        $dateDir = sprintf('%04d/%02d/%02d', $y, $m, $d);
        $this->ensurePathNonEmpty($dateDir, 'dateDir');

        if (PdfDocument::where('year', $y)->where('month', $m)->where('day', $d)->where('name', $originalName)->exists()) {
            throw new RuntimeException("Ya existe un examen con la misma fecha y nombre («{$originalName}»). No se ha subido para evitar duplicados.");
        }

        $storedFilename = $this->buildStoredFilename($file, $originalName);
        $this->ensurePathNonEmpty($storedFilename, 'filename');

        $savedPath = null;
        $source = PdfDocument::SOURCE_LOCAL;

        if ($this->trySaveToRemote($file, $dateDir, $storedFilename)) {
            $savedPath = self::UPLOAD_SUBDIR . '/' . $dateDir . '/' . $storedFilename;
            $source = PdfDocument::SOURCE_REMOTE;
        } else {
            $savedPath = $this->saveToLocal($file, $dateDir, $storedFilename);
        }

        $this->ensurePathNonEmpty($savedPath, 'path guardado');

        $pdf = PdfDocument::updateOrCreate(
            ['path' => $savedPath],
            [
                'name' => $originalName,
                'year' => $y,
                'month' => $m,
                'day' => $d,
                'source' => $source,
            ]
        );

        return [
            'id' => $pdf->id,
            'name' => $pdf->name,
            'path' => $pdf->path,
            'year' => $pdf->year,
            'month' => $pdf->month,
            'day' => $pdf->day,
            'source' => $pdf->source,
            'url_proxy' => $pdf->url_proxy,
        ];
    }

    /**
     * Intenta guardar en la carpeta compartida de red.
     * Retorna true si éxito, false si falla (sin lanzar).
     */
    private function trySaveToRemote($file, string $dateDir, string $filename): bool
    {
        $remoteRoot = config('filesystems.disks.pdf_remote.root');

        if ($remoteRoot === null || $remoteRoot === '' || !is_dir($remoteRoot)) {
            Log::debug('[pdf/upload] Remoto no disponible: root vacío o no es directorio.');
            return false;
        }

        $this->ensurePathNonEmpty(trim($remoteRoot), 'pdf_remote.root');

        try {
            $destDir = rtrim(str_replace('\\', '/', $remoteRoot), '/') . '/' . self::UPLOAD_SUBDIR . '/' . str_replace('\\', '/', $dateDir);

            if (!is_dir($destDir) && !@mkdir($destDir, 0775, true)) {
                Log::warning('[pdf/upload] No se pudo crear directorio remoto.', ['destDir' => $destDir]);
                return false;
            }

            $destPath = $destDir . '/' . $filename;
            $this->ensurePathNonEmpty($destPath, 'ruta destino remota');

            $sourcePath = $file->getRealPath();
            if ($sourcePath === false || $sourcePath === '') {
                Log::warning('[pdf/upload] Ruta temporal del archivo no disponible.');
                return false;
            }

            if (@copy($sourcePath, $destPath)) {
                return true;
            }

            Log::warning('[pdf/upload] Falló copy() al remoto.', ['destPath' => $destPath]);
            return false;
        } catch (Throwable $e) {
            Log::warning('[pdf/upload] Excepción en remoto: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Guarda en disco local. Lanza RuntimeException si la config o la escritura fallan.
     */
    private function saveToLocal($file, string $dateDir, string $filename): string
    {
        $diskName = 'pdf_uploads';
        $localRoot = config("filesystems.disks.{$diskName}.root");

        if ($localRoot === null || trim((string) $localRoot) === '') {
            throw new RuntimeException("Configuración crítica: filesystems.disks.{$diskName}.root está vacío o null. Revise config/filesystems.php y .env.");
        }

        $this->ensurePathNonEmpty($dateDir, 'dateDir (local)');
        $this->ensurePathNonEmpty($filename, 'filename (local)');

        $disk = Storage::disk($diskName);
        $disk->makeDirectory($dateDir);
        $path = $disk->putFileAs($dateDir, $file, $filename);

        if ($path === '' || $path === false) {
            throw new RuntimeException("Fallo al escribir en disco local ({$diskName}). Verifique permisos en storage/app/pdf_uploads.");
        }

        return $path;
    }

    private function buildStoredFilename($file, string $originalName): string
    {
        $safe = Str::slug(pathinfo($originalName, PATHINFO_FILENAME), '_');
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $safe) ?: 'documento';
        $ext = strtolower($file->getClientOriginalExtension() ?: 'pdf');
        if ($ext !== 'pdf') {
            $ext = 'pdf';
        }
        return substr(uniqid('', true), -8) . '_' . Str::limit($safe, 80) . '.' . $ext;
    }

    /**
     * Valida que una ruta/valor no esté vacío. Lanza RuntimeException si está vacío.
     */
    private function ensurePathNonEmpty(?string $path, string $label): void
    {
        $trimmed = trim((string) $path);
        if ($trimmed === '') {
            throw new RuntimeException("Error interno: El valor para «{$label}» está vacío. Revise la configuración de filesystems.");
        }
    }
}
