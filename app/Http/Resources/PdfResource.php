<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PdfResource extends JsonResource
{
    public function toArray($request): array
    {
        // preferimos usar 'path' si existe
        $relative = $this->path ?? trim("{$this->year}/{$this->month}/{$this->day}/{$this->name}", '/');

        return [
            'id'    => $this->id ?? null,
            'name'  => $this->name,
            'year'  => $this->year,
            'month' => $this->month,
            'day'   => $this->day,

            // URL pública (vía symlink public/pdfs -> PDF_REMOTE_ROOT)
            'url_public' => Storage::disk('pdf_remote')->url($relative),

            // URL proxy firmada por 30 minutos (requiere ruta con ->name('pdf.view')->middleware('signed'))
            'url_proxy'  => URL::temporarySignedRoute(
                'pdf.view',
                now()->addMinutes(30),
                ['path' => $relative]
            ),

            // Para compatibilidad con tu campo actual 'url' (si existe en BD)
            'url_legacy' => $this->url ?? null,
        ];
    }
}
