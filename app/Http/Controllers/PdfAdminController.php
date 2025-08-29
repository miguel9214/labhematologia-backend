<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class PdfAdminController extends Controller
{
    // Si quieres proteger estas rutas con autenticación (ej. Sanctum), descomenta:
    // public function __construct()
    // {
    //     $this->middleware('auth:sanctum')->only(['import']);
    // }

    /**
     * Devuelve la última fecha/hora en que se ejecutó la importación/sincronización.
     */
    public function lastSync()
    {
        $ts = Cache::get('pdfs:last_sync'); // ej: "2025-08-29 16:02:00"

        return response()->json([
            'last_sync' => $ts ? (string) $ts : null,
        ]);
    }

    /**
     * Dispara la importación de PDFs usando el comando artisan pdfs:import.
     * - Usa un lock de 5 minutos para evitar ejecuciones concurrentes.
     * - Guarda la marca de tiempo en cache para mostrarla en el frontend.
     */
public function import(Request $request)
{
    $ttlSeconds = 120;   // el candado dura 2 min
    $waitMax    = 5;     // espera hasta 5s para adquirir el lock

    $lock = Cache::lock('pdfs:import', $ttlSeconds);

    try {
        // Espera hasta 5s a que se libere el lock, si no lanza LockTimeoutException
        $lock->block($waitMax);

        Log::info('[pdfs:import] inicio');
        Artisan::call('pdfs:import');

        $now = now()->format('Y-m-d H:i:s');
        Cache::put('pdfs:last_sync', $now, 60 * 60 * 24 * 30);

        Log::info('[pdfs:import] fin OK', ['last_sync' => $now]);

        return response()->json([
            'ok'        => true,
            'message'   => 'Importación completada.',
            'last_sync' => $now,
        ]);
    } catch (LockTimeoutException $e) {
        // No pudo adquirir el lock en 5s → devuelve 429 con Retry-After
        $retry = 5;
        return response()
            ->json([
                'ok'        => false,
                'message'   => 'Ya hay una importación en curso. Intenta de nuevo en unos segundos.',
                'last_sync' => Cache::get('pdfs:last_sync'),
            ], 429)
            ->header('Retry-After', $retry);
    } finally {
        // Libera candado si lo tienes
        optional($lock)->release();
    }
}
}
