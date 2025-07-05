<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PdfDocument extends Model
{
    protected $fillable = ['name', 'path', 'year', 'month', 'day'];
    protected $appends  = ['url'];

    // app/Models/PdfDocument.php

    public function getUrlAttribute()
    {
        // Genera: https://tu-dominio.com/storage/pdfs/2025/07/01/1174621.pdf
        return asset("storage/pdfs/{$this->path}");
    }
}
