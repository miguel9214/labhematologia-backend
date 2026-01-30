<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PdfProxyController extends Controller
{
    public function show(Request $request)
    {
        $relative = $request->query('path'); // ej: "AGOSTO 2025/29 AGOSTO/26739518.pdf"
        if (!$relative) abort(404);

        $relative = trim(str_replace(['..', '\\'], ['','/'], $relative), '/');

        // Buscar en subidas directas primero, luego en carpeta compartida
        $disk = Storage::disk('pdf_uploads')->exists($relative)
            ? Storage::disk('pdf_uploads')
            : Storage::disk('pdf_remote');
        if (!$disk->exists($relative)) {
            abort(404);
        }

        $absolute = $disk->path($relative);
        return new BinaryFileResponse($absolute, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($relative).'"',
        ]);
    }
}
