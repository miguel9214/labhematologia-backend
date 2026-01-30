<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PdfDocument extends Model
{
    protected $fillable = ['name', 'path', 'year', 'month', 'day', 'source'];

    /** 'remote' = carpeta compartida, 'local' = subido por la app */
    public const SOURCE_REMOTE = 'remote';
    public const SOURCE_LOCAL = 'local';

    // Agregamos accesores útiles:
    protected $appends  = ['url_public', 'url_proxy', 'basename'];

    /**
     * Normaliza slashes al guardar 'path'
     */
    public function setPathAttribute($value): void
    {
        $this->attributes['path'] = str_replace('\\', '/', trim($value, '/'));
    }

    /**
     * Nombre de archivo (p. ej. "1174621.pdf")
     */
    public function getBasenameAttribute(): ?string
    {
        return $this->path ? basename($this->path) : null;
    }

    /**
     * URL pública: local usa pdf_uploads, remoto usa pdf_remote
     */
    public function getUrlPublicAttribute(): ?string
    {
        if (!$this->path) return null;
        $disk = ($this->attributes['source'] ?? 'remote') === self::SOURCE_LOCAL
            ? 'pdf_uploads'
            : 'pdf_remote';
        return Storage::disk($disk)->url($this->path);
    }

    /**
     * URL proxy firmada (opcional, si tu ruta tiene ->name('pdf.view')->middleware('signed'))
     */
    public function getUrlProxyAttribute(): ?string
    {
        if (!$this->path) return null;

        return URL::temporarySignedRoute(
            'pdf.view',
            now()->addMinutes(30),
            ['path' => $this->path]
        );
    }

    /**
     * (Compat) Si quieres mantener 'url' como antes,
     * ahora que apunte a la URL correcta del disk.
     */
    public function getUrlAttribute(): ?string
    {
        return $this->url_public;
    }

    /* Scopes útiles para filtros */
    public function scopeYear($q, $y)  { return $y ? $q->where('year',  $y) : $q; }
    public function scopeMonth($q, $m) { return $m ? $q->where('month', $m) : $q; }
    public function scopeDay($q, $d)   { return $d ? $q->where('day',   $d) : $q; }
    public function scopeSearch($q, $s)
    {
        if (!$s) return $q;
        return $q->where(function($w) use ($s) {
            $w->where('name', 'like', "%{$s}%")
              ->orWhere('path', 'like', "%{$s}%");
        });
    }
}
